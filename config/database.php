<?php

function dbSetting($name, $default) {
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function getDB() {
    static $db = null;
    if ($db instanceof PDO) return $db;

    $host = dbSetting('DB_HOST', '127.0.0.1');
    $port = dbSetting('DB_PORT', '3308');
    $name = dbSetting('DB_NAME', 'bdescolinha');
    try {
        $db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            dbSetting('DB_USER', 'root'), dbSetting('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $error) {
        error_log('GestorFC: falha na conexão com o banco.');
        http_response_code(503);
        exit('Não foi possível conectar ao banco de dados. Tente novamente mais tarde.');
    }
    return $db;
}
