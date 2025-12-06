<?php

function telegram_request(array $config, string $method, array $params = []): ?array
{
    if (empty($config['api_url'])) {
        throw new InvalidArgumentException('Telegram API URL is missing in configuration.');
    }

    $url = rtrim($config['api_url'], '/') . '/' . ltrim($method, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        error_log('Telegram request failed: ' . $error);
        return null;
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        error_log('Telegram request failed with status ' . $statusCode . ': ' . $response);
        return null;
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Failed to decode Telegram response: ' . json_last_error_msg());
        return null;
    }

    if (isset($decoded['ok']) && $decoded['ok'] === false) {
        error_log('Telegram API error: ' . json_encode($decoded));
    }

    return $decoded;
}

function get_bot_username(array $config): ?string
{
    static $cachedUsername = null;

    if ($cachedUsername !== null) {
        return $cachedUsername;
    }

    $configured = isset($config['bot_username']) ? trim((string) $config['bot_username']) : '';

    // Пытаемся получить актуальное имя бота из Bot API, чтобы диплинки всегда были корректными
    $response = telegram_request($config, 'getMe');
    if (
        is_array($response)
        && isset($response['ok'], $response['result']['username'])
        && $response['ok'] === true
        && $response['result']['username'] !== ''
    ) {
        $cachedUsername = ltrim($response['result']['username'], '@');
        return $cachedUsername;
    }

    if ($configured !== '') {
        $cachedUsername = ltrim($configured, '@');
        return $cachedUsername;
    }

    error_log('Unable to determine bot username for deep links.');

    return null;
}
