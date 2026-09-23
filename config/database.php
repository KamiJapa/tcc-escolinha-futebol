<?php
// config/database.php

// Database configuration constants
define('DB_HOST', 'localhost');
// XAMPP está configurado para usar a porta 3308 neste computador.
define('DB_PORT', 3308);
define('DB_NAME', 'BDESCOLINHA');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * Get a PDO database connection instance.
 * Uses a static variable to reuse the connection.
 * 
 * @return PDO
 */
function getDB() {
    static $db = null;
    
    if ($db === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // O detalhe fica no log; a tela não deve expor dados técnicos do banco.
            error_log('Falha na conexão com o banco: ' . $e->getMessage());
            http_response_code(500);
            exit('Não foi possível conectar ao banco de dados. Verifique a configuração do ambiente.');
        }
    }
    
    return $db;
}
