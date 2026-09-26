<?php
require_once __DIR__ . '/includes/layout.php';
checkLogin();
require_once __DIR__ . '/config/database.php';

$db = getDB();
$escolinha = currentEscolinhaId();
$perfil = $_SESSION['user_role'];
$podeAdministrar = hasRole(['ADMIN', 'SECRETARIA']);
$podeDarAula = hasRole(['ADMIN', 'PROFESSOR']);
$podeGerenciarUsuarios = hasRole(['ADMIN']);
$ehResponsavel = $perfil === 'RESPONSAVEL';
$filtroProfessor = $perfil === 'PROFESSOR';
$responsavel = 0;
$filhos = [];

if ($ehResponsavel) {
    $consulta = $db->prepare('SELECT COD_RESPONSAVEL FROM TB_RESPONSAVEL WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
    $consulta->execute([$_SESSION['user_id'], $escolinha]);
    $responsavel = (int) $consulta->fetchColumn();

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
}

$parametrosTurma = [$escolinha];
$filtroTurma = '';
if ($filtroProfessor) {
    $filtroTurma .= ' AND t.COD_PROFESSOR = ?';
    $parametrosTurma[] = $_SESSION['user_id'];
}
$filtroVinculoResponsavel = '';
$parametrosResponsavel = [];
if ($ehResponsavel) {
    $filtroVinculoResponsavel = $responsavel ? " AND EXISTS (
        SELECT 1 FROM TB_MATRICULA m
        JOIN TB_ALUNO aluno ON aluno.COD_ALUNO = m.COD_ALUNO
        WHERE m.COD_TURMA = t.COD_TURMA AND m.STATUS = 'ATIVA'
          AND aluno.COD_RESPONSAVEL = ? AND aluno.COD_ESCOLINHA = ?
    )" : ' AND 1 = 0';
    if ($responsavel) {
        $parametrosResponsavel = [$responsavel, $escolinha];
    }
}

$consulta = $db->prepare(
    'SELECT DISTINCT t.COD_TURMA, t.NOME, t.DIAS_TREINO, t.HORARIO,
            u.NOME AS PROFESSOR
     FROM TB_TURMA t
     LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = t.COD_PROFESSOR AND u.ATIVO = 1
     WHERE t.COD_ESCOLINHA = ? AND t.ATIVA = 1' . $filtroTurma . $filtroVinculoResponsavel . '
     ORDER BY t.NOME'
);
$consulta->execute(array_merge($parametrosTurma, $parametrosResponsavel));
$turmasVisiveis = $consulta->fetchAll();

if ($ehResponsavel) {
    $totalAlunos = count($filhos);
} else {
    $sqlAlunos = "SELECT COUNT(DISTINCT a.COD_ALUNO)
                  FROM TB_ALUNO a
                  JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = 'ATIVA'
                  JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA AND t.ATIVA = 1
                  WHERE a.COD_ESCOLINHA = ?" . ($filtroProfessor ? ' AND t.COD_PROFESSOR = ?' : '');
    $consulta = $db->prepare($sqlAlunos);
    $consulta->execute($filtroProfessor ? [$escolinha, $_SESSION['user_id']] : [$escolinha]);
    $totalAlunos = (int) $consulta->fetchColumn();
}

$totalTurmas = count($turmasVisiveis);
$totalProfessores = count(array_unique(array_filter(array_column($turmasVisiveis, 'PROFESSOR'))));
$filtroAula = $filtroTurma . $filtroVinculoResponsavel;
$parametrosAula = array_merge($parametrosTurma, $parametrosResponsavel);
$consulta = $db->prepare(
    'SELECT COUNT(*) FROM TB_AULA a JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA
     WHERE t.COD_ESCOLINHA = ?' . $filtroAula
);
$consulta->execute($parametrosAula);
$totalTreinos = (int) $consulta->fetchColumn();

$consulta = $db->prepare(
    'SELECT AVG(p.PRESENTE) * 100
     FROM TB_PRESENCA p
     JOIN TB_ALUNO al ON al.COD_ALUNO = p.COD_ALUNO
     JOIN TB_AULA au ON au.COD_AULA = p.COD_AULA
     JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA
     WHERE al.COD_ESCOLINHA = ?' . ($filtroProfessor ? ' AND t.COD_PROFESSOR = ?' : '')
        . ($ehResponsavel ? ' AND al.COD_RESPONSAVEL = ?' : '')
);
$parametrosPresenca = [$escolinha];
if ($filtroProfessor) {
    $parametrosPresenca[] = $_SESSION['user_id'];
}
if ($ehResponsavel) {
    $parametrosPresenca[] = $responsavel;
}
$consulta->execute($parametrosPresenca);
$presencaMedia = $consulta->fetchColumn();
$presencaMedia = $presencaMedia === null ? null : round((float) $presencaMedia, 1);

$consulta = $db->prepare(
    'SELECT AVG(av.NOTA_GERAL), COUNT(*)
     FROM TB_AVALIACAO av
     JOIN TB_ALUNO al ON al.COD_ALUNO = av.COD_ALUNO
     WHERE al.COD_ESCOLINHA = ?' . ($filtroProfessor ? ' AND av.COD_PROFESSOR = ?' : '')
        . ($ehResponsavel ? ' AND al.COD_RESPONSAVEL = ?' : '')
);
$parametrosAvaliacao = [$escolinha];
if ($filtroProfessor) {
    $parametrosAvaliacao[] = $_SESSION['user_id'];
}
if ($ehResponsavel) {
    $parametrosAvaliacao[] = $responsavel;
}
$consulta->execute($parametrosAvaliacao);
$resultadoAvaliacao = $consulta->fetch(PDO::FETCH_NUM);
$desempenhoMedio = $resultadoAvaliacao[0] === null ? null : round((float) $resultadoAvaliacao[0], 1);
$totalAvaliacoes = (int) $resultadoAvaliacao[1];

$sqlTreinosSemana =
    'SELECT YEARWEEK(a.DATA_AULA, 1) AS SEMANA, COUNT(DISTINCT a.COD_AULA) AS TOTAL
     FROM TB_AULA a JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA
     WHERE t.COD_ESCOLINHA = ? AND a.DATA_AULA >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
       AND a.DATA_AULA <= CURDATE()' . $filtroAula . '
     GROUP BY YEARWEEK(a.DATA_AULA, 1)';
$consulta = $db->prepare($sqlTreinosSemana);
$consulta->execute($parametrosAula);
$treinosPorSemana = [];
foreach ($consulta->fetchAll() as $linha) {
    $treinosPorSemana[(int) $linha['SEMANA']] = (int) $linha['TOTAL'];
}

$sqlPresencaSemana =
    'SELECT YEARWEEK(au.DATA_AULA, 1) AS SEMANA,
            SUM(p.PRESENTE) AS PRESENTES, COUNT(*) AS TOTAL
     FROM TB_PRESENCA p
     JOIN TB_ALUNO al ON al.COD_ALUNO = p.COD_ALUNO
     JOIN TB_AULA au ON au.COD_AULA = p.COD_AULA
     JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA
     WHERE al.COD_ESCOLINHA = ? AND au.DATA_AULA >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
       AND au.DATA_AULA <= CURDATE()' . ($filtroProfessor ? ' AND t.COD_PROFESSOR = ?' : '')
        . ($ehResponsavel ? ($responsavel ? ' AND al.COD_RESPONSAVEL = ?' : ' AND 1 = 0') : '') . '
     GROUP BY YEARWEEK(au.DATA_AULA, 1)';
$consulta = $db->prepare($sqlPresencaSemana);
$parametrosPresencaSemana = [$escolinha];
if ($filtroProfessor) {
    $parametrosPresencaSemana[] = $_SESSION['user_id'];
}
if ($ehResponsavel && $responsavel) {
    $parametrosPresencaSemana[] = $responsavel;
}
$consulta->execute($parametrosPresencaSemana);
$presencasPorSemana = [];
foreach ($consulta->fetchAll() as $linha) {
    $presencasPorSemana[(int) $linha['SEMANA']] = [
        'presentes' => (int) $linha['PRESENTES'],
        'total' => (int) $linha['TOTAL']
    ];
}

$sqlAvaliacoesSemana =
    'SELECT YEARWEEK(av.DATA_AVALIACAO, 1) AS SEMANA, COUNT(*) AS TOTAL
     FROM TB_AVALIACAO av JOIN TB_ALUNO al ON al.COD_ALUNO = av.COD_ALUNO
     WHERE al.COD_ESCOLINHA = ? AND av.DATA_AVALIACAO >= DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY)
       AND av.DATA_AVALIACAO <= CURDATE()' . ($filtroProfessor ? ' AND av.COD_PROFESSOR = ?' : '')
        . ($ehResponsavel ? ($responsavel ? ' AND al.COD_RESPONSAVEL = ?' : ' AND 1 = 0') : '') . '
     GROUP BY YEARWEEK(av.DATA_AVALIACAO, 1)';
$consulta = $db->prepare($sqlAvaliacoesSemana);
$parametrosAvaliacaoSemana = [$escolinha];
if ($filtroProfessor) {
    $parametrosAvaliacaoSemana[] = $_SESSION['user_id'];
}
if ($ehResponsavel && $responsavel) {
    $parametrosAvaliacaoSemana[] = $responsavel;
}
$consulta->execute($parametrosAvaliacaoSemana);
$avaliacoesPorSemana = [];
foreach ($consulta->fetchAll() as $linha) {
    $avaliacoesPorSemana[(int) $linha['SEMANA']] = (int) $linha['TOTAL'];
}

$relogio = $db->query("SELECT CURDATE() AS HOJE, TIME_FORMAT(CURTIME(), '%H:%i') AS AGORA")->fetch();
$hoje = new DateTimeImmutable($relogio['HOJE']);
$agora = $relogio['AGORA'];
$semanaAtual = (int) $db->query('SELECT YEARWEEK(CURDATE(), 1)')->fetchColumn();
$semanaAnterior = (int) $db->query('SELECT YEARWEEK(DATE_SUB(CURDATE(), INTERVAL 7 DAY), 1)')->fetchColumn();
$treinosNestaSemana = $treinosPorSemana[$semanaAtual] ?? 0;
$avaliacoesNestaSemana = $avaliacoesPorSemana[$semanaAtual] ?? 0;
$presencaSemanaAtual = $presencasPorSemana[$semanaAtual] ?? ['presentes' => 0, 'total' => 0];
$presencaSemanaAnterior = $presencasPorSemana[$semanaAnterior] ?? ['presentes' => 0, 'total' => 0];
$percentualSemana = $presencaSemanaAtual['total']
    ? round(100 * $presencaSemanaAtual['presentes'] / $presencaSemanaAtual['total'], 1)
    : null;
$percentualSemanaAnterior = $presencaSemanaAnterior['total']
    ? round(100 * $presencaSemanaAnterior['presentes'] / $presencaSemanaAnterior['total'], 1)
    : null;
$variacaoPresenca = ($percentualSemana !== null && $percentualSemanaAnterior !== null)
    ? round($percentualSemana - $percentualSemanaAnterior, 1)
    : null;

function dashboardDiasTreino($dias) {
    $mapa = [
        'domingo' => 0, 'segunda' => 1, 'terça' => 2, 'terca' => 2,
        'quarta' => 3, 'quinta' => 4, 'sexta' => 5, 'sábado' => 6, 'sabado' => 6
    ];
    preg_match_all('/domingo|segunda|terça|terca|quarta|quinta|sexta|sábado|sabado/iu', (string) $dias, $correspondencias);
    $resultado = [];
    foreach ($correspondencias[0] as $dia) {
        $chave = mb_strtolower($dia, 'UTF-8');
        if (isset($mapa[$chave])) {
            $resultado[] = $mapa[$chave];
        }
    }
    return array_values(array_unique($resultado));
}

$agenda = [];
$chavesAgenda = [];
$eventosGravados = [];
$consulta = $db->prepare(
    'SELECT a.COD_AULA, a.DATA_AULA, a.TEMA_TREINO, t.COD_TURMA, t.NOME AS TURMA,
            t.HORARIO, t.DIAS_TREINO, u.NOME AS PROFESSOR
     FROM TB_AULA a
     JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA
     LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = t.COD_PROFESSOR AND u.ATIVO = 1
     WHERE t.COD_ESCOLINHA = ? AND a.DATA_AULA >= CURDATE()
       AND a.DATA_AULA < DATE_ADD(CURDATE(), INTERVAL 7 DAY)' . $filtroAula . '
     ORDER BY a.DATA_AULA, t.HORARIO'
);
$consulta->execute($parametrosAula);
foreach ($consulta->fetchAll() as $aula) {
    $eventosGravados[$aula['DATA_AULA'] . '-' . $aula['COD_TURMA']] = $aula;
}

foreach ($turmasVisiveis as $turma) {
    preg_match('/\b([01]?\d|2[0-3]):[0-5]\d\b/u', (string) $turma['HORARIO'], $horaTreino);
    $horaInicio = $horaTreino[0] ?? '';
    foreach (dashboardDiasTreino($turma['DIAS_TREINO']) as $diaSemana) {
        $deslocamento = ($diaSemana - (int) $hoje->format('w') + 7) % 7;
        if ($deslocamento > 6) {
            continue;
        }
        $dataEvento = $hoje->modify('+' . $deslocamento . ' days')->format('Y-m-d');
        if ($deslocamento === 0 && $horaInicio !== '' && $horaInicio <= $agora) {
            continue;
        }
        $chave = $dataEvento . '-' . $turma['COD_TURMA'];
        $aulaGravada = $eventosGravados[$chave] ?? null;
        $agenda[$chave] = [
            'data' => $dataEvento,
            'hora' => $horaInicio,
            'turma' => $turma['NOME'],
            'horario' => $turma['HORARIO'],
            'professor' => $turma['PROFESSOR'],
            'tema' => $aulaGravada['TEMA_TREINO'] ?? null,
            'registrado' => $aulaGravada !== null
        ];
    }
}

foreach ($eventosGravados as $chave => $aula) {
    if (isset($agenda[$chave])) {
        continue;
    }
    preg_match('/\b([01]?\d|2[0-3]):[0-5]\d\b/u', (string) $aula['HORARIO'], $horaTreino);
    $horaInicio = $horaTreino[0] ?? '';
    if ($aula['DATA_AULA'] === $hoje->format('Y-m-d') && $horaInicio !== '' && $horaInicio <= $agora) {
        continue;
    }
    $agenda[$chave] = [
        'data' => $aula['DATA_AULA'],
        'hora' => $horaInicio,
        'turma' => $aula['TURMA'],
        'horario' => $aula['HORARIO'],
        'professor' => $aula['PROFESSOR'],
        'tema' => $aula['TEMA_TREINO'],
        'registrado' => true
    ];
}

$agenda = array_values($agenda);
usort($agenda, function ($a, $b) {
    return [$a['data'], $a['hora'], $a['turma']] <=> [$b['data'], $b['hora'], $b['turma']];
});
$proximoTreino = $agenda[0] ?? null;
$agendaVisivel = array_slice($agenda, 0, 4);
$diasCalendario = [];
$nomesDias = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
for ($i = 0; $i < 7; $i++) {
    $data = $hoje->modify('+' . $i . ' days');
    $dataString = $data->format('Y-m-d');
    $diasCalendario[] = [
        'data' => $data,
        'quantidade' => count(array_filter($agenda, function ($evento) use ($dataString) {
            return $evento['data'] === $dataString;
        }))
    ];
}
$nomesMeses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$nomesDiasLongos = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$horaAtual = (int) substr($agora, 0, 2);
$saudacao = $horaAtual < 12 ? 'Bom dia' : ($horaAtual < 18 ? 'Boa tarde' : 'Boa noite');
$dataHojeFormatada = $nomesDiasLongos[(int) $hoje->format('w')] . ', ' . $hoje->format('d') . ' de ' . $nomesMeses[(int) $hoje->format('n') - 1];
$formatarData = function ($data) use ($nomesDias) {
    $dataObj = new DateTimeImmutable($data);
    return $nomesDias[(int) $dataObj->format('w')] . ', ' . $dataObj->format('d/m');
};

pageStart('Dashboard estratégico');
?>
<div class="dashboard-page">
    <header class="dashboard-boas-vindas">
        <div>
            <p class="dashboard-eyebrow">PAINEL DA ESCOLINHA <span aria-hidden="true">/</span> <?= e($dataHojeFormatada) ?></p>
            <h2><?= e($saudacao) ?>, <?= e($_SESSION['username']) ?></h2>
            <p>Acompanhe a rotina esportiva e os indicadores da sua escolinha.</p>
        </div>
        <span class="dashboard-status"><i aria-hidden="true"></i> Operação em andamento</span>
    </header>

    <div class="dashboard-grid">
        <section class="dashboard-card proximo-treino-card">
            <div class="dashboard-card-topline"><span class="dashboard-card-label"><span class="dashboard-pulse" aria-hidden="true"></span> PRÓXIMO TREINO</span><span class="chip-outline">AGENDA REGULAR</span></div>
            <?php if ($proximoTreino): ?>
                <div class="treino-destaque">
                    <div class="treino-data"><strong><?= e((new DateTimeImmutable($proximoTreino['data']))->format('d')) ?></strong><span><?= e(strtoupper(substr($nomesDias[(int) (new DateTimeImmutable($proximoTreino['data']))->format('w')], 0, 3))) ?></span></div>
                    <div class="treino-info"><h2><?= e($proximoTreino['turma']) ?></h2><p><?= e($proximoTreino['tema'] ?: 'Treino regular') ?></p></div>
                </div>
                <div class="treino-meta"><span><strong><?= e($formatarData($proximoTreino['data'])) ?></strong></span><span><?= e($proximoTreino['horario']) ?></span><?php if ($proximoTreino['professor']): ?><span><?= e($proximoTreino['professor']) ?></span><?php endif; ?></div>
                <p class="dashboard-card-footnote"><?= $proximoTreino['registrado'] ? 'Aula cadastrada no sistema.' : 'Próximo horário recorrente cadastrado para esta turma.' ?></p>
            <?php else: ?>
                <div class="treino-vazio"><span class="treino-vazio-icone" aria-hidden="true">◷</span><div><h2>Nenhum treino próximo na agenda</h2><p>Não há horário recorrente futuro cadastrado para suas turmas visíveis.</p></div></div>
            <?php endif; ?>
            <?php if ($podeDarAula): ?>
                <button class="botao-secundario-dashboard" type="button" data-tela="aulas.php?mini=1" data-titulo="Cadastrar aula">Cadastrar aula <span aria-hidden="true">↗</span></button>
            <?php elseif ($ehResponsavel && $filhos): ?>
                <a class="botao-secundario-dashboard link-botao-dashboard" href="meus_alunos.php">Ver detalhes dos alunos <span aria-hidden="true">↗</span></a>
            <?php endif; ?>
        </section>

        <section class="dashboard-card desempenho-card">
            <div class="dashboard-card-topline"><span class="dashboard-card-label">DESEMPENHO GERAL</span><span class="desempenho-icone" aria-hidden="true">↗</span></div>
            <?php if ($desempenhoMedio !== null): ?>
                <div class="desempenho-valor"><?= e(number_format($desempenhoMedio, 1, ',', '.')) ?><small>/ 10</small></div>
                <div class="desempenho-barra" role="img" aria-label="Média de <?= e(number_format($desempenhoMedio, 1, ',', '.')) ?> de 10"><span style="width: <?= e(min(100, max(0, $desempenhoMedio * 10))) ?>%"></span></div>
                <p class="desempenho-legenda">Média das avaliações registradas</p>
                <div class="desempenho-total"><span>Avaliações no sistema</span><strong><?= (int) $totalAvaliacoes ?></strong></div>
            <?php else: ?>
                <div class="desempenho-vazio"><strong>—</strong><p>Ainda não há avaliações registradas.</p></div>
            <?php endif; ?>
        </section>

        <section class="dashboard-card resumo-card">
            <div class="dashboard-section-heading"><div><p class="dashboard-card-label">VISÃO GERAL</p><h2>Resumo da escolinha</h2></div><span class="resumo-periodo">Dados atuais</span></div>
            <div class="resumo-metricas">
                <article class="resumo-metrica"><span class="metrica-simbolo alunos" aria-hidden="true">◎</span><div><strong><?= (int) $totalAlunos ?></strong><span><?= $ehResponsavel ? 'Alunos vinculados' : 'Alunos ativos' ?></span></div></article>
                <article class="resumo-metrica"><span class="metrica-simbolo professores" aria-hidden="true">♙</span><div><strong><?= (int) $totalProfessores ?></strong><span><?= $totalProfessores === 1 ? 'Professor' : 'Professores' ?></span></div></article>
                <article class="resumo-metrica"><span class="metrica-simbolo turmas" aria-hidden="true">⌘</span><div><strong><?= (int) $totalTurmas ?></strong><span>Turmas ativas</span></div></article>
                <article class="resumo-metrica"><span class="metrica-simbolo treinos" aria-hidden="true">◷</span><div><strong><?= (int) $totalTreinos ?></strong><span>Treinos dados</span></div></article>
            </div>
            <div class="resumo-presenca"><div><span>Presença média</span><strong><?= $presencaMedia === null ? '—' : e(number_format($presencaMedia, 1, ',', '.') . '%') ?></strong></div><?php if ($presencaMedia !== null): ?><div class="resumo-presenca-barra" role="img" aria-label="Presença média de <?= e(number_format($presencaMedia, 1, ',', '.')) ?> por cento"><span style="width: <?= e(min(100, max(0, $presencaMedia))) ?>%"></span></div><?php else: ?><p>As chamadas ainda não têm registros.</p><?php endif; ?></div>
        </section>

        <section class="dashboard-card semana-card">
            <div class="dashboard-section-heading"><div><p class="dashboard-card-label">DESTAQUES DA SEMANA</p><h2>Ritmo da equipe</h2></div><span class="semana-indicador" aria-hidden="true">7d</span></div>
            <div class="semana-lista">
                <div class="semana-item"><span class="semana-dot verde"></span><div><strong><?= (int) $treinosNestaSemana ?></strong><span>Treinos realizados</span></div><small>esta semana</small></div>
                <div class="semana-item"><span class="semana-dot azul"></span><div><strong><?= $percentualSemana === null ? '—' : e(number_format($percentualSemana, 1, ',', '.') . '%') ?></strong><span>Presença nas chamadas</span></div><small><?php if ($variacaoPresenca === null): ?>sem comparativo<?php else: ?><?= $variacaoPresenca > 0 ? '+' : '' ?><?= e(number_format($variacaoPresenca, 1, ',', '.')) ?> p.p.<?php endif; ?></small></div>
                <div class="semana-item"><span class="semana-dot violeta"></span><div><strong><?= (int) $avaliacoesNestaSemana ?></strong><span>Avaliações realizadas</span></div><small>esta semana</small></div>
            </div>
            <div class="mini-grafico-semana" role="img" aria-label="Comparativo de presença entre a semana atual e a anterior">
                <div class="grafico-coluna"><span style="height: <?= $percentualSemana === null ? '4' : e(max(4, $percentualSemana)) ?>%"></span><small>Esta semana</small></div>
                <div class="grafico-eixo"></div>
                <div class="grafico-coluna anterior"><span style="height: <?= $percentualSemanaAnterior === null ? '4' : e(max(4, $percentualSemanaAnterior)) ?>%"></span><small>Semana anterior</small></div>
                <div class="grafico-legenda"><i></i><?= $percentualSemana === null ? 'Sem dados de presença nesta semana' : e(number_format($percentualSemana, 1, ',', '.') . '% nesta semana') ?></div>
            </div>
        </section>

        <section class="dashboard-card calendario-card">
            <div class="dashboard-section-heading"><div><p class="dashboard-card-label">AGENDA</p><h2>Próximos eventos</h2></div><span class="agenda-legenda"><i></i>Treinos</span></div>
            <div class="calendario-resumo" aria-label="Calendário dos próximos sete dias">
                <?php foreach ($diasCalendario as $dia): ?>
                    <div class="calendario-dia<?= $dia['quantidade'] ? ' tem-treino' : '' ?><?= $dia['data']->format('Y-m-d') === $hoje->format('Y-m-d') ? ' hoje' : '' ?>">
                        <span><?= e($nomesDias[(int) $dia['data']->format('w')]) ?></span><strong><?= e($dia['data']->format('d')) ?></strong>
                        <?php if ($dia['quantidade']): ?><i aria-label="<?= (int) $dia['quantidade'] ?> treinos"></i><?php else: ?><i class="sem-treino" aria-hidden="true"></i><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($agendaVisivel): ?>
                <div class="agenda-lista">
                    <?php foreach ($agendaVisivel as $evento): ?>
                        <article class="agenda-evento"><div class="agenda-evento-data"><strong><?= e((new DateTimeImmutable($evento['data']))->format('d')) ?></strong><span><?= e(strtoupper(substr($nomesDias[(int) (new DateTimeImmutable($evento['data']))->format('w')], 0, 3))) ?></span></div><div class="agenda-evento-info"><strong><?= e($evento['turma']) ?></strong><span><?= e($evento['tema'] ?: 'Treino regular') ?> · <?= e($evento['horario']) ?></span></div><span class="agenda-evento-status<?= $evento['registrado'] ? ' registrado' : '' ?>"><?= $evento['registrado'] ? 'Aula' : 'Regular' ?></span></article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="agenda-vazia">Nenhum treino previsto nos próximos sete dias, conforme os dias cadastrados nas turmas.</p>
            <?php endif; ?>
        </section>

        <section class="dashboard-card notificacoes-card">
            <div class="dashboard-section-heading"><div><p class="dashboard-card-label">CENTRAL DE AVISOS</p><h2>Notificações recentes</h2></div><span class="notificacao-contador">0</span></div>
            <div class="notificacoes-vazio"><span class="notificacao-icone" aria-hidden="true">♧</span><div><strong>Nenhum aviso recente</strong><p>O sistema ainda não registra notificações.</p></div></div>
            <div class="notificacoes-nota">Os dados exibidos aqui usam os registros atuais da escolinha.</div>
        </section>
    </div>

    <?php if (!$ehResponsavel): ?>
        <section class="dashboard-card acoes-painel acoes-dashboard">
            <div class="dashboard-section-heading"><div><p class="dashboard-card-label">ATALHOS</p><h2>Ações rápidas</h2><p>Abra um formulário sem sair do painel.</p></div></div>
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
            <header class="modal-cabecalho"><h2 id="tituloModal">Cadastro</h2><button type="button" class="fechar-modal" id="fecharModal" aria-label="Fechar">×</button></header>
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
            document.getElementById('fecharModal').addEventListener('click', function () { modal.close(); });
            modal.addEventListener('close', function () { iframe.src = 'about:blank'; });
        </script>
    <?php else: ?>
        <?php if (!$responsavel): ?>
            <section class="dashboard-card responsavel-vazio"><h2>Conta sem vínculo</h2><p>Peça à secretaria para associar seu usuário ao cadastro de responsável.</p></section>
        <?php elseif (!$filhos): ?>
            <section class="dashboard-card responsavel-vazio"><h2>Nenhum aluno vinculado</h2><p>Quando a secretaria cadastrar e vincular seus alunos, eles aparecerão aqui.</p></section>
        <?php else: ?>
            <section class="dashboard-card filhos-card"><div class="dashboard-section-heading"><div><p class="dashboard-card-label">ACOMPANHAMENTO</p><h2>Seus alunos</h2></div><a href="meus_alunos.php">Ver presença e detalhes <span aria-hidden="true">↗</span></a></div><div class="filhos-lista"><?php foreach ($filhos as $filho): ?><article><span class="filho-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($filho['NOME'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><div><strong><?= e($filho['NOME']) ?></strong><span><?= e($filho['TURMAS'] ?: 'Sem matrícula ativa') ?></span></div><span class="filho-status"><?= e($filho['STATUS']) ?></span><span class="filho-camisa">Camisa <?= $filho['NUMERO_CAMISA'] === null ? '—' : '#' . (int) $filho['NUMERO_CAMISA'] ?></span></article><?php endforeach; ?></div></section>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php pageEnd(); ?>
