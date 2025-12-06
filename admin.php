<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_auth.php';

$config = require __DIR__ . '/config.php';
$conn = get_connection($config);
$users = [];
$games = [];

if (admin_logged_in()) {
    $userStmt = $conn->query('SELECT id, telegram_id, first_name, last_name, username FROM users ORDER BY id DESC');
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    $gamesStmt = $conn->query('SELECT id, game_number, game_date, start_time, location, price, type FROM games ORDER BY game_date DESC, id DESC');
    $games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админка MindGames Bot</title>
    <style>
        :root { --blue: #0066ff; --blue-dark: #0052cc; --bg: #f5f5f5; --border: #e5e7eb; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: var(--bg); padding: 24px; margin: 0; }
        h1 { margin-bottom: 16px; }
        h2 { margin: 0 0 12px; }
        h3 { margin: 0 0 8px; }
        .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); margin-bottom: 20px; }
        label { display: block; margin: 8px 0 4px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; font-size: 14px; }
        button { padding: 10px 16px; border: none; border-radius: 8px; background: var(--blue); color: #fff; cursor: pointer; font-weight: 600; }
        button:hover { background: var(--blue-dark); }
        .success { color: green; }
        .error { color: #c00; }
        .muted { color: #666; }
        .link { color: #0066ff; text-decoration: none; }
        .tabs { display: flex; gap: 8px; margin-bottom: 16px; }
        .tab-btn { background: #fff; color: #111; border: 1px solid var(--border); padding: 10px 14px; border-radius: 10px; cursor: pointer; font-weight: 600; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .tab-btn.active { background: var(--blue); color: #fff; border-color: var(--blue); box-shadow: 0 1px 6px rgba(0,0,0,0.12); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }
        .games-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
        .game { border: 1px solid var(--border); padding: 12px; border-radius: 10px; background: #fafafa; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; background: #eef2ff; color: #3730a3; font-size: 12px; margin-bottom: 6px; }
        .user-layout { display: grid; gap: 14px; grid-template-columns: 280px 1fr; align-items: start; }
        .user-list { max-height: 620px; overflow-y: auto; border: 1px solid var(--border); border-radius: 12px; padding: 8px; }
        .user-btn { width: 100%; text-align: left; padding: 10px; border: 1px solid transparent; border-radius: 10px; background: transparent; cursor: pointer; margin-bottom: 6px; color: #111; }
        .user-btn:hover, .user-btn.active { border-color: var(--blue); background: #f0f6ff; }
        .dialogue { border: 1px solid var(--border); border-radius: 12px; padding: 12px; background: #fff; min-height: 200px; max-height: 480px; overflow-y: auto; }
        .bubble { padding: 10px 12px; border-radius: 12px; margin-bottom: 10px; max-width: 80%; white-space: pre-wrap; }
        .bubble.user { background: #eef2ff; margin-right: auto; }
        .bubble.bot { background: #ecfdf3; margin-left: auto; }
        .dialogue-empty { color: #666; text-align: center; padding: 20px; }
        .logout-card { max-width: 320px; }
    </style>
</head>
<body>
    <h1>Админка MindGames Bot</h1>

    <?php if (!admin_logged_in()): ?>
        <div class="card">
            <p>Введите email и пароль администратора, чтобы получить доступ.</p>
            <form id="login-form">
                <label for="login-email">Email</label>
                <input type="email" id="login-email" name="email" required>

                <label for="login-password">Пароль</label>
                <input type="password" id="login-password" name="password" required>

                <button type="submit">Войти</button>
                <p id="login-status" class="error"></p>
            </form>
            <p class="muted">Нужно создать аккаунт? Используйте страницу <a class="link" href="create_admin.php">добавления администратора</a>.</p>
        </div>
    <?php else: ?>
        <div class="tabs">
            <button class="tab-btn active" data-tab="games">Список игр</button>
            <button class="tab-btn" data-tab="users">Список пользователей</button>
            <button class="tab-btn" data-tab="logout">Выйти</button>
        </div>

        <div class="tab-panel active" id="tab-games">
            <div class="card">
                <h2>Список игр</h2>
                <div class="games-grid" id="games-grid">
                    <?php foreach ($games as $game): ?>
                        <div class="game">
                            <div class="badge"><?php echo htmlspecialchars($game['type'] ?: 'unknown'); ?></div>
                            <div><strong><?php echo htmlspecialchars($game['game_number']); ?></strong></div>
                            <div class="muted"><?php echo htmlspecialchars($game['game_date']); ?> в <?php echo htmlspecialchars($game['start_time']); ?></div>
                            <div><?php echo htmlspecialchars($game['location']); ?></div>
                            <div class="muted">Стоимость: <?php echo htmlspecialchars($game['price']); ?></div>
                            <div class="muted">ID: <?php echo (int) $game['id']; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$games): ?>
                    <p class="muted" id="games-empty">Пока нет созданных игр.</p>
                <?php endif; ?>
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
        </div>

        <div class="tab-panel" id="tab-users">
            <div class="card">
                <div class="user-layout">
                    <div>
                        <h2>Список пользователей</h2>
                        <div class="user-list" id="user-list">
                            <?php foreach ($users as $user): ?>
                                <?php
                                    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                                    $username = $user['username'] ? ' (@' . $user['username'] . ')' : '';
                                    $label = $name !== '' ? $name : 'Без имени';
                                    $label .= $username;
                                    $label .= ' – ' . $user['telegram_id'];
                                ?>
                                <button class="user-btn" data-user-id="<?php echo (int) $user['id']; ?>" data-user-label="<?php echo htmlspecialchars($label); ?>"><?php echo htmlspecialchars($label); ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <h2 id="dialogue-title">Диалог</h2>
                        <div class="dialogue" id="dialogue-feed">
                            <div class="dialogue-empty">Выберите пользователя, чтобы увидеть переписку</div>
                        </div>
                        <form id="dialogue-form" style="margin-top: 12px;">
                            <label for="dialogue-message">Сообщение</label>
                            <textarea id="dialogue-message" name="dialogue-message" rows="4" placeholder="Введите сообщение" required></textarea>
                            <p id="dialogue-status" class="error"></p>
                            <button type="submit">Отправить</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-panel" id="tab-logout">
            <div class="card logout-card">
                <h2>Выйти</h2>
                <p>Вы вошли как <strong><?php echo htmlspecialchars($_SESSION['admin_email']); ?></strong></p>
                <button type="button" onclick="logout()">Выйти из админки</button>
            </div>
        </div>
    <?php endif; ?>

<script>
const loginForm = document.getElementById('login-form');
if (loginForm) {
    loginForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const status = document.getElementById('login-status');
        status.textContent = '';

        const payload = {
            email: document.getElementById('login-email').value,
            password: document.getElementById('login-password').value,
        };

        fetch('admin_login.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
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
    });
}

function logout() {
    fetch('admin_logout.php', {method: 'POST'}).then(() => window.location.reload());
}

function setActiveTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tabId);
    });
    document.querySelectorAll('.tab-panel').forEach(panel => {
        panel.classList.toggle('active', panel.id === 'tab-' + tabId);
    });
}

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => setActiveTab(btn.dataset.tab));
});

const gameForm = document.getElementById('game-form');
if (gameForm) {
    gameForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const newGame = {
            number: document.getElementById('game_number').value,
            date: document.getElementById('game_date').value,
            time: document.getElementById('start_time').value,
            location: document.getElementById('location').value,
            price: document.getElementById('price').value,
            type: document.getElementById('type').value,
        };
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
                addGameCard(data.id, newGame);
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

function addGameCard(id, game) {
    const grid = document.getElementById('games-grid');
    if (!grid) return;

    const emptyText = document.getElementById('games-empty');
    if (emptyText) {
        emptyText.remove();
    }

    const card = document.createElement('div');
    card.className = 'game';
    card.innerHTML = `
        <div class="badge">${escapeHtml(game.type || 'unknown')}</div>
        <div><strong>${escapeHtml(game.number)}</strong></div>
        <div class="muted">${escapeHtml(game.date)} в ${escapeHtml(game.time)}</div>
        <div>${escapeHtml(game.location)}</div>
        <div class="muted">Стоимость: ${escapeHtml(game.price)}</div>
        <div class="muted">ID: ${id}</div>
    `;

    grid.prepend(card);
}

const userList = document.getElementById('user-list');
const dialogueFeed = document.getElementById('dialogue-feed');
const dialogueForm = document.getElementById('dialogue-form');
let activeUserId = null;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function renderDialogue(messages) {
    if (!dialogueFeed) return;

    if (!messages || messages.length === 0) {
        dialogueFeed.innerHTML = '<div class="dialogue-empty">Диалог пустой</div>';
        return;
    }

    dialogueFeed.innerHTML = messages.map(msg => {
        const side = msg.from_bot ? 'bot' : 'user';
        return `<div class="bubble ${side}">${escapeHtml(msg.message || '')}</div>`;
    }).join('');
    dialogueFeed.scrollTop = dialogueFeed.scrollHeight;
}

function loadUserMessages(userId) {
    if (!userId) return;
    const formData = new FormData();
    formData.append('action', 'get_user_messages');
    formData.append('user_id', userId);

    fetch('admin_actions.php', {
        method: 'POST',
        body: formData
    }).then(async (res) => {
        const data = await res.json();
        if (res.ok && data.success) {
            renderDialogue(data.messages || []);
        } else {
            renderDialogue([]);
            const status = document.getElementById('dialogue-status');
            if (status) {
                status.textContent = data.error || 'Не удалось загрузить диалог';
                status.classList.add('error');
            }
        }
    }).catch(() => {
        renderDialogue([]);
        const status = document.getElementById('dialogue-status');
        if (status) {
            status.textContent = 'Ошибка сети при загрузке диалога';
            status.classList.add('error');
        }
    });
}

if (userList) {
    userList.querySelectorAll('.user-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const { userId, userLabel } = btn.dataset;
            activeUserId = userId;

            userList.querySelectorAll('.user-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const title = document.getElementById('dialogue-title');
            if (title) {
                title.textContent = userLabel ? `Диалог с ${userLabel}` : 'Диалог';
            }

            const status = document.getElementById('dialogue-status');
            if (status) {
                status.textContent = '';
                status.className = '';
            }

            loadUserMessages(userId);
        });
    });
}

if (dialogueForm) {
    dialogueForm.addEventListener('submit', (e) => {
        e.preventDefault();
        const status = document.getElementById('dialogue-status');
        if (!activeUserId) {
            if (status) {
                status.textContent = 'Выберите пользователя';
                status.className = 'error';
            }
            return;
        }

        const messageText = document.getElementById('dialogue-message').value.trim();
        if (messageText === '') {
            if (status) {
                status.textContent = 'Введите текст сообщения';
                status.className = 'error';
            }
            return;
        }

        const formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('user_id', activeUserId);
        formData.append('message', messageText);

        fetch('admin_actions.php', {
            method: 'POST',
            body: formData
        }).then(async (res) => {
            const data = await res.json();
            if (res.ok) {
                if (status) {
                    status.textContent = 'Сообщение отправлено';
                    status.className = 'success';
                }
                document.getElementById('dialogue-message').value = '';
                if (data.message) {
                    loadUserMessages(activeUserId);
                }
            } else {
                if (status) {
                    status.textContent = data.error || 'Не удалось отправить сообщение';
                    status.className = 'error';
                }
            }
        }).catch(() => {
            if (status) {
                status.textContent = 'Ошибка сети при отправке';
                status.className = 'error';
            }
        });
    });
}
</script>
</body>
</html>
