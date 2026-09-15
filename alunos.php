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
<section class="mb-8 rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
    <h2 class="mb-4 font-bold text-emerald-950"><?= $edit ? 'Editar aluno' : 'Novo aluno' ?></h2>
    <?php if (!$responsaveis): ?><p class="rounded bg-amber-50 p-3 text-sm text-amber-900">Cadastre um responsável antes de cadastrar um aluno.</p><?php else: ?>
    <form method="post" class="grid gap-4 md:grid-cols-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_ALUNO'] ?>"><?php endif; ?>
        <label class="text-sm font-medium">Nome completo<input required name="nome" value="<?= $value('NOME') ?>" class="mt-1 w-full rounded border border-stone-300 p-2"></label>
        <label class="text-sm font-medium">Data de nascimento<input required type="date" name="data_nascimento" value="<?= $value('DATA_NASCIMENTO') ?>" class="mt-1 w-full rounded border border-stone-300 p-2"></label>
        <label class="text-sm font-medium">Responsável<select required name="cod_responsavel" class="mt-1 w-full rounded border border-stone-300 p-2"><option value="">Selecione</option><?php foreach ($responsaveis as $r): ?><option value="<?= (int) $r['COD_RESPONSAVEL'] ?>" <?= ($edit['COD_RESPONSAVEL'] ?? '') == $r['COD_RESPONSAVEL'] ? 'selected' : '' ?>><?= e($r['NOME']) ?></option><?php endforeach; ?></select></label>
        <label class="text-sm font-medium">Posição<input name="posicao" value="<?= $value('POSICAO', 'Meio-Campo') ?>" class="mt-1 w-full rounded border border-stone-300 p-2"></label>
        <label class="text-sm font-medium">Número da camisa<input type="number" min="0" max="99" name="numero_camisa" value="<?= $value('NUMERO_CAMISA') ?>" class="mt-1 w-full rounded border border-stone-300 p-2"></label>
        <label class="text-sm font-medium">Situação<select name="status" class="mt-1 w-full rounded border border-stone-300 p-2"><?php foreach (['ATIVO', 'INATIVO', 'PENDENTE'] as $status): ?><option <?= ($edit['STATUS'] ?? 'ATIVO') === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></label>
        <label class="text-sm font-medium md:col-span-2">Observações médicas<textarea name="observacoes_medicas" class="mt-1 w-full rounded border border-stone-300 p-2"><?= $value('OBSERVACOES_MEDICAS') ?></textarea></label>
        <div class="flex gap-3"><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar aluno'); ?><?php if ($edit): ?><a class="rounded-lg border px-4 py-2" href="alunos.php">Cancelar</a><?php endif; ?></div>
    </form>
    <?php endif; ?>
</section>
<section class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-emerald-950 text-white"><tr><th class="p-3">Aluno</th><th class="p-3">Responsável</th><th class="p-3">Situação</th><th class="p-3">Turmas</th><th class="p-3">Ações</th></tr></thead><tbody><?php foreach ($lista as $aluno): ?><tr class="border-t"><td class="p-3 font-medium"><?= e($aluno['NOME']) ?></td><td class="p-3"><?= e($aluno['RESPONSAVEL']) ?></td><td class="p-3"><?= e($aluno['STATUS']) ?></td><td class="p-3"><?= (int) $aluno['TURMAS_ATIVAS'] ?></td><td class="flex gap-2 p-3"><a class="text-emerald-800 underline" href="alunos.php?editar=<?= (int) $aluno['COD_ALUNO'] ?>">Editar</a><form method="post" onsubmit="return confirm('Excluir este aluno? As matrículas e presenças relacionadas serão removidas.');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $aluno['COD_ALUNO'] ?>"><button class="text-rose-700 underline">Excluir</button></form></td></tr><?php endforeach; ?><?php if (!$lista): ?><tr><td class="p-4 text-slate-500" colspan="5">Nenhum aluno cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<?php pageEnd(); ?>
