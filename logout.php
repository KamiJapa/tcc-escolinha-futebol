<?php
// Encerra a sessão após a confirmação do formulário de saída.
// logout.php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
checkCsrf();
logoutUser();
