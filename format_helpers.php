<?php

function get_known_game_formats(): array
{
    return ['quiz', 'lightquiz', 'detective', 'quest'];
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
