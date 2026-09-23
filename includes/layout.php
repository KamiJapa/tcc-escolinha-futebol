<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

function menuItem($url, $label, $icon, $roles) {
    if (!hasRole($roles)) {
        return;
    }

    $ativo = basename($_SERVER['PHP_SELF']) === $url ? ' ativo' : '';
    echo '<a class="link-menu' . $ativo . '" href="' . e($url) . '">'
        . '<span aria-hidden="true">' . e($icon) . '</span> ' . e($label) . '</a>';
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
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="pagina-sistema">
    <header class="cabecalho-sistema">
        <a class="marca" href="index.php">GestorFC</a>
        <p><?= e($_SESSION['username']) ?> (<?= e($_SESSION['user_role']) ?>)</p>
    </header>

    <div class="estrutura-sistema">
        <aside class="menu-lateral">
            <nav aria-label="Menu principal">
                <?php menuItem('index.php', 'Início', '⌂', ['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL']); ?>
                <?php menuItem('alunos.php', 'Alunos', '◉', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('responsaveis.php', 'Responsáveis', '◌', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('turmas.php', 'Turmas', '▦', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('matriculas.php', 'Matrículas', '↔', ['ADMIN', 'SECRETARIA']); ?>
                <?php menuItem('aulas.php', 'Aulas e chamada', '✓', ['ADMIN', 'PROFESSOR']); ?>
                <?php menuItem('usuarios.php', 'Usuários', '⚙', ['ADMIN']); ?>
                <?php menuItem('meus_alunos.php', 'Meus alunos', '♥', ['RESPONSAVEL']); ?>
                <a class="link-menu" href="logout.php">Sair</a>
            </nav>
        </aside>

        <main class="conteudo-sistema">
            <h1><?= e($title) ?></h1>
            <?php showFlash(); ?>
    <?php
}

function pageEnd() {
    echo '</main></div></body></html>';
}

function actionButton($text, $class = '') {
    echo '<button class="botao-principal" type="submit">' . e($text) . '</button>';
}
