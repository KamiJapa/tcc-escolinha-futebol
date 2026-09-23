<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

function menuItem($url, $label, $icon, $roles) {
    if (!hasRole($roles)) {
        return;
    }

    $ativo = basename($_SERVER['PHP_SELF']) === $url
        ? ' bg-emerald-800 text-amber-300'
        : ' text-slate-200 hover:bg-emerald-800 hover:text-amber-200';
    echo '<a class="link-menu flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition ' . $ativo . '" href="' . e($url) . '">'
        . '<span aria-hidden="true">' . e($icon) . '</span> ' . e($label) . '</a>';
}

function pageStart($title) {
    checkLogin();

    // A mini tela usa a mesma página e o mesmo salvamento, sem repetir o menu.
    if (isset($_GET['mini'])) {
        $modoChamada = isset($_GET['chamadas']) ? ' chamadas' : '';
        $modoCamisas = $title === 'Números das camisas' ? ' camisas' : '';
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - GestorFC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="mini-pagina<?= $modoChamada . $modoCamisas ?> bg-slate-50 p-4 text-slate-800">
    <main class="conteudo-sistema">
        <h1 class="mb-5 text-xl font-bold text-emerald-950"><?= e($title) ?></h1>
        <?php showFlash(); ?>
        <?php
        return;
    }

    $classeAlunos = $title === 'Alunos' ? ' pagina-alunos' : '';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - GestorFC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="pagina-sistema<?= $classeAlunos ?> min-h-screen bg-slate-50 text-slate-800">
    <header class="cabecalho-sistema bg-emerald-950 text-white shadow-lg">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-4">
            <a class="marca text-xl font-extrabold tracking-tight" href="index.php">Gestor<span class="text-amber-400">FC</span></a>
            <p class="text-right text-sm"><?= e($_SESSION['username']) ?> (<?= e($_SESSION['user_role']) ?>)</p>
        </div>
    </header>

    <div class="estrutura-sistema mx-auto grid max-w-7xl gap-6 px-4 py-6 lg:grid-cols-[220px_1fr]">
        <aside class="menu-lateral rounded-xl bg-emerald-950 p-3 shadow-sm">
            <nav class="grid gap-1" aria-label="Menu principal">
                <?php menuItem('index.php', 'Início', '⌂', ['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL']); ?>
                <?php menuItem('alunos.php', 'Alunos', '◉', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('responsaveis.php', 'Responsáveis', '◌', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('turmas.php', 'Turmas', '▦', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('matriculas.php', 'Matrículas', '↔', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('aulas.php', 'Aulas e chamada', '✓', ['ADMIN', 'PROFESSOR']); ?>
                <?php menuItem('usuarios.php', 'Usuários', '⚙', ['ADMIN']); ?>
                <?php menuItem('meus_alunos.php', 'Meus alunos', '♥', ['RESPONSAVEL']); ?>
                <a class="link-menu mt-4 block rounded-lg px-3 py-2 text-sm text-slate-200 hover:bg-rose-900" href="logout.php">Sair</a>
            </nav>
        </aside>

        <main class="conteudo-sistema min-w-0">
            <h1 class="mb-6 text-2xl font-bold text-emerald-950"><?= e($title) ?></h1>
            <?php showFlash(); ?>
    <?php
}

function pageEnd() {
    if (isset($_GET['mini'])) {
        echo '</main></body></html>';
        return;
    }
    echo '</main></div></body></html>';
}

function actionButton($text) {
    echo '<button class="botao-principal rounded-lg bg-amber-400 px-4 py-2 font-semibold text-emerald-950 shadow-sm transition hover:bg-amber-300" type="submit">' . e($text) . '</button>';
}
