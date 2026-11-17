<?php
// Registra os dados do responsável e cria ou atualiza o acesso vinculado.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
checkRole(['ADMIN', 'SECRETARIA']);
$db = getDB();
$escolinha = currentEscolinhaId();
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach (['nome', 'cpf', 'telefone', 'email', 'endereco', 'senha'] as $campo) {
        if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
            flash('error', 'Revise os campos informados.');
            go('responsaveis.php');
        }
    }
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
    $cpfFormatado = cpfFormat($_POST['cpf'] ?? '');
    $telefone = preg_replace('/\D/', '', $_POST['telefone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $endereco = trim($_POST['endereco'] ?? '');
    if ($nome === '' || mb_strlen($nome) > 100 || strlen($email) > 100 || mb_strlen($endereco) > 255 || $cpfFormatado === '' || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        flash('error', 'Informe nome, CPF válido e e-mail válido quando preenchido.');
        go('responsaveis.php' . ($id ? '?editar=' . $id : ''));
    }
    if ($telefone !== '' && !in_array(strlen($telefone), [10, 11], true)) {
        flash('error', 'Informe o telefone com DDD e 8 ou 9 dígitos.');
        go('responsaveis.php' . ($id ? '?editar=' . $id : ''));
    }
    if (!is_string($senha) || ($senha !== '' && ($email === '' || strlen($senha) < 6 || strlen($senha) > 72 || strpos($senha, "\0") !== false))) {
        flash('error', 'Para criar ou trocar a senha, informe um e-mail válido e uma senha com pelo menos 6 caracteres.');
        go('responsaveis.php' . ($id ? '?editar=' . $id : ''));
    }
    if ($telefone !== '') {
        $telefone = '(' . substr($telefone, 0, 2) . ') ' . substr($telefone, 2, -4) . '-' . substr($telefone, -4);
    }
    try {
        if (!in_array($action, ['create', 'update'], true)) {
            throw new InvalidArgumentException('Ação inválida.');
        }
        // O cadastro e a conta de acesso são gravados juntos.
        $db->beginTransaction();
        $usuario = null;
        if ($action === 'update') {
            $q = $db->prepare('SELECT COD_USUARIO FROM TB_RESPONSAVEL WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ? FOR UPDATE');
            $q->execute([$id, $escolinha]);
            $responsavel = $q->fetch();
            if (!$responsavel) {
                throw new InvalidArgumentException('Responsável não encontrado.');
            }
            $usuario = $responsavel['COD_USUARIO'];
        }
        if ($usuario) {
            if ($email === '') {
                throw new InvalidArgumentException('Informe o e-mail usado para entrar no sistema.');
            }
            $q = $db->prepare("SELECT 1 FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ? AND PERFIL = 'RESPONSAVEL' FOR UPDATE");
            $q->execute([$usuario, $escolinha]);
            if (!$q->fetchColumn()) {
                throw new InvalidArgumentException('O acesso vinculado a este responsável precisa ser corrigido pelo administrador.');
            }
            if ($senha !== '') {
                $q = $db->prepare('UPDATE TB_USUARIO SET NOME = ?, EMAIL = ?, SENHA_HASH = ? WHERE COD_USUARIO = ?');
                $q->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $usuario]);
            } else {
                $q = $db->prepare('UPDATE TB_USUARIO SET NOME = ?, EMAIL = ? WHERE COD_USUARIO = ?');
                $q->execute([$nome, $email, $usuario]);
            }
        } elseif ($senha !== '') {
            $q = $db->prepare("INSERT INTO TB_USUARIO (COD_ESCOLINHA, NOME, EMAIL, SENHA_HASH, PERFIL, ATIVO) VALUES (?, ?, ?, ?, 'RESPONSAVEL', 1)");
            $q->execute([$escolinha, $nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
            $usuario = (int) $db->lastInsertId();
        }
        if ($action === 'create') {
            $stmt = $db->prepare('INSERT INTO TB_RESPONSAVEL (COD_ESCOLINHA, COD_USUARIO, NOME, CPF, TELEFONE, EMAIL, ENDERECO) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$escolinha, $usuario, $nome, $cpfFormatado, $telefone ?: null, $email ?: null, $endereco ?: null]);
        } else {
            $stmt = $db->prepare('UPDATE TB_RESPONSAVEL SET COD_USUARIO = ?, NOME = ?, CPF = ?, TELEFONE = ?, EMAIL = ?, ENDERECO = ? WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?');
            $stmt->execute([$usuario, $nome, $cpfFormatado, $telefone ?: null, $email ?: null, $endereco ?: null, $id, $escolinha]);
        }
        $db->commit();
        flash('success', $action === 'create' ? 'Responsável cadastrado.' : 'Dados do responsável atualizados.');
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', $e->getMessage());
        go('responsaveis.php' . ($id ? '?editar=' . $id : ''));
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', (int) ($e->errorInfo[1] ?? 0) === 1062
            ? 'CPF ou e-mail de acesso já cadastrado.'
            : 'Não foi possível salvar o responsável.');
        go('responsaveis.php' . ($id ? '?editar=' . $id : ''));
    }
    voltarAposSalvarCadastro('responsaveis.php');
}
if (isset($_GET['editar'])) {
    $stmt = $db->prepare("SELECT r.*, u.EMAIL AS EMAIL_ACESSO FROM TB_RESPONSAVEL r
        LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = r.COD_USUARIO AND u.COD_ESCOLINHA = r.COD_ESCOLINHA AND u.PERFIL = 'RESPONSAVEL'
        WHERE r.COD_RESPONSAVEL = ? AND r.COD_ESCOLINHA = ?");
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
<section><h2><?= $edit ? 'Editar responsável' : 'Novo responsável' ?></h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_RESPONSAVEL'] ?>"><?php endif; ?><label>Nome completo<input required name="nome" value="<?= $v('NOME') ?>"></label><label>CPF<input required name="cpf" inputmode="numeric" placeholder="000.000.000-00" maxlength="14" value="<?= $v('CPF') ?>"></label><label>Telefone<input type="tel" name="telefone" placeholder="(17) 99999-9999" maxlength="15" value="<?= $v('TELEFONE') ?>"></label><label>E-mail de acesso<input type="email" name="email" autocomplete="off" value="<?= e($edit['EMAIL_ACESSO'] ?? $edit['EMAIL'] ?? '') ?>"></label>
<label><?= !empty($edit['EMAIL_ACESSO']) ? 'Nova senha (deixe em branco para manter)' : 'Senha inicial (opcional)' ?>
    <input type="password" name="senha" minlength="6" autocomplete="new-password">
</label><label>Endereço (opcional)<input name="endereco" placeholder="Ex.: Rua Brasil" value="<?= $v('ENDERECO') ?>"></label><div><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar responsável'); ?><?php if ($edit): ?><a href="responsaveis.php">Cancelar</a><?php endif; ?></div></form></section>
<section><div><table><thead><tr><th>Responsável</th><th>CPF</th><th>Contato</th><th>Alunos</th><th>Ações</th></tr></thead><tbody><?php foreach ($lista as $r): ?><tr><td><?= e($r['NOME']) ?></td><td><?= e($r['CPF']) ?></td><td><?= e($r['TELEFONE']) ?><br><?= e($r['EMAIL']) ?></td><td><?= (int) $r['TOTAL_ALUNOS'] ?></td><td><button type="button" class="acao-tabela" data-modal-url="responsaveis.php?mini=1&amp;editar=<?= (int) $r['COD_RESPONSAVEL'] ?>" data-modal-titulo="Editar responsável">Editar</button><form method="post" data-confirm-submit="Excluir este responsável?"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['COD_RESPONSAVEL'] ?>"><button>Excluir</button></form></td></tr><?php endforeach; ?><?php if (!$lista): ?><tr><td colspan="5">Nenhum responsável cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<script nonce="<?= cspNonce() ?>">
    (() => {
        const cpf = document.querySelector('input[name=cpf]');
        const telefone = document.querySelector('input[name=telefone]');

        function formatarCpf() {
            const digitos = cpf.value.replace(/\D/g, '').slice(0, 11);
            cpf.value = digitos.replace(/^(\d{3})(?=\d)/, '$1.')
                .replace(/^(\d{3}\.\d{3})(?=\d)/, '$1.')
                .replace(/^(\d{3}\.\d{3}\.\d{3})(?=\d)/, '$1-');
        }

        function formatarTelefone() {
            const digitos = telefone.value.replace(/\D/g, '').slice(0, 11);
            if (!digitos) return;
            const corte = digitos.length === 11 ? 7 : 6;
            telefone.value = '(' + digitos.slice(0, 2) + (digitos.length > 2 ? ') ' : '')
                + digitos.slice(2, corte) + (digitos.length > corte ? '-' + digitos.slice(corte) : '');
        }

        cpf.addEventListener('input', formatarCpf);
        telefone.addEventListener('input', formatarTelefone);
        formatarCpf();
        formatarTelefone();
    })();
</script>
<?php pageEnd(); ?>
