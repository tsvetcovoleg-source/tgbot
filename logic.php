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

    foreach ($games as $game) {
        $text = "🎮 <b>{$game['game_number']}</b>\n📅 {$game['game_date']} в {$game['start_time']}\n📍 {$game['location']}\n💰 {$game['price']}";
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📥 Зарегистрироваться на игру', 'callback_data' => 'register_' . $game['id']]
                ]
            ]
        ];

        send_telegram($config, $chat_id, $text, $keyboard, 'HTML');
        log_bot_message($user_id, strip_tags($text), $conn);
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
        // Фиксируем новую регистрацию сразу после нажатия кнопки
        try {
            $registrationId = create_pending_registration($conn, $user_id, $game_id);
        } catch (Throwable $e) {
            $errorText = "❌ Не удалось создать новую регистрацию. Пожалуйста, попробуйте ещё раз чуть позже.";
            send_telegram($config, $chat_id, $errorText, null, 'HTML');
            log_bot_message($user_id, strip_tags($errorText), $conn);
            return;
        }

        // Формируем сообщение
        $msg = "✅ Вы зарегистрированы на игру:\n\n" .
               "🎮 <b>{$game['game_number']}</b>\n" .
               "📅 {$game['game_date']} в {$game['start_time']}\n" .
               "📍 {$game['location']}";

        // Инлайн-кнопка "Ввести название команды"
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 Ввести название команды', 'callback_data' => 'enter_team_' . $registrationId]
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
    // Получаем идентификатор из callback_data: enter_team_{registration_id} (новый формат)
    $identifier = (int) str_replace('enter_team_', '', $data);

    $stmt = $conn->prepare("
        SELECT id, game_id, team
        FROM registrations
        WHERE id = :rid AND user_id = :uid
        LIMIT 1
    ");
    $stmt->execute([
        ':rid' => $identifier,
        ':uid' => $user_id
    ]);

    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    $isAdditionalTeam = false;

    if ($registration) {
        // Если регистрация уже завершена (команда указана), создаём новую запись,
        // чтобы текущая команда осталась в базе, а пользователь смог добавить ещё одну.
        if ($registration['team'] !== null && $registration['team'] !== '' && !is_pending_team($registration['team'])) {
            $newId = create_pending_registration($conn, $user_id, (int) $registration['game_id']);
            $registration = [
                'id' => $newId,
                'game_id' => (int) $registration['game_id'],
                'team' => null,
            ];
            $isAdditionalTeam = true;
        }
    } else {
        // Fallback на старый формат callback'а: enter_team_{game_id}
        $game_id = $identifier;

        $stmtGame = $conn->prepare("
            SELECT id
            FROM games
            WHERE id = :gid
            LIMIT 1
        ");
        $stmtGame->execute([':gid' => $game_id]);

        if (!$stmtGame->fetchColumn()) {
            return;
        }

        $newId = create_pending_registration($conn, $user_id, $game_id);

        $registration = [
            'id' => $newId,
            'game_id' => $game_id,
            'team' => null
        ];
    }

    $reg_id = (int) $registration['id'];

    // Сообщение-подсказка
    $text = "📝 В ответе на это сообщение введите <b>название вашей команды</b>.";

    if ($isAdditionalTeam) {
        $text .= "\n\n➕ Эта запись позволит добавить ещё одну команду на выбранную игру.";
    }

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
        WHERE user_id = :uid AND (
            team IS NULL OR team = '' OR team LIKE '__pending__%'
        )
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


function create_pending_registration(PDO $conn, int $user_id, int $game_id): int
{
    $sql = "INSERT INTO registrations (user_id, game_id, team, created_at)\n" .
           "VALUES (:uid, :gid, :team, NOW())";

    $attempts = 0;
    $maxAttempts = 5;

    do {
        $attempts++;
        $teamPlaceholder = generate_pending_team_token();
        $stmt = $conn->prepare($sql);

        try {
            $stmt->execute([
                ':uid' => $user_id,
                ':gid' => $game_id,
                ':team' => $teamPlaceholder,
            ]);

            return (int) $conn->lastInsertId();
        } catch (PDOException $e) {
            $errorCode = $e->getCode();

            if ($errorCode === '23000') {
                ensure_multiple_registrations_allowed($conn);
            } elseif ($errorCode === 'HY000' && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1615) {
                // MySQL can request a statement to be re-prepared after schema changes.
            } else {
                throw $e;
            }

            if ($attempts >= $maxAttempts) {
                throw $e;
            }
        }
    } while ($attempts < $maxAttempts);

    throw new RuntimeException('Не удалось создать новую регистрацию.');
}

function ensure_multiple_registrations_allowed(PDO $conn): void
{
    static $attempted = false;

    if ($attempted) {
        return;
    }

    $attempted = true;

    try {
        $sql = "
            SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'registrations'
              AND NON_UNIQUE = 0
              AND INDEX_NAME <> 'PRIMARY'
            GROUP BY INDEX_NAME
        ";

        $stmt = $conn->query($sql);

        $indexes = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        foreach ($indexes as $index) {
            $columns = explode(',', $index['cols']);
            $normalized = array_map('trim', $columns);

            sort($normalized);

            if ($normalized === ['game_id', 'user_id']) {
                $indexName = str_replace('`', '``', $index['INDEX_NAME']);
                $conn->exec("ALTER TABLE registrations DROP INDEX `{$indexName}`");
            }
        }
    } catch (PDOException $e) {
        // Если не удалось изменить схему (нет прав или индекс уже удалён), просто продолжаем попытки вставки.
    }
}

function generate_pending_team_token(): string
{
    try {
        return '__pending__' . bin2hex(random_bytes(6));
    } catch (Exception $e) {
        return '__pending__' . uniqid();
    }
}

function is_pending_team(?string $team): bool
{
    if ($team === null) {
        return false;
    }

    return strncmp($team, '__pending__', 11) === 0;
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
