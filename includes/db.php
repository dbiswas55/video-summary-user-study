<?php
/**
 * Database Connection (PDO)
 * Supports both TCP (default) and Unix socket (MAMP on Mac).
 */

function getDb() {
    static $pdo = null;

    if ($pdo === null) {
        $config = require __DIR__ . '/../config/config.php';
        $db = $config['db'];

        // Build DSN — use socket on Mac/MAMP, TCP elsewhere
        if (!empty($db['socket']) && file_exists($db['socket'])) {
            $dsn = "mysql:unix_socket={$db['socket']};dbname={$db['name']};charset={$db['charset']}";
        } else {
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false
        ];

        try {
            $pdo = new PDO($dsn, $db['user'], $db['pass'], $options);
        } catch (PDOException $e) {
            if ($config['debug']) {
                die('Database connection failed: ' . $e->getMessage());
            }
            die('Database connection failed. Please contact the administrator.');
        }
    }

    return $pdo;
}
