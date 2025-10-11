<?php
$config = include 'config.php';

try {
    $conn = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']};charset=utf8mb4", $config['db']['user'], $config['db']['pass']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
