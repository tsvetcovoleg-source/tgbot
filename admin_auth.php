<?php
session_start();

function verify_google_token(string $idToken, string $clientId): ?array
{
    if ($idToken === '' || $clientId === '') {
        return null;
    }

    $verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $context = stream_context_create([
        'http' => [
            'timeout' => 5,
        ],
    ]);

    $response = @file_get_contents($verifyUrl, false, $context);
    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }

    if (($data['aud'] ?? '') !== $clientId) {
        return null;
    }

    if (!isset($data['email_verified']) || $data['email_verified'] !== 'true') {
        return null;
    }

    return $data;
}

function find_admin_by_email(PDO $conn, string $email): ?array
{
    $stmt = $conn->prepare('SELECT id, email FROM admins WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);

    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    return $admin ?: null;
}

function require_admin_session(PDO $conn): array
{
    if (empty($_SESSION['admin_email'])) {
        http_response_code(401);
        exit(json_encode(['error' => 'Требуется авторизация администратора']));
    }

    $admin = find_admin_by_email($conn, $_SESSION['admin_email']);
    if (!$admin) {
        session_destroy();
        http_response_code(403);
        exit(json_encode(['error' => 'Нет доступа']));
    }

    return $admin;
}

function admin_logged_in(): bool
{
    return isset($_SESSION['admin_email']) && $_SESSION['admin_email'] !== '';
}
