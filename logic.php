<?php

require_once __DIR__ . '/format_helpers.php';
require_once __DIR__ . '/group_bridge.php';

function handle_message($text, $user_id, $chat_id, $config, $conn, $callback = null, $telegramMessageId = null, $storedMessageId = null, $isNewUser = false) {
    $original_text = trim($text);
    $text_lower = mb_strtolower($original_text);

    if (strpos($text_lower, '/start') === 0) {
        $payload = trim(mb_substr($original_text, mb_strlen('/start')));
        if ($payload !== '') {
            update_user_status($conn, $user_id, 1);
            if ($isNewUser) {
                handle_start_command($chat_id, $user_id, $conn, $config);
            }
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

    if (preg_match('/^(?P<game_id>\d+)(?:[_\/-])lot$/iu', $payload, $match)) {
        $gameId = (int) ($match['game_id'] ?? 0);
        if ($gameId > 0) {
            if ($telegramMessageId) {
                delete_message_silently($config, $chat_id, $telegramMessageId);
            }

            return handle_lot_deep_link($chat_id, $user_id, $conn, $config, $gameId);
        }
    }

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

    if (strpos($data, 'register_') === 0) {
        return handle_register_button($data, $chat_id, $user_id, $conn, $config, $callback, null);
    }

    if (strpos($data, 'enter_team_') === 0) {
        return handle_enter_team_button($data, $chat_id, $user_id, $conn, $config, $callback);
    }

    if (strpos($data, 'quantity_') === 0) {
        return handle_quantity_selection($data, $chat_id, $user_id, $conn, $config, $callback);
    }

    if (strpos($data, 'team_suggestion_') === 0) {
        return handle_team_suggestion_selection($data, $chat_id, $user_id, $conn, $config);
    }

    if (strpos($data, 'lot_bet_') === 0) {
        return handle_lot_bet_selection($data, $chat_id, $user_id, $conn, $config, $callback);
    }

    if (strpos($data, 'subscribe_format_') === 0) {
        return handle_subscribe_format_button($data, $chat_id, $user_id, $conn, $config);
    }


    // можно добавить другие...
}


# --------------------- ОБРАБОТЧИКИ КОМАНД ----------------------

function handle_start_command($chat_id, $user_id, $conn, $config) {
    $message = "Привет! 👋\nЯ твой новый Бадди. Добро пожаловать в мир MindGames — тут скука не выживает, а дофамин чувствует себя отлично 😏\nИгры, форматы и календарь мероприятий — всё под рукой.\nС чего начнём? 👇";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📅 Календарь игр', 'callback_data' => 'show_games']
            ],
            [
                ['text' => 'ℹ️ Какие игры у нас есть?', 'callback_data' => 'show_game_formats']
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

    $text = "📋 <b>Вот список мероприятий, которые, на данный момент, нами запланированы:</b>\n\n\n" . build_games_message($games, $config);

    send_telegram($config, $chat_id, $text, null, 'HTML');
    log_bot_message($user_id, strip_tags($text), $conn);

    return null;
}

function handle_game_formats_info($chat_id, $user_id, $conn, $config) {
    $message = build_format_info_message($config);

    if ($message === null) {
        send_reply($config, $chat_id, 'Не удалось сформировать ссылку. Попробуйте позже или отправьте команду /игры.', null, $user_id, $conn);
        return null;
    }

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
        $primaryFormat = resolve_primary_format($types);
        if ($primaryFormat !== null) {
            $formatDisplay = get_format_display_name($primaryFormat);
            $message = 'Упс — пока в расписании нет игр формата ' . $formatDisplay . " 🙈\n" .
                'Хотите, чтобы мы сразу сообщили вам, как только будет запланирована новая игра этого формата? 👇';

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Да, хочу быть в курсе', 'callback_data' => 'subscribe_format_' . $primaryFormat]
                    ]
                ]
            ];

            send_reply($config, $chat_id, $message, $keyboard, $user_id, $conn);
            return null;
        }

        send_reply($config, $chat_id, $emptyMessage, null, $user_id, $conn);
        return null;
    }

    $textPrefix = build_game_format_intro($types, $config);
    $text = $textPrefix . $title . "\n\n" . build_games_message($games, $config);

    send_telegram($config, $chat_id, $text, null, 'HTML');
    log_bot_message($user_id, strip_tags($text), $conn);

    return null;
}

function build_game_format_intro(array $types, array $config): string
{
    $primaryFormat = resolve_primary_format($types);
    if ($primaryFormat === null) {
        return '';
    }

    $message = build_format_info_message($config, [$primaryFormat], false);

    if ($message === null) {
        return '';
    }

    return $message . "\n\n";
}

function build_format_info_message(array $config, array $onlyFormats = null, bool $includeLinks = true): ?string
{
    $definitions = get_game_format_definitions();

    if ($onlyFormats !== null) {
        $definitions = array_filter(
            $definitions,
            function ($key) use ($onlyFormats) {
                return in_array($key, $onlyFormats, true);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    if (!$definitions) {
        return '';
    }

    $botUsername = get_bot_username($config);
    if ($botUsername === null) {
        return null;
    }

    $messages = [];

    foreach ($definitions as $definition) {
        $requiredKeys = ['start_payload', 'title', 'description'];

        if ($includeLinks) {
            $requiredKeys[] = 'link_text';
        }

        foreach ($requiredKeys as $key) {
            if (!isset($definition[$key])) {
                continue 2;
            }
        }

        $message = $definition['title'] . "\n" . $definition['description'];

        if ($includeLinks) {
            $link = sprintf('https://t.me/%s?start=%s', rawurlencode($botUsername), rawurlencode($definition['start_payload']));

            $message .= "\n" . '<a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' .
                htmlspecialchars($definition['link_text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }

        $messages[] = $message;
    }

    return implode("\n\n", $messages);
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

    $existingRegistration = fetch_latest_registration($conn, $user_id, $game_id);

    if (
        $existingRegistration
        && $existingRegistration['team'] !== null
        && $existingRegistration['quantity'] !== null
    ) {
        $message = 'Вы уже зарегистрировали свою команду на эту игру, если у вас что-то изменилось или есть дополнительный запрос, то просто напишите сюда в чат и мы ответим вам при первой возможности';
        send_reply($config, $chat_id, $message, null, $user_id, $conn);
        return;
    }

    $game = $prefetchedGame ?? fetch_game_by_id($conn, $game_id);
    $teamSuggestionsKeyboard = null;

    if ($game) {
        $gameStatus = (int) ($game['status'] ?? 1);

        if ($gameStatus === 3) {
            $message = '❌ Регистрация на эту игру закрыта. Пожалуйста, выберите другую игру из списка.';
            send_reply($config, $chat_id, $message, null, $user_id, $conn);
            return;
        }

        prepare_registration_for_team_entry($conn, $user_id, $game_id, $existingRegistration, $game);

        $formattedDateTime = format_game_datetime($game['game_date'], $game['start_time']);
        $formattedDateTimeEscaped = htmlspecialchars(
            $formattedDateTime ?? trim($game['game_date'] . ' ' . $game['start_time']),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

        $teamSuggestionsKeyboard = build_team_suggestions_keyboard($conn, $user_id);
        $statusDetails = get_game_status_details($gameStatus);
        $statusNotice = isset($statusDetails['description']) ? $statusDetails['description'] : '';

        $teamPromptTextWithChoices = "Готовы присоединиться к игре?\nТогда просто введите название своей команды или выберите его из списка ниже 👇";
        $teamPromptTextWithoutChoices = "Готовы присоединиться к игре? Тогда просто введите название команды в ответ на это сообщение.";
        $teamPrompt = ($statusNotice !== '' ? $statusNotice . "\n\n" : '') .
            ($teamSuggestionsKeyboard !== null ? $teamPromptTextWithChoices : $teamPromptTextWithoutChoices);

        $msg = "✅ Отличный выбор!\n\n" .
               "🎮 " . htmlspecialchars($game['game_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" .
               "📅 " . $formattedDateTimeEscaped . "\n" .
               "📍 " . htmlspecialchars($game['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n" .
               "💰 " . htmlspecialchars($game['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n\n" .
               $teamPrompt;

    } else {
        // Если игра не найдена (например, удалили из БД)
        $msg = "❌ Игра с ID $game_id не найдена.";
        $keyboard = null;
    }

    // Отправляем пользователю
    $replyMarkup = $teamSuggestionsKeyboard ?? ['remove_keyboard' => true];

    send_telegram($config, $chat_id, $msg, $replyMarkup, 'HTML');

    // Логируем ответ бота
    log_bot_message($user_id, strip_tags($msg), $conn);
}

function prepare_registration_for_team_entry($conn, $user_id, $game_id, $existingRegistration = null, $game = null) {
    if ($existingRegistration === null) {
        $existingRegistration = fetch_latest_registration($conn, $user_id, $game_id);
    }

    $gameStatus = null;

    if ($game !== null && isset($game['status'])) {
        $gameStatus = (int) $game['status'];
    } else {
        $gameData = fetch_game_by_id($conn, $game_id);
        if ($gameData !== null && isset($gameData['status'])) {
            $gameStatus = (int) $gameData['status'];
        }
    }

    if ($gameStatus === null) {
        $gameStatus = 1;
    }

    if ($existingRegistration) {
        if ($existingRegistration['team'] !== null && $existingRegistration['quantity'] !== null) {
            return;
        }

        $stmtReset = $conn->prepare("UPDATE registrations SET team = NULL, quantity = NULL, status = :status WHERE id = :rid");
        $stmtReset->execute([
            ':status' => $gameStatus,
            ':rid' => $existingRegistration['id']
        ]);
        return;
    }

    $stmtInsert = $conn->prepare("
        INSERT INTO registrations (user_id, game_id, status, created_at)
        VALUES (:uid, :gid, :status, NOW())
    ");
    $stmtInsert->execute([
        ':uid' => $user_id,
        ':gid' => $game_id,
        ':status' => $gameStatus
    ]);
}

function fetch_latest_registration($conn, $user_id, $game_id)
{
    $stmt = $conn->prepare("
        SELECT id, team, quantity, status, game_id
        FROM registrations
        WHERE user_id = :uid AND game_id = :gid
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':uid' => $user_id,
        ':gid' => $game_id
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function handle_enter_team_button($data, $chat_id, $user_id, $conn, $config, $callback) {
    update_user_status($conn, $user_id, 1);

    // Получаем game_id из callback_data: enter_team_{id}
    $game_id = (int) str_replace('enter_team_', '', $data);

    $game = fetch_game_by_id($conn, $game_id);
    prepare_registration_for_team_entry($conn, $user_id, $game_id, null, $game);

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

    $params['reply_markup'] = json_encode(['remove_keyboard' => true], JSON_UNESCAPED_UNICODE);

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

    $pendingLot = fetch_pending_game_lot($conn, $user_id);
    if ($pendingLot) {
        update_user_status($conn, $user_id, 1);
        save_lot_team_and_request_bet($conn, $config, $chat_id, $user_id, $pendingLot, $userInput);
        return null;
    }

    // Ищем самую свежую регистрацию без названия команды или количества
    $stmt = $conn->prepare("
        SELECT id, team, quantity, game_id, status
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

        $message = "Сообщение получил 👍\n" .
            "Человеческий помощник подключится совсем скоро.\n" .
            "Пока ждёте — можете посмотреть список игр или узнать больше о MindGames через кнопки ниже 👇";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📅 Календарь игр', 'callback_data' => 'show_games']
                ],
                [
                    ['text' => 'ℹ️ Какие игры у нас есть?', 'callback_data' => 'show_game_formats']
                ]
            ]
        ];

        send_reply($config, $chat_id, $message, $keyboard, $user_id, $conn);

        return null;
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
        SELECT id, team, game_id, status
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

function handle_lot_bet_selection($data, $chat_id, $user_id, $conn, $config, $callback)
{
    $betKey = str_replace('lot_bet_', '', $data);
    $options = get_lot_bet_options();

    if (!isset($options[$betKey])) {
        return null;
    }

    $lot = fetch_pending_game_lot($conn, $user_id);
    if (!$lot || trim((string) ($lot['team_name'] ?? '')) === '') {
        return null;
    }

    $betLabel = $options[$betKey];

    $stmt = $conn->prepare('UPDATE game_lot_bets SET bet_option = :bet WHERE id = :id');
    $stmt->execute([
        ':bet' => $betLabel,
        ':id' => (int) $lot['id'],
    ]);

    $teamEscaped = htmlspecialchars($lot['team_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $betEscaped = htmlspecialchars($betLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $confirm = "Умный ход. Мы всё записали: ставка {$betEscaped} для команды «{$teamEscaped}»\n\n" .
        "На будущее — держите наши основные кнопки, вдруг пригодятся 👇";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📅 Календарь игр', 'callback_data' => 'show_games']
            ],
            [
                ['text' => 'ℹ️ Какие игры у нас есть?', 'callback_data' => 'show_game_formats']
            ]
        ]
    ];

    send_telegram($config, $chat_id, $confirm, $keyboard, 'HTML');
    log_bot_message($user_id, strip_tags($confirm), $conn);

    return null;
}

function handle_lot_deep_link($chat_id, $user_id, $conn, $config, $gameId)
{
    $game = fetch_game_by_id($conn, $gameId);

    if (!$game) {
        send_reply($config, $chat_id, '❌ Игра не найдена. Проверьте ссылку и попробуйте снова.', null, $user_id, $conn);
        return null;
    }

    prepare_lot_for_team_entry($conn, $user_id, $gameId);

    $gameNameEscaped = htmlspecialchars($game['game_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $message = "Давайте посмотрим, какую ставку вы выберете для тематического раунда на игре «{$gameNameEscaped}».\n" .
        "Сперва введите название своей команды:";
    send_telegram($config, $chat_id, $message, ['remove_keyboard' => true], 'HTML');
    log_bot_message($user_id, strip_tags($message), $conn);

    return null;
}

function prepare_lot_for_team_entry($conn, $user_id, $gameId)
{
    $stmt = $conn->prepare('INSERT INTO game_lot_bets (user_id, game_id, created_at) VALUES (:uid, :gid, NOW())');
    $stmt->execute([
        ':uid' => (int) $user_id,
        ':gid' => (int) $gameId,
    ]);
}

function fetch_pending_game_lot($conn, $user_id)
{
    $stmt = $conn->prepare('
        SELECT id, game_id, team_name, bet_option
        FROM game_lot_bets
        WHERE user_id = :uid
          AND bet_option IS NULL
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute([':uid' => (int) $user_id]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_lot_bet_options()
{
    return [
        'plus1_0' => '+1 / 0',
        'plus2_minus2' => '+2 / -2',
    ];
}

function build_lot_bet_keyboard()
{
    $options = get_lot_bet_options();

    return [
        'inline_keyboard' => [
            [['text' => $options['plus1_0'], 'callback_data' => 'lot_bet_plus1_0']],
            [['text' => $options['plus2_minus2'], 'callback_data' => 'lot_bet_plus2_minus2']],
        ],
    ];
}

function save_lot_team_and_request_bet($conn, $config, $chat_id, $user_id, $lot, $teamName)
{
    $stmt = $conn->prepare('UPDATE game_lot_bets SET team_name = :team WHERE id = :id');
    $stmt->execute([
        ':team' => $teamName,
        ':id' => (int) $lot['id'],
    ]);

    $message = "А теперь нажмите на ставку, которую вы выбираете 👇";
    $keyboard = build_lot_bet_keyboard();

    send_telegram($config, $chat_id, $message, $keyboard, 'HTML');
    log_bot_message($user_id, strip_tags($message), $conn);
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

function get_recent_team_suggestions($conn, $user_id, $limit = 3) {
    $stmt = $conn->prepare("
        SELECT team
        FROM registrations
        WHERE user_id = :uid AND team IS NOT NULL AND team != ''
        ORDER BY id DESC
        LIMIT 10
    ");
    $stmt->execute([':uid' => $user_id]);

    $suggestions = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $team = trim($row['team']);

        if ($team === '') {
            continue;
        }

        if (!in_array($team, $suggestions, true)) {
            $suggestions[] = $team;
        }

        if (count($suggestions) >= $limit) {
            break;
        }
    }

    return $suggestions;
}

function build_team_suggestions_keyboard($conn, $user_id) {
    $suggestions = get_recent_team_suggestions($conn, $user_id);

    if (empty($suggestions)) {
        return null;
    }

    $keyboardButtons = [];

    foreach ($suggestions as $index => $teamName) {
        $keyboardButtons[] = [
            ['text' => $teamName, 'callback_data' => 'team_suggestion_' . $index]
        ];
    }

    return [
        'inline_keyboard' => $keyboardButtons
    ];
}

function handle_team_suggestion_selection($data, $chat_id, $user_id, $conn, $config) {
    $index = (int) str_replace('team_suggestion_', '', $data);

    $suggestions = get_recent_team_suggestions($conn, $user_id);

    if (!isset($suggestions[$index])) {
        $message = '❌ Не удалось определить выбранное название команды. Пожалуйста, введите название вручную.';
        send_reply($config, $chat_id, $message, null, $user_id, $conn);
        return null;
    }

    $teamName = $suggestions[$index];

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
        $message = '❌ Не удалось найти активную регистрацию. Пожалуйста, выберите игру из списка ещё раз.';
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📅 Календарь игр', 'callback_data' => 'show_games']
                ]
            ]
        ];

        send_reply($config, $chat_id, $message, $keyboard, $user_id, $conn);
        return null;
    }

    update_user_status($conn, $user_id, 1);

    $stmtUp = $conn->prepare("UPDATE registrations SET team = :team WHERE id = :rid");
    $stmtUp->execute([
        ':team' => $teamName,
        ':rid'  => $registration['id']
    ]);

    $askQuantity = "Отлично! Теперь выберите, сколько человек будет в вашей команде 👇";
    $keyboard = build_quantity_keyboard();

    send_telegram($config, $chat_id, $askQuantity, $keyboard, 'HTML');
    log_bot_message($user_id, strip_tags($askQuantity), $conn);

    return null;
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

    $confirm = null;
    $registrationStatus = isset($registration['status']) ? (int) $registration['status'] : null;

    if (!empty($registration['game_id'])) {
        $game = fetch_game_by_id($conn, $registration['game_id']);

        if ($game) {
            $gameNumberEscaped = htmlspecialchars($game['game_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $locationEscaped = htmlspecialchars($game['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $formattedDateTime = format_game_datetime($game['game_date'], $game['start_time']);
            $formattedDateTimeEscaped = htmlspecialchars(
                $formattedDateTime ?? trim($game['game_date'] . ' ' . $game['start_time']),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            if ($registrationStatus === 2) {
                $confirm = "Вы записались в резерв ✅\n\n" .
                    "Вот данные вашей регистрации:\n" .
                    "🎮 {$gameNumberEscaped}\n" .
                    "📅 {$formattedDateTimeEscaped}\n" .
                    "📍 {$locationEscaped}\n" .
                    "👥 Команда: «{$teamEscaped}» (Количество игроков: {$quantityEscaped})\n\n" .
                    "Если появится возможность разместить вашу команду, мы сразу сообщим об этом здесь.\n" .
                    "Остаёмся на связи 😊\n\n" .
                    "А пока — вот ваши основные кнопки, вдруг пригодятся 👇";
            } else {
                $confirm = "🎉 Вы успешно зарегистрированы!\n\n" .
                    "Вот данные вашей регистрации:\n\n" .
                    "🎮 {$gameNumberEscaped}\n" .
                    "📅 {$formattedDateTimeEscaped}\n" .
                    "📍 {$locationEscaped}\n" .
                    "👥 Команда: «{$teamEscaped}» (Количество игроков: {$quantityEscaped})\n\n" .
                    "Мы вас ждём! Если что-то нужно изменить — просто напишите в чат.\n\n" .
                    "А пока — вот ваши основные кнопки, вдруг пригодятся 👇";
            }
        }
    }

    if ($confirm === null) {
        $confirm = "✅ Команда «" . $teamEscaped . "» сохранена.\nРазмер команды: " . $quantityEscaped . ".\n\n" .
            "А пока — вот ваши основные кнопки, вдруг пригодятся 👇";
    }

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📅 Календарь игр', 'callback_data' => 'show_games']
            ],
            [
                ['text' => 'ℹ️ Какие игры у нас есть?', 'callback_data' => 'show_game_formats']
            ]
        ]
    ];

    send_telegram($config, $chat_id, $confirm, $keyboard, 'HTML');

    log_bot_message($user_id, strip_tags($confirm), $conn);

    if ($user_id) {
        mirror_registration_event($conn, $config, (int) $user_id, (string) $registration['team'], (string) $quantity);
        $gameName = null;
        $gameDateTime = null;

        if (!empty($game)) {
            $gameName = isset($game['game_number']) ? (string) $game['game_number'] : null;
            $gameDateTime = $formattedDateTime ?? trim(((string) ($game['game_date'] ?? '')) . ' ' . ((string) ($game['start_time'] ?? '')));
        }

        mirror_registration_event(
            $conn,
            $config,
            (int) $user_id,
            (string) $registration['team'],
            (string) $quantity,
            $gameName,
            $gameDateTime
        );
    }
}


# --------------------- ДОПОЛНИТЕЛЬНЫЕ ХЕЛПЕРЫ ----------------------

function fetch_games($conn, $type = null)
{
    $query = "
        SELECT id, game_number, game_date, start_time, location, price, type, status
        FROM games
        WHERE (game_date > CURDATE() OR (game_date = CURDATE() AND start_time >= CURTIME()))
          AND status <> 3
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
                $query .= ' AND type IN (' . implode(', ', $placeholders) . ')';
            }
        } else {
            $query .= " AND type = :type";
            $params[':type'] = $type;
        }
    }

    $query .= " ORDER BY game_date ASC, start_time ASC, id ASC";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function handle_subscribe_format_button($data, $chat_id, $user_id, $conn, $config)
{
    $format = str_replace('subscribe_format_', '', $data);

    if (!$user_id) {
        send_reply($config, $chat_id, 'Не удалось определить пользователя для подписки на уведомления.', null, $user_id, $conn);
        return null;
    }

    $knownFormats = get_known_game_formats();
    if (!in_array($format, $knownFormats, true)) {
        send_reply($config, $chat_id, 'Неизвестный формат игры. Попробуйте позже.', null, $user_id, $conn);
        return null;
    }

    save_format_subscription($conn, $user_id, $format);

    $formatDisplay = get_format_display_name($format);
    $message = "Принято 👍\n" .
        'Я сразу дам вам знать, как только в расписании появится новая игра формата ' . $formatDisplay . ".\n" .
        "Спасибо! 😌\n" .
        "А пока — вот ваши основные кнопки, вдруг пригодятся 👇";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '📅 Календарь игр', 'callback_data' => 'show_games']
            ],
            [
                ['text' => 'ℹ️ Какие игры у нас есть?', 'callback_data' => 'show_game_formats']
            ]
        ]
    ];

    send_reply($config, $chat_id, $message, $keyboard, $user_id, $conn);

    return null;
}

function build_games_message(array $games, array $config)
{
    $messages = [];

    $botUsername = get_bot_username($config);

    foreach ($games as $game) {
        $gameNumberEscaped = htmlspecialchars($game['game_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $locationEscaped = htmlspecialchars($game['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $priceEscaped = htmlspecialchars($game['price'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $statusDescription = get_game_status_description((int) ($game['status'] ?? 1));
        $registrationLinkText = ((int) ($game['status'] ?? 1) === 2)
            ? '📝 Записаться в резерв'
            : '✉️ Зарегистрироваться на игру';

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
            "💰 {$priceEscaped}\n\n" .
            "{$statusDescription}\n\n";

        if ($shareLink !== null) {
            $messageText .= '<a href="' . htmlspecialchars($shareLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($registrationLinkText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
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
        SELECT id, game_number, game_date, start_time, location, price, status
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
