<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$mostrarDemo = in_array(strtolower((string) (getenv('APP_DEMO_ACCOUNTS') ?: ($_ENV['APP_DEMO_ACCOUNTS'] ?? 'false'))), ['true', '1'], true);
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $email = trim(postString('email'));
    $senha = postString('senha');

    if (loginUser($email, $senha)) {
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
    <link rel="stylesheet" href="assets/css/tailwind.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-fundo flex min-h-screen flex-col items-center justify-center bg-slate-900 px-4 py-8 text-slate-100">
    <header class="login-cabecalho mb-7 text-center">
        <h1 class="text-4xl font-extrabold tracking-tight">Gestor<span class="text-emerald-400">FC</span></h1>
        <p class="text-slate-400">Sistema de gestão para escolinhas de futebol</p>
    </header>

    <main class="login-caixa w-full max-w-md rounded-2xl border border-slate-700 bg-slate-800 p-8 shadow-2xl">
        <?php showFlash(); ?>
        <?php if ($erro !== ''): ?><p class="mensagem-erro"><?= e($erro) ?></p><?php endif; ?>

        <form class="space-y-4" method="post" action="login.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label class="login-label" for="email">E-mail</label>
            <input class="login-input" type="email" id="email" name="email" required autocomplete="username">

            <label class="login-label" for="senha">Senha</label>
            <div class="campo-senha flex gap-2">
                <input class="login-input" type="password" id="senha" name="senha" required autocomplete="current-password">
                <button type="button" class="botao-olho" id="alternarSenha">Mostrar</button>
            </div>

            <button class="botao-principal w-full rounded-xl bg-emerald-600 px-4 py-3 font-bold text-white shadow-lg transition hover:bg-emerald-500" type="submit">Entrar</button>
        </form>

        <p class="login-cadastro-link mt-5 text-center text-sm text-slate-300">Ainda não tem uma conta? <a class="font-semibold text-emerald-400 hover:text-emerald-300" href="cadastro.php">Cadastre-se</a></p>

        <?php if ($mostrarDemo): ?>
        <section class="login-testes mt-6 border-t border-slate-700 pt-4 text-sm text-slate-300">
            <h2 class="font-bold text-white">Contas para teste</h2>
            <p>Senha das contas: <strong class="text-amber-300">123456</strong></p>
            <div class="grid gap-2 sm:grid-cols-2">
                <button class="conta-teste" type="button" data-conta="admin@teste.com"><strong>Administrador</strong><span>admin@teste.com</span></button>
                <button class="conta-teste" type="button" data-conta="secretaria@teste.com"><strong>Secretaria</strong><span>secretaria@teste.com</span></button>
                <button class="conta-teste" type="button" data-conta="professor@teste.com"><strong>Professor</strong><span>professor@teste.com</span></button>
                <button class="conta-teste" type="button" data-conta="responsavel@teste.com"><strong>Responsável</strong><span>responsavel@teste.com</span></button>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <script nonce="<?= cspNonce() ?>">
        document.getElementById("alternarSenha").addEventListener("click", mostrarSenha);
        <?php if ($mostrarDemo): ?>
        document.querySelectorAll("[data-conta]").forEach(button => {
            button.addEventListener("click", () => usarConta(button.dataset.conta));
        });
        <?php endif; ?>
        function mostrarSenha() {
            const campo = document.getElementById('senha');
            campo.type = campo.type === 'password' ? 'text' : 'password';
        }

        <?php if ($mostrarDemo): ?>
        function usarConta(email) {
            document.getElementById('email').value = email;
            document.getElementById('senha').value = '123456';
        }
        <?php endif; ?>
    </script>
</body>
</html>
