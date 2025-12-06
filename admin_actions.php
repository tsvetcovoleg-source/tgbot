<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/admin_auth.php';

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
$conn = get_connection($config);

$admin = require_admin_session($conn);
$action = $_POST['action'] ?? '';

if ($action === 'create_game') {
    $gameNumber = trim($_POST['game_number'] ?? '');
    $gameDate = trim($_POST['game_date'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $type = trim($_POST['type'] ?? '');

    if ($gameNumber === '' || $gameDate === '' || $startTime === '' || $location === '' || $price === '' || $type === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Пожалуйста, заполните все поля игры']);
        exit;
    }

    $stmt = $conn->prepare('
        INSERT INTO games (game_number, game_date, start_time, location, price, type)
        VALUES (:number, :date, :time, :loc, :price, :type)
    ');

    $stmt->execute([
        ':number' => $gameNumber,
        ':date' => $gameDate,
        ':time' => $startTime,
        ':loc' => $location,
        ':price' => $price,
        ':type' => $type,
    ]);

    echo json_encode(['success' => true, 'id' => (int) $conn->lastInsertId()]);
    exit;
}

if ($action === 'send_message') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $messageText = trim($_POST['message'] ?? '');

    if ($userId <= 0 || $messageText === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Укажите пользователя и текст сообщения']);
        exit;
    }

    $stmt = $conn->prepare('SELECT telegram_id FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $telegramId = $stmt->fetchColumn();

    if (!$telegramId) {
        http_response_code(404);
        echo json_encode(['error' => 'Пользователь не найден']);
        exit;
    }

    telegram_request($config, 'sendMessage', [
        'chat_id' => $telegramId,
        'text' => $messageText,
    ]);

    $logStmt = $conn->prepare('INSERT INTO messages (user_id, message, from_bot) VALUES (:uid, :msg, 1)');
    $logStmt->execute([
        ':uid' => $userId,
        ':msg' => $messageText,
    ]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
