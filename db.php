<?php

function get_connection(array $config): PDO
{
    if (!isset($config['db']) || !is_array($config['db'])) {
        throw new InvalidArgumentException('Database configuration is missing.');
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db']['host'] ?? 'localhost',
        $config['db']['dbname'] ?? ''
    );

    try {
        $conn = new PDO($dsn, $config['db']['user'] ?? '', $config['db']['pass'] ?? '');
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        throw new RuntimeException('Connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
    }

    return $conn;
}
