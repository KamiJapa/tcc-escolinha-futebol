<?php
// Agenda aulas nos dias da turma e registra presença ou falta por aluno.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/avaliacao_helpers.php';
require_once __DIR__ . '/includes/notificacoes_helpers.php';
checkRole(['ADMIN', 'PROFESSOR', 'SECRETARIA']);

$db = getDB();
$escolinha = currentEscolinhaId();
$professor = hasRole(['PROFESSOR']) ? (int) $_SESSION['user_id'] : null;
$edit = null;

function aulaDaEscolinha($db, $id, $escolinha, $professor) {
    $sql = 'SELECT a.* FROM TB_AULA a JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA WHERE a.COD_AULA = ? AND t.COD_ESCOLINHA = ?';
    $argumentos = [$id, $escolinha];
    if ($professor) {
        $sql .= ' AND t.COD_PROFESSOR = ?';
        $argumentos[] = $professor;
    }
    $consulta = $db->prepare($sql);
    $consulta->execute($argumentos);
    return $consulta->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach (['action', 'tema_treino', 'observacao', 'objetivo', 'exercicios', 'tipo_treino', 'data_aula'] as $campo) {
        if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
            flash('error', 'Revise os campos informados.');
            go('aulas.php');
        }
    }
    $acao = postString('action');
    $id = positiveId($_POST['id'] ?? null) ?? 0;
    if (!in_array($acao, ['create','update','delete','attendance','delete_attendance'], true) || ($acao !== 'create' && !$id)) {
        flash('error', 'Operação ou identificador inválido.');
        go('aulas.php');
    }

    if ($acao === 'delete_attendance') {
        if (!aulaDaEscolinha($db, $id, $escolinha, $professor)) {
            flash('error', 'Treino não encontrado.');
            go('aulas.php');
        }
        $sql = 'DELETE p FROM TB_PRESENCA p JOIN TB_AULA a ON a.COD_AULA=p.COD_AULA
                JOIN TB_TURMA t ON t.COD_TURMA=a.COD_TURMA
                WHERE a.COD_AULA=? AND t.COD_ESCOLINHA=?';
        $params = [$id, $escolinha];
        if ($professor) {
            $sql .= ' AND t.COD_PROFESSOR=?';
            $params[] = $professor;
        }
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            flash('success', $stmt->rowCount() ? 'Chamada excluída.' : 'Nenhuma chamada registrada para este treino.');
        } catch (PDOException $erro) {
            flash('error', 'Não foi possível excluir a chamada.');
        }
        go('aulas.php?chamada=' . $id);
    }

    if ($acao === 'attendance') {
        $aula = aulaDaEscolinha($db, $id, $escolinha, $professor);
        if (!$aula) {
            flash('error', 'Aula não encontrada.');
            go('aulas.php');
        }
        $alunos = $_POST['alunos'] ?? [];
        if (!is_array($alunos)) { http_response_code(400); exit('Chamada inválida.'); }
        $q = $db->prepare("SELECT a.COD_ALUNO FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO=m.COD_ALUNO WHERE m.COD_TURMA=? AND m.STATUS='ATIVA' AND a.COD_ESCOLINHA=? AND a.STATUS='ATIVO'");
        $q->execute([$aula['COD_TURMA'], $escolinha]);
        $permitidos = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        foreach ($alunos as $codigo => $dados) {
            if (!in_array((int) $codigo, $permitidos, true) || !is_array($dados) || !is_string($dados['justificativa'] ?? '') || mb_strlen($dados['justificativa'] ?? '') > 255) {
                http_response_code(400); exit('Aluno ou justificativa inválidos para esta chamada.');
            }
        }
        $db->beginTransaction();
        try {
        $salvar = $db->prepare(
            'INSERT INTO TB_PRESENCA (COD_AULA, COD_ALUNO, PRESENTE, JUSTIFICATIVA) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE PRESENTE = VALUES(PRESENTE), JUSTIFICATIVA = VALUES(JUSTIFICATIVA)'
        );
        foreach ($alunos as $codigo => $dados) {
            $salvar->execute([$id, (int) $codigo, isset($dados['presente']) ? 1 : 0, trim($dados['justificativa'] ?? '') ?: null]);
        }
        $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Não foi possível salvar a chamada. Nenhuma presença foi alterada.');
            go('aulas.php?chamada=' . $id);
        }
        flash('success', 'Chamada registrada.');
        go('aulas.php?chamada=' . $id);
    }

    if ($acao === 'delete') {
        $aulaExcluir = aulaDaEscolinha($db, $id, $escolinha, $professor);
        if ($aulaExcluir) {
            $destinatarios = notificacaoDestinatariosTurma($db, $escolinha, (int) $aulaExcluir['COD_TURMA']);
            notificacaoEnviar($db, $escolinha, $destinatarios, 'ALTERACAO_TREINO', 'Treino cancelado', 'O treino “' . $aulaExcluir['TEMA_TREINO'] . '” de ' . notificacaoDataTreino($aulaExcluir['DATA_AULA'], $aulaExcluir['HORARIO']) . ' foi cancelado.', 'calendario.php', (int) $_SESSION['user_id']);
            $db->prepare('DELETE FROM TB_AULA WHERE COD_AULA = ?')->execute([$id]);
            flash('success', 'Aula excluída. As presenças foram removidas; as avaliações sem vínculo com a aula foram preservadas.');
        }
        go('aulas.php');
    }

    $aulaAtual = $acao === 'update' ? aulaDaEscolinha($db, $id, $escolinha, $professor) : null;
    if ($acao === 'update' && !$aulaAtual) {
        flash('error', 'Aula não encontrada.');
        go('aulas.php');
    }

    $turma = (int) ($_POST['cod_turma'] ?? 0);
    $data = $_POST['data_aula'] ?? '';
    $tema = trim($_POST['tema_treino'] ?? '');
    $objetivo = trim($_POST['objetivo'] ?? '');
    $exercicios = $aulaAtual['EXERCICIOS'] ?? null;
    $observacao = trim($_POST['observacao'] ?? '');
    $tipo = $_POST['tipo_treino'] ?? '';
    $tiposValidos = avaliacaoTiposTreino();
    $customizados = avaliacaoAtributosPersonalizados($_POST['atributos_avaliaveis'] ?? []);
    $tipoValido = isset($tiposValidos[$tipo])
        || ($acao === 'update' && $tipo === '' && $aulaAtual['TIPO_TREINO'] === null);

    $turmaSql = 'SELECT DIAS_TREINO, HORARIO FROM TB_TURMA WHERE COD_TURMA = ? AND COD_ESCOLINHA = ? AND ATIVA = 1';
    $argumentosTurma = [$turma, $escolinha];
    if ($professor) {
        $turmaSql .= ' AND COD_PROFESSOR = ?';
        $argumentosTurma[] = $professor;
    }
    $consulta = $db->prepare($turmaSql);
    $consulta->execute($argumentosTurma);

    $programacao = $consulta->fetch();
    $dataValida = is_string($data) ? DateTimeImmutable::createFromFormat('!Y-m-d', $data) : false;
    $horario = $programacao ? inicioDoTreino($programacao['HORARIO']) : null;
    if (!$programacao || !$horario || !$dataValida || $dataValida->format('Y-m-d') !== $data
        || !in_array((int) $dataValida->format('w'), diasDeTreino($programacao['DIAS_TREINO']), true)) {
        flash('error', 'Escolha uma data de treino da turma. Confira os dias e o horário no cadastro da turma.');
        go($aulaAtual ? 'aulas.php?editar=' . $id : 'aulas.php');
    }
    if (mb_strlen($tema)>100 || mb_strlen($objetivo)>65535 || mb_strlen($observacao)>65535 || !$tema || !$tipoValido
        || ($acao === 'create' && $tipo === '')
        || ($tipo === 'PERSONALIZADO' && !$customizados)) {
        flash('error', 'Revise turma, data, tema e tipo do treino. Treinos personalizados precisam de ao menos um atributo.');
        go('aulas.php' . (isset($_GET['mini']) ? '?mini=1' : ''));
    }

    if ($aulaAtual && (int) $aulaAtual['COD_TURMA'] !== $turma) {
        $q = $db->prepare('SELECT (SELECT COUNT(*) FROM TB_PRESENCA WHERE COD_AULA=?) + (SELECT COUNT(*) FROM TB_AVALIACAO WHERE COD_AULA=?)');
        $q->execute([$id, $id]);
        if ($q->fetchColumn()) {
            flash('error', 'Este treino já tem chamada ou avaliações. Preserve a turma ou crie outro treino.');
            go('aulas.php');
        }
    }
    $atributosJson = $tipo === 'PERSONALIZADO' ? json_encode($customizados, JSON_UNESCAPED_UNICODE) : null;
    if ($acao === 'create') {
        try { $db->prepare(
            'INSERT INTO TB_AULA (COD_TURMA, DATA_AULA, HORARIO, TEMA_TREINO, OBJETIVO, EXERCICIOS, TIPO_TREINO, ATRIBUTOS_AVALIAVEIS_JSON, OBSERVACAO)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$turma, $data, $horario ?: null, $tema, $objetivo ?: null, $exercicios ?: null, $tipo, $atributosJson, $observacao ?: null]);
        $id = (int) $db->lastInsertId();
        notificacaoEnviar($db, $escolinha, notificacaoDestinatariosTurma($db, $escolinha, $turma), 'NOVO_TREINO', 'Novo treino', 'Foi marcado o treino “' . $tema . '” para ' . notificacaoDataTreino($data, $horario ?: null) . '.', 'calendario.php', (int) $_SESSION['user_id']);
        flash('success', 'Treino cadastrado.'); } catch (PDOException $e) { flash('error','Não foi possível cadastrar o treino. Confira os campos e seus limites.'); go('aulas.php'); }
    } elseif ($acao === 'update') {
        if ($tipo === '' && $aulaAtual['TIPO_TREINO'] === null) {
            $atributosJson = $aulaAtual['ATRIBUTOS_AVALIAVEIS_JSON'];
        }
        $db->prepare(
            'UPDATE TB_AULA SET COD_TURMA = ?, DATA_AULA = ?, HORARIO = ?, TEMA_TREINO = ?, OBJETIVO = ?, EXERCICIOS = ?, TIPO_TREINO = ?,
             ATRIBUTOS_AVALIAVEIS_JSON = ?, OBSERVACAO = ? WHERE COD_AULA = ?'
        )->execute([$turma, $data, $horario ?: null, $tema, $objetivo ?: null, $exercicios ?: null, $tipo ?: null, $atributosJson, $observacao ?: null, $id]);
        $destinatarios = [];
        foreach (array_unique([(int) $aulaAtual['COD_TURMA'], $turma]) as $turmaNotificada) {
            $destinatarios = array_merge($destinatarios, notificacaoDestinatariosTurma($db, $escolinha, $turmaNotificada));
        }
        notificacaoEnviar($db, $escolinha, $destinatarios, 'ALTERACAO_TREINO', 'Treino atualizado', 'O treino “' . $tema . '” foi atualizado para ' . notificacaoDataTreino($data, $horario ?: null) . '.', 'calendario.php', (int) $_SESSION['user_id']);
        flash('success', 'Treino atualizado.');
    }
    voltarAposSalvarCadastro('aulas.php');
}

if (isset($_GET['editar'])) {
    $edit = aulaDaEscolinha($db, (int) $_GET['editar'], $escolinha, $professor);
    if (!$edit) {
        flash('error', 'Aula não encontrada.');
        go('aulas.php');
    }
}

$consulta = $db->prepare('SELECT COD_TURMA, NOME, HORARIO, DIAS_TREINO FROM TB_TURMA WHERE COD_ESCOLINHA = ? AND ATIVA = 1' . ($professor ? ' AND COD_PROFESSOR = ?' : '') . ' ORDER BY NOME');
$consulta->execute($professor ? [$escolinha, $professor] : [$escolinha]);
$turmas = $consulta->fetchAll();

if (isset($_GET['chamada'])) {
    $aula = aulaDaEscolinha($db, (int) $_GET['chamada'], $escolinha, $professor);
    if (!$aula) {
        flash('error', 'Aula não encontrada.');
        go('aulas.php');
    }
    $consulta = $db->prepare(
        "SELECT a.COD_ALUNO, a.NOME, p.PRESENTE, p.JUSTIFICATIVA
         FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO
         LEFT JOIN TB_PRESENCA p ON p.COD_ALUNO = a.COD_ALUNO AND p.COD_AULA = ?
         WHERE m.COD_TURMA = ? AND m.STATUS = 'ATIVA' AND a.COD_ESCOLINHA = ? AND a.STATUS = 'ATIVO' ORDER BY a.NOME"
    );
    $consulta->execute([$aula['COD_AULA'], $aula['COD_TURMA'], $escolinha]);
    $alunos = $consulta->fetchAll();
    $presencas = $db->prepare('SELECT 1 FROM TB_PRESENCA WHERE COD_AULA=? LIMIT 1');
    $presencas->execute([$aula['COD_AULA']]);
    $temChamada = (bool) $presencas->fetchColumn();
    pageStart('Chamada: ' . date('d/m/Y', strtotime($aula['DATA_AULA'])));
    ?>
    <form method="post" class="formulario-chamada">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="attendance">
        <input type="hidden" name="id" value="<?= (int) $aula['COD_AULA'] ?>">
        <div class="lista-chamada">
            <?php foreach ($alunos as $aluno): ?>
                <div class="item-chamada"><strong><?= e($aluno['NOME']) ?></strong><label><input type="checkbox" name="alunos[<?= (int) $aluno['COD_ALUNO'] ?>][presente]" <?= ($aluno['PRESENTE'] ?? 1) ? 'checked' : '' ?>> Presente</label><input name="alunos[<?= (int) $aluno['COD_ALUNO'] ?>][justificativa]" value="<?= e($aluno['JUSTIFICATIVA'] ?? '') ?>" placeholder="Justificativa da ausência"></div>
            <?php endforeach; ?>
        </div>
        <div><?php actionButton('Salvar chamada'); ?><a href="aulas.php<?= isset($_GET['mini']) ? '?mini=1&chamadas=1' : '' ?>">Voltar</a></div>
    </form>
    <?php if ($temChamada): ?>
        <form method="post" data-confirm-submit="Excluir a chamada deste treino? As presenças e justificativas serão apagadas.">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="delete_attendance">
            <input type="hidden" name="id" value="<?= (int) $aula['COD_AULA'] ?>">
            <button type="submit" class="acao-tabela">Excluir chamada</button>
        </form>
    <?php endif; ?>
    <?php
    pageEnd();
    exit;
}

$sqlLista = 'SELECT a.*, t.NOME AS TURMA, COUNT(DISTINCT av.COD_AVALIACAO) AS AVALIACOES
             FROM TB_AULA a JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA
             LEFT JOIN TB_AVALIACAO av ON av.COD_AULA = a.COD_AULA
             WHERE t.COD_ESCOLINHA = ?';
$argumentosLista = [$escolinha];
if ($professor) {
    $sqlLista .= ' AND t.COD_PROFESSOR = ?';
    $argumentosLista[] = $professor;
}
$sqlLista .= ' GROUP BY a.COD_AULA, t.NOME ORDER BY a.DATA_AULA DESC, a.COD_AULA DESC';
$consulta = $db->prepare($sqlLista);
$consulta->execute($argumentosLista);
$lista = $consulta->fetchAll();
$tipoAtual = $edit['TIPO_TREINO'] ?? '';
$atributosAtuais = avaliacaoAtributosPersonalizados($edit['ATRIBUTOS_AVALIAVEIS_JSON'] ?? null);
pageStart('Aulas e chamada');
?>
<section class="treino-form-card">
    <div class="avaliacao-section-heading"><div><h2><?= $edit ? 'Editar treino' : 'Novo treino' ?></h2></div></div>
    <form method="post" class="formulario-treino">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_AULA'] ?>"><?php endif; ?>
        <label>Turma<select required name="cod_turma" id="turmaTreino"><option value="">Selecione uma turma</option><?php foreach ($turmas as $turma): ?><option value="<?= (int) $turma['COD_TURMA'] ?>" data-dias="<?= e(implode(',', diasDeTreino($turma['DIAS_TREINO']))) ?>" data-horario="<?= e(inicioDoTreino($turma['HORARIO']) ? $turma['HORARIO'] : '') ?>" <?= ($edit['COD_TURMA'] ?? '') == $turma['COD_TURMA'] ? 'selected' : '' ?>><?= e($turma['NOME']) ?></option><?php endforeach; ?></select></label>
        <label>Horário da turma<input id="horarioTreino" readonly placeholder="Selecione a turma"></label>
        <label>Mês<input required type="month" id="mesTreino" value="<?= e(substr($edit['DATA_AULA'] ?? date('Y-m-d'), 0, 7)) ?>"></label>
        <label>Data do treino<select required name="data_aula" id="dataTreino" data-atual="<?= e($edit['DATA_AULA'] ?? '') ?>"><option value="">Selecione a turma e o mês</option></select></label>
        <label>Tipo de treino<select name="tipo_treino" id="tipoTreino" <?= !$edit || $edit['TIPO_TREINO'] !== null ? 'required' : '' ?>><option value="" <?= $tipoAtual === '' ? 'selected' : '' ?>><?= $edit ? 'Não definido (treino antigo)' : 'Selecione' ?></option><?php foreach (avaliacaoTiposTreino() as $codigo => $tipo): ?><option value="<?= e($codigo) ?>" <?= $tipoAtual === $codigo ? 'selected' : '' ?>><?= e($tipo['nome']) ?></option><?php endforeach; ?></select></label>
        <label>Nome do treino<input required name="tema_treino" value="<?= e($edit['TEMA_TREINO'] ?? '') ?>" placeholder="Ex.: Fundamentos e coletivo"></label>
        <label>Objetivo do treino<textarea name="objetivo" rows="2" placeholder="O que a turma vai desenvolver nesta sessão?"><?= e($edit['OBJETIVO'] ?? '') ?></textarea></label>
        <div class="atributos-customizados" id="atributosPersonalizados" <?= $tipoAtual === 'PERSONALIZADO' ? '' : 'hidden' ?>>
            <label>Fundamentos do treino
                <select multiple name="atributos_avaliaveis[]" id="fundamentosTreino" size="5">
                    <?php foreach (avaliacaoCatalogoAtributos() as $codigo => $nome): ?>
                        <option value="<?= e($codigo) ?>" <?= in_array($codigo, $atributosAtuais, true) ? 'selected' : '' ?>><?= e($nome) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p>No computador, use Ctrl para selecionar mais de um fundamento.</p>
        </div>
        <label class="treino-observacao">Observações<textarea name="observacao" rows="2" placeholder="Informações para professor, atletas e responsáveis"><?= e($edit['OBSERVACAO'] ?? '') ?></textarea></label>
        <div class="treino-form-actions"><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar treino'); ?><?php if ($edit): ?><a href="aulas.php">Cancelar</a><?php endif; ?></div>
    </form>
</section>

<section class="lista-aulas-avaliacao">
    <div class="avaliacao-section-heading"><div><h2>Treinos e avaliações</h2><p>Abra uma sessão para avaliar os atletas e acompanhar os registros da semana.</p></div><a class="avaliacao-voltar-treinos" href="calendario.php">Abrir calendário <span aria-hidden="true">↗</span></a></div>
    <div class="aula-avaliacao-lista">
        <?php foreach ($lista as $aula): $tipo = avaliacaoTiposTreino()[$aula['TIPO_TREINO']]['nome'] ?? 'Tipo não definido'; ?>
            <article class="aula-avaliacao-item">
                <div class="aula-avaliacao-data"><strong><?= e(date('d', strtotime($aula['DATA_AULA']))) ?></strong><span><?= e(date('m/Y', strtotime($aula['DATA_AULA']))) ?></span></div>
                <div class="aula-avaliacao-info"><strong><?= e($aula['TEMA_TREINO']) ?></strong><span><?= e($aula['TURMA']) ?> · <?= e($tipo) ?><?= $aula['HORARIO'] ? ' · ' . e(substr((string) $aula['HORARIO'], 0, 5)) : '' ?></span><?php if (!empty($aula['OBJETIVO'])): ?><small><?= e($aula['OBJETIVO']) ?></small><?php endif; ?></div>
                <span class="aula-avaliacao-count"><?= (int) $aula['AVALIACOES'] ?> avaliados</span>
                <div class="aula-avaliacao-actions"><a class="botao-avaliar-treino" href="avaliacoes.php?aula=<?= (int) $aula['COD_AULA'] ?>"><?= (int) $aula['AVALIACOES'] ? 'Editar avaliações' : 'Avaliar treino' ?><span aria-hidden="true">↗</span></a><?php if (isset($_GET['mini'])): ?><a href="aulas.php?chamada=<?= (int) $aula['COD_AULA'] ?>&amp;mini=1">Chamada</a><a href="aulas.php?editar=<?= (int) $aula['COD_AULA'] ?>&amp;mini=1">Editar</a><?php else: ?><button type="button" class="acao-tabela" data-modal-url="aulas.php?chamada=<?= (int) $aula['COD_AULA'] ?>&amp;mini=1" data-modal-titulo="Fazer chamada">Chamada</button><button type="button" class="acao-tabela" data-modal-url="aulas.php?mini=1&amp;editar=<?= (int) $aula['COD_AULA'] ?>" data-modal-titulo="Editar treino">Editar</button>
                <form method="post" data-confirm-submit="Excluir este treino e a chamada? As avaliações serão preservadas.">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $aula['COD_AULA'] ?>">
                    <button type="submit" class="acao-tabela">Excluir treino</button>
                </form><?php endif; ?></div>
            </article>
        <?php endforeach; ?>
        <?php if (!$lista): ?><p class="avaliacoes-sem-registros">Nenhum treino registrado até agora.</p><?php endif; ?>
    </div>
</section>

<script nonce="<?= cspNonce() ?>">
    const turmaTreino = document.getElementById('turmaTreino');
    const mesTreino = document.getElementById('mesTreino');
    const dataTreino = document.getElementById('dataTreino');
    const horarioTreino = document.getElementById('horarioTreino');
    const tipoTreino = document.getElementById('tipoTreino');
    const atributos = document.getElementById('atributosPersonalizados');
    const fundamentos = document.getElementById('fundamentosTreino');
    const temaTreino = document.querySelector('[name="tema_treino"]');
    const formatarDia = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'full', timeZone: 'UTC' });

    function atualizarDatas(dataSelecionada) {
        const turma = turmaTreino.selectedOptions[0];
        horarioTreino.value = turma.dataset.horario || '';
        dataTreino.replaceChildren(new Option('Selecione uma data', ''));
        dataTreino.disabled = !turma.dataset.dias || !horarioTreino.value || !mesTreino.value;
        if (dataTreino.disabled) return;

        const dias = turma.dataset.dias.split(',').map(Number);
        const data = new Date(mesTreino.value + '-01T00:00:00Z');
        const mes = data.getUTCMonth();
        while (data.getUTCMonth() === mes) {
            if (dias.includes(data.getUTCDay())) {
                const valor = data.toISOString().slice(0, 10);
                dataTreino.add(new Option(formatarDia.format(data), valor, false, valor === dataSelecionada));
            }
            data.setUTCDate(data.getUTCDate() + 1);
        }
    }

    turmaTreino.addEventListener('change', () => atualizarDatas(dataTreino.value));
    mesTreino.addEventListener('change', () => atualizarDatas(''));
    atualizarDatas(dataTreino.dataset.atual);

    let sugestao = '';
    function atualizarFundamentos() {
        const personalizado = tipoTreino.value === 'PERSONALIZADO';
        atributos.hidden = !personalizado;
        fundamentos.disabled = !personalizado;
        fundamentos.required = personalizado;
        const nome = personalizado ? Array.from(fundamentos.selectedOptions, item => item.text).join(' + ') : '';
        if (!temaTreino.value || temaTreino.value === sugestao) temaTreino.value = nome;
        sugestao = nome;
    }
    tipoTreino.addEventListener('change', atualizarFundamentos);
    fundamentos.addEventListener('change', atualizarFundamentos);
    atualizarFundamentos();
</script>
<?php pageEnd(); ?>
