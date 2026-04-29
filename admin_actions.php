<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/format_helpers.php';
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/group_bridge.php';

header('Content-Type: application/json; charset=utf-8');

$months = [
    1 => 'января',
    2 => 'февраля',
    3 => 'марта',
    4 => 'апреля',
    5 => 'мая',
    6 => 'июня',
    7 => 'июля',
    8 => 'августа',
    9 => 'сентября',
    10 => 'октября',
    11 => 'ноября',
    12 => 'декабря',
];

function format_game_datetime_label(string $date, string $time, array $months): string
{
    $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', trim($date . ' ' . $time));

    if (!$dateTime) {
        $dateTime = DateTime::createFromFormat('Y-m-d H:i', trim($date . ' ' . $time));
    }

    if (!$dateTime) {
        $dateTime = DateTime::createFromFormat('Y-m-d', trim($date));
    }

    if (!$dateTime) {
        return trim($date . ' ' . $time);
    }

    $monthNumber = (int) $dateTime->format('n');
    $monthName = $months[$monthNumber] ?? $dateTime->format('m');

    return sprintf(
        '%s %s %s, %s',
        $dateTime->format('d'),
        $monthName,
        $dateTime->format('Y'),
        $dateTime->format('H:i')
    );
}

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
    $status = (int) ($_POST['status'] ?? 1);

    if ($gameNumber === '' || $gameDate === '' || $startTime === '' || $location === '' || $price === '' || $type === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Пожалуйста, заполните все поля игры']);
        exit;
    }

    if (!in_array($status, [1, 2, 3], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный статус игры']);
        exit;
    }

    $stmt = $conn->prepare('
        INSERT INTO games (game_number, game_date, start_time, location, price, type, status)
        VALUES (:number, :date, :time, :loc, :price, :type, :status)
    ');

    $stmt->execute([
        ':number' => $gameNumber,
        ':date' => $gameDate,
        ':time' => $startTime,
        ':loc' => $location,
        ':price' => $price,
        ':type' => $type,
        ':status' => $status,
    ]);

    echo json_encode(['success' => true, 'id' => (int) $conn->lastInsertId()]);
    exit;
}

if ($action === 'update_game') {
    $gameId = (int) ($_POST['game_id'] ?? 0);
    $gameNumber = trim($_POST['game_number'] ?? '');
    $gameDate = trim($_POST['game_date'] ?? '');
    $startTime = trim($_POST['start_time'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $price = trim($_POST['price'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $status = (int) ($_POST['status'] ?? 1);

    if ($gameId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный идентификатор игры']);
        exit;
    }

    if ($gameNumber === '' || $gameDate === '' || $startTime === '' || $location === '' || $price === '' || $type === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Заполните все поля перед сохранением']);
        exit;
    }

    if (!in_array($status, [1, 2, 3], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный статус игры']);
        exit;
    }

    $stmt = $conn->prepare('
        UPDATE games
        SET game_number = :number,
            game_date = :date,
            start_time = :time,
            location = :loc,
            price = :price,
            type = :type,
            status = :status
        WHERE id = :id
    ');

    $stmt->execute([
        ':number' => $gameNumber,
        ':date' => $gameDate,
        ':time' => $startTime,
        ':loc' => $location,
        ':price' => $price,
        ':type' => $type,
        ':status' => $status,
        ':id' => $gameId,
    ]);

    echo json_encode([
        'success' => true,
        'game' => [
            'id' => $gameId,
            'game_number' => $gameNumber,
            'game_date' => $gameDate,
            'start_time' => $startTime,
            'location' => $location,
            'price' => $price,
            'type' => $type,
            'status' => $status,
        ],
    ]);
    exit;
}

if ($action === 'get_game_details') {
    $gameId = (int) ($_POST['game_id'] ?? 0);

    if ($gameId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный идентификатор игры']);
        exit;
    }

    $gameStmt = $conn->prepare('SELECT id, game_number, game_date, start_time, location, price, type, status FROM games WHERE id = :id LIMIT 1');
    $gameStmt->execute([':id' => $gameId]);
    $game = $gameStmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        http_response_code(404);
        echo json_encode(['error' => 'Игра не найдена']);
        exit;
    }

    $regsStmt = $conn->prepare('
        SELECT r.id, r.team, r.quantity, r.status, r.created_at, r.user_id, u.telegram_id, u.first_name, u.last_name, u.username
        FROM registrations r
        INNER JOIN users u ON r.user_id = u.id
        WHERE r.game_id = :gid
        ORDER BY r.created_at ASC, r.id ASC
    ');
    $regsStmt->execute([':gid' => $gameId]);

    $registrations = array_map(static function ($row) {
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $username = $row['username'] ? '(@' . $row['username'] . ')' : '';
        $label = $name !== '' ? $name : 'Без имени';
        $label .= $username ? ' ' . $username : '';

        return [
            'id' => (int) $row['id'],
            'team' => $row['team'],
            'quantity' => $row['quantity'] ?? null,
            'created_at' => $row['created_at'],
            'status' => isset($row['status']) ? (int) $row['status'] : 1,
            'user_id' => (int) $row['user_id'],
            'user_label' => $label,
            'telegram_id' => $row['telegram_id'],
        ];
    }, $regsStmt->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success' => true,
        'game' => $game,
        'registrations' => $registrations,
    ]);
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

    $stmt = $conn->prepare('SELECT telegram_id, status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow || !$userRow['telegram_id']) {
        http_response_code(404);
        echo json_encode(['error' => 'Пользователь не найден']);
        exit;
    }

    $telegramId = $userRow['telegram_id'];
    $currentStatus = isset($userRow['status']) ? (int) $userRow['status'] : null;

    if ($currentStatus === 1) {
        $statusStmt = $conn->prepare('UPDATE users SET status = 2 WHERE id = :id');
        $statusStmt->execute([':id' => $userId]);
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

    mirror_bot_reply_message($conn, $config, $userId, $messageText);

    echo json_encode([
        'success' => true,
        'message' => [
            'id' => (int) $conn->lastInsertId(),
            'message' => $messageText,
            'from_bot' => true,
        ],
    ]);
    exit;
}

if ($action === 'update_registration') {
    $registrationId = (int) ($_POST['registration_id'] ?? 0);
    $team = trim($_POST['team'] ?? '');
    $quantity = trim($_POST['quantity'] ?? '');

    if ($registrationId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный идентификатор регистрации']);
        exit;
    }

    $stmt = $conn->prepare('SELECT id, status FROM registrations WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $registrationId]);
    $existingRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existingRow) {
        http_response_code(404);
        echo json_encode(['error' => 'Регистрация не найдена']);
        exit;
    }

    $quantityValue = $quantity !== '' ? $quantity : null;

    $updateStmt = $conn->prepare('UPDATE registrations SET team = :team, quantity = :quantity WHERE id = :id');
    $updateStmt->execute([
        ':team' => $team,
        ':quantity' => $quantityValue,
        ':id' => $registrationId,
    ]);

    echo json_encode([
        'success' => true,
        'registration' => [
            'id' => $registrationId,
            'team' => $team,
            'quantity' => $quantityValue,
            'status' => isset($existingRow['status']) ? (int) $existingRow['status'] : 1,
        ],
    ]);
    exit;
}

if ($action === 'confirm_reserve') {
    $registrationId = (int) ($_POST['registration_id'] ?? 0);

    if ($registrationId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный идентификатор регистрации']);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT r.id, r.team, r.quantity, r.status, r.user_id, r.game_id,
               u.telegram_id, u.status AS user_status,
               g.game_number, g.game_date, g.start_time, g.location, g.price
        FROM registrations r
        INNER JOIN users u ON r.user_id = u.id
        INNER JOIN games g ON r.game_id = g.id
        WHERE r.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $registrationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Регистрация не найдена']);
        exit;
    }

    $currentStatus = isset($row['status']) ? (int) $row['status'] : 1;

    if ($currentStatus !== 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Эта регистрация не находится в резерве']);
        exit;
    }

    if (!$row['telegram_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'Не удалось отправить сообщение: Telegram ID отсутствует']);
        exit;
    }

    $dateTimeLabel = format_game_datetime_label($row['game_date'], $row['start_time'], $months);
    $teamLabel = trim((string) ($row['team'] ?? ''));
    $quantityLabel = trim((string) ($row['quantity'] ?? ''));
    $quantityDisplay = '';

    if ($quantityLabel !== '') {
        if ($quantityLabel === 'Пока не знаем') {
            $quantityDisplay = "Количество игроков: {$quantityLabel}";
        } else {
            $quantityDisplay = str_replace('-', '–', $quantityLabel) . " игроков";
        }
    }

    $messageText = "Отличные новости! 🎉\n" .
        "Мы нашли место для вашей команды — вы в игре!\n" .
        "🎮 " . $row['game_number'] . "\n" .
        "📅 " . $dateTimeLabel . "\n" .
        "📍 " . $row['location'] . "\n" .
        "💰 " . $row['price'];

    if ($teamLabel !== '') {
        $messageText .= "\n👥 Команда: «" . $teamLabel . "»";
        if ($quantityDisplay !== '') {
            $messageText .= " (" . $quantityDisplay . ")";
        }
    }

    $messageText .= "\nЕсли вдруг планы изменятся — просто напишите мне здесь.\nСпасибо 🙌";

    $userStatus = isset($row['user_status']) ? (int) $row['user_status'] : null;
    if ($userStatus === 1) {
        $statusStmt = $conn->prepare('UPDATE users SET status = 2 WHERE id = :id');
        $statusStmt->execute([':id' => $row['user_id']]);
    }

    telegram_request($config, 'sendMessage', [
        'chat_id' => $row['telegram_id'],
        'text' => $messageText,
    ]);

    $logStmt = $conn->prepare('INSERT INTO messages (user_id, message, from_bot) VALUES (:uid, :msg, 1)');
    $logStmt->execute([
        ':uid' => $row['user_id'],
        ':msg' => $messageText,
    ]);

    $updateStatus = $conn->prepare('UPDATE registrations SET status = 1 WHERE id = :id');
    $updateStatus->execute([':id' => $registrationId]);

    echo json_encode([
        'success' => true,
        'registration' => [
            'id' => $registrationId,
            'status' => 1,
            'team' => $teamLabel,
            'quantity' => $quantityLabel !== '' ? $quantityLabel : null,
        ],
        'message' => $messageText,
    ]);
    exit;
}

if ($action === 'get_user_messages') {
    $userId = (int) ($_POST['user_id'] ?? 0);

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректный пользователь']);
        exit;
    }

    $stmt = $conn->prepare('SELECT id, message, from_bot, text_grammar FROM messages WHERE user_id = :uid ORDER BY id ASC');
    $stmt->execute([':uid' => $userId]);

    echo json_encode([
        'success' => true,
        'messages' => array_map(static function ($row) {
            return [
                'id' => (int) $row['id'],
                'message' => $row['message'],
                'from_bot' => (bool) $row['from_bot'],
                'text_grammar' => $row['text_grammar'] ?? null,
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC)),
    ]);
    exit;
}

if ($action === 'format_message_grammar') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $messageId = (int) ($_POST['message_id'] ?? 0);

    if ($userId <= 0 || $messageId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Некорректные параметры']);
        exit;
    }

    $messageStmt = $conn->prepare('SELECT id, message, from_bot FROM messages WHERE id = :id AND user_id = :uid LIMIT 1');
    $messageStmt->execute([':id' => $messageId, ':uid' => $userId]);
    $row = $messageStmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Сообщение не найдено']);
        exit;
    }

    if ((int) $row['from_bot'] === 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Можно форматировать только сообщения пользователя']);
        exit;
    }

    $apiKey = trim((string) ($config['gemini_api_key'] ?? ''));
    if ($apiKey === '') {
        http_response_code(500);
        echo json_encode(['error' => 'Не задан gemini_api_key в config.php']);
        exit;
    }

    $prompt = "You are a professional English editor with experience in fintech and credit risk.\n\nYour task is to rewrite the user's answer in clear, correct, and professional English.\n\nSTRICT RULES:\n\n* Do NOT add any new ideas, arguments, or examples.\n* Do NOT change the meaning.\n* Do NOT expand the content.\n* Only improve grammar, wording, and sentence structure.\n* Use standard financial and credit risk terminology where appropriate.\n* Prefer simple, clear, business-friendly language.\n* Avoid overly complex or academic vocabulary.\n* Keep the tone suitable for a fintech interview.\n\nOUTPUT RULE:\n\n* Return ONLY the corrected version.\n* Do NOT add explanations, comments, or formatting.\n* Do NOT include titles like \"Corrected version\".\n* Do NOT use bullet points.\n\n---\n\nUser answer:\n\"\"\"\n" . trim((string) $row['message']) . "\n\"\"\"";

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode($apiKey);
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                ],
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 45,
    ]);
    $rawResponse = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($rawResponse === false || $httpCode >= 400) {
        http_response_code(502);
        echo json_encode(['error' => 'Ошибка Gemini API: ' . ($curlError !== '' ? $curlError : ('HTTP ' . $httpCode))]);
        exit;
    }

    $decoded = json_decode($rawResponse, true);
    $formatted = trim((string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));

    if ($formatted === '') {
        http_response_code(502);
        echo json_encode(['error' => 'Gemini вернул пустой ответ']);
        exit;
    }

    $updateStmt = $conn->prepare('UPDATE messages SET text_grammar = :grammar WHERE id = :id AND user_id = :uid');
    $updateStmt->execute([
        ':grammar' => $formatted,
        ':id' => $messageId,
        ':uid' => $userId,
    ]);

    $insertBot = $conn->prepare('INSERT INTO messages (user_id, message, from_bot) VALUES (:uid, :msg, 1)');
    $insertBot->execute([
        ':uid' => $userId,
        ':msg' => $formatted,
    ]);

    echo json_encode([
        'success' => true,
        'formatted' => $formatted,
        'bot_message_id' => (int) $conn->lastInsertId(),
    ]);
    exit;
}

if ($action === 'create_subscription') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $format = trim($_POST['format'] ?? '');

    if ($userId <= 0 || $format === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Укажите пользователя и формат']);
        exit;
    }

    if (!in_array($format, get_known_game_formats(), true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Неизвестный формат']);
        exit;
    }

    save_format_subscription($conn, $userId, $format);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);
