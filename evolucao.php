<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/avaliacao_helpers.php';
checkRole(['RESPONSAVEL']);

$db = getDB();
$escolinha = currentEscolinhaId();
$consulta = $db->prepare('SELECT COD_RESPONSAVEL FROM TB_RESPONSAVEL WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
$consulta->execute([$_SESSION['user_id'], $escolinha]);
$responsavel = (int) $consulta->fetchColumn();
$atletas = [];
$atletaSelecionado = null;
$semanas = [];
$historicoAvaliacoes = [];
$historicoPresencas = [];
$catalogo = avaliacaoCatalogoAtributos();

if ($responsavel) {
    $consulta = $db->prepare(
        'SELECT COD_ALUNO, NOME, POSICAO, NUMERO_CAMISA
         FROM TB_ALUNO
         WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?
         ORDER BY NOME'
    );
    $consulta->execute([$responsavel, $escolinha]);
    $atletas = $consulta->fetchAll();
}

$idAtleta = (int) ($_GET['aluno'] ?? ($atletas[0]['COD_ALUNO'] ?? 0));
foreach ($atletas as $atleta) {
    if ((int) $atleta['COD_ALUNO'] === $idAtleta) {
        $atletaSelecionado = $atleta;
        break;
    }
}

if ($atletaSelecionado) {
    $consulta = $db->prepare(
        'SELECT av.COD_AVALIACAO, av.DATA_AVALIACAO, av.CRITERIOS_JSON, av.NOTA_GERAL,
                av.OBSERVACAO, u.NOME AS PROFESSOR
         FROM TB_AVALIACAO av
         LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = av.COD_PROFESSOR
         WHERE av.COD_ALUNO = ?
         ORDER BY av.DATA_AVALIACAO DESC, av.COD_AVALIACAO DESC'
    );
    $consulta->execute([$idAtleta]);
    $historicoAvaliacoes = $consulta->fetchAll();

    $consulta = $db->prepare(
        'SELECT au.COD_AULA, au.DATA_AULA, au.TEMA_TREINO, t.NOME AS TURMA,
                p.PRESENTE, p.JUSTIFICATIVA
         FROM TB_PRESENCA p
         JOIN TB_AULA au ON au.COD_AULA = p.COD_AULA
         JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA
         WHERE p.COD_ALUNO = ? AND t.COD_ESCOLINHA = ?
         ORDER BY au.DATA_AULA DESC, au.COD_AULA DESC'
    );
    $consulta->execute([$idAtleta, $escolinha]);
    $historicoPresencas = $consulta->fetchAll();

    foreach ($historicoAvaliacoes as $avaliacao) {
        $data = new DateTimeImmutable($avaliacao['DATA_AVALIACAO']);
        $inicioSemana = $data->modify('monday this week')->format('Y-m-d');
        if (!isset($semanas[$inicioSemana])) {
            $semanas[$inicioSemana] = ['inicio' => $inicioSemana, 'soma' => 0.0, 'contagem' => 0, 'atributos' => [], 'avaliacoes' => [], 'presencas' => 0, 'ausencias' => 0, 'chamadas' => 0];
        }
        $semanas[$inicioSemana]['avaliacoes'][] = $avaliacao;
        if ($avaliacao['NOTA_GERAL'] !== null) {
            $semanas[$inicioSemana]['soma'] += (float) $avaliacao['NOTA_GERAL'];
            $semanas[$inicioSemana]['contagem']++;
        }
        $criterios = json_decode($avaliacao['CRITERIOS_JSON'], true);
        if (is_array($criterios)) {
            foreach ($catalogo as $codigo => $rotulo) {
                if (isset($criterios[$codigo]) && is_numeric($criterios[$codigo])) {
                    $semanas[$inicioSemana]['atributos'][$codigo]['soma'] = ($semanas[$inicioSemana]['atributos'][$codigo]['soma'] ?? 0) + (float) $criterios[$codigo];
                    $semanas[$inicioSemana]['atributos'][$codigo]['contagem'] = ($semanas[$inicioSemana]['atributos'][$codigo]['contagem'] ?? 0) + 1;
                }
            }
        }
    }
    foreach ($historicoPresencas as $presenca) {
        $data = new DateTimeImmutable($presenca['DATA_AULA']);
        $inicioSemana = $data->modify('monday this week')->format('Y-m-d');
        if (!isset($semanas[$inicioSemana])) {
            $semanas[$inicioSemana] = ['inicio' => $inicioSemana, 'soma' => 0.0, 'contagem' => 0, 'atributos' => [], 'avaliacoes' => [], 'presencas' => 0, 'ausencias' => 0, 'chamadas' => 0];
        }
        $semanas[$inicioSemana]['chamadas']++;
        if ((int) $presenca['PRESENTE'] === 1) {
            $semanas[$inicioSemana]['presencas']++;
        } else {
            $semanas[$inicioSemana]['ausencias']++;
        }
    }
    krsort($semanas);
}

$semanasDisponiveis = array_keys($semanas);
$semanaSolicitada = (string) ($_GET['semana'] ?? ($semanasDisponiveis[0] ?? (new DateTimeImmutable())->modify('monday this week')->format('Y-m-d')));
$dataSemanaSolicitada = DateTimeImmutable::createFromFormat('!Y-m-d', $semanaSolicitada);
$semanaSelecionada = $dataSemanaSolicitada && $dataSemanaSolicitada->format('Y-m-d') === $semanaSolicitada && isset($semanas[$semanaSolicitada])
    ? $semanaSolicitada
    : ($semanasDisponiveis[0] ?? $semanaSolicitada);
$dadosSemana = $semanas[$semanaSelecionada] ?? ['inicio' => $semanaSelecionada, 'soma' => 0.0, 'contagem' => 0, 'atributos' => [], 'avaliacoes' => [], 'presencas' => 0, 'ausencias' => 0, 'chamadas' => 0];
$overallSemana = $dadosSemana['contagem'] ? $dadosSemana['soma'] / $dadosSemana['contagem'] : null;
$semanaAnterior = (new DateTimeImmutable($semanaSelecionada))->modify('-7 days')->format('Y-m-d');
$overallAnterior = isset($semanas[$semanaAnterior]) && $semanas[$semanaAnterior]['contagem']
    ? $semanas[$semanaAnterior]['soma'] / $semanas[$semanaAnterior]['contagem']
    : null;
$variacaoSemana = $overallSemana !== null && $overallAnterior !== null ? round($overallSemana - $overallAnterior, 1) : null;
$dadosGrafico = [];
foreach (array_reverse(array_slice($semanasDisponiveis, 0, 24)) as $inicio) {
    $semana = $semanas[$inicio];
    $item = ['inicio' => $inicio, 'overall' => $semana['contagem'] ? round($semana['soma'] / $semana['contagem'], 2) : null, 'atributos' => []];
    foreach ($catalogo as $codigo => $rotulo) {
        $atributo = $semana['atributos'][$codigo] ?? null;
        $item['atributos'][$codigo] = $atributo && $atributo['contagem'] ? round($atributo['soma'] / $atributo['contagem'], 2) : null;
    }
    $dadosGrafico[] = $item;
}
$atributosComDados = [];
foreach ($catalogo as $codigo => $rotulo) {
    foreach ($dadosGrafico as $semana) {
        if ($semana['atributos'][$codigo] !== null) {
            $atributosComDados[$codigo] = $rotulo;
            break;
        }
    }
}

pageStart('Evolução do atleta');
?>
<div class="evolucao-page">
    <header class="evolucao-hero">
        <div><p class="evolucao-kicker"><span></span> DESENVOLVIMENTO DO ATLETA</p><h2>Uma evolução feita treino a treino.</h2><p>Acompanhe os resultados semanais, os atributos trabalhados e a frequência no mesmo período.</p></div>
        <span class="evolucao-hero-icon" aria-hidden="true">↗</span>
    </header>

    <?php if (!$responsavel): ?>
        <section class="evolucao-empty"><span>◎</span><div><h3>Vínculo de responsável pendente</h3><p>Solicite à secretaria a associação do usuário ao cadastro de responsável para acompanhar os atletas.</p></div></section>
    <?php elseif ($atletas && !$atletaSelecionado): ?>
        <section class="evolucao-empty"><span>◎</span><div><h3>Atleta indisponível</h3><p>Escolha um atleta associado ao seu cadastro de responsável.</p><a href="evolucao.php">Voltar aos meus atletas</a></div></section>
    <?php elseif (!$atletaSelecionado): ?>
        <section class="evolucao-empty"><span>⚽</span><div><h3>Nenhum atleta vinculado</h3><p>Quando um atleta estiver associado ao cadastro de responsável, seus dados de evolução aparecerão aqui.</p></div></section>
    <?php else: ?>
        <section class="evolucao-toolbar">
            <div class="evolucao-atleta-resumo"><span class="evolucao-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($atletaSelecionado['NOME'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><div><small>ATLETA SELECIONADO</small><strong><?= e($atletaSelecionado['NOME']) ?></strong><span><?= e($atletaSelecionado['POSICAO'] ?: 'Posição não informada') ?><?= $atletaSelecionado['NUMERO_CAMISA'] !== null ? ' · Camisa #' . (int) $atletaSelecionado['NUMERO_CAMISA'] : '' ?></span></div></div>
            <form method="get" class="evolucao-filtros"><label>Atleta<select name="aluno" onchange="this.form.submit()"><?php foreach ($atletas as $atleta): ?><option value="<?= (int) $atleta['COD_ALUNO'] ?>" <?= (int) $atleta['COD_ALUNO'] === $idAtleta ? 'selected' : '' ?>><?= e($atleta['NOME']) ?></option><?php endforeach; ?></select></label><label>Semana<select name="semana" onchange="this.form.submit()"><option value="<?= e($semanaSelecionada) ?>" <?= !$semanasDisponiveis ? 'selected' : '' ?>><?= $semanasDisponiveis ? 'Selecione uma semana' : 'Sem dados por semana' ?></option><?php foreach ($semanas as $inicio => $semana): $fim = (new DateTimeImmutable($inicio))->modify('+6 days'); ?><option value="<?= e($inicio) ?>" <?= $inicio === $semanaSelecionada ? 'selected' : '' ?>><?= e(date('d/m', strtotime($inicio))) ?> – <?= e($fim->format('d/m/Y')) ?></option><?php endforeach; ?></select></label></form>
        </section>

        <section class="evolucao-metricas" aria-label="Resumo da semana">
            <article><span class="evolucao-met-icon">◎</span><div><small>Overall na semana</small><strong><?= $overallSemana === null ? '—' : e(number_format($overallSemana, 1, ',', '.')) ?><em><?= $overallSemana === null ? '' : '/10' ?></em></strong><span><?= (int) $dadosSemana['contagem'] ?> avaliações com nota</span></div></article>
            <article><span class="evolucao-met-icon azul">↗</span><div><small>Variação semanal</small><strong class="<?= $variacaoSemana !== null && $variacaoSemana < 0 ? 'negativo' : '' ?>"><?= $variacaoSemana === null ? '—' : ($variacaoSemana > 0 ? '+' : '') . e(number_format($variacaoSemana, 1, ',', '.')) ?><em><?= $variacaoSemana === null ? '' : ' pts' ?></em></strong><span><?= $variacaoSemana === null ? 'Sem nota na semana anterior' : 'Em relação à semana anterior' ?></span></div></article>
            <article><span class="evolucao-met-icon verde">✓</span><div><small>Presença no período</small><strong><?= (int) $dadosSemana['presencas'] ?><em>/<?= (int) $dadosSemana['chamadas'] ?></em></strong><span><?= (int) $dadosSemana['ausencias'] ?> ausências registradas</span></div></article>
        </section>

        <div class="evolucao-graficos" data-evolution="<?= e(json_encode($dadosGrafico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-labels="<?= e(json_encode($catalogo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
            <section class="evolucao-card evolucao-grafico-overall"><div class="evolucao-card-heading"><div><p class="evolucao-kicker">TENDÊNCIA</p><h3>Histórico semanal do Overall</h3></div><span>Últimas 24 semanas com registros</span></div><?php if ($dadosGrafico): ?><div class="evolucao-canvas-wrap"><canvas id="graficoOverall" aria-label="Gráfico semanal do Overall" role="img"></canvas></div><div class="evolucao-legenda"><i></i> Overall médio por semana</div><?php else: ?><div class="evolucao-chart-empty">Ainda não há avaliações com dados para desenhar o histórico.</div><?php endif; ?></section>
            <section class="evolucao-card evolucao-grafico-atributos"><div class="evolucao-card-heading"><div><p class="evolucao-kicker">ATRIBUTOS</p><h3>Desenvolvimento técnico</h3></div><?php if ($atributosComDados): ?><label class="evolucao-seletor-atributo"><span>Atributo</span><select id="atributoGrafico"><?php foreach ($atributosComDados as $codigo => $nome): ?><option value="<?= e($codigo) ?>"><?= e($nome) ?></option><?php endforeach; ?></select></label><?php endif; ?></div><?php if ($atributosComDados): ?><div class="evolucao-canvas-wrap"><canvas id="graficoAtributos" aria-label="Gráfico semanal do atributo selecionado" role="img"></canvas></div><div class="evolucao-legenda azul"><i></i> Média das notas avaliadas</div><?php else: ?><div class="evolucao-chart-empty">Os atributos aparecem quando forem avaliados em um treino.</div><?php endif; ?></section>
        </div>

        <section class="evolucao-card evolucao-detalhes-semana"><div class="evolucao-card-heading"><div><p class="evolucao-kicker">PERÍODO SELECIONADO</p><h3>Resumo da semana</h3></div><span><?= e(date('d/m', strtotime($semanaSelecionada))) ?> – <?= e((new DateTimeImmutable($semanaSelecionada))->modify('+6 days')->format('d/m/Y')) ?></span></div><div class="evolucao-atributos-semana"><?php $atributosSemanaExibidos = 0; foreach ($catalogo as $codigo => $nome): $valor = $dadosSemana['atributos'][$codigo] ?? null; if (!$valor || !$valor['contagem']) continue; $nota = $valor['soma'] / $valor['contagem']; $atributosSemanaExibidos++; ?><div><span><?= e($nome) ?></span><strong><?= e(number_format($nota, 1, ',', '.')) ?></strong><i><b style="width: <?= e($nota * 10) ?>%"></b></i></div><?php endforeach; ?><?php if (!$atributosSemanaExibidos): ?><p>Nenhum atributo foi avaliado nesta semana.</p><?php endif; ?></div><p class="evolucao-presenca-nota"><?= (int) $dadosSemana['chamadas'] ? (int) $dadosSemana['presencas'] . ' presenças e ' . (int) $dadosSemana['ausencias'] . ' ausências foram registradas nos treinos desta semana.' : 'Não há chamadas registradas para esta semana.' ?></p></section>

        <section class="evolucao-card evolucao-historico">
            <div class="evolucao-card-heading"><div><p class="evolucao-kicker">REGISTROS REAIS</p><h3>Avaliações da semana</h3></div><span><?= count($dadosSemana['avaliacoes']) ?> registros</span></div>
            <?php if ($dadosSemana['avaliacoes']): ?>
                <div class="evolucao-historico-lista">
                    <?php foreach ($dadosSemana['avaliacoes'] as $avaliacao):
                        $criterios = json_decode($avaliacao['CRITERIOS_JSON'], true);
                        $nomesCriterios = is_array($criterios) && $criterios
                            ? implode(', ', array_map(static function ($codigo) use ($catalogo) { return $catalogo[$codigo] ?? $codigo; }, array_keys($criterios)))
                            : '';
                    ?>
                        <article><span class="evolucao-historico-data"><?= e(date('d/m', strtotime($avaliacao['DATA_AVALIACAO']))) ?></span><div><strong><?= $avaliacao['NOTA_GERAL'] === null ? 'Avaliação sem Overall' : 'Overall ' . e(number_format((float) $avaliacao['NOTA_GERAL'], 1, ',', '.')) ?></strong><span><?= e($avaliacao['PROFESSOR'] ?: 'Professor não identificado') ?><?= $nomesCriterios ? ' · ' . e($nomesCriterios) : '' ?></span><?php if (!empty($avaliacao['OBSERVACAO'])): ?><p><?= e($avaliacao['OBSERVACAO']) ?></p><?php endif; ?></div><span class="evolucao-registro-status">Registrada</span></article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?><div class="evolucao-chart-empty">Nenhuma avaliação registrada nesta semana.</div><?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?php if ($atletaSelecionado && $dadosGrafico): ?>
<script>
(function () {
    'use strict';
    const root = document.querySelector('[data-evolution]');
    if (!root) return;
    const data = JSON.parse(root.dataset.evolution);
    const labels = JSON.parse(root.dataset.labels);
    const palette = {overall: '#34d399', attribute: '#60a5fa'};

    function desenhar(canvas, values, color) {
        if (!canvas) return;
        const context = canvas.getContext('2d');
        const width = Math.max(280, canvas.parentElement.clientWidth);
        const height = 220;
        const ratio = window.devicePixelRatio || 1;
        canvas.width = width * ratio;
        canvas.height = height * ratio;
        canvas.style.height = height + 'px';
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.clearRect(0, 0, width, height);
        const padding = {left: 34, right: 12, top: 16, bottom: 32};
        const plotWidth = width - padding.left - padding.right;
        const plotHeight = height - padding.top - padding.bottom;
        const usable = values.map((value, index) => ({value, index})).filter((point) => point.value !== null);
        context.font = '11px system-ui, sans-serif';
        context.textBaseline = 'middle';
        [0, 5, 10].forEach((tick) => {
            const y = padding.top + plotHeight * (1 - tick / 10);
            context.beginPath(); context.strokeStyle = 'rgba(148,163,184,.12)'; context.moveTo(padding.left, y); context.lineTo(width - padding.right, y); context.stroke();
            context.fillStyle = '#8093a8'; context.textAlign = 'left'; context.fillText(String(tick), 5, y);
        });
        if (!usable.length) {
            context.fillStyle = '#91a3b7'; context.textAlign = 'center'; context.fillText('Sem notas neste período', width / 2, height / 2); return;
        }
        const pointX = (index) => padding.left + (data.length < 2 ? plotWidth / 2 : plotWidth * index / (data.length - 1));
        const pointY = (value) => padding.top + plotHeight * (1 - value / 10);
        context.beginPath();
        usable.forEach((point, index) => { const x = pointX(point.index), y = pointY(point.value); if (!index) context.moveTo(x, y); else context.lineTo(x, y); });
        context.strokeStyle = color; context.lineWidth = 2.5; context.lineJoin = 'round'; context.lineCap = 'round'; context.stroke();
        usable.forEach((point) => { context.beginPath(); context.arc(pointX(point.index), pointY(point.value), 4, 0, Math.PI * 2); context.fillStyle = color; context.fill(); context.strokeStyle = '#101a29'; context.lineWidth = 2; context.stroke(); });
        const step = Math.max(1, Math.ceil(data.length / 6));
        data.forEach((point, index) => { if (index % step !== 0 && index !== data.length - 1) return; context.fillStyle = '#8093a8'; context.textAlign = 'center'; context.fillText(point.inicio.slice(5).replace('-', '/'), pointX(index), height - 13); });
    }
    function redraw() {
        desenhar(document.getElementById('graficoOverall'), data.map((item) => item.overall), palette.overall);
        const seletor = document.getElementById('atributoGrafico');
        if (seletor) desenhar(document.getElementById('graficoAtributos'), data.map((item) => item.atributos[seletor.value]), palette.attribute);
    }
    const seletor = document.getElementById('atributoGrafico');
    if (seletor) seletor.addEventListener('change', redraw);
    if ('ResizeObserver' in window) new ResizeObserver(redraw).observe(root);
    else window.addEventListener('resize', redraw);
    redraw();
}());
</script>
<?php endif; ?>
<?php pageEnd(); ?>
