<?php

function handle_message($text, $user_id, $chat_id, $config, $conn, $callback = null) {
    $original_text = trim($text);
    $text_lower = mb_strtolower($original_text);

    if (strpos($text_lower, '/start') === 0) {
        $payload = trim(mb_substr($original_text, mb_strlen('/start')));
        if ($payload !== '') {
            return handle_start_with_payload($chat_id, $user_id, $conn, $config, $payload);
        }
    }

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

    if (preg_match('/^\s*я\s+хочу\s+зарегистрироваться\s+на\s+игру\s+[«"]?(?P<title>.+?)[»"]?\s*$/ui', $original_text, $match)) {
        $gameTitle = trim($match['title']);
        if ($gameTitle !== '') {
            return handle_text_registration_request($gameTitle, $chat_id, $user_id, $conn, $config);
        }
    }

    // fallback для обычного текста
    return handle_free_text($text, $chat_id, $user_id, $conn, $config);
}

function handle_start_with_payload($chat_id, $user_id, $conn, $config, $payload) {
    if (strpos($payload, 'register_') === 0) {
        $game_id = (int) mb_substr($payload, mb_strlen('register_'));
        if ($game_id > 0) {
            $data = 'register_' . $game_id;
            return handle_register_button($data, $chat_id, $user_id, $conn, $config, null);
        }
    }

    return handle_start_command($chat_id, $user_id, $conn, $config);
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

    send_reply($config, $chat_id, $message, $keyboard, $user_id, $conn);
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
        send_reply($config, $chat_id, "Пока нет активных игр 😢", null, $user_id, $conn);
        return null;
    }

    $messages = [];

    foreach ($games as $game) {
        $gameNumberEscaped = htmlspecialchars($game['game_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $gameDateEscaped = htmlspecialchars($game['game_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $startTimeEscaped = htmlspecialchars($game['start_time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locationEscaped = htmlspecialchars($game['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $priceEscaped = htmlspecialchars($game['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $shareText = sprintf('Я хочу зарегистрироваться на игру «%s»', $game['game_number']);
        $shareLink = sprintf(
            'tg://resolve?domain=%s&text=%s',
            rawurlencode($config['bot_username']),
            rawurlencode($shareText)
        );

        $messages[] = "🎮 <b>{$gameNumberEscaped}</b>\n" .
            "📅 {$gameDateEscaped} в {$startTimeEscaped}\n" .
            "📍 {$locationEscaped}\n" .
            "💰 {$priceEscaped}\n\n" .
            '<a href="' . htmlspecialchars($shareLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">📥 Зарегистрироваться на игру</a>';
    }

    $text = "📋 <b>Список доступных игр:</b>\n\n" . implode("\n\n", $messages);

    send_telegram($config, $chat_id, $text, null, 'HTML');
    log_bot_message($user_id, strip_tags($text), $conn);

    return null;
}

function handle_register_button($data, $chat_id, $user_id, $conn, $config, $callback) {
    $game_id = (int) str_replace('register_', '', $data);

    send_registration_confirmation($game_id, $chat_id, $user_id, $conn, $config);
}

function handle_text_registration_request($gameTitle, $chat_id, $user_id, $conn, $config) {
    $stmt = $conn->prepare("
        SELECT id
        FROM games
        WHERE game_number = :title
        LIMIT 1
    ");
    $stmt->execute([':title' => $gameTitle]);
    $game_id = $stmt->fetchColumn();

    if ($game_id) {
        send_registration_confirmation((int) $game_id, $chat_id, $user_id, $conn, $config);
        return null;
    }

    $message = '❌ Не удалось найти игру с таким названием. Пожалуйста, выберите её из списка ещё раз.';
    send_reply($config, $chat_id, $message, null, $user_id, $conn);
    return null;
}

function send_registration_confirmation($game_id, $chat_id, $user_id, $conn, $config) {
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
               "🎮 <b>" . htmlspecialchars($game['game_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</b>\n" .
               "📅 " . htmlspecialchars($game['game_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . " в " . htmlspecialchars($game['start_time'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" .
               "📍 " . htmlspecialchars($game['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

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
    send_telegram($config, $chat_id, $msg, $keyboard, 'HTML');

    // Логируем ответ бота
    log_bot_message($user_id, strip_tags($msg), $conn);
}

function handle_enter_team_button($data, $chat_id, $user_id, $conn, $config, $callback) {
    // Получаем game_id из callback_data: enter_team_{id}
    $game_id = (int) str_replace('enter_team_', '', $data);

    // Проверяем, существует ли уже регистрация пользователя на эту игру
    $stmt = $conn->prepare("
        SELECT id, team
        FROM registrations
        WHERE user_id = :uid AND game_id = :gid
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':gid' => $game_id
    ]);

    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($registration) {
        $reg_id = (int) $registration['id'];

        // Сбрасываем предыдущее название команды, чтобы пользователь мог ввести новое
        $stmtReset = $conn->prepare("UPDATE registrations SET team = NULL WHERE id = :rid");
        $stmtReset->execute([':rid' => $reg_id]);
    } else {
        // Создаём новую регистрацию: только user_id, game_id, created_at
        $stmtInsert = $conn->prepare("
            INSERT INTO registrations (user_id, game_id, created_at)
            VALUES (:uid, :gid, NOW())
        ");
        $stmtInsert->execute([
            ':uid' => $user_id,
            ':gid' => $game_id
        ]);
    }

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

    telegram_request($config, 'sendMessage', $params);

    // Логируем отправленную подсказку
    log_bot_message($user_id, strip_tags($text), $conn);
}



function handle_free_text($text, $chat_id, $user_id, $conn, $config) {
    if (!$user_id) {
        return 'Не удалось определить пользователя. Пожалуйста, отправьте команду /start.';
    }

    $teamName = trim($text);

    if ($teamName === '') {
        return 'Название команды не может быть пустым. Пожалуйста, отправьте текстовое название.';
    }

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
        send_telegram($config, $chat_id, $confirm, null, 'HTML');

        log_bot_message($user_id, strip_tags($confirm), $conn);
        return null;
    }

    // Fallback — если незавершённых регистраций нет
    return "Спасибо за сообщение! Напишите /игры, чтобы посмотреть ближайшие события.";
}


# --------------------- УТИЛИТЫ ----------------------

function send_reply($config, $chat_id, $text, $keyboard, $user_id, $conn) {
    send_telegram($config, $chat_id, $text, $keyboard, 'HTML');
    log_bot_message($user_id, strip_tags($text), $conn);
}

function send_telegram($config, $chat_id, $text, $keyboard = null, $parseMode = null) {
    $params = [
        'chat_id' => $chat_id,
        'text'    => $text
    ];

    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }

    if ($parseMode) {
        $params['parse_mode'] = $parseMode;
    }

    telegram_request($config, 'sendMessage', $params);
}

function log_bot_message($user_id, $text, $conn) {
    if (!$user_id) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO messages (user_id, message, from_bot)
        VALUES (:uid, :msg, 1)
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':msg' => $text
    ]);
}
