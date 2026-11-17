<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dsn = getenv('TEST_DB_DSN') ?: '';
if (!preg_match('/^mysql:host=127\.0\.0\.1;port=(\d+);dbname=gestorfc_test_[a-z0-9_]+(?:;charset=utf8mb4)?$/D', $dsn)) {
    fwrite(STDERR, "Defina TEST_DB_DSN para um banco descartável gestorfc_test_* em 127.0.0.1.\n");
    exit(1);
}

require __DIR__ . '/../includes/auth.php';
$db = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$old = random_bytes(32);
$recent = random_bytes(32);
$insert = $db->prepare('INSERT INTO TB_LOGIN_TENTATIVA (CHAVE, FALHAS, EXPIRA_EM)
    VALUES (?, 3, UTC_TIMESTAMP() - INTERVAL ? MINUTE + INTERVAL 15 MINUTE)');
$lookup = $db->prepare('SELECT FALHAS FROM TB_LOGIN_TENTATIVA WHERE CHAVE = ?');

$db->beginTransaction();
try {
    $insert->execute([$old, 2 * 24 * 60]);
    $insert->execute([$recent, 5]);
    purgeExpiredLoginAttempts($db);
    $lookup->execute([$old]);
    if ($lookup->fetchColumn() !== false) {
        throw new RuntimeException('O registro de dois dias deveria ter sido removido.');
    }
    $lookup->execute([$recent]);
    if ((int) $lookup->fetchColumn() !== 3) {
        throw new RuntimeException('O bloqueio recente deveria ter sido preservado.');
    }
} finally {
    $db->rollBack();
}
echo "OK: registro antigo removido e bloqueio de cinco minutos preservado.\n";
