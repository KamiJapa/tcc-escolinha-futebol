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
<section><h2><?= $edit ? 'Editar responsável' : 'Novo responsável' ?></h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_RESPONSAVEL'] ?>"><?php endif; ?><label>Nome completo<input required name="nome" value="<?= $v('NOME') ?>"></label><label>CPF<input required name="cpf" maxlength="14" value="<?= $v('CPF') ?>"></label><label>Telefone<input name="telefone" value="<?= $v('TELEFONE') ?>"></label><label>E-mail<input type="email" name="email" value="<?= $v('EMAIL') ?>"></label><label>Endereço<input name="endereco" value="<?= $v('ENDERECO') ?>"></label><div><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar responsável'); ?><?php if ($edit): ?><a href="responsaveis.php">Cancelar</a><?php endif; ?></div></form></section>
<section><div><table><thead><tr><th>Responsável</th><th>CPF</th><th>Contato</th><th>Alunos</th><th>Ações</th></tr></thead><tbody><?php foreach ($lista as $r): ?><tr><td><?= e($r['NOME']) ?></td><td><?= e($r['CPF']) ?></td><td><?= e($r['TELEFONE']) ?><br><?= e($r['EMAIL']) ?></td><td><?= (int) $r['TOTAL_ALUNOS'] ?></td><td><a href="responsaveis.php?editar=<?= (int) $r['COD_RESPONSAVEL'] ?>">Editar</a><form method="post" onsubmit="return confirm('Excluir este responsável?');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['COD_RESPONSAVEL'] ?>"><button>Excluir</button></form></td></tr><?php endforeach; ?><?php if (!$lista): ?><tr><td colspan="5">Nenhum responsável cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<?php pageEnd(); ?>
