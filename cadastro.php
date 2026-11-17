<?php
// Encaminha a antiga página de cadastro público para o login. A equipe cadastra os responsáveis.
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

header('Location: login.php');
exit;
