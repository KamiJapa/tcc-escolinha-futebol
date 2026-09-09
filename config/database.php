<?php
// config/database.php

// Database configuration constants
define('DB_HOST', 'localhost');
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
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In a production environment, you might want to log this error instead of displaying it.
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    return $db;
}
