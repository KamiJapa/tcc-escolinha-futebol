<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/config/database.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$erro = '';
$nome = '';
$email = '';
$cpf = '';
$telefone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $cpf = trim($_POST['cpf'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $confirmacao = $_POST['confirmacao'] ?? '';

    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $cpf === '') {
        $erro = 'Preencha o nome, um e-mail válido e o CPF.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha precisa ter pelo menos 6 caracteres.';
    } elseif ($senha !== $confirmacao) {
        $erro = 'As senhas digitadas são diferentes.';
    } else {
        $db = getDB();
        try {
            $db->beginTransaction();

            // A conta de cadastro público sempre começa com o perfil RESPONSAVEL.
            $escolinha = $db->query('SELECT COD_ESCOLINHA FROM TB_ESCOLINHA ORDER BY COD_ESCOLINHA LIMIT 1')->fetchColumn();
            if (!$escolinha) {
                throw new RuntimeException('Importe database.sql antes de cadastrar usuários.');
            }

            $criarUsuario = $db->prepare(
                'INSERT INTO TB_USUARIO (COD_ESCOLINHA, NOME, EMAIL, SENHA_HASH, PERFIL, ATIVO) VALUES (?, ?, ?, ?, ?, 1)'
            );
            $criarUsuario->execute([
                $escolinha,
                $nome,
                $email,
                password_hash($senha, PASSWORD_DEFAULT),
                'RESPONSAVEL'
            ]);
            $usuario = $db->lastInsertId();

            $criarResponsavel = $db->prepare(
                'INSERT INTO TB_RESPONSAVEL (COD_ESCOLINHA, COD_USUARIO, NOME, CPF, TELEFONE, EMAIL) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $criarResponsavel->execute([
                $escolinha,
                $usuario,
                $nome,
                $cpf,
                $telefone ?: null,
                $email
            ]);

            $db->commit();
            flash('success', 'Cadastro realizado. Entre usando seu e-mail e senha.');
            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ($e->getCode() === '23000') {
                $erro = 'Este e-mail ou CPF já está cadastrado.';
            } else {
                error_log('Falha no cadastro: ' . $e->getMessage());
                $erro = 'Não foi possível concluir o cadastro. Verifique o banco de dados.';
            }
        } catch (RuntimeException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $erro = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de responsável - GestorFC</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-fundo">
    <header class="login-cabecalho">
        <h1>Cadastro de responsável</h1>
        <p>Crie sua conta para acessar o sistema</p>
    </header>

    <main class="login-caixa">
        <?php if ($erro !== ''): ?><p class="mensagem-erro"><?= e($erro) ?></p><?php endif; ?>
        <form method="post" action="cadastro.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label for="nome">Nome completo</label>
            <input id="nome" name="nome" value="<?= e($nome) ?>" required maxlength="100">

            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" value="<?= e($email) ?>" required maxlength="100">

            <label for="cpf">CPF</label>
            <input id="cpf" name="cpf" value="<?= e($cpf) ?>" required maxlength="14" placeholder="000.000.000-00">

            <label for="telefone">Telefone</label>
            <input id="telefone" name="telefone" value="<?= e($telefone) ?>" maxlength="20">

            <label for="senha">Senha (mínimo 6 caracteres)</label>
            <input type="password" id="senha" name="senha" required minlength="6" autocomplete="new-password">

            <label for="confirmacao">Confirme a senha</label>
            <input type="password" id="confirmacao" name="confirmacao" required minlength="6" autocomplete="new-password">

            <button class="botao-principal" type="submit">Criar conta</button>
        </form>
        <p class="login-cadastro-link"><a href="login.php">Voltar para o login</a></p>
    </main>
</body>
</html>
