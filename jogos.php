<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notificacoes_helpers.php';
checkRole(['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL', 'ALUNO']);

$db = getDB();
$escolinha = currentEscolinhaId();
$perfil = $_SESSION['user_role'];
$usuario = (int) $_SESSION['user_id'];
$podeGerenciar = hasRole(['ADMIN', 'SECRETARIA', 'PROFESSOR']);
$responsavelId = 0;
$alunosResponsavel = [];
$alunoId = 0;
if ($perfil === 'RESPONSAVEL') {
    $stmt = $db->prepare('SELECT COD_RESPONSAVEL FROM TB_RESPONSAVEL WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
    $stmt->execute([$usuario, $escolinha]);
    $responsavelId = (int) $stmt->fetchColumn();
    $stmt = $db->prepare('SELECT COD_ALUNO FROM TB_ALUNO WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?');
    $stmt->execute([$responsavelId, $escolinha]);
    $alunosResponsavel = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

if ($perfil === 'ALUNO') {
    $stmt = $db->prepare('SELECT COD_ALUNO FROM TB_ALUNO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
    $stmt->execute([$usuario, $escolinha]);
    $alunoId = (int) $stmt->fetchColumn();
    if ($alunoId) $alunosResponsavel = [$alunoId];
}

if ($perfil === 'RESPONSAVEL' || $perfil === 'ALUNO') {
    $stmt = $db->prepare(
        "SELECT DISTINCT t.COD_TURMA, t.NOME, t.FAIXA_ETARIA
         FROM TB_TURMA t JOIN TB_MATRICULA m ON m.COD_TURMA = t.COD_TURMA
         JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO
         WHERE t.COD_ESCOLINHA = ? AND " . ($perfil === 'ALUNO' ? 'a.COD_ALUNO = ?' : 'a.COD_RESPONSAVEL = ?') . " AND a.COD_ESCOLINHA = ? ORDER BY t.NOME"
    );
    $stmt->execute([$escolinha, $perfil === 'ALUNO' ? $alunoId : $responsavelId, $escolinha]);
} else {
    $sql = 'SELECT COD_TURMA, NOME, FAIXA_ETARIA FROM TB_TURMA WHERE COD_ESCOLINHA = ?';
    $args = [$escolinha];
    if ($perfil === 'PROFESSOR') {
        $sql .= ' AND COD_PROFESSOR = ?';
        $args[] = $usuario;
    }
    $stmt = $db->prepare($sql . ' ORDER BY NOME');
    $stmt->execute($args);
}
$turmas = $stmt->fetchAll();
$idsTurmas = array_map(static function ($turma) { return (int) $turma['COD_TURMA']; }, $turmas);
$baseUrl = 'jogos.php';

function jogoDaEscolinha($db, $id, $escolinha, $perfil, $usuario, $responsavelId, $alunoId = 0) {
    $sql = 'SELECT j.* FROM TB_JOGO j JOIN TB_TURMA t ON t.COD_TURMA = j.COD_TURMA WHERE j.COD_JOGO = ? AND t.COD_ESCOLINHA = ?';
    $params = [$id, $escolinha];
    if ($perfil === 'PROFESSOR') {
        $sql .= ' AND t.COD_PROFESSOR = ?';
        $params[] = $usuario;
    } elseif ($perfil === 'RESPONSAVEL') {
        $sql .= ' AND EXISTS (SELECT 1 FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO WHERE m.COD_TURMA = t.COD_TURMA AND a.COD_RESPONSAVEL = ?)';
        $params[] = $responsavelId;
    } elseif ($perfil === 'ALUNO') {
        $sql .= ' AND EXISTS (SELECT 1 FROM TB_MATRICULA m WHERE m.COD_TURMA = t.COD_TURMA AND m.STATUS = "ATIVA" AND m.COD_ALUNO = ?)';
        $params[] = $alunoId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (!$podeGerenciar) {
        http_response_code(403);
        exit('Acesso não autorizado.');
    }
    $acao = (string) ($_POST['acao'] ?? '');
    $id = (int) ($_POST['jogo'] ?? 0);
    $retorno = $baseUrl . (isset($_GET['mini']) ? '?mini=1' : '');

    if ($acao === 'excluir') {
        $jogo = jogoDaEscolinha($db, $id, $escolinha, $perfil, $usuario, $responsavelId, $alunoId);
        if (!$jogo) {
            flash('error', 'Jogo não encontrado nesta escolinha.');
        } else {
            notificacaoEnviar($db, $escolinha, notificacaoDestinatariosTurma($db, $escolinha, (int) $jogo['COD_TURMA']), 'OUTRA_ALTERACAO', 'Jogo cancelado', 'O jogo contra “' . $jogo['ADVERSARIO'] . '” foi cancelado.', 'jogos.php', $usuario);
            $db->prepare('DELETE FROM TB_JOGO WHERE COD_JOGO = ?')->execute([$id]);
            flash('success', 'Jogo removido.');
        }
        go($retorno);
    }

    if ($acao === 'salvar') {
        $turmaId = (int) ($_POST['turma'] ?? 0);
        $adversario = trim((string) ($_POST['adversario'] ?? ''));
        $data = (string) ($_POST['data'] ?? '');
        $horario = trim((string) ($_POST['horario'] ?? ''));
        $local = trim((string) ($_POST['local'] ?? ''));
        $golsCasaTexto = trim((string) ($_POST['gols_escolinha'] ?? ''));
        $golsForaTexto = trim((string) ($_POST['gols_adversario'] ?? ''));
        $observacoes = trim((string) ($_POST['observacoes'] ?? ''));
        $dataValida = DateTime::createFromFormat('!Y-m-d', $data);
        $horaValida = $horario === '' || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horario);
        $resultadoVazio = $golsCasaTexto === '' && $golsForaTexto === '';
        $golsCasa = $golsCasaTexto === '' ? null : filter_var($golsCasaTexto, FILTER_VALIDATE_INT);
        $golsFora = $golsForaTexto === '' ? null : filter_var($golsForaTexto, FILTER_VALIDATE_INT);
        $resultadoValido = $resultadoVazio || ($golsCasa !== false && $golsFora !== false && $golsCasa !== null && $golsFora !== null && $golsCasa >= 0 && $golsCasa <= 99 && $golsFora >= 0 && $golsFora <= 99);
        $turmaPermitida = in_array($turmaId, $idsTurmas, true);
        $atual = $id ? jogoDaEscolinha($db, $id, $escolinha, $perfil, $usuario, $responsavelId, $alunoId) : null;
        if (!$turmaPermitida || !$adversario || mb_strlen($adversario) > 100 || !$dataValida || $dataValida->format('Y-m-d') !== $data || !$horaValida || !$resultadoValido || ($id && !$atual)) {
            flash('error', 'Confira turma, adversário, data, horário e resultado (0 a 99 gols).');
            go($retorno);
        }

        $linhasAtletas = $_POST['atletas'] ?? [];
        if (!is_array($linhasAtletas)) $linhasAtletas = [];
        $consultaElenco = $db->prepare(
            "SELECT a.COD_ALUNO, a.NOME, a.NUMERO_CAMISA FROM TB_ALUNO a
             JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = 'ATIVA'
             WHERE m.COD_TURMA = ? AND a.COD_ESCOLINHA = ? AND a.STATUS = 'ATIVO' ORDER BY a.NOME"
        );
        $consultaElenco->execute([$turmaId, $escolinha]);
        $elencoIds = array_map('intval', $consultaElenco->fetchAll(PDO::FETCH_COLUMN));
        if ($atual && (int) $atual['COD_TURMA'] === $turmaId) {
            $stmtAnterior = $db->prepare('SELECT ja.COD_ALUNO FROM TB_JOGO_ATLETA ja JOIN TB_ALUNO a ON a.COD_ALUNO = ja.COD_ALUNO WHERE ja.COD_JOGO = ? AND a.COD_ESCOLINHA = ?');
            $stmtAnterior->execute([$id, $escolinha]);
            $elencoIds = array_merge($elencoIds, array_map('intval', $stmtAnterior->fetchAll(PDO::FETCH_COLUMN)));
            $elencoIds = array_values(array_unique($elencoIds));
        }
        $elencoPermitido = array_fill_keys($elencoIds, true);
        $estatisticas = [];
        foreach ($linhasAtletas as $alunoTexto => $linha) {
            if (!is_array($linha)) continue;
            $alunoId = filter_var($alunoTexto, FILTER_VALIDATE_INT);
            if ($alunoId === false || !isset($elencoPermitido[(int) $alunoId])) continue;
            $participou = !empty($linha['participou']) ? 1 : 0;
            $titular = !empty($linha['titular']) ? 1 : 0;
            $gols = filter_var($linha['gols'] ?? '0', FILTER_VALIDATE_INT);
            $assistencias = filter_var($linha['assistencias'] ?? '0', FILTER_VALIDATE_INT);
            if ($gols === false || $assistencias === false || $gols < 0 || $gols > 20 || $assistencias < 0 || $assistencias > 20 || (($gols > 0 || $assistencias > 0 || $titular) && !$participou)) {
                flash('error', 'Gols, assistências e titularidade exigem participação do atleta; use valores de 0 a 20.');
                go($retorno);
            }
            if ($participou) $estatisticas[] = [(int) $alunoId, $titular, $participou, $gols, $assistencias];
        }

        try {
            $db->beginTransaction();
            if ($id) {
                $db->prepare('UPDATE TB_JOGO SET COD_TURMA = ?, ADVERSARIO = ?, DATA_JOGO = ?, HORARIO = ?, LOCAL = ?, GOLS_ESCOLINHA = ?, GOLS_ADVERSARIO = ?, OBSERVACOES = ? WHERE COD_JOGO = ?')
                    ->execute([$turmaId, $adversario, $data, $horario ?: null, $local ?: null, $golsCasa, $golsFora, $observacoes ?: null, $id]);
                $jogoId = $id;
                $db->prepare('DELETE FROM TB_JOGO_ATLETA WHERE COD_JOGO = ?')->execute([$jogoId]);
            } else {
                $db->prepare('INSERT INTO TB_JOGO (COD_TURMA, ADVERSARIO, DATA_JOGO, HORARIO, LOCAL, GOLS_ESCOLINHA, GOLS_ADVERSARIO, OBSERVACOES) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$turmaId, $adversario, $data, $horario ?: null, $local ?: null, $golsCasa, $golsFora, $observacoes ?: null]);
                $jogoId = (int) $db->lastInsertId();
            }
            $inserir = $db->prepare('INSERT INTO TB_JOGO_ATLETA (COD_JOGO, COD_ALUNO, TITULAR, PARTICIPOU, GOLS, ASSISTENCIAS) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($estatisticas as $estatistica) $inserir->execute(array_merge([$jogoId], $estatistica));
            $db->commit();
            $destinatarios = [];
            foreach (array_unique(array_filter([$atual ? (int) $atual['COD_TURMA'] : 0, $turmaId])) as $turmaNotificada) {
                $destinatarios = array_merge($destinatarios, notificacaoDestinatariosTurma($db, $escolinha, $turmaNotificada));
            }
            notificacaoEnviar($db, $escolinha, $destinatarios, $id ? 'OUTRA_ALTERACAO' : 'NOVO_JOGO', $id ? 'Jogo atualizado' : 'Novo jogo', $id ? 'O jogo contra “' . $adversario . '” foi atualizado para ' . notificacaoDataTreino($data, $horario ?: null) . '.' : 'Foi marcado um jogo contra “' . $adversario . '” para ' . notificacaoDataTreino($data, $horario ?: null) . '.', 'jogos.php', $usuario);
            flash('success', $id ? 'Jogo atualizado e estatísticas recalculadas.' : 'Jogo cadastrado e estatísticas registradas.');
        } catch (Throwable $erro) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Falha ao salvar jogo: ' . $erro->getMessage());
            flash('error', 'Não foi possível salvar o jogo. Confira os atletas e tente novamente.');
        }
        go($retorno);
    }
}

$turmaFiltro = (int) ($_GET['turma'] ?? 0);
if ($turmaFiltro && !in_array($turmaFiltro, $idsTurmas, true)) $turmaFiltro = 0;
$idsPermitidos = $idsTurmas ?: [0];
$marcadoresTurma = implode(',', array_fill(0, count($idsPermitidos), '?'));
$sqlJogos = "SELECT j.*, t.NOME AS TURMA, t.FAIXA_ETARIA, u.NOME AS PROFESSOR
    FROM TB_JOGO j JOIN TB_TURMA t ON t.COD_TURMA = j.COD_TURMA
    LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = t.COD_PROFESSOR
    WHERE t.COD_ESCOLINHA = ? AND j.COD_TURMA IN ($marcadoresTurma)";
$paramsJogos = array_merge([$escolinha], $idsPermitidos);
if ($turmaFiltro) { $sqlJogos .= ' AND j.COD_TURMA = ?'; $paramsJogos[] = $turmaFiltro; }
$sqlJogos .= ' ORDER BY j.DATA_JOGO DESC, j.HORARIO DESC, j.COD_JOGO DESC';
$stmt = $db->prepare($sqlJogos);
$stmt->execute($paramsJogos);
$jogos = $stmt->fetchAll();
$proximos = [];
$anteriores = [];
foreach ($jogos as $jogo) {
    if ($jogo['DATA_JOGO'] >= date('Y-m-d')) $proximos[] = $jogo;
    else $anteriores[] = $jogo;
}
usort($proximos, static function ($a, $b) {
    $inicioA = strtotime($a['DATA_JOGO'] . ' ' . ($a['HORARIO'] ?: '00:00:00'));
    $inicioB = strtotime($b['DATA_JOGO'] . ' ' . ($b['HORARIO'] ?: '00:00:00'));
    return $inicioA <=> $inicioB;
});

$linhasPorJogo = [];
if ($jogos) {
    $idsJogos = array_map(static function ($jogo) { return (int) $jogo['COD_JOGO']; }, $jogos);
    $marcadoresJogos = implode(',', array_fill(0, count($idsJogos), '?'));
    $stmt = $db->prepare("SELECT ja.*, a.NOME, a.NUMERO_CAMISA FROM TB_JOGO_ATLETA ja JOIN TB_ALUNO a ON a.COD_ALUNO = ja.COD_ALUNO WHERE ja.COD_JOGO IN ($marcadoresJogos) ORDER BY ja.TITULAR DESC, a.NOME");
    $stmt->execute($idsJogos);
    foreach ($stmt->fetchAll() as $linha) {
        if (in_array($perfil, ['RESPONSAVEL', 'ALUNO'], true) && !in_array((int) $linha['COD_ALUNO'], $alunosResponsavel, true)) continue;
        $linhasPorJogo[(int) $linha['COD_JOGO']][] = $linha;
    }
}

$sqlStats = "SELECT t.COD_TURMA, t.NOME AS TURMA, t.FAIXA_ETARIA, a.COD_ALUNO, a.NOME AS ATLETA, a.NUMERO_CAMISA,
        SUM(ja.GOLS) AS GOLS, SUM(ja.ASSISTENCIAS) AS ASSISTENCIAS, COUNT(DISTINCT j.COD_JOGO) AS JOGOS
    FROM TB_JOGO_ATLETA ja JOIN TB_JOGO j ON j.COD_JOGO = ja.COD_JOGO
    JOIN TB_TURMA t ON t.COD_TURMA = j.COD_TURMA JOIN TB_ALUNO a ON a.COD_ALUNO = ja.COD_ALUNO
    WHERE t.COD_ESCOLINHA = ? AND j.COD_TURMA IN ($marcadoresTurma) AND j.DATA_JOGO < CURDATE() AND ja.PARTICIPOU = 1";
$paramsStats = array_merge([$escolinha], $idsPermitidos);
if ($turmaFiltro) { $sqlStats .= ' AND t.COD_TURMA = ?'; $paramsStats[] = $turmaFiltro; }
if (in_array($perfil, ['RESPONSAVEL', 'ALUNO'], true)) {
    if ($alunosResponsavel) {
        $sqlStats .= ' AND a.COD_ALUNO IN (' . implode(',', array_fill(0, count($alunosResponsavel), '?')) . ')';
        $paramsStats = array_merge($paramsStats, $alunosResponsavel);
    } else { $sqlStats .= ' AND 1 = 0'; }
}
$sqlStats .= ' GROUP BY t.COD_TURMA, t.NOME, t.FAIXA_ETARIA, a.COD_ALUNO, a.NOME, a.NUMERO_CAMISA ORDER BY GOLS DESC, ASSISTENCIAS DESC, JOGOS DESC, ATLETA';
$stmt = $db->prepare($sqlStats);
$stmt->execute($paramsStats);
$estatisticas = $stmt->fetchAll();
$estatisticasPorTurma = [];
foreach ($estatisticas as $estatistica) $estatisticasPorTurma[$estatistica['COD_TURMA']][] = $estatistica;

$edit = null;
$elencoEdicao = [];
if ($podeGerenciar && isset($_GET['editar'])) {
    $edit = jogoDaEscolinha($db, (int) $_GET['editar'], $escolinha, $perfil, $usuario, $responsavelId, $alunoId);
    if (!$edit || !in_array((int) $edit['COD_TURMA'], $idsTurmas, true)) {
        flash('error', 'Jogo não encontrado nesta escolinha.');
        go('jogos.php');
    }
    $stmt = $db->prepare('SELECT COD_ALUNO, TITULAR, PARTICIPOU, GOLS, ASSISTENCIAS FROM TB_JOGO_ATLETA WHERE COD_JOGO = ?');
    $stmt->execute([(int) $edit['COD_JOGO']]);
    foreach ($stmt->fetchAll() as $registro) $elencoEdicao[(int) $registro['COD_ALUNO']] = $registro;
}
$turmaForm = $edit ? (int) $edit['COD_TURMA'] : (int) ($_GET['nova_turma'] ?? ($idsTurmas[0] ?? 0));
if (!in_array($turmaForm, $idsTurmas, true)) $turmaForm = (int) ($idsTurmas[0] ?? 0);
$elenco = [];
if ($podeGerenciar && $turmaForm) {
    $stmt = $db->prepare("SELECT a.COD_ALUNO, a.NOME, a.NUMERO_CAMISA FROM TB_ALUNO a JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = 'ATIVA' WHERE m.COD_TURMA = ? AND a.COD_ESCOLINHA = ? AND a.STATUS = 'ATIVO' ORDER BY a.NOME");
    $stmt->execute([$turmaForm, $escolinha]);
    $elenco = $stmt->fetchAll();
    if ($edit) {
        $existentes = array_column($elenco, null, 'COD_ALUNO');
        if ($elencoEdicao) {
            $idsAnteriores = array_keys($elencoEdicao);
            $placeholders = implode(',', array_fill(0, count($idsAnteriores), '?'));
            $stmt = $db->prepare("SELECT COD_ALUNO, NOME, NUMERO_CAMISA FROM TB_ALUNO WHERE COD_ESCOLINHA = ? AND COD_ALUNO IN ($placeholders)");
            $stmt->execute(array_merge([$escolinha], $idsAnteriores));
            foreach ($stmt->fetchAll() as $aluno) $existentes[$aluno['COD_ALUNO']] = $aluno;
        }
        $elenco = array_values($existentes);
        usort($elenco, static function ($a, $b) { return strcasecmp($a['NOME'], $b['NOME']); });
    }
}
$jogosJogados = count(array_filter($anteriores, static function ($jogo) { return $jogo['GOLS_ESCOLINHA'] !== null && $jogo['GOLS_ADVERSARIO'] !== null; }));
$golsMarcados = array_sum(array_map(static function ($jogo) { return (int) ($jogo['GOLS_ESCOLINHA'] ?? 0); }, $anteriores));
$golsSofridos = array_sum(array_map(static function ($jogo) { return (int) ($jogo['GOLS_ADVERSARIO'] ?? 0); }, $anteriores));
$rotuloData = static function ($data) { return date('d/m/Y', strtotime($data)); };

pageStart('Jogos');
?>
<div class="jogos-page">
    <header class="jogos-hero">
        <div><p class="jogos-kicker"><span></span> TEMPORADA · GESTORFC</p><h2>Jogos e estatísticas.</h2><p>Organize partidas e acompanhe o desempenho real dos atletas por turma.</p></div>
        <div class="jogos-hero-resumo"><span aria-hidden="true">⚽</span><div><strong><?= count($proximos) ?></strong><small>próximos jogos</small></div></div>
    </header>

    <section class="jogos-metricas" aria-label="Resumo dos jogos"><article><span>Partidas realizadas</span><strong><?= $jogosJogados ?></strong><small>com resultado registrado</small></article><article><span>Gols marcados</span><strong><?= $golsMarcados ?></strong><small>escolinha</small></article><article><span>Gols sofridos</span><strong><?= $golsSofridos ?></strong><small>adversários</small></article><article><span>Atletas no ranking</span><strong><?= count($estatisticas) ?></strong><small>com participação registrada</small></article></section>

    <section class="jogos-filtro-card"><form method="get" class="jogos-filtros"><label><span>Turma e categoria</span><select name="turma"><option value="0">Todas as categorias</option><?php foreach ($turmas as $turma): ?><option value="<?= (int) $turma['COD_TURMA'] ?>" <?= $turmaFiltro === (int) $turma['COD_TURMA'] ? 'selected' : '' ?>><?= e($turma['NOME']) ?> · <?= e($turma['FAIXA_ETARIA']) ?></option><?php endforeach; ?></select></label><button class="jogos-botao-secundario" type="submit">Aplicar filtro</button></form><?php if ($podeGerenciar): ?><a class="jogos-botao-principal" href="jogos.php?novo=1<?= $turmaFiltro ? '&amp;nova_turma=' . $turmaFiltro : '' ?>#formularioJogo">+ Registrar jogo</a><?php endif; ?></section>

    <?php if ($podeGerenciar && (isset($_GET['novo']) || $edit)): ?>
    <section class="jogos-form-card" id="formularioJogo"><div class="jogos-titulo-secao"><div><p class="jogos-kicker">SÚMULA</p><h2><?= $edit ? 'Editar partida' : 'Registrar partida' ?></h2><p>Escalação e números individuais ficam vinculados a esta partida.</p></div><a class="jogos-fechar-form" href="jogos.php<?= $turmaFiltro ? '?turma=' . $turmaFiltro : '' ?>" aria-label="Fechar formulário">×</a></div>
        <form method="post" class="jogos-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="jogo" value="<?= (int) ($edit['COD_JOGO'] ?? 0) ?>">
            <label class="jogos-form-turma"><span>Turma</span><select name="turma" required onchange="window.location.href='jogos.php?novo=1&nova_turma='+this.value+'#formularioJogo'"><?php foreach ($turmas as $turma): ?><option value="<?= (int) $turma['COD_TURMA'] ?>" <?= $turmaForm === (int) $turma['COD_TURMA'] ? 'selected' : '' ?>><?= e($turma['NOME']) ?> · <?= e($turma['FAIXA_ETARIA']) ?></option><?php endforeach; ?></select></label>
            <label><span>Adversário</span><input name="adversario" required maxlength="100" value="<?= e($edit['ADVERSARIO'] ?? '') ?>" placeholder="Nome do time adversário"></label>
            <label><span>Data</span><input name="data" type="date" required value="<?= e($edit['DATA_JOGO'] ?? '') ?>"></label>
            <label><span>Horário</span><input name="horario" type="time" value="<?= e(!empty($edit['HORARIO']) ? substr((string) $edit['HORARIO'], 0, 5) : '') ?>"></label>
            <label><span>Local</span><input name="local" maxlength="150" value="<?= e($edit['LOCAL'] ?? '') ?>" placeholder="Campo ou endereço"></label>
            <fieldset class="jogos-placar"><legend>Placar <small>deixe vazio se ainda não foi definido</small></legend><label><span>Escolinha</span><input name="gols_escolinha" type="number" min="0" max="99" value="<?= $edit && $edit['GOLS_ESCOLINHA'] !== null ? (int) $edit['GOLS_ESCOLINHA'] : '' ?>"></label><b>×</b><label><span>Adversário</span><input name="gols_adversario" type="number" min="0" max="99" value="<?= $edit && $edit['GOLS_ADVERSARIO'] !== null ? (int) $edit['GOLS_ADVERSARIO'] : '' ?>"></label></fieldset>
            <label class="jogos-form-observacoes"><span>Observações</span><textarea name="observacoes" rows="3" placeholder="Notas da partida"><?= e($edit['OBSERVACOES'] ?? '') ?></textarea></label>
            <section class="jogos-elenco-form"><div class="jogos-titulo-secao"><div><h3>Elenco e estatísticas</h3><p>Marque quem entrou em campo; titularidade, gols e assistências alimentam o histórico do atleta.</p></div></div>
                <?php if ($elenco): ?><div class="jogos-elenco-lista"><div class="jogos-elenco-cabecalho"><span>Atleta</span><span>Jogou</span><span>Titular</span><span>Gols</span><span>Assist.</span></div><?php foreach ($elenco as $aluno): $alunoId = (int) $aluno['COD_ALUNO']; $registro = $elencoEdicao[$alunoId] ?? null; ?><div class="jogos-elenco-linha"><strong><?= $aluno['NUMERO_CAMISA'] !== null ? '#' . (int) $aluno['NUMERO_CAMISA'] . ' · ' : '' ?><?= e($aluno['NOME']) ?></strong><label class="jogos-checkbox" aria-label="<?= e('Participou: ' . $aluno['NOME']) ?>"><input type="checkbox" name="atletas[<?= $alunoId ?>][participou]" value="1" <?= $registro && $registro['PARTICIPOU'] ? 'checked' : '' ?>><span>Jogou</span></label><label class="jogos-checkbox" aria-label="<?= e('Titular: ' . $aluno['NOME']) ?>"><input type="checkbox" name="atletas[<?= $alunoId ?>][titular]" value="1" <?= $registro && $registro['TITULAR'] ? 'checked' : '' ?>><span>Sim</span></label><label class="jogos-stat-input"><span class="sr-only">Gols de <?= e($aluno['NOME']) ?></span><input type="number" min="0" max="20" name="atletas[<?= $alunoId ?>][gols]" value="<?= $registro ? (int) $registro['GOLS'] : 0 ?>"></label><label class="jogos-stat-input"><span class="sr-only">Assistências de <?= e($aluno['NOME']) ?></span><input type="number" min="0" max="20" name="atletas[<?= $alunoId ?>][assistencias]" value="<?= $registro ? (int) $registro['ASSISTENCIAS'] : 0 ?>"></label></div><?php endforeach; ?></div><?php else: ?><p class="jogos-sem-elenco">Não há atletas ativos matriculados nesta turma.</p><?php endif; ?>
            </section>
            <div class="jogos-form-acoes"><button class="jogos-botao-principal" type="submit"><?= $edit ? 'Salvar alterações' : 'Salvar jogo e estatísticas' ?></button><a class="jogos-botao-secundario" href="jogos.php<?= $turmaFiltro ? '?turma=' . $turmaFiltro : '' ?>">Cancelar</a></div>
        </form>
    </section>
    <?php endif; ?>

    <?php
    $renderJogo = static function ($jogo, $linhas, $podeGerenciar, $turmaFiltro) use ($rotuloData) {
        $jogoId = (int) $jogo['COD_JOGO'];
        $temResultado = $jogo['GOLS_ESCOLINHA'] !== null && $jogo['GOLS_ADVERSARIO'] !== null;
        $participantes = array_values(array_filter($linhas, static function ($linha) { return (int) $linha['PARTICIPOU'] === 1; }));
        ?>
        <article class="jogo-card"><div class="jogo-card-cabecalho"><div><span class="jogo-data"><?= e($rotuloData($jogo['DATA_JOGO'])) ?><?= $jogo['HORARIO'] ? ' · ' . e(substr((string) $jogo['HORARIO'], 0, 5)) : '' ?></span><span class="jogo-categoria"><?= e($jogo['TURMA']) ?> · <?= e($jogo['FAIXA_ETARIA']) ?></span></div><?php if ($temResultado): ?><span class="jogo-status <?= (int) $jogo['GOLS_ESCOLINHA'] > (int) $jogo['GOLS_ADVERSARIO'] ? 'venceu' : ((int) $jogo['GOLS_ESCOLINHA'] === (int) $jogo['GOLS_ADVERSARIO'] ? 'empatou' : 'perdeu') ?>">Resultado final</span><?php else: ?><span class="jogo-status agendado">Agendado</span><?php endif; ?></div>
        <div class="jogo-placar-card"><div class="jogo-equipe"><span class="jogo-escudo">⚽</span><strong>Escolinha</strong></div><div class="jogo-resultado"><strong><?= $temResultado ? (int) $jogo['GOLS_ESCOLINHA'] . ' : ' . (int) $jogo['GOLS_ADVERSARIO'] : '— : —' ?></strong><small><?= $temResultado ? 'PLACAR' : 'VS' ?></small></div><div class="jogo-equipe adversario"><span class="jogo-escudo adversario-escudo" aria-hidden="true">◈</span><strong><?= e($jogo['ADVERSARIO']) ?></strong></div></div>
        <div class="jogo-card-meta"><?php if (!empty($jogo['LOCAL'])): ?><span>⌖ <?= e($jogo['LOCAL']) ?></span><?php endif; ?><?php if (!empty($jogo['PROFESSOR'])): ?><span>◉ <?= e($jogo['PROFESSOR']) ?></span><?php endif; ?><span><?= count($participantes) ?> atleta<?= count($participantes) === 1 ? '' : 's' ?> com participação</span></div>
        <?php if ($jogo['OBSERVACOES']): ?><p class="jogo-observacao"><?= e($jogo['OBSERVACOES']) ?></p><?php endif; ?>
        <details class="jogo-detalhes"><summary>Escalação e números individuais</summary><?php if ($participantes): ?><div class="jogo-participantes"><?php foreach ($participantes as $atleta): ?><span><?= (int) $atleta['TITULAR'] ? '<b>XI</b> ' : '' ?><?= $atleta['NUMERO_CAMISA'] !== null ? '#' . (int) $atleta['NUMERO_CAMISA'] . ' ' : '' ?><?= e($atleta['NOME']) ?><?php if ((int) $atleta['GOLS'] || (int) $atleta['ASSISTENCIAS']): ?><small><?= (int) $atleta['GOLS'] ?> gol<?= (int) $atleta['GOLS'] === 1 ? '' : 's' ?> · <?= (int) $atleta['ASSISTENCIAS'] ?> assist.</small><?php endif; ?></span><?php endforeach; ?></div><?php else: ?><p class="jogos-vazio-inline">Ainda não há participações registradas.</p><?php endif; ?></details>
        <?php if ($podeGerenciar): ?><div class="jogo-card-acoes"><a href="jogos.php?editar=<?= $jogoId ?>#formularioJogo">Editar jogo</a><form method="post" onsubmit="return confirm('Remover este jogo e as estatísticas individuais vinculadas?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="jogo" value="<?= $jogoId ?>"><button type="submit">Remover</button></form></div><?php endif; ?></article>
        <?php
    };
    ?>
    <section class="jogos-lista-secao"><div class="jogos-titulo-secao"><div><p class="jogos-kicker">AGENDA</p><h2>Próximos jogos <span><?= count($proximos) ?></span></h2></div></div><?php if ($proximos): ?><div class="jogos-lista"><?php foreach ($proximos as $jogo) $renderJogo($jogo, $linhasPorJogo[(int) $jogo['COD_JOGO']] ?? [], $podeGerenciar, $turmaFiltro); ?></div><?php else: ?><div class="jogos-estado-vazio"><span>◷</span><div><strong>Nenhum próximo jogo</strong><p>As próximas partidas registradas aparecerão aqui.</p></div><?php if ($podeGerenciar): ?><a href="jogos.php?novo=1#formularioJogo">Registrar partida</a><?php endif; ?></div><?php endif; ?></section>

    <section class="jogos-ranking-secao"><div class="jogos-titulo-secao"><div><p class="jogos-kicker">DESEMPENHO INDIVIDUAL</p><h2>Estatísticas por turma</h2><p>Contabilizadas a partir de jogos anteriores com participação registrada.</p></div></div><?php if ($estatisticasPorTurma): ?><div class="jogos-ranking-grupos"><?php foreach ($estatisticasPorTurma as $codTurma => $ranking): ?><article class="jogos-ranking-card"><header><div><strong><?= e($ranking[0]['TURMA']) ?></strong><span><?= e($ranking[0]['FAIXA_ETARIA']) ?></span></div><span class="jogos-ranking-count"><?= count($ranking) ?> atletas</span></header><div class="jogos-tabela-scroll"><table class="jogos-ranking-tabela"><thead><tr><th>Atleta</th><th>Jogos</th><th>Gols</th><th>Assist.</th><th>G+A / jogo</th></tr></thead><tbody><?php foreach ($ranking as $posicao => $linha): ?><tr><td><span class="jogos-ranking-posicao"><?= $posicao + 1 ?></span><strong><?= $linha['NUMERO_CAMISA'] !== null ? '#' . (int) $linha['NUMERO_CAMISA'] . ' ' : '' ?><?= e($linha['ATLETA']) ?></strong></td><td><?= (int) $linha['JOGOS'] ?></td><td><b class="jogos-gols-value"><?= (int) $linha['GOLS'] ?></b></td><td><?= (int) $linha['ASSISTENCIAS'] ?></td><td><?= $linha['JOGOS'] ? number_format(((int) $linha['GOLS'] + (int) $linha['ASSISTENCIAS']) / (int) $linha['JOGOS'], 2, ',', '.') : '0,00' ?></td></tr><?php endforeach; ?></tbody></table></div></article><?php endforeach; ?></div><?php else: ?><div class="jogos-estado-vazio"><span>⌁</span><div><strong>Sem estatísticas registradas</strong><p>Depois dos jogos realizados, os números de participação, gols e assistências serão consolidados aqui.</p></div></div><?php endif; ?></section>

    <section class="jogos-lista-secao jogos-historico-secao"><div class="jogos-titulo-secao"><div><p class="jogos-kicker">ARQUIVO</p><h2>Jogos anteriores <span><?= count($anteriores) ?></span></h2></div></div><?php if ($anteriores): ?><div class="jogos-lista"><?php foreach ($anteriores as $jogo) $renderJogo($jogo, $linhasPorJogo[(int) $jogo['COD_JOGO']] ?? [], $podeGerenciar, $turmaFiltro); ?></div><?php else: ?><p class="jogos-vazio-inline">Nenhum jogo anterior registrado.</p><?php endif; ?></section>
</div>
<?php pageEnd(); ?>
