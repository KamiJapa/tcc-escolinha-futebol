<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $senha === '') {
        $erro = 'Digite um e-mail válido e a senha.';
    } elseif (loginUser($email, $senha)) {
        header('Location: index.php');
        exit;
    } else {
        $erro = 'E-mail ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar - GestorFC</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-fundo">
    <header class="login-cabecalho">
        <h1>GestorFC</h1>
        <p>Sistema de gestão para escolinhas de futebol</p>
    </header>

    <main class="login-caixa">
        <?php showFlash(); ?>
        <?php if ($erro !== ''): ?><p class="mensagem-erro"><?= e($erro) ?></p><?php endif; ?>

        <form method="post" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autocomplete="username">

            <label for="senha">Senha</label>
            <div class="campo-senha">
                <input type="password" id="senha" name="senha" required autocomplete="current-password">
                <button type="button" class="botao-olho" onclick="mostrarSenha()">Mostrar</button>
            </div>

            <button class="botao-principal" type="submit">Entrar</button>
        </form>

        <p class="login-cadastro-link">Ainda não tem uma conta? <a href="cadastro.php">Cadastre-se</a></p>

        <section class="login-testes">
            <h2>Contas para teste</h2>
            <p>Senha das contas: <strong>123456</strong></p>
            <ul>
                <li>Administrador: admin@teste.com</li>
                <li>Secretaria: secretaria@teste.com</li>
                <li>Professor: professor@teste.com</li>
                <li>Responsável: responsavel@teste.com</li>
            </ul>
        </section>
    </main>

    <script>
        function mostrarSenha() {
            const campo = document.getElementById('senha');
            campo.type = campo.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>
