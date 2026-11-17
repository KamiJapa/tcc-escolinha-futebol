<?php
// Define a categoria, a programação, o professor e a capacidade de cada turma.
require_once __DIR__ . '/includes/layout.php'; require_once __DIR__ . '/config/database.php';
checkRole(['ADMIN', 'SECRETARIA']); $db = getDB(); $escolinha = currentEscolinhaId(); $edit = null;
$diasSemana = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 0 => 'Domingo'];
$q = $db->prepare('SELECT DISTINCT FAIXA_ETARIA FROM TB_TURMA WHERE COD_ESCOLINHA = ? ORDER BY FAIXA_ETARIA');
$q->execute([$escolinha]);
$categorias = array_values(array_unique(array_merge(['Sub-9', 'Sub-12', 'Sub-17'], $q->fetchAll(PDO::FETCH_COLUMN))));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach (['nome', 'faixa_etaria', 'horario', 'capacidade', 'ativa'] as $campo) {
        if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
            flash('error', 'Revise os campos informados.');
            go('turmas.php');
        }
    } $action = postString('action'); $id = positiveId($_POST['id'] ?? null) ?? 0;
    if (!in_array($action, ['create','update','delete'], true) || ($action !== 'create' && !$id)) {
        flash('error', 'Operação ou identificador inválido.');
        go('turmas.php');
    }
    if ($action === 'delete') { $q=$db->prepare('SELECT (SELECT COUNT(*) FROM TB_MATRICULA WHERE COD_TURMA=?)+(SELECT COUNT(*) FROM TB_AULA WHERE COD_TURMA=?)+(SELECT COUNT(*) FROM TB_JOGO WHERE COD_TURMA=?)');$q->execute([$id,$id,$id]); if($q->fetchColumn()){flash('error','Turma possui histórico vinculado. Desative-a pelo campo Turma ativa.');go('turmas.php');} try { $db->prepare('DELETE FROM TB_TURMA WHERE COD_TURMA = ? AND COD_ESCOLINHA = ?')->execute([$id, $escolinha]); flash('success', 'Turma excluída.'); } catch (PDOException $e) { flash('error', 'Não foi possível excluir a turma. Desative-a pelo campo Turma ativa.'); } go('turmas.php'); }
    $selecionados = $_POST['dias_treino'] ?? [];
    $valoresValidos = array_map('strval', array_keys($diasSemana));
    if (!is_array($selecionados) || !$selecionados || array_filter($selecionados, fn($dia) => !in_array($dia, $valoresValidos, true))) {
        flash('error', 'Selecione pelo menos um dia de treino válido.');
        go('turmas.php' . ($id ? '?editar=' . $id : ''));
    }
    $dias = implode(', ', array_intersect_key($diasSemana, array_flip($selecionados)));
    $nome = trim($_POST['nome'] ?? ''); $horario = trim($_POST['horario'] ?? ''); $capacidade = positiveId($_POST['capacidade'] ?? null) ?? 0; $professor = (int) ($_POST['cod_professor'] ?? 0); $ativa = isset($_POST['ativa']) ? 1 : 0;
    $categoria = $_POST['faixa_etaria'] ?? '';
    $novaCategoria = $_POST['nova_categoria'] ?? '';
    if ($categoria === '__nova_categoria__') {
        $faixa = is_string($novaCategoria) ? trim($novaCategoria) : '';
        if ($faixa === '' || mb_strlen($faixa) > 30 || $faixa === '__nova_categoria__') {
            flash('error', 'Informe um nome para a categoria, com até 30 caracteres.');
            go('turmas.php' . ($id ? '?editar=' . $id : ''));
        }
    } else {
        $faixa = in_array($categoria, $categorias, true) ? $categoria : '';
    }
    if (mb_strlen($nome)>50 || $nome === '' || $faixa === ''
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $horario) || $capacidade < 1 || $capacidade > 25) {
        flash('error', 'Informe o nome, a categoria, um horário válido e capacidade de 1 a 25 alunos.');
        go('turmas.php' . ($id ? '?editar=' . $id : ''));
    }
    if ($professor) { $ok = $db->prepare("SELECT 1 FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ? AND PERFIL = 'PROFESSOR' AND ATIVO=1"); $ok->execute([$professor, $escolinha]); if (!$ok->fetchColumn()) { flash('error', 'Professor inválido.'); go('turmas.php'); } }
    if ($action === 'update') {
        $q = $db->prepare('SELECT HORARIO FROM TB_TURMA WHERE COD_TURMA=? AND COD_ESCOLINHA=?');
        $q->execute([$id, $escolinha]);
        $horarioAnterior = $q->fetchColumn();
        if ($horarioAnterior !== false && inicioDoTreino($horarioAnterior) === $horario) {
            $horario = $horarioAnterior;
        }
        $q = $db->prepare("SELECT COUNT(*) FROM TB_MATRICULA WHERE COD_TURMA=? AND STATUS='ATIVA'");
        $q->execute([$id]);
        if ((int) $q->fetchColumn() > $capacidade) { flash('error', 'A capacidade não pode ser menor que o número de matrículas ativas.'); go('turmas.php'); }
    }
    try {
    if ($action === 'create') { $db->prepare('INSERT INTO TB_TURMA (COD_ESCOLINHA, COD_PROFESSOR, NOME, FAIXA_ETARIA, DIAS_TREINO, HORARIO, CAPACIDADE, ATIVA) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$escolinha, $professor ?: null, $nome, $faixa, $dias, $horario, $capacidade, $ativa]); flash('success', 'Turma cadastrada.'); }
    if ($action === 'update') { $db->prepare('UPDATE TB_TURMA SET COD_PROFESSOR = ?, NOME = ?, FAIXA_ETARIA = ?, DIAS_TREINO = ?, HORARIO = ?, CAPACIDADE = ?, ATIVA = ? WHERE COD_TURMA = ? AND COD_ESCOLINHA = ?')->execute([$professor ?: null, $nome, $faixa, $dias, $horario, $capacidade, $ativa, $id, $escolinha]); flash('success', 'Turma atualizada.'); } } catch (PDOException $e) { flash('error','Não foi possível salvar a turma. Confira os campos e seus limites.'); }
    voltarAposSalvarCadastro('turmas.php');
}
if (isset($_GET['editar'])) { $q = $db->prepare('SELECT * FROM TB_TURMA WHERE COD_TURMA = ? AND COD_ESCOLINHA = ?'); $q->execute([(int) $_GET['editar'], $escolinha]); $edit = $q->fetch(); if (!$edit) { flash('error', 'Turma não encontrada.'); go('turmas.php'); } }
$professores = $db->prepare("SELECT COD_USUARIO, NOME FROM TB_USUARIO WHERE COD_ESCOLINHA = ? AND PERFIL = 'PROFESSOR' AND ATIVO = 1 ORDER BY NOME"); $professores->execute([$escolinha]); $professores = $professores->fetchAll();
$diasSelecionados = diasDeTreino($edit['DIAS_TREINO'] ?? '');
$q = $db->prepare("SELECT t.*, u.NOME AS PROFESSOR, COUNT(m.COD_MATRICULA) AS MATRICULADOS FROM TB_TURMA t LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = t.COD_PROFESSOR LEFT JOIN TB_MATRICULA m ON m.COD_TURMA = t.COD_TURMA AND m.STATUS = 'ATIVA' WHERE t.COD_ESCOLINHA = ? GROUP BY t.COD_TURMA ORDER BY t.NOME"); $q->execute([$escolinha]); $lista = $q->fetchAll();
pageStart('Turmas'); $v = function ($key, $default = '') use ($edit) { return e($edit[$key] ?? $default); };
?>
<section><h2><?= $edit ? 'Editar turma' : 'Nova turma' ?></h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_TURMA'] ?>"><?php endif; ?><label>Nome<input required name="nome" value="<?= $v('NOME') ?>"></label><label>Categoria<select required name="faixa_etaria" id="categoriaTurma">
    <option value="">Selecione uma categoria</option>
    <?php foreach ($categorias as $categoria): ?>
        <option value="<?= e($categoria) ?>" <?= ($edit['FAIXA_ETARIA'] ?? '') === $categoria ? 'selected' : '' ?>><?= e($categoria) ?></option>
    <?php endforeach; ?>
    <option value="__nova_categoria__">Nova categoria</option>
</select></label>
<div id="novaCategoriaTurma" hidden><label>Nome da nova categoria
    <input name="nova_categoria" maxlength="30" disabled>
</label></div><label>Dias de treino
    <select name="dias_treino[]" multiple required size="7" aria-describedby="ajudaDiasTreino">
        <?php foreach ($diasSemana as $numero => $rotulo): ?>
            <option value="<?= $numero ?>" <?= in_array($numero, $diasSelecionados, true) ? 'selected' : '' ?>><?= e($rotulo) ?></option>
        <?php endforeach; ?>
    </select>
    <small id="ajudaDiasTreino">No computador, use Ctrl ou Command para selecionar vários dias.</small>
</label><label>Horário<input required type="time" name="horario" value="<?= e(inicioDoTreino($edit['HORARIO'] ?? '') ?? '') ?>"></label><label>Capacidade<input required min="1" max="25" type="number" name="capacidade" value="<?= $v('CAPACIDADE', 25) ?>"></label><label>Professor<select name="cod_professor"><option value="">Não definido</option><?php foreach ($professores as $p): ?><option value="<?= (int) $p['COD_USUARIO'] ?>" <?= ($edit['COD_PROFESSOR'] ?? '') == $p['COD_USUARIO'] ? 'selected' : '' ?>><?= e($p['NOME']) ?></option><?php endforeach; ?></select></label><label><input type="checkbox" name="ativa" <?= ($edit['ATIVA'] ?? 1) ? 'checked' : '' ?>> Turma ativa</label><div><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar turma'); ?><?php if ($edit): ?><a href="turmas.php">Cancelar</a><?php endif; ?></div></form></section>
<section><div><table><thead><tr><th>Turma</th><th>Professor</th><th>Horário</th><th>Vagas</th><th>Ações</th></tr></thead><tbody><?php foreach ($lista as $t): ?><tr><td><?= e($t['NOME']) ?><br><span><?= e($t['FAIXA_ETARIA']) ?></span></td><td><?= e($t['PROFESSOR'] ?: 'Não definido') ?></td><td><?= e($t['DIAS_TREINO']) ?><br><?= e($t['HORARIO']) ?></td><td><?= (int) $t['MATRICULADOS'] ?>/<?= (int) $t['CAPACIDADE'] ?></td><td><button type="button" class="acao-tabela" data-modal-url="turmas.php?mini=1&amp;editar=<?= (int) $t['COD_TURMA'] ?>" data-modal-titulo="Editar turma">Editar</button><form method="post" data-confirm-submit="Excluir esta turma?"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['COD_TURMA'] ?>"><button>Excluir</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
<script nonce="<?= cspNonce() ?>">
    const categoriaTurma = document.getElementById('categoriaTurma');
    const novaCategoriaTurma = document.getElementById('novaCategoriaTurma');
    const nomeCategoriaTurma = novaCategoriaTurma.querySelector('input');
    categoriaTurma.addEventListener('change', () => {
        const nova = categoriaTurma.value === '__nova_categoria__';
        novaCategoriaTurma.hidden = !nova;
        nomeCategoriaTurma.disabled = !nova;
        nomeCategoriaTurma.required = nova;
        if (nova) nomeCategoriaTurma.focus();
    });
</script>
<?php pageEnd(); ?>
