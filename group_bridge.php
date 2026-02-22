<?php

function is_group_bridge_enabled(array $config): bool
{
    if (!isset($config['group_chat_id'])) {
        return false;
    }

    $chatId = trim((string) $config['group_chat_id']);
    if ($chatId === '' || $chatId === '-1001234567890') {
        return false;
    }

    return true;
}

function ensure_group_bridge_schema(PDO $conn): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $conn->exec(
        'CREATE TABLE IF NOT EXISTS user_topic_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            message_thread_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_topic_links_user (user_id),
            UNIQUE KEY uq_user_topic_links_thread (message_thread_id),
            CONSTRAINT fk_user_topic_links_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $ensured = true;
}

function get_user_topic_link(PDO $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT user_id, message_thread_id FROM user_topic_links WHERE user_id = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function find_user_id_by_topic(PDO $conn, int $threadId): ?int
{
    $stmt = $conn->prepare('SELECT user_id FROM user_topic_links WHERE message_thread_id = :thread LIMIT 1');
    $stmt->execute([':thread' => $threadId]);
    $userId = $stmt->fetchColumn();

    return $userId ? (int) $userId : null;
}

function build_topic_title(array $userRow): string
{
    $nameParts = [trim((string) ($userRow['first_name'] ?? '')), trim((string) ($userRow['last_name'] ?? ''))];
    $fullName = trim(implode(' ', array_filter($nameParts, static function ($v) { return $v !== ''; })));
    $username = trim((string) ($userRow['username'] ?? ''));

    $title = $fullName !== '' ? $fullName : 'Пользователь #' . (int) $userRow['telegram_id'];
    if ($username !== '') {
        $title .= ' (@' . preg_replace('/[^a-zA-Z0-9_]/', '', $username) . ')';
    }

    return mb_substr($title, 0, 128);
}

function ensure_user_topic(PDO $conn, array $config, int $userId): ?int
{
    if (!is_group_bridge_enabled($config)) {
        return null;
    }

    ensure_group_bridge_schema($conn);

    $existing = get_user_topic_link($conn, $userId);
    if ($existing && !empty($existing['message_thread_id'])) {
        return (int) $existing['message_thread_id'];
    }

    $stmt = $conn->prepare('SELECT telegram_id, first_name, last_name, username FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow || empty($userRow['telegram_id'])) {
        return null;
    }

    $response = telegram_request($config, 'createForumTopic', [
        'chat_id' => $config['group_chat_id'],
        'name' => build_topic_title($userRow),
    ]);

    if (!is_array($response) || empty($response['ok']) || empty($response['result']['message_thread_id'])) {
        error_log('Failed to create forum topic for user ' . $userId . ': ' . json_encode($response));
        return null;
    }

    $threadId = (int) $response['result']['message_thread_id'];

    $insert = $conn->prepare('INSERT INTO user_topic_links (user_id, message_thread_id) VALUES (:uid, :thread)');
    $insert->execute([
        ':uid' => $userId,
        ':thread' => $threadId,
    ]);

    return $threadId;
}

function send_topic_message(PDO $conn, array $config, int $userId, string $text): void
{
    if (!is_group_bridge_enabled($config) || $text === '') {
        return;
    }

    $threadId = ensure_user_topic($conn, $config, $userId);
    if (!$threadId) {
        return;
    }

    telegram_request($config, 'sendMessage', [
        'chat_id' => $config['group_chat_id'],
        'message_thread_id' => $threadId,
        'text' => $text,
    ]);
}

function mirror_status2_message(PDO $conn, array $config, int $userId, string $text): void
{
    if (trim($text) === '') {
        return;
    }

    $safeText = mb_substr(trim($text), 0, 3500);
    send_topic_message($conn, $config, $userId, "💬 Сообщение (status=2):\n" . $safeText);
}

function mirror_registration_event(PDO $conn, array $config, int $userId, string $team, string $quantity): void
{
    $teamText = trim($team) !== '' ? $team : '—';
    $quantityText = trim($quantity) !== '' ? $quantity : '—';

    send_topic_message(
        $conn,
        $config,
        $userId,
        "✅ Пользователь зарегистрировал команду\n👥 Команда: {$teamText}\n🔢 Количество: {$quantityText}"
    );
}

function relay_topic_message_to_user(PDO $conn, array $config, array $message): bool
{
    if (!is_group_bridge_enabled($config)) {
        return false;
    }

    if (empty($message['is_topic_message']) || empty($message['message_thread_id'])) {
        return false;
    }

    if (!empty($message['from']['is_bot'])) {
        return false;
    }

    $text = trim((string) ($message['text'] ?? ''));
    if ($text === '') {
        return false;
    }

    $userId = find_user_id_by_topic($conn, (int) $message['message_thread_id']);
    if (!$userId) {
        return false;
    }

    $stmt = $conn->prepare('SELECT telegram_id, status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow || empty($userRow['telegram_id'])) {
        return false;
    }

    if (isset($userRow['status']) && (int) $userRow['status'] === 1) {
        $statusStmt = $conn->prepare('UPDATE users SET status = 2 WHERE id = :id');
        $statusStmt->execute([':id' => $userId]);
    }

    telegram_request($config, 'sendMessage', [
        'chat_id' => $userRow['telegram_id'],
        'text' => $text,
    ]);

    $logStmt = $conn->prepare('INSERT INTO messages (user_id, message, from_bot) VALUES (:uid, :msg, 1)');
    $logStmt->execute([
        ':uid' => $userId,
        ':msg' => $text,
    ]);

    return true;
}
