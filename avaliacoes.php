<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/avaliacao_helpers.php';
checkRole(['ADMIN', 'PROFESSOR']);

$db = getDB();
$escolinha = currentEscolinhaId();
$professor = hasRole(['PROFESSOR']) ? (int) $_SESSION['user_id'] : null;
$tiposTreino = avaliacaoTiposTreino();
$catalogo = avaliacaoCatalogoAtributos();
$idAula = (int) ($_GET['aula'] ?? $_POST['id_aula'] ?? 0);
$aulaSelecionada = null;
$alunos = [];
$atributosAtivos = [];

function avaliacaoBuscarAula($db, $idAula, $escolinha, $professor) {
    $sql = 'SELECT a.*, t.NOME AS TURMA, t.FAIXA_ETARIA
            FROM TB_AULA a JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA
            WHERE a.COD_AULA = ? AND t.COD_ESCOLINHA = ?';
    $argumentos = [$idAula, $escolinha];
    if ($professor) {
        $sql .= ' AND t.COD_PROFESSOR = ?';
        $argumentos[] = $professor;
    }
    $consulta = $db->prepare($sql);
    $consulta->execute($argumentos);
    return $consulta->fetch();
}

function avaliacaoAlunosDoTreino($db, $idAula) {
    $consulta = $db->prepare(
        "SELECT a.COD_ALUNO, a.NOME, a.POSICAO, a.NUMERO_CAMISA,
                p.PRESENTE, p.JUSTIFICATIVA,
                av.COD_AVALIACAO, av.CRITERIOS_JSON,
                av.NOTA_GERAL, av.OBSERVACAO AS OBSERVACAO_AVALIACAO
         FROM TB_AULA au
         JOIN TB_MATRICULA m ON m.COD_TURMA = au.COD_TURMA AND m.STATUS = 'ATIVA'
         JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO AND a.STATUS = 'ATIVO'
         LEFT JOIN TB_PRESENCA p ON p.COD_AULA = au.COD_AULA AND p.COD_ALUNO = a.COD_ALUNO
         LEFT JOIN TB_AVALIACAO av ON av.COD_AULA = au.COD_AULA AND av.COD_ALUNO = a.COD_ALUNO
         WHERE au.COD_AULA = ? ORDER BY a.NOME"
    );
    $consulta->execute([$idAula]);
    return $consulta->fetchAll();
}

if ($idAula) {
    $aulaSelecionada = avaliacaoBuscarAula($db, $idAula, $escolinha, $professor);
    if (!$aulaSelecionada) {
        flash('error', 'Treino não encontrado ou sem permissão de acesso.');
        go('avaliacoes.php');
    }
    $alunos = avaliacaoAlunosDoTreino($db, $idAula);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_evaluations') {
    checkCsrf();
    if (!$aulaSelecionada) {
        flash('error', 'Treino não encontrado.');
        go('avaliacoes.php');
    }

    $tipo = $aulaSelecionada['TIPO_TREINO'];
    if ($tipo === null) {
        $tipo = $_POST['tipo_treino'] ?? '';
        if (!isset($tiposTreino[$tipo])) {
            flash('error', 'Selecione o tipo deste treino antes de avaliar.');
            go('avaliacoes.php?aula=' . $idAula);
        }
    }

    $customizados = $tipo === 'PERSONALIZADO'
        ? avaliacaoAtributosPersonalizados($_POST['atributos_avaliaveis'] ?? [])
        : [];
    if ($tipo === 'PERSONALIZADO' && !$customizados) {
        flash('error', 'Selecione ao menos um atributo para o treino personalizado.');
        go('avaliacoes.php?aula=' . $idAula);
    }
    $permitidos = avaliacaoAtributosDoTreino($tipo, $customizados);

    $notasEnviadas = $_POST['notas'] ?? [];
    $observacoesEnviadas = $_POST['observacoes'] ?? [];
    $dadosParaSalvar = [];
    foreach ($alunos as $aluno) {
        $idAluno = (int) $aluno['COD_ALUNO'];
        $notasAluno = is_array($notasEnviadas[$idAluno] ?? null) ? $notasEnviadas[$idAluno] : [];
        $criterios = [];
        foreach ($permitidos as $atributo) {
            if (!array_key_exists($atributo, $notasAluno) || trim((string) $notasAluno[$atributo]) === '') {
                continue;
            }
            $valorBruto = str_replace(',', '.', trim((string) $notasAluno[$atributo]));
            if (!preg_match('/^(?:10(?:\.0)?|[0-9](?:\.[0-9])?)$/', $valorBruto)) {
                flash('error', 'Use notas de 0 a 10, com até uma casa decimal.');
                go('avaliacoes.php?aula=' . $idAula);
            }
            $criterios[$atributo] = (float) $valorBruto;
        }
        $observacao = trim((string) ($observacoesEnviadas[$idAluno] ?? ''));
        $overall = $criterios ? round(array_sum($criterios) / count($criterios), 1) : null;
        $dadosParaSalvar[] = [
            'aluno' => $idAluno,
            'criterios' => $criterios,
            'overall' => $overall,
            'observacao' => $observacao !== '' ? $observacao : null
        ];
    }

    $db->beginTransaction();
    try {
        if ($aulaSelecionada['TIPO_TREINO'] === null) {
            $atributosJson = $tipo === 'PERSONALIZADO' ? json_encode($customizados, JSON_UNESCAPED_UNICODE) : null;
            $db->prepare('UPDATE TB_AULA SET TIPO_TREINO = ?, ATRIBUTOS_AVALIAVEIS_JSON = ? WHERE COD_AULA = ?')
                ->execute([$tipo, $atributosJson, $idAula]);
        } elseif ($tipo === 'PERSONALIZADO') {
            $db->prepare('UPDATE TB_AULA SET ATRIBUTOS_AVALIAVEIS_JSON = ? WHERE COD_AULA = ?')
                ->execute([json_encode($customizados, JSON_UNESCAPED_UNICODE), $idAula]);
        }

        $buscarExistente = $db->prepare('SELECT COD_AVALIACAO FROM TB_AVALIACAO WHERE COD_AULA = ? AND COD_ALUNO = ?');
        $inserir = $db->prepare(
            'INSERT INTO TB_AVALIACAO (COD_AULA, COD_ALUNO, COD_PROFESSOR, DATA_AVALIACAO, CRITERIOS_JSON, NOTA_GERAL, OBSERVACAO)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $atualizar = $db->prepare(
            'UPDATE TB_AVALIACAO SET COD_PROFESSOR = ?, DATA_AVALIACAO = ?, CRITERIOS_JSON = ?, NOTA_GERAL = ?, OBSERVACAO = ?
             WHERE COD_AVALIACAO = ? AND COD_AULA = ?'
        );
        foreach ($dadosParaSalvar as $dados) {
            $buscarExistente->execute([$idAula, $dados['aluno']]);
            $existente = $buscarExistente->fetchColumn();
            $json = json_encode($dados['criterios'], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if ($existente) {
                $atualizar->execute([$professor ?: (int) $_SESSION['user_id'], $aulaSelecionada['DATA_AULA'], $json, $dados['overall'], $dados['observacao'], $existente, $idAula]);
            } else {
                $inserir->execute([$idAula, $dados['aluno'], $professor ?: (int) $_SESSION['user_id'], $aulaSelecionada['DATA_AULA'], $json, $dados['overall'], $dados['observacao']]);
            }
        }
        $db->commit();
        flash('success', 'Avaliações salvas. Notas anteriores deste treino foram atualizadas sem remover o histórico de outras sessões.');
    } catch (Throwable $erro) {
        $db->rollBack();
        error_log('Falha ao salvar avaliações: ' . $erro->getMessage());
        flash('error', 'Não foi possível salvar as avaliações. Verifique os dados e tente novamente.');
    }
    go('avaliacoes.php?aula=' . $idAula);
}

if ($aulaSelecionada) {
    $alunos = avaliacaoAlunosDoTreino($db, $idAula);
    $tipoSessao = $aulaSelecionada['TIPO_TREINO'] ?? '';
    $atributosPersonalizados = avaliacaoAtributosPersonalizados($aulaSelecionada['ATRIBUTOS_AVALIAVEIS_JSON'] ?? null);
    $atributosAtivos = $tipoSessao === 'PERSONALIZADO'
        ? $atributosPersonalizados
        : avaliacaoAtributosDoTreino($tipoSessao, $atributosPersonalizados);
}

$filtroTurma = $professor ? ' AND t.COD_PROFESSOR = ?' : '';
$argumentosTurma = $professor ? [$escolinha, $professor] : [$escolinha];
$sqlSemanas =
    'SELECT YEARWEEK(a.DATA_AULA, 1) AS SEMANA,
            MIN(DATE_SUB(a.DATA_AULA, INTERVAL WEEKDAY(a.DATA_AULA) DAY)) AS INICIO_SEMANA,
            AVG(av.NOTA_GERAL) AS OVERALL_MEDIO, COUNT(av.NOTA_GERAL) AS NOTAS,
            COUNT(DISTINCT a.COD_AULA) AS TREINOS
     FROM TB_AVALIACAO av JOIN TB_AULA a ON a.COD_AULA = av.COD_AULA
     JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA
     WHERE t.COD_ESCOLINHA = ?' . $filtroTurma . '
       AND a.DATA_AULA >= DATE_SUB(CURDATE(), INTERVAL 56 DAY) AND a.DATA_AULA <= CURDATE()
     GROUP BY YEARWEEK(a.DATA_AULA, 1) ORDER BY INICIO_SEMANA DESC LIMIT 8';
$consulta = $db->prepare($sqlSemanas);
$consulta->execute($argumentosTurma);
$semanas = $consulta->fetchAll();
$semanaAtual = (int) $db->query('SELECT YEARWEEK(CURDATE(), 1)')->fetchColumn();
$semanaAnterior = (int) $db->query('SELECT YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 7 DAY), 1)')->fetchColumn();
$dadosSemanaAtual = null;
$dadosSemanaAnterior = null;
foreach ($semanas as $semana) {
    if ((int) $semana['SEMANA'] === $semanaAtual) $dadosSemanaAtual = $semana;
    if ((int) $semana['SEMANA'] === $semanaAnterior) $dadosSemanaAnterior = $semana;
}
$evolucaoSemanal = ($dadosSemanaAtual && $dadosSemanaAnterior && $dadosSemanaAtual['OVERALL_MEDIO'] !== null && $dadosSemanaAnterior['OVERALL_MEDIO'] !== null)
    ? round((float) $dadosSemanaAtual['OVERALL_MEDIO'] - (float) $dadosSemanaAnterior['OVERALL_MEDIO'], 1)
    : null;

$sqlTreinos = 'SELECT a.COD_AULA, a.DATA_AULA, a.TEMA_TREINO, a.TIPO_TREINO,
                      t.NOME AS TURMA, COUNT(DISTINCT av.COD_AVALIACAO) AS AVALIACOES,
                      COUNT(DISTINCT CASE WHEN av.NOTA_GERAL IS NOT NULL THEN av.COD_AVALIACAO END) AS COM_OVERALL
               FROM TB_AULA a JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA
               LEFT JOIN TB_AVALIACAO av ON av.COD_AULA = a.COD_AULA
               WHERE t.COD_ESCOLINHA = ?' . $filtroTurma . '
               GROUP BY a.COD_AULA, t.NOME ORDER BY a.DATA_AULA DESC, a.COD_AULA DESC LIMIT 60';
$consulta = $db->prepare($sqlTreinos);
$consulta->execute($argumentosTurma);
$treinos = $consulta->fetchAll();

pageStart('Avaliações');
?>
<div class="avaliacao-page">
    <?php if (!$aulaSelecionada): ?>
        <header class="avaliacao-hero"><div><p class="avaliacao-eyebrow"><span></span> DESENVOLVIMENTO EM CAMPO</p><h2>Avaliações por treino</h2><p>Lance notas durante a sessão, acompanhe o Overall semanal e mantenha cada avaliação vinculada ao seu treino.</p></div><div class="avaliacao-hero-icon" aria-hidden="true">↗</div></header>

        <section class="avaliacao-semana">
            <div class="avaliacao-section-heading"><div><p class="avaliacao-eyebrow">CONSOLIDAÇÃO SEMANAL</p><h2>Evolução do Overall</h2></div><span class="avaliacao-periodo">Últimas 8 semanas</span></div>
            <div class="avaliacao-semana-grid">
                <article><span class="avaliacao-metrica-icone">◎</span><div><small>Overall desta semana</small><strong><?= $dadosSemanaAtual && $dadosSemanaAtual['OVERALL_MEDIO'] !== null ? e(number_format((float) $dadosSemanaAtual['OVERALL_MEDIO'], 1, ',', '.')) : '—' ?><em>/10</em></strong><span><?= $dadosSemanaAtual ? (int) $dadosSemanaAtual['NOTAS'] . ' notas em ' . (int) $dadosSemanaAtual['TREINOS'] . ' treinos' : 'Nenhuma nota consolidada ainda' ?></span></div></article>
                <article><span class="avaliacao-metrica-icone azul">↗</span><div><small>Variação contra semana anterior</small><strong class="<?= $evolucaoSemanal !== null && $evolucaoSemanal < 0 ? 'negativo' : '' ?>"><?= $evolucaoSemanal === null ? '—' : ($evolucaoSemanal > 0 ? '+' : '') . e(number_format($evolucaoSemanal, 1, ',', '.')) ?><em><?= $evolucaoSemanal === null ? '' : ' pts' ?></em></strong><span><?= $dadosSemanaAnterior ? 'Comparação baseada em notas dos treinos' : 'Aguardando duas semanas com avaliações' ?></span></div></article>
                <article><span class="avaliacao-metrica-icone violeta">⚽</span><div><small>Treinos avaliados</small><strong><?= $dadosSemanaAtual ? (int) $dadosSemanaAtual['TREINOS'] : 0 ?></strong><span>nesta semana</span></div></article>
            </div>
            <?php if ($semanas): ?><div class="avaliacao-historico-semanas"><strong>Histórico semanal</strong><div><?php foreach (array_reverse($semanas) as $semana): ?><span><small><?= e(date('d/m', strtotime($semana['INICIO_SEMANA']))) ?></small><b style="height: <?= $semana['OVERALL_MEDIO'] === null ? '4' : e(max(4, (float) $semana['OVERALL_MEDIO'] * 9)) ?>%" title="Overall médio <?= $semana['OVERALL_MEDIO'] === null ? 'sem notas' : e(number_format((float) $semana['OVERALL_MEDIO'], 1, ',', '.')) ?>"></b><em><?= $semana['OVERALL_MEDIO'] === null ? '—' : e(number_format((float) $semana['OVERALL_MEDIO'], 1, ',', '.')) ?></em></span><?php endforeach; ?></div></div><?php endif; ?>
        </section>

        <section class="avaliacao-treinos-section">
            <div class="avaliacao-section-heading"><div><p class="avaliacao-eyebrow">SESSÕES</p><h2>Histórico de treinos</h2><p>Selecione um treino para lançar ou editar as avaliações dos atletas.</p></div><a class="avaliacao-voltar-treinos" href="aulas.php">Gerenciar treinos <span aria-hidden="true">↗</span></a></div>
            <div class="avaliacao-treinos-lista">
                <?php foreach ($treinos as $treino): $tipoNome = $tiposTreino[$treino['TIPO_TREINO']]['nome'] ?? 'Tipo não definido'; ?>
                    <article class="avaliacao-treino-item"><div class="avaliacao-treino-data"><strong><?= e(date('d', strtotime($treino['DATA_AULA']))) ?></strong><span><?= e(date('M/Y', strtotime($treino['DATA_AULA']))) ?></span></div><div class="avaliacao-treino-info"><strong><?= e($treino['TEMA_TREINO']) ?></strong><span><?= e($treino['TURMA']) ?> · <?= e($tipoNome) ?></span></div><span class="avaliacao-treino-progresso"><?= (int) $treino['COM_OVERALL'] ?> Overall</span><a class="botao-avaliar-treino" href="avaliacoes.php?aula=<?= (int) $treino['COD_AULA'] ?>"><?= (int) $treino['AVALIACOES'] ? 'Editar' : 'Avaliar' ?><span aria-hidden="true">↗</span></a></article>
                <?php endforeach; ?>
                <?php if (!$treinos): ?><p class="avaliacoes-sem-registros">Cadastre um treino para começar as avaliações.</p><?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <?php
            $statusAvaliacao = [];
            foreach ($alunos as $aluno) {
                $criteriosAluno = json_decode($aluno['CRITERIOS_JSON'] ?? '', true);
                $statusAvaliacao[(int) $aluno['COD_ALUNO']] = [
                    'criterios' => is_array($criteriosAluno) ? $criteriosAluno : [],
                    'overall' => $aluno['NOTA_GERAL'],
                    'observacao' => $aluno['OBSERVACAO_AVALIACAO'] ?? ''
                ];
            }
        ?>
        <header class="avaliacao-session-hero"><a class="avaliacao-voltar-link" href="avaliacoes.php">← Todas as avaliações</a><div><p class="avaliacao-eyebrow"><span></span> AVALIAÇÃO EM CAMPO</p><h2><?= e($aulaSelecionada['TEMA_TREINO']) ?></h2><p><?= e($aulaSelecionada['TURMA']) ?> · <?= e($aulaSelecionada['FAIXA_ETARIA']) ?> · <?= e(date('d/m/Y', strtotime($aulaSelecionada['DATA_AULA']))) ?></p></div><span class="avaliacao-session-badge"><?= count($alunos) ?> atletas</span></header>

        <form method="post" class="avaliacao-lancamento-form" id="formAvaliacoes">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_evaluations">
            <input type="hidden" name="id_aula" value="<?= (int) $idAula ?>">
            <?php if ($aulaSelecionada['TIPO_TREINO'] === null): ?>
                <section class="avaliacao-type-panel"><label for="tipoAvaliacao">Tipo deste treino<select name="tipo_treino" id="tipoAvaliacao" required><option value="">Selecione o foco do treino</option><?php foreach ($tiposTreino as $codigo => $tipo): ?><option value="<?= e($codigo) ?>"><?= e($tipo['nome']) ?></option><?php endforeach; ?></select></label><p>Este treino ainda não tem um tipo definido. A seleção será salva junto à sessão.</p></section>
            <?php else: ?>
                <div class="avaliacao-type-readonly"><span>FOCO DO TREINO</span><strong><?= e($tiposTreino[$aulaSelecionada['TIPO_TREINO']]['nome'] ?? 'Tipo não definido') ?></strong></div>
            <?php endif; ?>

            <section class="avaliacao-atributos-panel" id="painelAtributosPersonalizados" <?= $aulaSelecionada['TIPO_TREINO'] === 'PERSONALIZADO' ? '' : 'hidden' ?>><div><p class="avaliacao-eyebrow">FOCO DA AVALIAÇÃO</p><h3>Atributos avaliáveis</h3><span>Os atributos marcados recebem nota neste treino. Os demais permanecem como “Não avaliado”.</span></div><div class="avaliacao-check-grid"><?php foreach ($catalogo as $codigo => $nome): ?><label><input type="checkbox" name="atributos_avaliaveis[]" value="<?= e($codigo) ?>" <?= in_array($codigo, $atributosAtivos, true) ? 'checked' : '' ?>><span><?= e($nome) ?></span></label><?php endforeach; ?></div></section>

            <div class="avaliacao-roster-heading"><div><p class="avaliacao-eyebrow">EQUIPE</p><h3>Avaliações dos atletas</h3><span>Notas de 0 a 10 · Campo em branco = não avaliado · 0 é uma nota válida</span></div><span><?= count($alunos) ?> atletas</span></div>

            <?php if ($alunos): ?>
                <div class="avaliacao-atletas-lista">
                    <?php foreach ($alunos as $aluno): $idAluno = (int) $aluno['COD_ALUNO']; $estado = $statusAvaliacao[$idAluno]; ?>
                        <article class="avaliacao-atleta-card">
                            <header class="avaliacao-atleta-topo"><span class="avaliacao-atleta-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($aluno['NOME'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><div class="avaliacao-atleta-identidade"><strong><?= e($aluno['NOME']) ?></strong><span><?= e($aluno['POSICAO'] ?: 'Posição não informada') ?><?= $aluno['NUMERO_CAMISA'] !== null ? ' · Camisa #' . (int) $aluno['NUMERO_CAMISA'] : '' ?></span></div><span class="avaliacao-presenca <?= $aluno['PRESENTE'] === null ? 'pendente' : ((int) $aluno['PRESENTE'] ? 'presente' : 'ausente') ?>"><?= $aluno['PRESENTE'] === null ? 'Chamada pendente' : ((int) $aluno['PRESENTE'] ? 'Presente' : 'Ausente') ?></span><span class="avaliacao-overall-badge"><?= $estado['overall'] === null ? 'Overall —' : 'Overall ' . e(number_format((float) $estado['overall'], 1, ',', '.')) ?></span></header>
                            <div class="avaliacao-notas-grid">
                                <?php foreach ($catalogo as $codigo => $nome): $avaliavel = in_array($codigo, $atributosAtivos, true); ?>
                                    <label class="avaliacao-nota <?= $avaliavel ? '' : 'nao-avaliada' ?>" data-atributo="<?= e($codigo) ?>"><span><?= e($nome) ?></span><input type="number" name="notas[<?= $idAluno ?>][<?= e($codigo) ?>]" min="0" max="10" step="0.1" inputmode="decimal" placeholder="—" value="<?= isset($estado['criterios'][$codigo]) && is_numeric($estado['criterios'][$codigo]) ? e(number_format((float) $estado['criterios'][$codigo], 1, '.', '')) : '' ?>" <?= $avaliavel ? '' : 'disabled' ?>><small><?= $avaliavel ? '0–10' : 'Não avaliado' ?></small></label>
                                <?php endforeach; ?>
                            </div>
                            <label class="avaliacao-observacao">Observação técnica<textarea name="observacoes[<?= $idAluno ?>]" rows="2" placeholder="Opcional: detalhe um ponto importante do treino"><?= e($estado['observacao']) ?></textarea></label>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="avaliacao-save-bar"><span>O Overall considera somente as notas preenchidas.</span><?php actionButton('Salvar avaliações'); ?></div>
            <?php else: ?>
                <div class="avaliacao-sem-atletas"><span>♙</span><strong>Nenhum atleta ativo matriculado nesta turma.</strong><p>Confira as matrículas antes de avaliar este treino.</p></div>
            <?php endif; ?>
        </form>
        <?php if ($tipoSessao === '' || $tipoSessao === 'PERSONALIZADO'): ?>
            <script>
                const tipoAvaliacao = document.getElementById('tipoAvaliacao');
                const painelAtributos = document.getElementById('painelAtributosPersonalizados');
                const tipoSalvo = <?= json_encode($tipoSessao) ?>;
                const atributosPorTipo = {
                    VELOCIDADE: ['velocidade'],
                    FINALIZACAO_PASSE: ['finalizacao', 'passe'],
                    DRIBLE: ['drible'],
                    FISICO: ['fisico'],
                    POSICIONAMENTO: ['posicionamento'],
                    TECNICO_GERAL: ['velocidade', 'finalizacao', 'passe', 'drible', 'fisico', 'posicionamento']
                };
                function atualizarAtributos() {
                    const tipo = tipoAvaliacao ? tipoAvaliacao.value : tipoSalvo;
                    const personalizado = tipo === 'PERSONALIZADO';
                    if (!tipoAvaliacao && !personalizado) return;
                    if (painelAtributos) painelAtributos.hidden = !personalizado;
                    const marcados = Array.from(document.querySelectorAll('#painelAtributosPersonalizados input:checked')).map(function (item) { return item.value; });
                    const permitidos = personalizado ? marcados : (atributosPorTipo[tipo] || []);
                    document.querySelectorAll('.avaliacao-nota').forEach(function (campo) {
                        const codigo = campo.dataset.atributo;
                        const input = campo.querySelector('input');
                        const ativo = permitidos.includes(codigo);
                        input.disabled = !ativo;
                        if (!ativo) input.value = '';
                        campo.classList.toggle('nao-avaliada', !ativo);
                        campo.querySelector('small').textContent = ativo ? '0–10' : 'Não avaliado';
                    });
                }
                if (tipoAvaliacao) tipoAvaliacao.addEventListener('change', atualizarAtributos);
                if (painelAtributos) painelAtributos.addEventListener('change', function (evento) {
                    if (evento.target.matches('input[type="checkbox"]')) atualizarAtributos();
                });
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php pageEnd(); ?>
