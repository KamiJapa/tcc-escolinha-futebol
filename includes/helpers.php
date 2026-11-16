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
    if (!is_string($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
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
    $type = $flash['type'] === 'success' ? 'sucesso' : 'erro';
    echo '<p class="mensagem ' . $type . '">' . e($flash['message']) . '</p>';
}

function go($url) {
    // Mantém o formulário dentro da mini tela depois de salvar ou mostrar um erro.
    if (isset($_GET['mini']) && strpos($url, 'mini=') === false) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'mini=1';
    }
    header('Location: ' . $url);
    exit;
}

function currentEscolinhaId() {
    return (int) ($_SESSION['escolinha_id'] ?? 0);
}

function hasRole($roles) {
    return in_array($_SESSION['user_role'] ?? '', $roles, true);
}

function validDate($value) {
    if (!is_string($value) || !preg_match('/^[1-9]\d{3}-\d{2}-\d{2}$/D', $value)) return false;
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
}

function cpfFormat($value) {
    if (!is_string($value)) return '';
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits)) return '';
    $sum=0; for($i=0;$i<9;$i++) $sum+=(int)$digits[$i]*(10-$i); $d1=($sum*10)%11; if($d1===10)$d1=0;
    $sum=0; for($i=0;$i<10;$i++) $sum+=(int)$digits[$i]*(11-$i); $d2=($sum*10)%11; if($d2===10)$d2=0;
    if((int)$digits[9]!==$d1 || (int)$digits[10]!==$d2) return '';
    return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9);
}

function postString($key, $default = '') {
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

function positiveId($value) {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) ?: null;
}

function isDuplicateShirt(PDOException $error) {
    return (int) ($error->errorInfo[1] ?? 0) === 1062
        && strpos($error->errorInfo[2] ?? '', 'UQ_ALUNO_CAMISA_ATIVA') !== false;
}
