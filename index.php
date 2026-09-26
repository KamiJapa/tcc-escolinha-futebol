<?php
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;
