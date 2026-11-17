<?php
// Reúne os próximos compromissos, os treinos recentes e o resumo de mensalidades por perfil.
require_once __DIR__ . '/includes/layout.php';
// Visão individual com vínculo validado no servidor.
if (hasRole(['RESPONSAVEL'])) {
    checkLogin();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(403); exit('Acesso somente para consulta.'); }
    header('Location: area_responsavel.php');
    exit;
}

checkLogin();
require_once __DIR__ . '/config/database.php';

if (($_SESSION['user_role'] ?? '') === 'ALUNO') {
    header('Location: meus_alunos.php');
    exit;
}

$db = getDB();
$escolinha = currentEscolinhaId();
$perfil = $_SESSION['user_role'];
$podeAdministrar = hasRole(['ADMIN', 'SECRETARIA']);
$podeDarAula = hasRole(['ADMIN', 'PROFESSOR']);
$podeGerenciarUsuarios = hasRole(['ADMIN']);
$filtroProfessor = $perfil === 'PROFESSOR';

$parametrosTurma = [$escolinha];
$filtroTurma = '';
if ($filtroProfessor) {
    $filtroTurma .= ' AND t.COD_PROFESSOR = ?';
    $parametrosTurma[] = $_SESSION['user_id'];
}
$hoje = new DateTimeImmutable($db->query('SELECT CURDATE()')->fetchColumn());
$treinoFuturo = '(a.DATA_AULA > CURDATE() OR (a.DATA_AULA = CURDATE() AND (a.HORARIO IS NULL OR a.HORARIO >= CURTIME())))';
$secoesTreino = [
    ['titulo' => 'Próximos treinos', 'vazio' => 'Nenhum treino futuro cadastrado.',
     'filtro' => 't.ATIVA=1 AND ' . $treinoFuturo, 'ordem' => 'a.DATA_AULA, a.HORARIO, a.COD_AULA', 'ordemCronologica' => false],
    ['titulo' => 'Treinos recentes', 'vazio' => 'Nenhum treino anterior cadastrado.',
     'filtro' => 'NOT ' . $treinoFuturo, 'ordem' => 'a.DATA_AULA DESC, a.HORARIO DESC, a.COD_AULA DESC', 'ordemCronologica' => true],
];
foreach ($secoesTreino as $indice => $secao) {
    $consulta = $db->prepare(
        "SELECT a.COD_AULA AS aula, a.DATA_AULA AS data, a.TEMA_TREINO AS tema,
                t.NOME AS turma, COALESCE(TIME_FORMAT(a.HORARIO, '%H:%i'), t.HORARIO) AS horario
         FROM TB_AULA a JOIN TB_TURMA t ON t.COD_TURMA=a.COD_TURMA
         WHERE t.COD_ESCOLINHA=? AND " . $secao['filtro'] . $filtroTurma
        . ' ORDER BY ' . $secao['ordem'] . ' LIMIT 4'
    );
    $consulta->execute($parametrosTurma);
    $treinos = $consulta->fetchAll();
    $secoesTreino[$indice]['treinos'] = $secao['ordemCronologica'] ? array_reverse($treinos) : $treinos;
}
$dias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$mes = $hoje->format('Y-m');
if ($podeAdministrar) {
    $consulta = $db->prepare("SELECT
        COUNT(CASE WHEN f.STATUS='PAGO' THEN 1 END) AS pagas,
        COUNT(CASE WHEN f.STATUS='NAO_PAGO' OR (f.STATUS='PENDENTE' AND f.VENCIMENTO >= ?) THEN 1 END) AS pendentes,
        COUNT(CASE WHEN f.STATUS='ATRASADO' OR (f.STATUS='PENDENTE' AND f.VENCIMENTO < ?) THEN 1 END) AS atrasadas,
        COUNT(CASE WHEN f.COD_LANCAMENTO IS NULL THEN 1 END) AS sem_lancamento
        FROM TB_ALUNO a LEFT JOIN TB_FINANCEIRO f ON f.COD_ALUNO=a.COD_ALUNO
            AND f.COD_ESCOLINHA=a.COD_ESCOLINHA AND f.CATEGORIA='MENSALIDADE' AND f.COMPETENCIA=?
        WHERE a.COD_ESCOLINHA=? AND (a.STATUS='ATIVO' OR f.COD_LANCAMENTO IS NOT NULL)");
    $consulta->execute([$hoje->format('Y-m-d'), $hoje->format('Y-m-d'), $mes . '-01', $escolinha]);
    $mensalidades = $consulta->fetch();
}
pageStart('Painel');
?>
<div class="painel-rotina">
    <?php foreach ($secoesTreino as $secao): ?>
    <section class="painel-bloco">
        <div class="painel-secao"><h2><?= e($secao['titulo']) ?></h2><a href="aulas.php">Ver todos os treinos</a></div>
        <?php if ($secao['treinos']): ?>
            <div class="painel-tabela"><table>
                <thead><tr><th>Data</th><th>Horário</th><th>Turma e treino</th><th>Ação</th></tr></thead>
                <tbody>
                    <?php foreach ($secao['treinos'] as $treino): $data = new DateTimeImmutable($treino['data']); ?>
                        <tr>
                            <td><?= e($dias[(int) $data->format('w')] . ', ' . $data->format('d/m')) ?></td>
                            <td><?= e($treino['horario'] ?: '—') ?></td>
                            <td><strong><?= e($treino['turma']) ?></strong><br><small><?= e($treino['tema'] ?: 'Treino') ?></small></td>
                            <td><?php if ($treino['aula']): ?><a href="aulas.php?chamada=<?= (int) $treino['aula'] ?>">Abrir chamada</a><?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else: ?><p><?= e($secao['vazio']) ?></p><?php endif; ?>
    </section>

    <?php endforeach; ?>

    <?php if ($podeAdministrar): ?>
        <section class="painel-bloco">
            <div class="painel-secao"><h2>Mensalidades · <?= e($hoje->format('m/Y')) ?></h2><a href="alunos.php?mensalidades=1&amp;mes=<?= e($mes) ?>&amp;relatorio=1">Ver relatório</a></div>
            <dl class="painel-totais">
                <div><dt>Pagas</dt><dd><?= (int) $mensalidades['pagas'] ?></dd></div>
                <div><dt>A vencer</dt><dd><?= (int) $mensalidades['pendentes'] ?></dd></div>
                <div><dt>Atrasadas</dt><dd<?= (int) $mensalidades['atrasadas'] ? ' class="mensalidade-atrasada"' : '' ?>><?= (int) $mensalidades['atrasadas'] ?></dd></div>
                <div><dt>Sem lançamento</dt><dd><?= (int) $mensalidades['sem_lancamento'] ?></dd></div>
            </dl>
        </section>
    <?php endif; ?>

    <section class="painel-bloco">
        <h2>Ações frequentes</h2>
        <div class="painel-atalhos">
            <?php if ($podeAdministrar): ?>
                <button type="button" data-tela="alunos.php?mini=1" data-titulo="Cadastrar aluno">Cadastrar aluno</button>
                <a href="alunos.php?mensalidades=1">Registrar pagamento</a>
                <a href="responsaveis.php">Gerenciar responsáveis</a>
                <a href="turmas.php">Gerenciar turmas</a>
                <a href="matriculas.php">Gerenciar matrículas</a>
                <a href="camisas.php">Gerenciar camisas</a>
            <?php endif; ?>
            <?php if ($podeDarAula): ?>
                <button type="button" data-tela="aulas.php?mini=1" data-titulo="Cadastrar treino">Cadastrar treino</button>
                <button type="button" data-tela="aulas.php?mini=1&amp;chamadas=1" data-titulo="Fazer chamada">Fazer chamada</button>
            <?php endif; ?>
            <?php if ($podeGerenciarUsuarios): ?><a href="usuarios.php">Gerenciar usuários</a><?php endif; ?>
        </div>
    </section>
        <dialog class="modal-cadastro" id="modalCadastro" aria-labelledby="tituloModal">
            <header class="modal-cabecalho"><h2 id="tituloModal">Cadastro</h2><button type="button" class="fechar-modal" id="fecharModal" aria-label="Fechar">×</button></header>
            <iframe id="conteudoModal" title="Formulário de cadastro"></iframe>
        </dialog>
        <script nonce="<?= cspNonce() ?>">
            const modal = document.getElementById('modalCadastro');
            const iframe = document.getElementById('conteudoModal');
            const tituloModal = document.getElementById('tituloModal');
            document.querySelectorAll('button[data-tela]').forEach(function (botao) {
                botao.addEventListener('click', function () {
                    iframe.src = botao.dataset.tela;
                    tituloModal.textContent = botao.dataset.titulo;
                    modal.showModal();
                });
            });
            document.getElementById('fecharModal').addEventListener('click', function () { modal.close(); });
            modal.addEventListener('close', function () { iframe.src = 'about:blank'; });
        </script>
</div>
<?php pageEnd(); ?>
