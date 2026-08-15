<?php

// Creates and returns the PDO connection for MariaDB.

declare(strict_types=1);

function app_pdo(array $creds): PDO
{
    $db = $creds['db'] ?? null;
    if (!is_array($db)) {
        throw new RuntimeException('Missing db credentials in app/creds.php');
    }

    $host = $db['host'] ?? '127.0.0.1';
    $port = (int)($db['port'] ?? 3306);
    $name = $db['database'] ?? '';
    $user = $db['username'] ?? '';
    $pass = $db['password'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database name and username are required in app/creds.php');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

    return new PDO(
        $dsn,
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}
