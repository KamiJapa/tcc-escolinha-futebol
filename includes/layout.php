<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

function menuItem($url, $label, $icon, $roles) {
    if (!hasRole($roles)) {
        return;
    }
    $active = basename($_SERVER['PHP_SELF']) === $url;
    $class = $active ? 'bg-emerald-800 text-amber-300' : 'text-slate-200 hover:bg-emerald-800 hover:text-amber-200';
    echo '<a class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm ' . $class . '" href="' . e($url) . '"><span>' . e($icon) . '</span>' . e($label) . '</a>';
}

function pageStart($title) {
    checkLogin();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - GestorFC</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-stone-50 text-slate-800">
    <header class="bg-emerald-950 text-white shadow">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4">
            <a href="index.php" class="text-xl font-extrabold tracking-tight">Gestor<span class="text-amber-400">FC</span></a>
            <div class="text-right text-sm"><strong><?= e($_SESSION['username']) ?></strong><br><span class="text-amber-300"><?= e($_SESSION['user_role']) ?></span></div>
        </div>
    </header>
    <div class="mx-auto grid max-w-7xl gap-6 px-4 py-6 lg:grid-cols-[220px_1fr]">
        <aside class="rounded-xl bg-emerald-950 p-3 shadow-sm">
            <nav class="space-y-1">
                <?php menuItem('index.php', 'Dashboard', '⌂', ['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL']); ?>
                <?php menuItem('alunos.php', 'Alunos', '◉', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('responsaveis.php', 'Responsáveis', '◌', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('turmas.php', 'Turmas', '▦', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('matriculas.php', 'Matrículas', '↔', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('aulas.php', 'Aulas e chamada', '✓', ['ADMIN', 'PROFESSOR']); ?>
                <?php menuItem('usuarios.php', 'Usuários', '⚙', ['ADMIN']); ?>
                <?php menuItem('meus_alunos.php', 'Meus alunos', '♥', ['RESPONSAVEL']); ?>
                <a class="mt-4 block rounded-lg px-3 py-2 text-sm text-slate-200 hover:bg-rose-900" href="logout.php">Sair</a>
            </nav>
        </aside>
        <main>
            <div class="mb-6 flex items-center justify-between">
                <h1 class="text-2xl font-bold text-emerald-950"><?= e($title) ?></h1>
            </div>
            <?php showFlash(); ?>
    <?php
}

function pageEnd() {
    echo '</main></div></body></html>';
}

function actionButton($text, $class = 'bg-amber-500 hover:bg-amber-400') {
    echo '<button class="rounded-lg px-4 py-2 font-semibold text-emerald-950 ' . $class . '" type="submit">' . e($text) . '</button>';
}
