<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/avaliacao_helpers.php';
checkRole(['ADMIN', 'PROFESSOR']);

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
    $acao = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($acao === 'attendance') {
        $aula = aulaDaEscolinha($db, $id, $escolinha, $professor);
        if (!$aula) {
            flash('error', 'Aula não encontrada.');
            go('aulas.php');
        }
        $alunos = $_POST['alunos'] ?? [];
        $salvar = $db->prepare(
            'INSERT INTO TB_PRESENCA (COD_AULA, COD_ALUNO, PRESENTE, JUSTIFICATIVA) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE PRESENTE = VALUES(PRESENTE), JUSTIFICATIVA = VALUES(JUSTIFICATIVA)'
        );
        foreach ($alunos as $codigo => $dados) {
            $salvar->execute([$id, (int) $codigo, isset($dados['presente']) ? 1 : 0, trim($dados['justificativa'] ?? '') ?: null]);
        }
        flash('success', 'Chamada registrada.');
        go('aulas.php?chamada=' . $id);
    }

    if ($acao === 'delete') {
        if (aulaDaEscolinha($db, $id, $escolinha, $professor)) {
            $db->prepare('DELETE FROM TB_AULA WHERE COD_AULA = ?')->execute([$id]);
            flash('success', 'Aula excluída. As avaliações vinculadas serão preservadas no histórico.');
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
    $observacao = trim($_POST['observacao'] ?? '');
    $tipo = $_POST['tipo_treino'] ?? '';
    $tiposValidos = avaliacaoTiposTreino();
    $customizados = avaliacaoAtributosPersonalizados($_POST['atributos_avaliaveis'] ?? []);
    $tipoValido = isset($tiposValidos[$tipo])
        || ($acao === 'update' && $tipo === '' && $aulaAtual['TIPO_TREINO'] === null);

    $turmaSql = 'SELECT 1 FROM TB_TURMA WHERE COD_TURMA = ? AND COD_ESCOLINHA = ?';
    $argumentosTurma = [$turma, $escolinha];
    if ($professor) {
        $turmaSql .= ' AND COD_PROFESSOR = ?';
        $argumentosTurma[] = $professor;
    }
    $consulta = $db->prepare($turmaSql);
    $consulta->execute($argumentosTurma);

    if (!$consulta->fetchColumn() || !$data || !$tema || !$tipoValido
        || ($acao === 'create' && $tipo === '')
        || ($tipo === 'PERSONALIZADO' && !$customizados)) {
        flash('error', 'Revise turma, data, tema e tipo do treino. Treinos personalizados precisam de ao menos um atributo.');
        go('aulas.php' . (isset($_GET['mini']) ? '?mini=1' : ''));
    }

    $atributosJson = $tipo === 'PERSONALIZADO' ? json_encode($customizados, JSON_UNESCAPED_UNICODE) : null;
    if ($acao === 'create') {
        $db->prepare(
            'INSERT INTO TB_AULA (COD_TURMA, DATA_AULA, TEMA_TREINO, TIPO_TREINO, ATRIBUTOS_AVALIAVEIS_JSON, OBSERVACAO)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$turma, $data, $tema, $tipo, $atributosJson, $observacao ?: null]);
        flash('success', 'Treino cadastrado.');
    } elseif ($acao === 'update') {
        if ($tipo === '' && $aulaAtual['TIPO_TREINO'] === null) {
            $atributosJson = $aulaAtual['ATRIBUTOS_AVALIAVEIS_JSON'];
        }
        $db->prepare(
            'UPDATE TB_AULA SET COD_TURMA = ?, DATA_AULA = ?, TEMA_TREINO = ?, TIPO_TREINO = ?,
             ATRIBUTOS_AVALIAVEIS_JSON = ?, OBSERVACAO = ? WHERE COD_AULA = ?'
        )->execute([$turma, $data, $tema, $tipo ?: null, $atributosJson, $observacao ?: null, $id]);
        flash('success', 'Treino atualizado.');
    }
    go('aulas.php');
}

if (isset($_GET['editar'])) {
    $edit = aulaDaEscolinha($db, (int) $_GET['editar'], $escolinha, $professor);
    if (!$edit) {
        flash('error', 'Aula não encontrada.');
        go('aulas.php');
    }
}

$consulta = $db->prepare('SELECT COD_TURMA, NOME FROM TB_TURMA WHERE COD_ESCOLINHA = ? AND ATIVA = 1' . ($professor ? ' AND COD_PROFESSOR = ?' : '') . ' ORDER BY NOME');
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
         WHERE m.COD_TURMA = ? AND m.STATUS = 'ATIVA' ORDER BY a.NOME"
    );
    $consulta->execute([$aula['COD_AULA'], $aula['COD_TURMA']]);
    $alunos = $consulta->fetchAll();
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
    <div class="avaliacao-section-heading"><div><p class="avaliacao-eyebrow">PLANEJAMENTO</p><h2><?= $edit ? 'Editar treino' : 'Novo treino' ?></h2></div><span class="avaliacao-heading-icon" aria-hidden="true">⚽</span></div>
    <form method="post" class="formulario-treino">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_AULA'] ?>"><?php endif; ?>
        <label>Turma<select required name="cod_turma"><option value="">Selecione uma turma</option><?php foreach ($turmas as $turma): ?><option value="<?= (int) $turma['COD_TURMA'] ?>" <?= ($edit['COD_TURMA'] ?? '') == $turma['COD_TURMA'] ? 'selected' : '' ?>><?= e($turma['NOME']) ?></option><?php endforeach; ?></select></label>
        <label>Data<input required type="date" name="data_aula" value="<?= e($edit['DATA_AULA'] ?? date('Y-m-d')) ?>"></label>
        <label>Tema do treino<input required name="tema_treino" value="<?= e($edit['TEMA_TREINO'] ?? '') ?>" placeholder="Ex.: Finalização + passe"></label>
        <label>Tipo de treino<select name="tipo_treino" id="tipoTreino" <?= !$edit || $edit['TIPO_TREINO'] !== null ? 'required' : '' ?>><option value="" <?= $tipoAtual === '' ? 'selected' : '' ?>><?= $edit ? 'Não definido (treino antigo)' : 'Selecione' ?></option><?php foreach (avaliacaoTiposTreino() as $codigo => $tipo): ?><option value="<?= e($codigo) ?>" <?= $tipoAtual === $codigo ? 'selected' : '' ?>><?= e($tipo['nome']) ?></option><?php endforeach; ?></select></label>
        <div class="atributos-customizados" id="atributosPersonalizados" <?= $tipoAtual === 'PERSONALIZADO' ? '' : 'hidden' ?>><strong>Atributos avaliáveis neste treino personalizado</strong><p>Marque o que será avaliado. Os outros atributos aparecerão como “Não avaliado”.</p><div class="avaliacao-check-grid"><?php foreach (avaliacaoCatalogoAtributos() as $codigo => $nome): ?><label><input type="checkbox" name="atributos_avaliaveis[]" value="<?= e($codigo) ?>" <?= in_array($codigo, $atributosAtuais, true) ? 'checked' : '' ?>><span><?= e($nome) ?></span></label><?php endforeach; ?></div></div>
        <label class="treino-observacao">Observação<textarea name="observacao"><?= e($edit['OBSERVACAO'] ?? '') ?></textarea></label>
        <div class="treino-form-actions"><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar treino'); ?><?php if ($edit): ?><a href="aulas.php">Cancelar</a><?php endif; ?></div>
    </form>
</section>

<section class="lista-aulas-avaliacao">
    <div class="avaliacao-section-heading"><div><p class="avaliacao-eyebrow">TREINOS REGISTRADOS</p><h2>Treinos e avaliações</h2><p>Abra uma sessão para avaliar os atletas e acompanhar os registros da semana.</p></div></div>
    <div class="aula-avaliacao-lista">
        <?php foreach ($lista as $aula): $tipo = avaliacaoTiposTreino()[$aula['TIPO_TREINO']]['nome'] ?? 'Tipo não definido'; ?>
            <article class="aula-avaliacao-item">
                <div class="aula-avaliacao-data"><strong><?= e(date('d', strtotime($aula['DATA_AULA']))) ?></strong><span><?= e(date('m/Y', strtotime($aula['DATA_AULA']))) ?></span></div>
                <div class="aula-avaliacao-info"><strong><?= e($aula['TEMA_TREINO']) ?></strong><span><?= e($aula['TURMA']) ?> · <?= e($tipo) ?></span></div>
                <span class="aula-avaliacao-count"><?= (int) $aula['AVALIACOES'] ?> avaliados</span>
                <div class="aula-avaliacao-actions"><a class="botao-avaliar-treino" href="avaliacoes.php?aula=<?= (int) $aula['COD_AULA'] ?>"><?= (int) $aula['AVALIACOES'] ? 'Editar avaliações' : 'Avaliar treino' ?><span aria-hidden="true">↗</span></a><?php if (isset($_GET['mini'])): ?><a href="aulas.php?chamada=<?= (int) $aula['COD_AULA'] ?>&amp;mini=1">Chamada</a><a href="aulas.php?editar=<?= (int) $aula['COD_AULA'] ?>&amp;mini=1">Editar</a><?php else: ?><button type="button" class="acao-tabela" data-modal-url="aulas.php?chamada=<?= (int) $aula['COD_AULA'] ?>&amp;mini=1" data-modal-titulo="Fazer chamada">Chamada</button><button type="button" class="acao-tabela" data-modal-url="aulas.php?mini=1&amp;editar=<?= (int) $aula['COD_AULA'] ?>" data-modal-titulo="Editar treino">Editar</button><?php endif; ?></div>
            </article>
        <?php endforeach; ?>
        <?php if (!$lista): ?><p class="avaliacoes-sem-registros">Nenhum treino registrado até agora.</p><?php endif; ?>
    </div>
</section>

<?php if (!isset($_GET['mini'])): ?>
    <dialog class="modal-cadastro" id="modalAulas" aria-labelledby="tituloModalAulas"><header class="modal-cabecalho"><h2 id="tituloModalAulas">Treinos</h2><button type="button" class="fechar-modal" id="fecharModalAulas" aria-label="Fechar">×</button></header><iframe id="iframeAulas" title="Chamada ou edição do treino"></iframe></dialog>
    <script>
        const tipoTreino = document.getElementById('tipoTreino');
        const atributosPersonalizados = document.getElementById('atributosPersonalizados');
        if (tipoTreino && atributosPersonalizados) {
            tipoTreino.addEventListener('change', function () {
                atributosPersonalizados.hidden = tipoTreino.value !== 'PERSONALIZADO';
            });
        }
        const modalAulas = document.getElementById('modalAulas');
        const iframeAulas = document.getElementById('iframeAulas');
        const tituloModalAulas = document.getElementById('tituloModalAulas');
        document.addEventListener('click', function (evento) {
            const botao = evento.target.closest('[data-modal-url]');
            if (!botao) return;
            iframeAulas.src = botao.dataset.modalUrl;
            tituloModalAulas.textContent = botao.dataset.modalTitulo;
            modalAulas.showModal();
        });
        document.getElementById('fecharModalAulas').addEventListener('click', function () { modalAulas.close(); });
        modalAulas.addEventListener('close', function () { iframeAulas.src = 'about:blank'; });
    </script>
<?php endif; ?>
<?php pageEnd(); ?>
