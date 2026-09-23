<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';

$db = getDB();
$escolinha = currentEscolinhaId();
$perfil = $_SESSION['user_role'];
$podeAdministrar = hasRole(['ADMIN', 'SECRETARIA']);
$podeDarAula = hasRole(['ADMIN', 'PROFESSOR']);
$podeGerenciarUsuarios = hasRole(['ADMIN']);

if ($perfil === 'RESPONSAVEL') {
    $consulta = $db->prepare('SELECT COD_RESPONSAVEL FROM TB_RESPONSAVEL WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
    $consulta->execute([$_SESSION['user_id'], $escolinha]);
    $responsavel = (int) $consulta->fetchColumn();

    $filhos = [];
    if ($responsavel) {
        $consulta = $db->prepare(
            "SELECT a.COD_ALUNO, a.NOME, a.STATUS, a.NUMERO_CAMISA,
                    GROUP_CONCAT(DISTINCT t.NOME ORDER BY t.NOME SEPARATOR ', ') AS TURMAS
             FROM TB_ALUNO a
             LEFT JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = 'ATIVA'
             LEFT JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA
             WHERE a.COD_RESPONSAVEL = ? AND a.COD_ESCOLINHA = ?
             GROUP BY a.COD_ALUNO ORDER BY a.NOME"
        );
        $consulta->execute([$responsavel, $escolinha]);
        $filhos = $consulta->fetchAll();
    }
} else {
    $filtroProfessor = $perfil === 'PROFESSOR';
    $parametros = $filtroProfessor ? [$escolinha, $_SESSION['user_id']] : [$escolinha];

    $consulta = $db->prepare(
        'SELECT COUNT(*) FROM TB_TURMA WHERE COD_ESCOLINHA = ? AND ATIVA = 1'
        . ($filtroProfessor ? ' AND COD_PROFESSOR = ?' : '')
    );
    $consulta->execute($parametros);
    $totalTurmas = (int) $consulta->fetchColumn();

    $sqlAlunos = "SELECT COUNT(DISTINCT a.COD_ALUNO)
                  FROM TB_ALUNO a
                  JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = 'ATIVA'
                  JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA AND t.ATIVA = 1
                  WHERE a.COD_ESCOLINHA = ?" . ($filtroProfessor ? ' AND t.COD_PROFESSOR = ?' : '');
    $consulta = $db->prepare($sqlAlunos);
    $consulta->execute($parametros);
    $totalAlunos = (int) $consulta->fetchColumn();

    $sqlAulas = 'SELECT COUNT(*) FROM TB_AULA a JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA WHERE t.COD_ESCOLINHA = ?'
        . ($filtroProfessor ? ' AND t.COD_PROFESSOR = ?' : '');
    $consulta = $db->prepare($sqlAulas);
    $consulta->execute($parametros);
    $totalAulas = (int) $consulta->fetchColumn();

    $sqlFrequencia = 'SELECT AVG(p.PRESENTE) * 100 FROM TB_PRESENCA p JOIN TB_ALUNO a ON a.COD_ALUNO = p.COD_ALUNO JOIN TB_AULA au ON au.COD_AULA = p.COD_AULA JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA WHERE a.COD_ESCOLINHA = ?'
        . ($filtroProfessor ? ' AND t.COD_PROFESSOR = ?' : '');
    $consulta = $db->prepare($sqlFrequencia);
    $consulta->execute($parametros);
    $frequencia = $consulta->fetchColumn();
    $frequencia = $frequencia === null ? 0 : round((float) $frequencia, 1);

    if ($podeAdministrar) {
        $consulta = $db->prepare('SELECT COUNT(*) FROM TB_RESPONSAVEL WHERE COD_ESCOLINHA = ?');
        $consulta->execute([$escolinha]);
        $totalResponsaveis = (int) $consulta->fetchColumn();

        $consulta = $db->prepare("SELECT COUNT(*) FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO WHERE a.COD_ESCOLINHA = ? AND m.STATUS = 'ATIVA'");
        $consulta->execute([$escolinha]);
        $totalMatriculas = (int) $consulta->fetchColumn();

        $consulta = $db->prepare('SELECT COUNT(*) FROM TB_USUARIO WHERE COD_ESCOLINHA = ? AND ATIVO = 1');
        $consulta->execute([$escolinha]);
        $totalUsuarios = (int) $consulta->fetchColumn();

        $indicadores = [
            ['Alunos ativos', $totalAlunos, 'Alunos com matrícula em turma ativa', '👥'],
            ['Responsáveis', $totalResponsaveis, 'Contas e contatos cadastrados', '♧'],
            ['Turmas ativas', $totalTurmas, 'Turmas disponíveis', '⚽'],
            ['Matrículas ativas', $totalMatriculas, 'Vínculos aluno e turma', '↔'],
            ['Aulas registradas', $totalAulas, 'Treinos cadastrados', '▣'],
            ['Usuários ativos', $totalUsuarios, 'Acessos habilitados', '☻'],
            ['Frequência média', e($frequencia) . '%', 'Presenças registradas nas aulas', '✓']
        ];
    } else {
        $indicadores = [
            ['Alunos nas suas turmas', $totalAlunos, 'Alunos com matrícula ativa', '👥'],
            ['Suas turmas', $totalTurmas, 'Turmas ativas atribuídas a você', '⚽'],
            ['Aulas registradas', $totalAulas, 'Treinos cadastrados', '▣'],
            ['Frequência média', e($frequencia) . '%', 'Presenças nas suas turmas', '✓']
        ];
    }
}

pageStart('Dashboard estratégico');
?>
<p class="dashboard-intro mb-6 text-slate-600">Olá, <?= e($_SESSION['username']) ?>. Acompanhe os registros da escolinha.</p>

<?php if ($perfil === 'RESPONSAVEL'): ?>
    <?php if (!$responsavel): ?>
        <section class="rounded-2xl border border-amber-200 bg-amber-50 p-6"><h2>Conta sem vínculo</h2><p>Peça à secretaria para associar seu usuário ao cadastro de responsável.</p></section>
    <?php elseif (!$filhos): ?>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2>Nenhum aluno vinculado</h2><p>Quando a secretaria cadastrar e vincular seus alunos, eles aparecerão aqui.</p></section>
    <?php else: ?>
        <div class="resumo-grid grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($filhos as $filho): ?>
                <section class="cartao-indicador rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <span class="icone-indicador alunos" aria-hidden="true">👤</span>
                    <h2><?= e($filho['NOME']) ?></h2>
                    <p class="texto-cartao"><?= e($filho['STATUS']) ?></p>
                    <p class="detalhe-cartao">Camisa: <?= $filho['NUMERO_CAMISA'] === null ? 'não definida' : '#' . (int) $filho['NUMERO_CAMISA'] ?></p>
                    <p class="detalhe-cartao">Turma(s): <?= e($filho['TURMAS'] ?: 'Sem matrícula ativa') ?></p>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="mt-5"><a class="link-destaque" href="meus_alunos.php">Ver presença e detalhes dos meus alunos</a></p>
<?php else: ?>
    <div class="resumo-grid grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($indicadores as $indicador): ?>
            <section class="cartao-indicador rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <span class="icone-indicador" aria-hidden="true"><?= e($indicador[3]) ?></span>
                <h2><?= e($indicador[0]) ?></h2>
                <p><?= $indicador[0] === 'Frequência média' ? $indicador[1] : (int) $indicador[1] ?></p>
                <span class="detalhe-cartao"><?= e($indicador[2]) ?></span>
            </section>
        <?php endforeach; ?>
    </div>

    <section class="dashboard-nota mt-6 rounded-2xl border border-emerald-100 bg-emerald-50 p-6">
        <h2>Resumo da gestão</h2>
        <p>Os indicadores são calculados a partir dos alunos, turmas, matrículas, usuários e aulas cadastrados.</p>
    </section>

    <section class="acoes-painel mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div><h2 class="text-lg font-bold text-emerald-950">Ações rápidas</h2><p class="text-sm text-slate-500">Abra um formulário sem sair do painel.</p></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <?php if ($podeAdministrar): ?>
                <button class="acao-rapida" type="button" data-tela="alunos.php?mini=1" data-titulo="Cadastrar aluno"><span>👥</span>Cadastrar aluno</button>
                <button class="acao-rapida" type="button" data-tela="responsaveis.php?mini=1" data-titulo="Cadastrar responsável"><span>♧</span>Cadastrar responsável</button>
                <button class="acao-rapida" type="button" data-tela="turmas.php?mini=1" data-titulo="Cadastrar turma"><span>⚽</span>Cadastrar turma</button>
                <button class="acao-rapida" type="button" data-tela="matriculas.php?mini=1" data-titulo="Nova matrícula"><span>↔</span>Nova matrícula</button>
                <button class="acao-rapida" type="button" data-tela="camisas.php?mini=1" data-titulo="Gerenciar camisas"><span>👕</span>Gerenciar camisas</button>
            <?php endif; ?>
            <?php if ($podeDarAula): ?>
                <button class="acao-rapida" type="button" data-tela="aulas.php?mini=1" data-titulo="Cadastrar aula"><span>▣</span>Cadastrar aula</button>
                <button class="acao-rapida" type="button" data-tela="aulas.php?mini=1&amp;chamadas=1" data-titulo="Fazer chamada"><span>✓</span>Fazer chamada</button>
            <?php endif; ?>
            <?php if ($podeGerenciarUsuarios): ?>
                <button class="acao-rapida" type="button" data-tela="usuarios.php?mini=1" data-titulo="Cadastrar usuário"><span>☻</span>Cadastrar usuário</button>
            <?php endif; ?>
        </div>
    </section>

    <dialog class="modal-cadastro" id="modalCadastro" aria-labelledby="tituloModal">
        <header class="modal-cabecalho">
            <h2 id="tituloModal">Cadastro</h2>
            <button type="button" class="fechar-modal" id="fecharModal" aria-label="Fechar">×</button>
        </header>
        <iframe id="conteudoModal" title="Formulário de cadastro"></iframe>
    </dialog>

    <script>
        const modal = document.getElementById('modalCadastro');
        const iframe = document.getElementById('conteudoModal');
        const tituloModal = document.getElementById('tituloModal');

        document.querySelectorAll('.acao-rapida').forEach(function (botao) {
            botao.addEventListener('click', function () {
                iframe.src = botao.dataset.tela;
                tituloModal.textContent = botao.dataset.titulo;
                modal.showModal();
            });
        });

        document.getElementById('fecharModal').addEventListener('click', function () {
            modal.close();
        });

        modal.addEventListener('close', function () {
            iframe.src = 'about:blank';
        });
    </script>
<?php endif; ?>
<?php pageEnd(); ?>
