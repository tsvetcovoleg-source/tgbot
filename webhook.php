<?php

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/logic.php';

$conn = get_connection($config);

function sync_user(PDO $conn, array $tg_user): ?int
{
    if (empty($tg_user['id'])) {
        return null;
    }

    $stmt = $conn->prepare("
        INSERT INTO users (telegram_id, first_name, last_name, username, language_code)
        VALUES (:id, :first, :last, :user, :lang)
        ON DUPLICATE KEY UPDATE
            first_name = VALUES(first_name),
            last_name = VALUES(last_name),
            username = VALUES(username),
            language_code = VALUES(language_code)
    ");

    $stmt->execute([
        ':id' => $tg_user['id'],
        ':first' => $tg_user['first_name'] ?? null,
        ':last' => $tg_user['last_name'] ?? null,
        ':user' => $tg_user['username'] ?? null,
        ':lang' => $tg_user['language_code'] ?? null,
    ]);

    $stmt = $conn->prepare('SELECT id FROM users WHERE telegram_id = :tgid');
    $stmt->execute([':tgid' => $tg_user['id']]);

    $user_id = $stmt->fetchColumn();

    return $user_id ? (int) $user_id : null;
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// === 1. Обработка callback_query ===
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['from']['id'];
    $data = $callback['data'];

    $user_id = sync_user($conn, $callback['from'] ?? []);

    // Логируем callback как "виртуальное сообщение"
    if ($user_id) {
        $stmt = $conn->prepare("INSERT INTO messages (user_id, message, from_bot) VALUES (:uid, :msg, 0)");
        $stmt->execute([':uid' => $user_id, ':msg' => "[Нажата кнопка: $data]"]);
    }

    // Обработка в logic.php
    handle_callback($data, $user_id, $chat_id, $config, $conn, $callback);

    // 1) Подтверждаем нажатие, чтобы у пользователя пропал "часик"
    if (!empty($callback['id'])) {
        telegram_request($config, 'answerCallbackQuery', [
            'callback_query_id' => $callback['id'],
        ]);
    }

    // 2) Явно отвечаем 200 OK, чтобы Telegram не ретраил update
    http_response_code(200);
    exit;
}


// === 2. Обработка обычных сообщений ===
$message = $update['message'] ?? null;
if (!$message) exit;

$tg_user = $message['from'];
$chat_id = $tg_user['id'];
$text = trim($message['text'] ?? '');
$telegram_message_id = $message['message_id'] ?? null;

$user_id = sync_user($conn, $tg_user);

$stored_message_id = null;

// Сохраняем входящее сообщение
if ($text !== '' && $user_id) {
    $stmt = $conn->prepare("
        INSERT INTO messages (user_id, message, from_bot)
        VALUES (:uid, :msg, 0)
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':msg' => $text
    ]);

    $stored_message_id = (int) $conn->lastInsertId();
}

// Получаем ответ из logic
$reply = handle_message($text, $user_id, $chat_id, $config, $conn, null, $telegram_message_id, $stored_message_id);

// Отправка, если ответ не null
if ($reply !== null && $reply !== '') {
    telegram_request($config, 'sendMessage', [
        'chat_id' => $chat_id,
        'text' => $reply,
    ]);

    // Логируем ответ бота
    if ($user_id) {
        $stmt = $conn->prepare("
            INSERT INTO messages (user_id, message, from_bot)
            VALUES (:uid, :msg, 1)
        ");
        $stmt->execute([
            ':uid' => $user_id,
            ':msg' => $reply
        ]);
    }
}
?>
