<?php
require_once __DIR__ . '/admin_shared.php';

[$conn, $config] = bootstrap_admin();

$gamesStmt = $conn->query('SELECT id, game_number, game_date, start_time, location, price, type FROM games ORDER BY game_date DESC, id DESC');
$games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

render_admin_layout_start('Игры — Админка', 'games', 'Игры');
?>
    <div class="card">
        <div class="section-header">
            <h2>Список игр</h2>
            <a class="link" href="admin_create_game.php">Создать новую игру</a>
        </div>
        <div class="games-grid" id="games-grid">
            <?php foreach ($games as $game): ?>
                <div class="game" data-game-id="<?php echo (int) $game['id']; ?>">
                    <div class="badge"><?php echo htmlspecialchars($game['type'] ?: 'unknown'); ?></div>
                    <div class="game-title"><strong><?php echo htmlspecialchars($game['game_number']); ?></strong></div>
                    <div class="muted game-date"><?php echo htmlspecialchars($game['game_date']); ?> в <?php echo htmlspecialchars($game['start_time']); ?></div>
                    <div class="game-location"><?php echo htmlspecialchars($game['location']); ?></div>
                    <div class="muted game-price">Стоимость: <?php echo htmlspecialchars($game['price']); ?></div>
                    <div class="muted">ID: <?php echo (int) $game['id']; ?></div>
                    <div class="game-actions">
                        <button type="button" class="outline-btn view-game" data-game-id="<?php echo (int) $game['id']; ?>">Открыть</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$games): ?>
            <p class="muted" id="games-empty">Пока нет созданных игр.</p>
        <?php endif; ?>
    </div>

    <div class="card" id="game-detail-card" style="display: none;">
        <h2 id="game-detail-title">Детали игры</h2>
        <div class="game-detail">
            <form id="game-edit-form">
                <input type="hidden" id="edit_game_id" name="game_id">
                <label for="edit_game_number">Название/номер игры</label>
                <input type="text" id="edit_game_number" name="game_number" required>

                <label for="edit_game_date">Дата (YYYY-MM-DD)</label>
                <input type="date" id="edit_game_date" name="game_date" required>

                <label for="edit_start_time">Время начала (HH:MM)</label>
                <input type="time" id="edit_start_time" name="start_time" required>

                <label for="edit_location">Локация</label>
                <input type="text" id="edit_location" name="location" required>

                <label for="edit_price">Стоимость</label>
                <input type="text" id="edit_price" name="price" required>

                <label for="edit_type">Тип игры</label>
                <select id="edit_type" name="type" required>
                    <option value="">-- выберите --</option>
                    <option value="quiz">quiz</option>
                    <option value="lightquiz">lightquiz</option>
                    <option value="detective">detective</option>
                    <option value="quest">quest</option>
                </select>

                <button type="submit">Сохранить изменения</button>
                <p id="game-edit-status"></p>
            </form>

            <div>
                <h3>Регистрации</h3>
                <div id="registrations-list" class="registrations">
                    <p class="muted" id="registrations-empty">Нет регистраций на эту игру</p>
                </div>
            </div>
        </div>
    </div>

    <script>
    let activeGameId = null;
    let currentRegistrations = [];

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function renderRegistrations(registrations) {
        const container = document.getElementById('registrations-list');
        if (!container) return;

        if (!registrations || registrations.length === 0) {
            container.innerHTML = '<p class="muted" id="registrations-empty">Нет регистраций на эту игру</p>';
            return;
        }

        container.innerHTML = registrations.map((reg) => {
            const quantityValue = reg.quantity ? String(reg.quantity) : '';
            const quantityLabel = quantityValue
                ? ` · ${escapeHtml(quantityValue)}${quantityValue === 'Пока не знаем' ? '' : ' чел.'}`
                : '';
            const teamLabel = reg.team ? `<strong>${escapeHtml(reg.team)}</strong>${quantityLabel}` : 'Без названия';
            const dialogueUrl = `admin_dialogues.php?user_id=${reg.user_id || ''}`;
            const userLine = `${escapeHtml(reg.user_label || '')} · ${escapeHtml(reg.telegram_id || '')}`;

            return `
                <div class="registration">
                    <div>${teamLabel}</div>
                    <div class="muted-small">${userLine}</div>
                    <div class="muted-small">${escapeHtml(reg.created_at || '')}</div>
                    <a class="link" href="${dialogueUrl}">Открыть диалог</a>
                </div>
            `;
        }).join('');
    }

    function showGameDetails(game, registrations) {
        const card = document.getElementById('game-detail-card');
        if (!card) return;

        activeGameId = game.id;
        card.style.display = 'block';

        const title = document.getElementById('game-detail-title');
        if (title) {
            title.textContent = `Детали игры: ${game.game_number}`;
        }

        document.getElementById('edit_game_id').value = game.id;
        document.getElementById('edit_game_number').value = game.game_number;
        document.getElementById('edit_game_date').value = game.game_date;
        document.getElementById('edit_start_time').value = game.start_time;
        document.getElementById('edit_location').value = game.location;
        document.getElementById('edit_price').value = game.price;
        document.getElementById('edit_type').value = game.type || '';

        const status = document.getElementById('game-edit-status');
        if (status) {
            status.textContent = '';
            status.className = '';
        }

        currentRegistrations = registrations || [];
        renderRegistrations(currentRegistrations);
    }

    function loadGameDetails(gameId) {
        const formData = new FormData();
        formData.append('action', 'get_game_details');
        formData.append('game_id', gameId);

        fetch('admin_actions.php', {
            method: 'POST',
            body: formData
        }).then(async (res) => {
            const data = await res.json();
            if (res.ok && data.success) {
                showGameDetails(data.game, data.registrations || []);
            } else {
                alert(data.error || 'Не удалось загрузить данные игры');
            }
        }).catch(() => {
            alert('Ошибка сети при загрузке игры');
        });
    }

    function updateGameCard(game) {
        const grid = document.getElementById('games-grid');
        if (!grid) return;
        const card = grid.querySelector(`.game[data-game-id="${game.id}"]`);
        if (!card) return;

        const title = card.querySelector('.game-title');
        if (title) title.innerHTML = `<strong>${escapeHtml(game.game_number)}</strong>`;

        const date = card.querySelector('.game-date');
        if (date) date.textContent = `${game.game_date} в ${game.start_time}`;

        const location = card.querySelector('.game-location');
        if (location) location.textContent = game.location;

        const price = card.querySelector('.game-price');
        if (price) price.textContent = `Стоимость: ${game.price}`;

        const badge = card.querySelector('.badge');
        if (badge) badge.textContent = game.type || 'unknown';
    }

    const gamesGrid = document.getElementById('games-grid');
    if (gamesGrid) {
        gamesGrid.addEventListener('click', (event) => {
            const btn = event.target.closest('.view-game');
            if (btn && btn.dataset.gameId) {
                loadGameDetails(btn.dataset.gameId);
            }
        });
    }

    const gameEditForm = document.getElementById('game-edit-form');
    if (gameEditForm) {
        gameEditForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const status = document.getElementById('game-edit-status');
            if (!activeGameId) {
                status.textContent = 'Выберите игру, чтобы сохранить изменения';
                status.className = 'error';
                return;
            }

            const formData = new FormData(gameEditForm);
            formData.append('action', 'update_game');
            formData.append('game_id', activeGameId);

            fetch('admin_actions.php', {
                method: 'POST',
                body: formData
            }).then(async (res) => {
                const data = await res.json();
                if (res.ok && data.success) {
                    status.textContent = 'Изменения сохранены';
                    status.className = 'success';
                    showGameDetails(data.game, currentRegistrations);
                    updateGameCard(data.game);
                } else {
                    status.textContent = data.error || 'Не удалось сохранить игру';
                    status.className = 'error';
                }
            }).catch(() => {
                status.textContent = 'Ошибка сети при сохранении';
                status.className = 'error';
            });
        });
    }
    </script>
<?php render_admin_layout_end(); ?>
