<?php
require_once __DIR__ . '/admin_shared.php';

[$conn, $config] = bootstrap_admin();

$userStmt = $conn->query(
    'SELECT u.id, u.telegram_id, u.first_name, u.last_name, u.username, lm.last_message_id
     FROM users u
     LEFT JOIN (
         SELECT user_id, MAX(id) AS last_message_id
         FROM messages
         GROUP BY user_id
     ) lm ON lm.user_id = u.id
     ORDER BY lm.last_message_id DESC, u.id DESC'
);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
$selectedUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;

render_admin_layout_start('Диалоги — Админка', 'dialogues', 'Диалоги');
?>
    <div class="card" id="dialogues-card" data-selected-user-id="<?php echo $selectedUserId ?: ''; ?>">
        <div class="user-layout">
            <div>
                <div class="section-header">
                    <h2>Список пользователей</h2>
                    <span class="muted-small">Всего: <?php echo count($users); ?></span>
                </div>
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

    <script>
    const userList = document.getElementById('user-list');
    const dialogueFeed = document.getElementById('dialogue-feed');
    const dialogueForm = document.getElementById('dialogue-form');
    const wrapper = document.getElementById('dialogues-card');
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

    function selectUser(btn) {
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
    }

    if (userList) {
        userList.querySelectorAll('.user-btn').forEach(btn => {
            btn.addEventListener('click', () => selectUser(btn));
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

    const initialUserId = wrapper ? wrapper.dataset.selectedUserId : '';
    if (initialUserId && userList) {
        const btn = userList.querySelector(`.user-btn[data-user-id="${initialUserId}"]`);
        if (btn) {
            btn.click();
            btn.scrollIntoView({block: 'center'});
        }
    }
    </script>
<?php render_admin_layout_end(); ?>
