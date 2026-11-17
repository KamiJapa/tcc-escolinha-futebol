<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function verifyAudit($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function auditRequest(string $base, string $path, ?string $cookie = null, ?array $form = null): array {
    verifyAudit(preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', $base), 'Use um servidor descartável em http://127.0.0.1:PORTA.');
    $options = ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5];
    $headers = $cookie === null ? [] : ["Cookie: $cookie"];
    if ($form !== null) {
        $options['method'] = 'POST';
        $options['content'] = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $options['header'] = $headers;
    $body = file_get_contents("$base/$path", false, stream_context_create(['http' => $options]));
    verifyAudit($body !== false, "Falha ao acessar $path.");
    $status = (int) explode(' ', $http_response_header[0])[1];
    $values = [];
    foreach (array_slice($http_response_header, 1) as $line) {
        [$key, $value] = explode(':', $line, 2);
        $values[strtolower($key)][] = trim($value);
    }
    return ['status' => $status, 'headers' => $values, 'body' => $body];
}

function auditSessionCookie(array $response): array {
    foreach ($response['headers']['set-cookie'] ?? [] as $cookie) {
        if (preg_match('/^PHPSESSID=([a-zA-Z0-9,-]+);/', $cookie, $match) && $match[1] !== 'deleted') {
            return [explode(';', $cookie, 2)[0], $match[1]];
        }
    }
    throw new RuntimeException('Cookie de sessão PHP ausente.');
}

function auditAuthenticatedSession(string $base, int $lastActivity): array {
    $directory = realpath(getenv('TEST_SESSION_DIR') ?: '');
    verifyAudit(getenv('TEST_SESSION_DIR') && is_dir($directory), 'Defina TEST_SESSION_DIR para as sessões descartáveis.');
    $login = auditRequest($base, 'login.php');
    verifyAudit($login['status'] === 200, 'Login indisponível.');
    verifyAudit(preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $token), 'Token CSRF ausente.');
    [$cookie, $id] = auditSessionCookie($login);
    $file = $directory . DIRECTORY_SEPARATOR . 'sess_' . $id;
    verifyAudit(is_file($file), 'Use o mesmo diretório de sessões do servidor de teste.');
    // IDs do administrador e da escolinha carregados pelo database.sql no banco descartável.
    $data = 'user_id|i:1;escolinha_id|i:1;username|s:2:"QA";user_role|s:5:"ADMIN";'
        . "last_activity|i:$lastActivity;";
    verifyAudit(file_put_contents($file, $data, FILE_APPEND | LOCK_EX) === strlen($data), 'Falha ao preparar sessão.');
    return [$cookie, $file, $token[1]];
}
