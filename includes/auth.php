<?php
// includes/auth.php

const SESSION_IDLE_TIMEOUT = 30 * 60;

function cspNonce() {
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(18));
}

if (PHP_SAPI !== 'cli') {
    $nonce = cspNonce();
    header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce'; style-src 'self'; img-src 'self' data:; font-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'none'");
}

if (session_status() === PHP_SESSION_NONE) {
    $https = in_array(strtolower($_SERVER['HTTPS'] ?? ''), ['on', '1'], true);
    ini_set('session.use_strict_mode', '1');
    // Permite que a aplicação reconheça a expiração antes da coleta do PHP.
    ini_set('session.gc_maxlifetime', (string) max(2 * SESSION_IDLE_TIMEOUT, (int) ini_get('session.gc_maxlifetime')));
    session_set_cookie_params(['secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

if (isset($_SESSION['user_id'])) {
    $now = time();
    if ($now - ($_SESSION['last_activity'] ?? $now) >= SESSION_IDLE_TIMEOUT) {
        logoutUser(true);
    }
    $_SESSION['last_activity'] = $now;
}

/**
 * Ensure the user is logged in.
 * Redirects to login page if not authenticated.
 */
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
    require_once __DIR__ . '/../config/database.php';
    $q = getDB()->prepare('SELECT PERFIL FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ? AND ATIVO = 1');
    $q->execute([$_SESSION['user_id'], $_SESSION['escolinha_id'] ?? 0]);
    $role = $q->fetchColumn();
    if ($role === false) {
        logoutUser();
    }
    $_SESSION['user_role'] = $role;
}

/**
 * Ensure the user has one of the allowed roles.
 * 
 * @param array $allowed_roles Array of roles (e.g., ['admin', 'coach'])
 */
function checkRole($allowed_roles = []) {
    checkLogin();
    
    // If no specific roles are required, any logged-in user is allowed
    if (empty($allowed_roles)) {
        return;
    }
    
    // Check if the user's role is in the list of allowed roles
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], $allowed_roles)) {
        header("HTTP/1.1 403 Forbidden");
        die("403 Forbidden - You do not have permission to access this page.");
    }
}

/**
 * Attempt to log in a user with email and password.
 * 
 * @param string $email
 * @param string $password
 * @return bool True if successful, false otherwise
 */
function loginUser($email, $password) {
    require_once __DIR__ . '/../config/database.php';
    if (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $email = strtolower($email);
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $origin = filter_var($remote, FILTER_VALIDATE_IP) ? inet_pton($remote) : '';
    $key = hash('sha256', $email . "\0" . $origin, true);
    $db = getDB();
    if (random_int(1, 50) === 1) {
        pruneExpiredLoginAttempts($db);
    }
    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO TB_LOGIN_TENTATIVA (CHAVE, EXPIRA_EM) VALUES (?, UTC_TIMESTAMP())
                      ON DUPLICATE KEY UPDATE CHAVE = CHAVE')->execute([$key]);
        $attempt = $db->prepare('SELECT FALHAS, EXPIRA_EM > UTC_TIMESTAMP() AS VIGENTE
                                 FROM TB_LOGIN_TENTATIVA WHERE CHAVE = ? FOR UPDATE');
        $attempt->execute([$key]);
        $state = $attempt->fetch();
        $failures = $state['VIGENTE'] ? (int) $state['FALHAS'] : 0;
        if ($failures >= 3) {
            $db->commit();
            return false;
        }

        $stmt = $db->prepare('SELECT COD_USUARIO, NOME, EMAIL, SENHA_HASH, PERFIL, COD_ESCOLINHA
                              FROM TB_USUARIO WHERE EMAIL = ? AND ATIVO = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $sameCostHash = '$2y$10$sJ3gNbN3cpD6PtjEeCmcXe5TLs4E7L6ed4gbRA/FU6ZZOVt/8Bsa.';
        $verified = password_verify($password, $user['SENHA_HASH'] ?? $sameCostHash);
        if (!$verified || !$user || $password === '') {
            $db->prepare('UPDATE TB_LOGIN_TENTATIVA SET FALHAS = ?,
                          EXPIRA_EM = UTC_TIMESTAMP() + INTERVAL 15 MINUTE WHERE CHAVE = ?')
                ->execute([$failures + 1, $key]);
            $db->commit();
            return false;
        }

        $db->prepare('DELETE FROM TB_LOGIN_TENTATIVA WHERE CHAVE = ?')->execute([$key]);
        $db->commit();
    } catch (PDOException $error) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('GestorFC: falha ao validar a tentativa de login.');
        http_response_code(503);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['COD_USUARIO'];
    $_SESSION['username'] = $user['NOME'];
    $_SESSION['user_email'] = $user['EMAIL'];
    $_SESSION['user_role'] = $user['PERFIL'];
    $_SESSION['escolinha_id'] = $user['COD_ESCOLINHA'];
    $_SESSION['last_activity'] = time();
    return true;
}

function pruneExpiredLoginAttempts(PDO $db) {
    try {
        // A limpeza usa o índice de expiração e cede se outra requisição detiver um bloqueio.
        $db->exec('SET STATEMENT innodb_lock_wait_timeout = 0 FOR
                   DELETE FROM TB_LOGIN_TENTATIVA WHERE EXPIRA_EM <= UTC_TIMESTAMP()
                   ORDER BY EXPIRA_EM LIMIT 100');
    } catch (PDOException $error) {
        error_log('GestorFC: limpeza de tentativas de login adiada.');
    }
}

/**
 * Log out the current user and destroy the session.
 */
function logoutUser($expired = false) {
    session_unset();
    session_destroy();
    
    // Also delete the session cookie for completeness
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        unset($params['lifetime']);
        $params['expires'] = time() - 42000;
        setcookie(session_name(), '', $params);
    }
    
    header('Location: ' . ($expired ? 'login.php?expirada=1' : 'login.php'));
    exit;
}
