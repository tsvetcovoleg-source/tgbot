<?php

function handle_message($text, $user_id, $chat_id, $config, $conn, $callback = null) {
    $text_lower = mb_strtolower(trim($text));

    // === Маршрутизация сообщений ===
    $routes = [
        '/start' => 'handle_start_command',
        'игры'   => 'handle_games_command',
        '/игры'  => 'handle_games_command'
        // Добавляй сюда другие команды
    ];

    if (isset($routes[$text_lower])) {
        return $routes[$text_lower]($chat_id, $user_id, $conn, $config);
    }

    // fallback для обычного текста
    return handle_free_text($text, $chat_id, $user_id, $conn, $config);
}

function handle_callback($data, $user_id, $chat_id, $config, $conn, $callback) {
    // === Маршрутизация callback'ов ===
    if ($data === 'show_games') {
        return handle_games_command($chat_id, $user_id, $conn, $config);
    }

    // было: if (str_starts_with($data, 'register_')) {
    if (strpos($data, 'register_') === 0) {
        return handle_register_button($data, $chat_id, $user_id, $conn, $config, $callback);
    }

    if (strpos($data, 'enter_team_') === 0) {
        return handle_enter_team_button($data, $chat_id, $user_id, $conn, $config, $callback);
    }


    // можно добавить другие...
}


# --------------------- ОБРАБОТЧИКИ КОМАНД ----------------------

function handle_start_command($chat_id, $user_id, $conn, $config) {
    $message = "Добро пожаловать в MindGames Bot! Здесь вы можете записаться на квиз или квест.";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📋 Посмотреть список игр', 'callback_data' => 'show_games']
            ]
        ]
    ];

    send_reply($chat_id, $message, $keyboard, $user_id, $conn);
    return null;
}

function handle_games_command($chat_id, $user_id, $conn, $config) {
    $stmt = $conn->query("
        SELECT id, game_number, game_date, start_time, location, price
        FROM games
        ORDER BY game_date ASC
    ");

    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$games) {
        send_reply($chat_id, "Пока нет активных игр 😢", null, $user_id, $conn);
        return null;
    }

    foreach ($games as $game) {
        $text = "🎮 <b>{$game['game_number']}</b>\n📅 {$game['game_date']} в {$game['start_time']}\n📍 {$game['location']}\n💰 {$game['price']}";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📥 Зарегистрироваться на игру', 'callback_data' => 'register_' . $game['id']]
                ]
            ]
        ];

        send_telegram($chat_id, $text, $keyboard);
        log_bot_message($user_id, $text, $conn);
    }

    return null;
}

function handle_register_button($data, $chat_id, $user_id, $conn, $config, $callback) {
    $game_id = (int) str_replace('register_', '', $data);

    // Берём данные игры
    $stmt = $conn->prepare("
        SELECT game_number, game_date, start_time, location
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($game) {
        // Формируем сообщение
        $msg = "✅ Вы зарегистрированы на игру:\n\n" .
               "🎮 <b>{$game['game_number']}</b>\n" .
               "📅 {$game['game_date']} в {$game['start_time']}\n" .
               "📍 {$game['location']}";

        // Инлайн-кнопка "Ввести название команды"
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 Ввести название команды', 'callback_data' => 'enter_team_' . $game_id]
                ]
            ]
        ];

    } else {
        // Если игра не найдена (например, удалили из БД)
        $msg = "❌ Игра с ID $game_id не найдена.";
        $keyboard = null;
    }

    // Отправляем пользователю
    @file_get_contents($config['api_url'] . "sendMessage?" . http_build_query([
        'chat_id'    => $chat_id,
        'text'       => $msg,
        'parse_mode' => 'HTML',
        'reply_markup' => $keyboard ? json_encode($keyboard, JSON_UNESCAPED_UNICODE) : null
    ]));

    // Логируем ответ бота
    log_bot_message($user_id, strip_tags($msg), $conn);
}

function handle_enter_team_button($data, $chat_id, $user_id, $conn, $config, $callback) {
    // Получаем game_id из callback_data: enter_team_{id}
    $game_id = (int) str_replace('enter_team_', '', $data);

    // Создаём новую регистрацию: только user_id, game_id, created_at
    $stmt = $conn->prepare("
        INSERT INTO registrations (user_id, game_id, created_at)
        VALUES (:uid, :gid, NOW())
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':gid' => $game_id
    ]);

    // Сообщение-подсказка
    $text = "📝 В ответе на это сообщение введите <b>название вашей команды</b>.";

    // Привязываем как «ответ» к сообщению с кнопкой (если есть message_id)
    $params = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => 'HTML'
    ];
    if (isset($callback['message']['message_id'])) {
        $params['reply_to_message_id'] = $callback['message']['message_id'];
    }

    @file_get_contents($config['api_url'] . "sendMessage?" . http_build_query($params));

    // Логируем отправленную подсказку
    log_bot_message($user_id, strip_tags($text), $conn);
}



function handle_free_text($text, $chat_id, $user_id, $conn, $config) {
    $teamName = trim($text);

    // Ищем самую свежую регистрацию без названия команды
    $stmt = $conn->prepare("
        SELECT id
        FROM registrations
        WHERE user_id = :uid AND (team IS NULL OR team = '')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    $reg_id = $stmt->fetchColumn();

    if ($reg_id) {
        // Обновляем team тем, что прислал пользователь
        $stmtUp = $conn->prepare("UPDATE registrations SET team = :team WHERE id = :rid");
        $stmtUp->execute([
            ':team' => $teamName,
            ':rid'  => $reg_id
        ]);

        $confirm = "✅ Команда «".htmlspecialchars($teamName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."» сохранена.";
        @file_get_contents($config['api_url'] . "sendMessage?" . http_build_query([
            'chat_id'    => $chat_id,
            'text'       => $confirm,
            'parse_mode' => 'HTML'
        ]));

        log_bot_message($user_id, strip_tags($confirm), $conn);
        return null;
    }

    // Fallback — если незавершённых регистраций нет
    return "Спасибо за сообщение! Напишите /игры, чтобы посмотреть ближайшие события.";
}


# --------------------- УТИЛИТЫ ----------------------

function send_reply($chat_id, $text, $keyboard, $user_id, $conn) {
    send_telegram($chat_id, $text, $keyboard);
    log_bot_message($user_id, $text, $conn);
}

function send_telegram($chat_id, $text, $keyboard = null) {
    global $config;

    $params = [
        'chat_id' => $chat_id,
        'text'    => $text
    ];

    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard);
    }

    file_get_contents($config['api_url'] . "sendMessage?" . http_build_query($params));
}

function log_bot_message($user_id, $text, $conn) {
    $stmt = $conn->prepare("
        INSERT INTO messages (user_id, message, from_bot)
        VALUES (:uid, :msg, 1)
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':msg' => $text
    ]);
}
