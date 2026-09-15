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
        try {
            $stmt = $db->prepare('DELETE FROM TB_RESPONSAVEL WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?');
            $stmt->execute([$id, $escolinha]);
            flash('success', 'Responsável excluído.');
        } catch (PDOException $e) {
            flash('error', 'Não é possível excluir um responsável que possui alunos cadastrados.');
        }
        go('responsaveis.php');
    }
    $nome = trim($_POST['nome'] ?? '');
    $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $endereco = trim($_POST['endereco'] ?? '');
    if ($nome === '' || strlen($cpf) !== 11 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        flash('error', 'Informe nome, CPF com 11 dígitos e e-mail válido quando preenchido.');
        go('responsaveis.php' . ($id ? '?editar=' . $id : ''));
    }
    $cpfFormatado = substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9);
    try {
        if ($action === 'create') {
            $stmt = $db->prepare('INSERT INTO TB_RESPONSAVEL (COD_ESCOLINHA, NOME, CPF, TELEFONE, EMAIL, ENDERECO) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$escolinha, $nome, $cpfFormatado, $telefone ?: null, $email ?: null, $endereco ?: null]);
            flash('success', 'Responsável cadastrado.');
        } elseif ($action === 'update') {
            $stmt = $db->prepare('UPDATE TB_RESPONSAVEL SET NOME = ?, CPF = ?, TELEFONE = ?, EMAIL = ?, ENDERECO = ? WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?');
            $stmt->execute([$nome, $cpfFormatado, $telefone ?: null, $email ?: null, $endereco ?: null, $id, $escolinha]);
            flash('success', 'Dados do responsável atualizados.');
        }
    } catch (PDOException $e) {
        flash('error', 'CPF já cadastrado ou dados inválidos.');
    }
    go('responsaveis.php');
}
if (isset($_GET['editar'])) {
    $stmt = $db->prepare('SELECT * FROM TB_RESPONSAVEL WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?');
    $stmt->execute([(int) $_GET['editar'], $escolinha]);
    $edit = $stmt->fetch();
    if (!$edit) { flash('error', 'Responsável não encontrado.'); go('responsaveis.php'); }
}
$lista = $db->prepare('SELECT r.*, COUNT(a.COD_ALUNO) AS TOTAL_ALUNOS FROM TB_RESPONSAVEL r LEFT JOIN TB_ALUNO a ON a.COD_RESPONSAVEL = r.COD_RESPONSAVEL WHERE r.COD_ESCOLINHA = ? GROUP BY r.COD_RESPONSAVEL ORDER BY r.NOME');
$lista->execute([$escolinha]);
$lista = $lista->fetchAll();
pageStart('Responsáveis');
$v = function ($field) use ($edit) { return e($edit[$field] ?? ''); };
?>
<section class="mb-8 rounded-xl border border-stone-200 bg-white p-5 shadow-sm"><h2 class="mb-4 font-bold text-emerald-950"><?= $edit ? 'Editar responsável' : 'Novo responsável' ?></h2><form method="post" class="grid gap-4 md:grid-cols-2"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_RESPONSAVEL'] ?>"><?php endif; ?><label class="text-sm font-medium">Nome completo<input required name="nome" value="<?= $v('NOME') ?>" class="mt-1 w-full rounded border p-2"></label><label class="text-sm font-medium">CPF<input required name="cpf" maxlength="14" value="<?= $v('CPF') ?>" class="mt-1 w-full rounded border p-2"></label><label class="text-sm font-medium">Telefone<input name="telefone" value="<?= $v('TELEFONE') ?>" class="mt-1 w-full rounded border p-2"></label><label class="text-sm font-medium">E-mail<input type="email" name="email" value="<?= $v('EMAIL') ?>" class="mt-1 w-full rounded border p-2"></label><label class="text-sm font-medium md:col-span-2">Endereço<input name="endereco" value="<?= $v('ENDERECO') ?>" class="mt-1 w-full rounded border p-2"></label><div class="flex gap-3"><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar responsável'); ?><?php if ($edit): ?><a class="rounded-lg border px-4 py-2" href="responsaveis.php">Cancelar</a><?php endif; ?></div></form></section>
<section class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-emerald-950 text-white"><tr><th class="p-3">Responsável</th><th class="p-3">CPF</th><th class="p-3">Contato</th><th class="p-3">Alunos</th><th class="p-3">Ações</th></tr></thead><tbody><?php foreach ($lista as $r): ?><tr class="border-t"><td class="p-3 font-medium"><?= e($r['NOME']) ?></td><td class="p-3"><?= e($r['CPF']) ?></td><td class="p-3"><?= e($r['TELEFONE']) ?><br><?= e($r['EMAIL']) ?></td><td class="p-3"><?= (int) $r['TOTAL_ALUNOS'] ?></td><td class="flex gap-2 p-3"><a class="text-emerald-800 underline" href="responsaveis.php?editar=<?= (int) $r['COD_RESPONSAVEL'] ?>">Editar</a><form method="post" onsubmit="return confirm('Excluir este responsável?');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['COD_RESPONSAVEL'] ?>"><button class="text-rose-700 underline">Excluir</button></form></td></tr><?php endforeach; ?><?php if (!$lista): ?><tr><td colspan="5" class="p-4 text-slate-500">Nenhum responsável cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<?php pageEnd(); ?>
