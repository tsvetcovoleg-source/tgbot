<?php

function get_known_game_formats(): array
{
    return ['quiz', 'lightquiz', 'detective', 'quest', 'music'];
}

function get_game_format_definitions(): array
{
    return [
        'quiz' => [
            'title' => '✨ Квиз',
            'description' => 'Квиз — это командная интеллектуальная игра с вопросами на логику, эрудицию и умение играть вместе. Настоящая классика наших мероприятий! Подробнее о формате можно узнать <a href="https://www.mindgames.md">здесь</a>',
            'link_text' => '👉 Узнать, когда ближайшие игры паб-квиза',
            'start_payload' => 'quiz'
        ],
        'detective' => [
            'title' => '🕵️‍♂️ Saint Twins Detective',
            'description' => 'Saint Twins Detective — это детективная игра-расследование с погружением в сюжет, уликами, версиями и неожиданными поворотами. Отлично подходит тем, кто любит загадки и атмосферу детектива. Подробнее о формате можно узнать <a href="https://www.detective.mindgames.md">здесь</a>',
            'link_text' => '👉 Узнать, когда ближайшая детективная игра',
            'start_payload' => 'detective'
        ],
        'quest' => [
            'title' => '🚗 Квест на автомобилях',
            'description' => 'Авто-квест — это динамичная городская игра, где вы разгадываете загадки, находите точки по городу и выполняете задания в реальном времени. Много движения, драйва и эмоций!',
            'link_text' => '👉 Узнать, когда ближайший авто-квест',
            'start_payload' => 'quest'
        ],
        'music' => [
            'title' => '🎵 Музыкальные игры',
            'description' => 'Музыкальные игры — это командный формат, где вас ждут хиты разных лет, необычные музыкальные задания, атмосфера вечеринки и море эмоций. Угадывайте песни, исполнителей, каверы, переводы и музыкальные ребусы, подпевайте любимым трекам и соревнуйтесь с другими командами в самом драйвовом формате наших игр!',
            'link_text' => '👉 Узнать, когда ближайшая музыкальная игра',
            'start_payload' => 'music'
        ],
    ];
}

function get_game_format_definition(string $format): ?array
{
    $definitions = get_game_format_definitions();

    return $definitions[$format] ?? null;
}

function resolve_primary_format(array $types): ?string
{
    foreach (get_known_game_formats() as $known) {
        if (in_array($known, $types, true)) {
            return $known;
        }
    }

    return $types[0] ?? null;
}

function get_format_display_name(string $format): string
{
    switch ($format) {
        case 'quiz':
            return 'паб-квиза';
        case 'lightquiz':
            return 'лайт-квиза';
        case 'detective':
            return 'Saint Twins Detective';
        case 'quest':
            return 'автоквеста';
        case 'music':
            return 'музыкальной игры';
        default:
            return 'этого формата';
    }
}

function save_format_subscription(PDO $conn, int $userId, string $format): void
{
    $stmt = $conn->prepare("
        INSERT INTO format_subscriptions (user_id, format, created_at)
        VALUES (:uid, :format, NOW())
        ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)
    ");

    $stmt->execute([
        ':uid' => $userId,
        ':format' => $format,
    ]);
}

function get_game_status_details(int $status): array
{
    $italicize = static function (string $text): string {
        return '<i>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</i>';
    };

    switch ($status) {
        case 2:
            $lines = [
                'Сейчас все столы уже заняты — аншлаг 🔥',
                'Но вы можете записаться в резерв. Если кто-то откажется от участия или мы найдём место для ещё одной команды, я сразу дам вам знать 😉',
            ];

            return [
                'label' => 'Резерв',
                'description' => $italicize(implode("\n", $lines)),
            ];
        case 3:
            return [
                'label' => 'Закрыта',
                'description' => htmlspecialchars('Статус: регистрация закрыта', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            ];
        default:
            return [
                'label' => 'Есть места',
                'description' => $italicize('На игру доступны свободные места.'),
            ];
    }
}

function get_game_status_label(int $status): string
{
    $details = get_game_status_details($status);

    return $details['label'] ?? '';
}

function get_game_status_description(int $status): string
{
    $details = get_game_status_details($status);

    return $details['description'] ?? '';
}
