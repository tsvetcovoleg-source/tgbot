<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_auth.php';

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
$conn = get_connection($config);

$body = json_decode(file_get_contents('php://input'), true);
$idToken = $body['credential'] ?? '';

$googleData = verify_google_token($idToken, $config['google_client_id'] ?? '');
if (!$googleData) {
    http_response_code(401);
    echo json_encode(['error' => 'Не удалось подтвердить токен Google']);
    exit;
}

$email = $googleData['email'] ?? '';
$admin = find_admin_by_email($conn, $email);

if (!$admin) {
    http_response_code(403);
    echo json_encode(['error' => 'У вашего аккаунта нет доступа к админке']);
    exit;
}

$_SESSION['admin_email'] = $email;
$_SESSION['admin_name'] = $googleData['name'] ?? $email;

echo json_encode(['success' => true, 'email' => $email]);
