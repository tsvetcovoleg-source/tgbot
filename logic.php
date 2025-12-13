<?php

function handle_message($text, $user_id, $chat_id, $config, $conn, $callback = null, $telegramMessageId = null, $storedMessageId = null) {
    $original_text = trim($text);
    $text_lower = mb_strtolower($original_text);

    if (strpos($text_lower, '/start') === 0) {
        $payload = trim(mb_substr($original_text, mb_strlen('/start')));
        if ($payload !== '') {
            update_user_status($conn, $user_id, 1);
            return handle_start_with_payload($chat_id, $user_id, $conn, $config, $payload, $telegramMessageId, $storedMessageId);
        }

        update_user_status($conn, $user_id, 1);
    }

    // === Маршрутизация сообщений ===
    $routes = [
        '/start' => 'handle_start_command',
        'игры'   => 'handle_games_command',
        '/игры'  => 'handle_games_command'
        // Добавляй сюда другие команды
    ];

    if (isset($routes[$text_lower])) {
        update_user_status($conn, $user_id, 1);
        return $routes[$text_lower]($chat_id, $user_id, $conn, $config);
    }

    if (preg_match('/^\s*я\s+хочу\s+зарегистрироваться\s+на\s+игру\s+[«"]?(?P<title>.+?)[»"]?\s*$/ui', $original_text, $match)) {
        $gameTitle = trim($match['title']);
        if ($gameTitle !== '') {
            update_user_status($conn, $user_id, 1);
            return handle_text_registration_request($gameTitle, $chat_id, $user_id, $conn, $config);
        }
    }

    // fallback для обычного текста
    return handle_free_text($text, $chat_id, $user_id, $conn, $config);
}

function handle_start_with_payload($chat_id, $user_id, $conn, $config, $payload, $telegramMessageId = null, $storedMessageId = null)
{
    update_user_status($conn, $user_id, 1);

    if ($payload === 'quiz') {
        if ($telegramMessageId) {
            delete_message_silently($config, $chat_id, $telegramMessageId);
        }

        return handle_quiz_games_command($chat_id, $user_id, $conn, $config);
    }

    if ($payload === 'detective') {
        if ($telegramMessageId) {
            delete_message_silently($config, $chat_id, $telegramMessageId);
        }

        return handle_detective_games_command($chat_id, $user_id, $conn, $config);
    }

    if ($payload === 'quest') {
        if ($telegramMessageId) {
            delete_message_silently($config, $chat_id, $telegramMessageId);
        }

        return handle_quest_games_command($chat_id, $user_id, $conn, $config);
    }

    if (strpos($payload, 'register_') === 0) {
        $game_id = (int) mb_substr($payload, mb_strlen('register_'));
        if ($game_id > 0) {
            $game = fetch_game_by_id($conn, $game_id);

            if ($game) {
                $userRequestText = sprintf('Я хочу зарегистрироваться на игру «%s»', $game['game_number']);

                if ($storedMessageId) {
                    overwrite_logged_message($conn, $storedMessageId, $userRequestText);
                }

                if ($telegramMessageId) {
                    delete_message_silently($config, $chat_id, $telegramMessageId);
                }

                return handle_register_button('register_' . $game_id, $chat_id, $user_id, $conn, $config, null, $game);
            }

            return handle_register_button('register_' . $game_id, $chat_id, $user_id, $conn, $config, null);
        }
    }

    return handle_start_command($chat_id, $user_id, $conn, $config);
}

function handle_callback($data, $user_id, $chat_id, $config, $conn, $callback) {
    update_user_status($conn, $user_id, 1);

    // === Маршрутизация callback'ов ===
    if ($data === 'show_games') {
        return handle_games_command($chat_id, $user_id, $conn, $config);
    }

    if ($data === 'show_game_formats') {
        return handle_game_formats_info($chat_id, $user_id, $conn, $config);
    }

    if ($data === 'show_quiz_games') {
        return handle_quiz_games_command($chat_id, $user_id, $conn, $config);
    }

    // было: if (str_starts_with($data, 'register_')) {
    if (strpos($data, 'register_') === 0) {
        return handle_register_button($data, $chat_id, $user_id, $conn, $config, $callback, null);
    }

    if (strpos($data, 'enter_team_') === 0) {
        return handle_enter_team_button($data, $chat_id, $user_id, $conn, $config, $callback);
    }

    if (strpos($data, 'quantity_') === 0) {
        return handle_quantity_selection($data, $chat_id, $user_id, $conn, $config, $callback);
    }


    // можно добавить другие...
}


# --------------------- ОБРАБОТЧИКИ КОМАНД ----------------------

function handle_start_command($chat_id, $user_id, $conn, $config) {
    $message = "Привет! 👋\nДобро пожаловать в MindGames Bot — место, где начинаются ваши игры и впечатления.\nЗапись на события, информация о формате, детали о нас и возможность заказать мероприятие — всё тут.\nЧто хотите сделать? 👇";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📋 Посмотреть список игр', 'callback_data' => 'show_games']
            ],
            [
                ['text' => 'ℹ️ Узнать про формат игр', 'callback_data' => 'show_game_formats']
            ]
        ]
    ];

    send_reply($config, $chat_id, $message, $keyboard, $user_id, $conn);
    return null;
}

function handle_games_command($chat_id, $user_id, $conn, $config) {
    $games = fetch_games($conn);

    if (!$games) {
        send_reply($config, $chat_id, "Пока нет активных игр 😢", null, $user_id, $conn);
        return null;
    }

    $text = "📋 <b>Список доступных игр:</b>\n\n" . build_games_message($games, $config);

    send_telegram($config, $chat_id, $text, null, 'HTML');
    log_bot_message($user_id, strip_tags($text), $conn);

    return null;
}

function handle_game_formats_info($chat_id, $user_id, $conn, $config) {
    $botUsername = get_bot_username($config);
    if ($botUsername === null) {
        send_reply($config, $chat_id, 'Не удалось сформировать ссылку. Попробуйте позже или отправьте команду /игры.', null, $user_id, $conn);
        return null;
    }
    $quizLink = sprintf('https://t.me/%s?start=quiz', rawurlencode($botUsername));
    $detectiveLink = sprintf('https://t.me/%s?start=detective', rawurlencode($botUsername));
    $questLink = sprintf('https://t.me/%s?start=quest', rawurlencode($botUsername));

    $message = "✨ Паб-квиз\n" .
        "Паб-квиз — это командная интеллектуальная игра MindGames с вопросами на логику, эрудицию и весёлые ассоциации. Настоящая классика наших мероприятий!\n" .
        '<a href="' . htmlspecialchars($quizLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">👉 Узнать, когда ближайшие игры паб-квиза</a>' .
        "\n\n🕵️‍♂️ Saint Twins Detective\n" .
        "Saint Twins Detective — это детективная игра-расследование с погружением в сюжет, уликами, версиями и неожиданными поворотами. Отлично подходит тем, кто любит загадки и атмосферу детектива.\n" .
        '<a href="' . htmlspecialchars($detectiveLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">👉 Узнать, когда ближайшая детективная игра</a>' .
        "\n\n🚗 Квест на автомобилях\n" .
        "Авто-квест — это динамичная городская игра MindGames, где вы разгадываете загадки, ищете точки по городу и проходите задания в реальном времени. Много драйва, движения и эмоций!\n" .
        '<a href="' . htmlspecialchars($questLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">👉 Узнать, когда ближайший авто-квест</a>';

    send_reply($config, $chat_id, $message, null, $user_id, $conn);

    return null;
}

function handle_quiz_games_command($chat_id, $user_id, $conn, $config)
{
    return handle_games_by_types($chat_id, $user_id, $conn, $config, ['quiz', 'lightquiz'], 'Список ближайших игр:', 'Пока нет активных квизов 😢');
}

function handle_detective_games_command($chat_id, $user_id, $conn, $config)
{
    return handle_games_by_types($chat_id, $user_id, $conn, $config, ['detective'], 'Список ближайших игр:', 'Пока нет активных детективов 😢');
}

function handle_quest_games_command($chat_id, $user_id, $conn, $config)
{
    return handle_games_by_types($chat_id, $user_id, $conn, $config, ['quest'], 'Список ближайших игр:', 'Пока нет активных квестов 😢');
}

function handle_register_button($data, $chat_id, $user_id, $conn, $config, $callback, $prefetchedGame = null) {
    $game_id = (int) str_replace('register_', '', $data);

    send_registration_confirmation($game_id, $chat_id, $user_id, $conn, $config, $prefetchedGame);
}

function handle_games_by_types($chat_id, $user_id, $conn, $config, array $types, $title, $emptyMessage)
{
    $games = fetch_games($conn, $types);

    if (!$games) {
        send_reply($config, $chat_id, $emptyMessage, null, $user_id, $conn);
        return null;
    }

    $text = $title . "\n\n" . build_games_message($games, $config);

    send_telegram($config, $chat_id, $text, null, 'HTML');
    log_bot_message($user_id, strip_tags($text), $conn);

    return null;
}

function handle_text_registration_request($gameTitle, $chat_id, $user_id, $conn, $config) {
    update_user_status($conn, $user_id, 1);

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

function send_registration_confirmation($game_id, $chat_id, $user_id, $conn, $config, $prefetchedGame = null) {
    update_user_status($conn, $user_id, 1);

    $game = $prefetchedGame ?? fetch_game_by_id($conn, $game_id);

    if ($game) {
        $formattedDateTime = format_game_datetime($game['game_date'], $game['start_time']);
        $formattedDateTimeEscaped = htmlspecialchars(
            $formattedDateTime ?? trim($game['game_date'] . ' ' . $game['start_time']),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $msg = "✅ Отличный выбор!\n\n" .
               "🎮 " . htmlspecialchars($game['game_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" .
               "📅 " . $formattedDateTimeEscaped . "\n" .
               "📍 " . htmlspecialchars($game['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" .
               "💰 " . htmlspecialchars($game['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n" .
               "Готовы присоединиться к игре? Тогда просто введите название своей команды 👇";

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
    update_user_status($conn, $user_id, 1);

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

        // Сбрасываем предыдущее название команды и количество, чтобы пользователь мог ввести новое
        $stmtReset = $conn->prepare("UPDATE registrations SET team = NULL, quantity = NULL WHERE id = :rid");
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

    $userInput = trim($text);

    if ($userInput === '') {
        return 'Название команды не может быть пустым. Пожалуйста, отправьте текстовое название.';
    }

    // Ищем самую свежую регистрацию без названия команды или количества
    $stmt = $conn->prepare("
        SELECT id, team, quantity
        FROM registrations
        WHERE user_id = :uid AND (team IS NULL OR team = '' OR quantity IS NULL)
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        // Fallback — если незавершённых регистраций нет
        $currentStatus = fetch_user_status($conn, $user_id);

        if ((int) $currentStatus === 2) {
            return null;
        }

        update_user_status($conn, $user_id, 2);

        return "Спасибо за сообщение! Напишите /игры, чтобы посмотреть ближайшие события.";
    }

    update_user_status($conn, $user_id, 1);

    $registrationHasTeam = isset($registration['team']) && trim($registration['team']) !== '';

    if (!$registrationHasTeam) {
        // Обновляем team тем, что прислал пользователь, и просим указать количество игроков
        $stmtUp = $conn->prepare("UPDATE registrations SET team = :team WHERE id = :rid");
        $stmtUp->execute([
            ':team' => $userInput,
            ':rid'  => $registration['id']
        ]);

        $askQuantity = "Отлично! Теперь выберите, сколько человек будет в вашей команде 👇";
        $keyboard = build_quantity_keyboard();
        send_telegram($config, $chat_id, $askQuantity, $keyboard, 'HTML');
        log_bot_message($user_id, strip_tags($askQuantity), $conn);
        return null;
    }

    // Если команда уже указана, ожидаем количество игроков
    $quantity = normalize_quantity_input($userInput);

    if ($quantity === null) {
        $askQuantityAgain = "Пожалуйста, выберите подходящий вариант на кнопке или укажите количество числом.";
        $keyboard = build_quantity_keyboard();
        send_telegram($config, $chat_id, $askQuantityAgain, $keyboard, 'HTML');
        log_bot_message($user_id, strip_tags($askQuantityAgain), $conn);
        return null;
    }

    save_quantity_and_confirm($conn, $config, $chat_id, $user_id, $registration, $quantity);
    return null;
}

function handle_quantity_selection($data, $chat_id, $user_id, $conn, $config, $callback) {
    $selectedKey = str_replace('quantity_', '', $data);
    $options = get_quantity_options();

    $selectedQuantity = null;
    foreach ($options as $label => $key) {
        if ($key === $selectedKey) {
            $selectedQuantity = $label;
            break;
        }
    }

    if ($selectedQuantity === null) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id, team
        FROM registrations
        WHERE user_id = :uid
          AND team IS NOT NULL AND team != ''
          AND quantity IS NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    $registration = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$registration) {
        return null;
    }

    save_quantity_and_confirm($conn, $config, $chat_id, $user_id, $registration, $selectedQuantity);

    return null;
}

function get_quantity_options() {
    return [
        '3-4'          => '3_4',
        '5-6'          => '5_6',
        '7-8'          => '7_8',
        '9-10'         => '9_10',
        'Пока не знаем' => 'unknown',
    ];
}

function build_quantity_keyboard() {
    $options = get_quantity_options();
    $keyboard = ['inline_keyboard' => []];

    foreach ($options as $label => $key) {
        $keyboard['inline_keyboard'][] = [
            ['text' => $label, 'callback_data' => 'quantity_' . $key]
        ];
    }

    return $keyboard;
}

function normalize_quantity_input($input) {
    $trimmed = trim($input);

    if ($trimmed === '') {
        return null;
    }

    $options = get_quantity_options();
    foreach ($options as $label => $key) {
        if (mb_strtolower($trimmed) === mb_strtolower($label)) {
            return $label;
        }
    }

    if (preg_match('/^(\d+)\s*-\s*(\d+)$/u', $trimmed, $matches)) {
        return $matches[1] . '-' . $matches[2];
    }

    $quantityInt = filter_var($trimmed, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    if ($quantityInt !== false) {
        return (string) $quantityInt;
    }

    return null;
}

function save_quantity_and_confirm($conn, $config, $chat_id, $user_id, $registration, $quantity) {
    $stmtUp = $conn->prepare("UPDATE registrations SET quantity = :qty WHERE id = :rid");
    $stmtUp->execute([
        ':qty' => $quantity,
        ':rid' => $registration['id']
    ]);

    $teamEscaped = htmlspecialchars($registration['team'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $quantityEscaped = htmlspecialchars($quantity, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $confirm = "✅ Команда «" . $teamEscaped . "» сохранена.\nРазмер команды: " . $quantityEscaped . ".";
    send_telegram($config, $chat_id, $confirm, null, 'HTML');

    log_bot_message($user_id, strip_tags($confirm), $conn);
}


# --------------------- ДОПОЛНИТЕЛЬНЫЕ ХЕЛПЕРЫ ----------------------

function fetch_games($conn, $type = null)
{
    $query = "
        SELECT id, game_number, game_date, start_time, location, price
        FROM games
    ";

    $params = [];

    if ($type !== null) {
        if (is_array($type)) {
            $placeholders = [];
            foreach ($type as $idx => $value) {
                $placeholder = ':type' . $idx;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $value;
            }

            if ($placeholders) {
                $query .= ' WHERE type IN (' . implode(', ', $placeholders) . ')';
            }
        } else {
            $query .= " WHERE type = :type";
            $params[':type'] = $type;
        }
    }

    $query .= " ORDER BY game_date ASC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function build_games_message(array $games, array $config)
{
    $messages = [];

    $botUsername = get_bot_username($config);

    foreach ($games as $game) {
        $gameNumberEscaped = htmlspecialchars($game['game_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locationEscaped = htmlspecialchars($game['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $priceEscaped = htmlspecialchars($game['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $formattedDateTime = format_game_datetime($game['game_date'], $game['start_time']);
        $formattedDateTimeEscaped = htmlspecialchars(
            $formattedDateTime ?? trim($game['game_date'] . ' ' . $game['start_time']),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $shareLink = null;

        if ($botUsername !== null) {
            $shareLink = sprintf(
                'https://t.me/%s?start=register_%d',
                rawurlencode($botUsername),
                (int) $game['id']
            );
        }

        $messageText = "🎮 {$gameNumberEscaped}\n" .
            "📅 {$formattedDateTimeEscaped}\n" .
            "📍 {$locationEscaped}\n" .
            "💰 {$priceEscaped}\n\n";

        if ($shareLink !== null) {
            $messageText .= '<a href="' . htmlspecialchars($shareLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">✉️ Зарегистрироваться на игру</a>';
        } else {
            $messageText .= "Отправьте /start, чтобы открыть бота и зарегистрироваться.";
        }

        $messages[] = $messageText;
    }

    return implode("\n\n", $messages);
}

function format_game_datetime(string $date, string $time)
{
    $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', trim($date . ' ' . $time));

    if (!$dateTime) {
        $dateTime = DateTime::createFromFormat('Y-m-d H:i', trim($date . ' ' . $time));
    }

    if (!$dateTime) {
        $dateTime = DateTime::createFromFormat('Y-m-d', trim($date));
    }

    if (!$dateTime) {
        return null;
    }

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

    $monthNumber = (int) $dateTime->format('n');
    $monthName = $months[$monthNumber] ?? $dateTime->format('m');

    $formattedDate = sprintf(
        '%s %s %s',
        $dateTime->format('d'),
        $monthName,
        $dateTime->format('Y')
    );

    $formattedTime = $dateTime->format('H:i');

    return sprintf('%s, %s', $formattedDate, $formattedTime);
}

function fetch_game_by_id($conn, $game_id)
{
    $stmt = $conn->prepare("
        SELECT id, game_number, game_date, start_time, location, price
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $game_id]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    return $game !== false ? $game : null;
}

function overwrite_logged_message($conn, $messageId, $text)
{
    if (!$messageId) {
        return;
    }

    $stmt = $conn->prepare("UPDATE messages SET message = :msg WHERE id = :id");
    $stmt->execute([
        ':msg' => $text,
        ':id'  => $messageId
    ]);
}

function delete_message_silently($config, $chat_id, $telegramMessageId)
{
    if (!$telegramMessageId) {
        return false;
    }

    $response = telegram_request($config, 'deleteMessage', [
        'chat_id'    => $chat_id,
        'message_id' => $telegramMessageId
    ]);

    if ($response === null) {
        return false;
    }

    if (isset($response['ok'])) {
        return (bool) $response['ok'];
    }

    return true;
}

function send_user_request_echo($config, $chat_id, $text)
{
    telegram_request($config, 'sendMessage', [
        'chat_id' => $chat_id,
        'text'    => $text
    ]);
}

# --------------------- СТАТУС ПОЛЬЗОВАТЕЛЯ ----------------------

function fetch_user_status($conn, $user_id)
{
    if (!$user_id) {
        return null;
    }

    $stmt = $conn->prepare('SELECT status FROM users WHERE id = :id');
    $stmt->execute([':id' => $user_id]);

    return $stmt->fetchColumn();
}

function update_user_status($conn, $user_id, $status)
{
    if (!$user_id) {
        return;
    }

    $currentStatus = fetch_user_status($conn, $user_id);

    if ((int) $currentStatus === (int) $status) {
        return;
    }

    $stmt = $conn->prepare('UPDATE users SET status = :status WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':id' => $user_id
    ]);
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
