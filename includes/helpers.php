<?php
// Funções pequenas usadas pelas telas do sistema.

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        exit('Solicitação inválida. Atualize a página e tente novamente.');
    }
}

function flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function showFlash() {
    if (empty($_SESSION['flash'])) {
        return;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $colors = $flash['type'] === 'success'
        ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
        : 'bg-rose-50 border-rose-200 text-rose-800';
    echo '<div class="mb-5 rounded-lg border px-4 py-3 text-sm ' . $colors . '">' . e($flash['message']) . '</div>';
}

function go($url) {
    header('Location: ' . $url);
    exit;
}

function currentEscolinhaId() {
    return (int) ($_SESSION['escolinha_id'] ?? 0);
}

function hasRole($roles) {
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}
