<?php
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . (($_SESSION['user_role'] ?? '') === 'ALUNO' ? 'meus_alunos.php' : 'dashboard.php'));
    exit;
}

header('Location: login.php');
exit;
