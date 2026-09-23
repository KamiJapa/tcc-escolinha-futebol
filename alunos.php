<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';

checkRole(['ADMIN', 'SECRETARIA']);
$db = getDB();
$escolinha = currentEscolinhaId();
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $stmt = $db->prepare('DELETE FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
        $stmt->execute([$id, $escolinha]);
        flash('success', 'Aluno excluído com sucesso.');
        go('alunos.php');
    }

    $nome = trim($_POST['nome'] ?? '');
    $nascimento = $_POST['data_nascimento'] ?? '';
    $responsavel = (int) ($_POST['cod_responsavel'] ?? 0);
    $posicao = trim($_POST['posicao'] ?? '');
    $numero = trim($_POST['numero_camisa'] ?? '');
    $status = $_POST['status'] ?? 'ATIVO';
    $observacoes = trim($_POST['observacoes_medicas'] ?? '');

    if ($nome === '' || $nascimento === '' || $responsavel <= 0 || strtotime($nascimento) > time() || !in_array($status, ['ATIVO', 'INATIVO', 'PENDENTE'], true)) {
        flash('error', 'Revise os campos obrigatórios e a data de nascimento.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }

    $validResponsavel = $db->prepare('SELECT 1 FROM TB_RESPONSAVEL WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?');
    $validResponsavel->execute([$responsavel, $escolinha]);
    if (!$validResponsavel->fetchColumn()) {
        flash('error', 'O responsável selecionado não pertence a esta escolinha.');
        go('alunos.php');
    }

    $numero = $numero === '' ? null : (int) $numero;
    if ($action === 'create') {
        $stmt = $db->prepare('INSERT INTO TB_ALUNO (COD_ESCOLINHA, COD_RESPONSAVEL, NOME, DATA_NASCIMENTO, POSICAO, NUMERO_CAMISA, STATUS, DATA_MATRICULA, OBSERVACOES_MEDICAS) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)');
        $stmt->execute([$escolinha, $responsavel, $nome, $nascimento, $posicao ?: 'Não definida', $numero, $status, $observacoes ?: null]);
        flash('success', 'Aluno cadastrado. Agora faça a matrícula em uma turma.');
    } elseif ($action === 'update') {
        $stmt = $db->prepare('UPDATE TB_ALUNO SET COD_RESPONSAVEL = ?, NOME = ?, DATA_NASCIMENTO = ?, POSICAO = ?, NUMERO_CAMISA = ?, STATUS = ?, OBSERVACOES_MEDICAS = ? WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
        $stmt->execute([$responsavel, $nome, $nascimento, $posicao ?: 'Não definida', $numero, $status, $observacoes ?: null, $id, $escolinha]);
        flash('success', 'Dados do aluno atualizados.');
    }
    go('alunos.php');
}

if (isset($_GET['editar'])) {
    $stmt = $db->prepare('SELECT * FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
    $stmt->execute([(int) $_GET['editar'], $escolinha]);
    $edit = $stmt->fetch();
    if (!$edit) {
        flash('error', 'Aluno não encontrado.');
        go('alunos.php');
    }
}

$responsaveis = $db->prepare('SELECT COD_RESPONSAVEL, NOME FROM TB_RESPONSAVEL WHERE COD_ESCOLINHA = ? ORDER BY NOME');
$responsaveis->execute([$escolinha]);
$responsaveis = $responsaveis->fetchAll();
$lista = $db->prepare('SELECT a.*, r.NOME AS RESPONSAVEL, COUNT(m.COD_MATRICULA) AS TURMAS_ATIVAS FROM TB_ALUNO a JOIN TB_RESPONSAVEL r ON r.COD_RESPONSAVEL = a.COD_RESPONSAVEL LEFT JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = "ATIVA" WHERE a.COD_ESCOLINHA = ? GROUP BY a.COD_ALUNO ORDER BY a.NOME');
$lista->execute([$escolinha]);
$lista = $lista->fetchAll();

pageStart('Alunos');
$value = function ($field, $default = '') use ($edit) { return e($edit[$field] ?? $default); };
?>
<section>
    <h2><?= $edit ? 'Editar aluno' : 'Novo aluno' ?></h2>
    <?php if (!$responsaveis): ?><p>Cadastre um responsável antes de cadastrar um aluno.</p><?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_ALUNO'] ?>"><?php endif; ?>
        <label>Nome completo<input required name="nome" value="<?= $value('NOME') ?>"></label>
        <label>Data de nascimento<input required type="date" name="data_nascimento" value="<?= $value('DATA_NASCIMENTO') ?>"></label>
        <label>Responsável<select required name="cod_responsavel"><option value="">Selecione</option><?php foreach ($responsaveis as $r): ?><option value="<?= (int) $r['COD_RESPONSAVEL'] ?>" <?= ($edit['COD_RESPONSAVEL'] ?? '') == $r['COD_RESPONSAVEL'] ? 'selected' : '' ?>><?= e($r['NOME']) ?></option><?php endforeach; ?></select></label>
        <label>Posição<input name="posicao" value="<?= $value('POSICAO', 'Meio-Campo') ?>"></label>
        <label>Número da camisa<input type="number" min="0" max="99" name="numero_camisa" value="<?= $value('NUMERO_CAMISA') ?>"></label>
        <label>Situação<select name="status"><?php foreach (['ATIVO', 'INATIVO', 'PENDENTE'] as $status): ?><option <?= ($edit['STATUS'] ?? 'ATIVO') === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></label>
        <label>Observações médicas<textarea name="observacoes_medicas"><?= $value('OBSERVACOES_MEDICAS') ?></textarea></label>
        <div><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar aluno'); ?><?php if ($edit): ?><a href="alunos.php">Cancelar</a><?php endif; ?></div>
    </form>
    <?php endif; ?>
</section>
<section><div><table><thead><tr><th>Aluno</th><th>Responsável</th><th>Situação</th><th>Turmas</th><th>Ações</th></tr></thead><tbody><?php foreach ($lista as $aluno): ?><tr><td><?= e($aluno['NOME']) ?></td><td><?= e($aluno['RESPONSAVEL']) ?></td><td><?= e($aluno['STATUS']) ?></td><td><?= (int) $aluno['TURMAS_ATIVAS'] ?></td><td><a href="alunos.php?editar=<?= (int) $aluno['COD_ALUNO'] ?>">Editar</a><form method="post" onsubmit="return confirm('Excluir este aluno? As matrículas e presenças relacionadas serão removidas.');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $aluno['COD_ALUNO'] ?>"><button>Excluir</button></form></td></tr><?php endforeach; ?><?php if (!$lista): ?><tr><td colspan="5">Nenhum aluno cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<?php pageEnd(); ?>
