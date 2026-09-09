<?php
// login.php
require_once __DIR__ . '/includes/auth.php';

// Se já estiver logado, vai pro dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: /index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = "Por favor, preencha o e-mail e a senha.";
    } else {
        if (loginUser($email, $password)) {
            header("Location: /index.php");
            exit;
        } else {
            $error = "E-mail ou senha inválidos.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - GestorFC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #0f172a; } /* slate-900 */
        .glass-panel {
            background-color: #1e293b; /* slate-800 */
            border: 1px solid #334155; /* slate-700 */
        }
    </style>
</head>
<body class="flex flex-col items-center justify-center min-h-screen text-slate-300">
    
    <div class="mb-8 text-center">
        <h1 class="text-4xl font-extrabold text-white tracking-tight mb-2">Gestor<span class="text-emerald-500">FC</span></h1>
        <p class="text-slate-400">Gestão Estratégica para Escolinhas de Futebol</p>
    </div>

    <div class="w-full max-w-md glass-panel p-8 rounded-xl shadow-2xl">
        <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/50 text-red-500 px-4 py-3 rounded mb-6 text-sm flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="login.php" class="space-y-6">
            <div>
                <label for="email" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">E-mail de Acesso</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500">
                        <i class="fas fa-at"></i>
                    </div>
                    <input type="email" id="email" name="email" class="w-full pl-10 pr-4 py-3 bg-slate-900 border border-slate-700 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 text-white placeholder-slate-600 transition-colors" placeholder="seu.email@escolinha.com" required autofocus>
                </div>
            </div>
            
            <div>
                <div class="flex justify-between items-center mb-2">
                    <label for="password" class="block text-xs font-semibold text-slate-400 uppercase tracking-wider">Senha</label>
                    <span class="text-xs text-slate-500">Padrão teste: 123456</span>
                </div>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500">
                        <i class="fas fa-lock"></i>
                    </div>
                    <input type="password" id="password" name="password" class="w-full pl-10 pr-10 py-3 bg-slate-900 border border-slate-700 rounded-lg focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 text-white placeholder-slate-600 transition-colors" placeholder="••••••••" required>
                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center cursor-pointer text-slate-500 hover:text-slate-300" onclick="togglePassword()">
                        <i class="fas fa-eye" id="eye-icon"></i>
                    </div>
                </div>
            </div>
            
            <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-bold py-3 px-4 rounded-lg shadow-lg transition-colors focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 focus:ring-offset-slate-900">
                Entrar no Sistema
            </button>
            
            <div class="text-center mt-4">
                <a href="#" class="text-sm text-emerald-500 hover:text-emerald-400 transition-colors">
                    <i class="fas fa-star mr-1"></i> Não tem uma conta? Cadastre sua Escolinha &rarr;
                </a>
            </div>
        </form>

        <div class="mt-8 pt-6 border-t border-slate-700">
            <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider text-center mb-4">Preenchimento Rápido (Perfis TCC)</p>
            <div class="grid grid-cols-2 gap-3">
                <button type="button" onclick="fillLogin('admin@teste.com', '123456')" class="bg-slate-900 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs py-2 px-3 rounded flex items-center transition-colors">
                    <i class="fas fa-crown text-amber-500 w-4"></i> Administrador
                </button>
                <button type="button" onclick="fillLogin('secretaria@teste.com', '123456')" class="bg-slate-900 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs py-2 px-3 rounded flex items-center transition-colors">
                    <i class="fas fa-clipboard-list text-pink-400 w-4"></i> Secretaria
                </button>
                <button type="button" onclick="fillLogin('professor@teste.com', '123456')" class="bg-slate-900 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs py-2 px-3 rounded flex items-center transition-colors">
                    <i class="fas fa-whistle text-blue-400 w-4"></i> Professor
                </button>
                <button type="button" onclick="fillLogin('responsavel@teste.com', '123456')" class="bg-slate-900 hover:bg-slate-700 border border-slate-700 text-slate-300 text-xs py-2 px-3 rounded flex items-center transition-colors">
                    <i class="fas fa-users text-orange-400 w-4"></i> Responsável
                </button>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('eye-icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function fillLogin(email, password) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;
        }
    </script>
</body>
</html>
