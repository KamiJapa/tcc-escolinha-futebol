<?php
require __DIR__ . '/audit_http_helpers.php';

foreach (['TEST_BASE_URL', 'TEST_DEMO_FALSE_URL', 'TEST_DEMO_TRUE_URL'] as $setting) {
    $page = auditRequest(getenv($setting) ?: '', 'login.php');
    verifyAudit($page['status'] === 200, "Login indisponível para $setting.");
    $enabled = $setting === 'TEST_DEMO_TRUE_URL';
    foreach (['Contas para teste', '123456', 'function usarConta', 'data-conta='] as $snippet) {
        verifyAudit(str_contains($page['body'], $snippet) === $enabled, "Visibilidade incorreta da demonstração para $setting.");
    }
}
echo "OK: demonstração ausente por padrão e com false, presente com true.\n";
