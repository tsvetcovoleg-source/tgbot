<?php
return [
    'bot_token' => '7170378380:AAFCH65ZxOg4mnraSGynxtpiMGrnOB7OocM',
    'api_url' => 'https://api.telegram.org/bot7170378380:AAFCH65ZxOg4mnraSGynxtpiMGrnOB7OocM/',
    'bot_username' => 'MindGamesPubQuizBot',
    'group_chat_id' => '-1001234567890', // ID супергруппы для логов (заменим позже)
    // OAuth Client ID для входа администраторов через Google (можно задать через переменную окружения GOOGLE_CLIENT_ID)
    'google_client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
    'db' => [
        'host' => 'localhost',
        'dbname' => 'pubquest_mg_tg_bot',
        'user' => 'pubquest_admin',
        'pass' => '#7K{#iELyX[N',
    ]
];
