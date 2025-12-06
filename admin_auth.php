<?php
session_start();

function find_admin_by_email(PDO $conn, string $email): ?array
{
    $stmt = $conn->prepare('SELECT id, email, password_hash FROM admins WHERE email = :email LIMIT 1');
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
