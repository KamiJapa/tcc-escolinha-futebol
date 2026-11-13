<?php
// includes/auth.php

// Start the session if it hasn't been started already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
    $db = getDB();
    
    $stmt = $db->prepare("SELECT COD_USUARIO, NOME, EMAIL, SENHA_HASH, PERFIL, COD_ESCOLINHA FROM TB_USUARIO WHERE EMAIL = ? AND ATIVO = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    // Verify password against the hashed password in the database
    if ($user && password_verify($password, $user['SENHA_HASH'])) {
        // Prevent session fixation
        session_regenerate_id(true);
        
        $_SESSION['user_id'] = $user['COD_USUARIO'];
        $_SESSION['username'] = $user['NOME'];
        $_SESSION['user_email'] = $user['EMAIL'];
        $_SESSION['user_role'] = $user['PERFIL'];
        $_SESSION['escolinha_id'] = $user['COD_ESCOLINHA'];
        return true;
    }
    
    return false;
}

/**
 * Log out the current user and destroy the session.
 */
function logoutUser() {
    session_unset();
    session_destroy();
    
    // Also delete the session cookie for completeness
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    header("Location: login.php");
    exit;
}
