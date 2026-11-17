<?php
require __DIR__ . '/audit_http_helpers.php';

$base = getenv('TEST_BASE_URL') ?: '';
[$cookie, $file, $token] = auditAuthenticatedSession($base, time());
$get = auditRequest($base, 'logout.php', $cookie);
verifyAudit($get['status'] === 302 && $get['headers']['location'] === ['index.php'], 'GET deve redirecionar para index.php.');
verifyAudit(auditRequest($base, 'logout.php', $cookie, [])['status'] === 400, 'POST sem CSRF deve retornar 400.');
verifyAudit(auditRequest($base, 'logout.php', $cookie, ['csrf_token' => 'invalido'])['status'] === 400, 'POST com CSRF inválido deve retornar 400.');
verifyAudit(auditRequest($base, 'dashboard.php', $cookie)['status'] === 200, 'Requisição sem autorização para logout encerrou a sessão.');
$post = auditRequest($base, 'logout.php', $cookie, ['csrf_token' => $token]);
verifyAudit($post['status'] === 302 && $post['headers']['location'] === ['login.php'], 'POST válido deve redirecionar ao login.');
verifyAudit(!is_file($file) && auditRequest($base, 'dashboard.php', $cookie)['status'] === 302, 'A sessão permaneceu autenticada.');
echo "OK: GET e POST sem CSRF válido preservam a sessão; POST válido encerra o acesso.\n";
