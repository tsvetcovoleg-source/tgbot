<?php

function get_known_game_formats(): array
{
    return ['quiz', 'lightquiz', 'detective', 'quest'];
}

function get_game_format_definitions(): array
{
    return [
        'quiz' => [
            'title' => '✨ Паб-квиз',
            'description' => 'Паб-квиз — это командная интеллектуальная игра MindGames с вопросами на логику, эрудицию и весёлые ассоциации. Настоящая классика наших мероприятий!',
            'link_text' => '👉 Узнать, когда ближайшие игры паб-квиза',
            'start_payload' => 'quiz'
        ],
        'detective' => [
            'title' => '🕵️‍♂️ Saint Twins Detective',
            'description' => 'Saint Twins Detective — это детективная игра-расследование с погружением в сюжет, уликами, версиями и неожиданными поворотами. Отлично подходит тем, кто любит загадки и атмосферу детектива.',
            'link_text' => '👉 Узнать, когда ближайшая детективная игра',
            'start_payload' => 'detective'
        ],
        'quest' => [
            'title' => '🚗 Квест на автомобилях',
            'description' => 'Авто-квест — это динамичная городская игра MindGames, где вы разгадываете загадки, ищете точки по городу и проходите задания в реальном времени. Много драйва, движения и эмоций!',
            'link_text' => '👉 Узнать, когда ближайший авто-квест',
            'start_payload' => 'quest'
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
