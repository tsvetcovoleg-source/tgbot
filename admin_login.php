<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_auth.php';

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
$conn = get_connection($config);

$body = json_decode(file_get_contents('php://input'), true);
$email = trim($body['email'] ?? '');
$password = (string) ($body['password'] ?? '');

if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Укажите email и пароль']);
    exit;
}

$admin = find_admin_by_email($conn, $email);
if (!$admin || !isset($admin['password_hash']) || !password_verify($password, $admin['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Неверный email или пароль']);
    exit;
}

$_SESSION['admin_email'] = $admin['email'];

echo json_encode(['success' => true, 'email' => $admin['email']]);
