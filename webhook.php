<?php
$config = include 'config.php';
include 'db.php';
include 'logic.php';

$content = file_get_contents("php://input");
$update = json_decode($content, true);

// === 1. Обработка callback_query ===
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $chat_id = $callback['from']['id'];
    $data = $callback['data'];

    // Получаем user_id
    $stmt = $conn->prepare("SELECT id FROM users WHERE telegram_id = :tgid");
    $stmt->execute([':tgid' => $chat_id]);
    $user_id = $stmt->fetchColumn();

    // Логируем callback как "виртуальное сообщение"
    $stmt = $conn->prepare("INSERT INTO messages (user_id, message, from_bot) VALUES (:uid, :msg, 0)");
    $stmt->execute([':uid' => $user_id, ':msg' => "[Нажата кнопка: $data]"]);

    // Обработка в logic.php
    handle_callback($data, $user_id, $chat_id, $config, $conn, $callback);

    // 1) Подтверждаем нажатие, чтобы у пользователя пропал "часик"
    if (!empty($callback['id'])) {
        @file_get_contents($config['api_url'] . "answerCallbackQuery?" . http_build_query([
            'callback_query_id' => $callback['id']
        ]));
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

// Обновляем или добавляем пользователя
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
    ':id' => $chat_id,
    ':first' => $tg_user['first_name'] ?? null,
    ':last' => $tg_user['last_name'] ?? null,
    ':user' => $tg_user['username'] ?? null,
    ':lang' => $tg_user['language_code'] ?? null
]);

// Получаем внутренний user_id
$stmt = $conn->prepare("SELECT id FROM users WHERE telegram_id = :tgid");
$stmt->execute([':tgid' => $chat_id]);
$user_id = $stmt->fetchColumn();

// Сохраняем входящее сообщение
if ($text !== '') {
    $stmt = $conn->prepare("
        INSERT INTO messages (user_id, message, from_bot)
        VALUES (:uid, :msg, 0)
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':msg' => $text
    ]);
}

// Получаем ответ из logic
$reply = handle_message($text, $user_id, $chat_id, $config, $conn, null);

// Отправка, если ответ не null
if ($reply !== null && $reply !== '') {
    file_get_contents($config['api_url'] . "sendMessage?" . http_build_query([
        'chat_id' => $chat_id,
        'text' => $reply
    ]));

    // Логируем ответ бота
    $stmt = $conn->prepare("
        INSERT INTO messages (user_id, message, from_bot)
        VALUES (:uid, :msg, 1)
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':msg' => $reply
    ]);
}
?>
