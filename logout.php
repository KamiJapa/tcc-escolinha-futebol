<?php
// logout.php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
checkCsrf();
logoutUser();
