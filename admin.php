<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_auth.php';

$config = require __DIR__ . '/config.php';
$conn = get_connection($config);
$users = [];

if (admin_logged_in()) {
    $stmt = $conn->query('SELECT id, telegram_id, first_name, last_name, username FROM users ORDER BY id DESC');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админка MindGames Bot</title>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 24px; }
        .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); margin-bottom: 20px; }
        h1 { margin-bottom: 10px; }
        label { display: block; margin: 8px 0 4px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; }
        button { padding: 10px 16px; border: none; border-radius: 8px; background: #0066ff; color: #fff; cursor: pointer; }
        button:hover { background: #0052cc; }
        .success { color: green; }
        .error { color: #c00; }
    </style>
</head>
<body>
    <h1>Админка MindGames Bot</h1>

    <?php if (!admin_logged_in()): ?>
        <div class="card">
            <p>Войдите через Google, чтобы управлять играми и отправлять сообщения.</p>
            <div id="g_id_onload"
                 data-client_id="<?php echo htmlspecialchars($config['google_client_id'] ?? '', ENT_QUOTES); ?>"
                 data-context="signin"
                 data-ux_mode="popup"
                 data-callback="handleCredentialResponse"
                 data-auto_select="false">
            </div>
            <div class="g_id_signin" data-type="standard" data-shape="rectangular" data-theme="outline" data-text="signin_with" data-size="large" data-logo_alignment="left"></div>
            <p id="login-status" class="error"></p>
        </div>
    <?php else: ?>
        <div class="card">
            <p>Вы вошли как <strong><?php echo htmlspecialchars($_SESSION['admin_email']); ?></strong></p>
            <button type="button" onclick="logout()">Выйти</button>
        </div>

        <div class="card">
            <h2>Добавить игру</h2>
            <form id="game-form">
                <label for="game_number">Название/номер игры</label>
                <input type="text" id="game_number" name="game_number" required>

                <label for="game_date">Дата (YYYY-MM-DD)</label>
                <input type="date" id="game_date" name="game_date" required>

                <label for="start_time">Время начала (HH:MM)</label>
                <input type="time" id="start_time" name="start_time" required>

                <label for="location">Локация</label>
                <input type="text" id="location" name="location" required>

                <label for="price">Стоимость</label>
                <input type="text" id="price" name="price" required>

                <label for="type">Тип игры (quiz / detective / quest / ...)</label>
                <select id="type" name="type" required>
                    <option value="">-- выберите --</option>
                    <option value="quiz">quiz</option>
                    <option value="lightquiz">lightquiz</option>
                    <option value="detective">detective</option>
                    <option value="quest">quest</option>
                </select>

                <button type="submit">Создать игру</button>
                <p id="game-status"></p>
            </form>
        </div>

        <div class="card">
            <h2>Отправить сообщение пользователю от имени бота</h2>
            <form id="message-form">
                <label for="user_id">Пользователь</label>
                <select id="user_id" name="user_id" required>
                    <option value="">-- выберите пользователя --</option>
                    <?php foreach ($users as $user): ?>
                        <?php
                            $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                            $username = $user['username'] ? ' (@' . $user['username'] . ')' : '';
                            $label = $name !== '' ? $name : 'Без имени';
                            $label .= $username;
                            $label .= ' – ' . $user['telegram_id'];
                        ?>
                        <option value="<?php echo (int) $user['id']; ?>"><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="message">Текст сообщения</label>
                <textarea id="message" name="message" rows="4" required></textarea>

                <button type="submit">Отправить</button>
                <p id="message-status"></p>
            </form>
        </div>
    <?php endif; ?>

<script>
function handleCredentialResponse(response) {
    const status = document.getElementById('login-status');
    status.textContent = '';

    fetch('admin_login.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({credential: response.credential})
    }).then(async (res) => {
        const data = await res.json();
        if (res.ok) {
            window.location.reload();
        } else {
            status.textContent = data.error || 'Ошибка авторизации';
        }
    }).catch(() => {
        status.textContent = 'Ошибка сети при авторизации';
    });
}

function logout() {
    fetch('admin_logout.php', {method: 'POST'}).then(() => window.location.reload());
}

const gameForm = document.getElementById('game-form');
if (gameForm) {
    gameForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(gameForm);
        formData.append('action', 'create_game');

        fetch('admin_actions.php', {
            method: 'POST',
            body: formData
        }).then(async (res) => {
            const data = await res.json();
            const status = document.getElementById('game-status');
            status.className = '';

            if (res.ok) {
                status.textContent = 'Игра создана (ID: ' + data.id + ')';
                status.classList.add('success');
                gameForm.reset();
            } else {
                status.textContent = data.error || 'Не удалось создать игру';
                status.classList.add('error');
            }
        }).catch(() => {
            const status = document.getElementById('game-status');
            status.textContent = 'Ошибка сети при создании игры';
            status.classList.add('error');
        });
    });
}

const messageForm = document.getElementById('message-form');
if (messageForm) {
    messageForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const formData = new FormData(messageForm);
        formData.append('action', 'send_message');

        fetch('admin_actions.php', {
            method: 'POST',
            body: formData
        }).then(async (res) => {
            const data = await res.json();
            const status = document.getElementById('message-status');
            status.className = '';

            if (res.ok) {
                status.textContent = 'Сообщение отправлено';
                status.classList.add('success');
                messageForm.reset();
            } else {
                status.textContent = data.error || 'Не удалось отправить сообщение';
                status.classList.add('error');
            }
        }).catch(() => {
            const status = document.getElementById('message-status');
            status.textContent = 'Ошибка сети при отправке';
            status.classList.add('error');
        });
    });
}
</script>
</body>
</html>
