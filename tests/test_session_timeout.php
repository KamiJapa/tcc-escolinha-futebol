<?php
require __DIR__ . '/audit_http_helpers.php';

$base = getenv('TEST_BASE_URL') ?: '';
$recent = time() - 100;
[$cookie, $file] = auditAuthenticatedSession($base, $recent);
verifyAudit(auditRequest($base, 'dashboard.php', $cookie)['status'] === 200, 'Sessão recente perdeu o acesso.');
verifyAudit(preg_match('/last_activity\|i:(\d+);/', file_get_contents($file), $updated)
    && (int) $updated[1] > $recent, 'A atividade da sessão não foi atualizada.');

[$oldCookie, $oldFile] = auditAuthenticatedSession($base, time() - 1805);
$expired = auditRequest($base, 'dashboard.php', $oldCookie);
verifyAudit($expired['status'] === 302 && $expired['headers']['location'] === ['login.php'], 'Sessão vencida não redirecionou ao login.');
verifyAudit(!is_file($oldFile), 'O servidor manteve a sessão vencida.');
[$flashCookie] = auditSessionCookie($expired);
verifyAudit($oldCookie !== $flashCookie, 'O flash reutilizou o identificador autenticado.');
verifyAudit(auditRequest($base, 'dashboard.php', $oldCookie)['status'] === 302, 'O cookie anterior ainda autoriza acesso.');
$message = 'Sua sessão expirou por inatividade. Faça login novamente.';
verifyAudit(str_contains(auditRequest($base, 'login.php', $flashCookie)['body'], $message), 'Flash de expiração ausente.');
verifyAudit(!str_contains(auditRequest($base, 'login.php', $flashCookie)['body'], $message), 'O flash apareceu mais de uma vez.');
echo "OK: sessão recente renovada, sessão vencida invalidada e flash exibido uma vez.\n";
