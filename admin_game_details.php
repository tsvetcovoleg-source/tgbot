<?php
require_once __DIR__ . '/admin_shared.php';
require_once __DIR__ . '/format_helpers.php';

[$conn, $config] = bootstrap_admin();

$gameId = (int) ($_GET['game_id'] ?? 0);

if ($gameId <= 0) {
    render_admin_layout_start('Игра не найдена — Админка', 'games', 'Игра не найдена');
    ?>
    <div class="card">
        <p class="error">Некорректный идентификатор игры.</p>
        <a class="link" href="admin_games.php">Вернуться к списку игр</a>
    </div>
    <?php
    render_admin_layout_end();
    exit;
}

$gameStmt = $conn->prepare('SELECT id, game_number, game_date, start_time, location, price, type, status FROM games WHERE id = :id LIMIT 1');
$gameStmt->execute([':id' => $gameId]);
$game = $gameStmt->fetch(PDO::FETCH_ASSOC);

if (!$game) {
    render_admin_layout_start('Игра не найдена — Админка', 'games', 'Игра не найдена');
    ?>
    <div class="card">
        <p class="error">Игра не найдена.</p>
        <a class="link" href="admin_games.php">Вернуться к списку игр</a>
    </div>
    <?php
    render_admin_layout_end();
    exit;
}

$regsStmt = $conn->prepare('
    SELECT r.id, r.team, r.quantity, r.created_at, r.user_id, u.telegram_id, u.first_name, u.last_name, u.username
    FROM registrations r
    INNER JOIN users u ON r.user_id = u.id
    WHERE r.game_id = :gid
    ORDER BY r.created_at ASC, r.id ASC
');
$regsStmt->execute([':gid' => $gameId]);

$registrations = array_map(static function ($row) {
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $username = $row['username'] ? '(@' . $row['username'] . ')' : '';
    $label = $name !== '' ? $name : 'Без имени';
    $label .= $username ? ' ' . $username : '';

    return [
        'id' => (int) $row['id'],
        'team' => $row['team'],
        'quantity' => $row['quantity'] ?? null,
        'created_at' => $row['created_at'],
        'user_id' => (int) $row['user_id'],
        'user_label' => $label,
        'telegram_id' => $row['telegram_id'],
    ];
}, $regsStmt->fetchAll(PDO::FETCH_ASSOC));

$statusDetails = get_game_status_details((int) ($game['status'] ?? 1));

render_admin_layout_start('Детали игры — Админка', 'games', 'Детали игры');
?>
    <div class="section-header">
        <div>
            <p class="muted-small"><a class="link" href="admin_games.php">&larr; Вернуться к списку игр</a></p>
            <h2 id="page-title">Игра: <?php echo htmlspecialchars($game['game_number']); ?></h2>
        </div>
        <div class="meta">
            <div class="meta-item"><strong>Дата:</strong> <span id="meta-date"><?php echo htmlspecialchars($game['game_date']); ?></span></div>
            <div class="meta-item"><strong>Время:</strong> <span id="meta-time"><?php echo htmlspecialchars($game['start_time']); ?></span></div>
            <div class="meta-item"><strong>Статус:</strong> <span class="badge status-<?php echo (int) ($game['status'] ?? 1); ?>" id="meta-status"><?php echo htmlspecialchars($statusDetails['label'] ?? ''); ?></span></div>
        </div>
    </div>

    <div class="card">
        <h3>Данные игры</h3>
        <div class="game-detail">
            <form id="game-edit-form">
                <input type="hidden" id="edit_game_id" name="game_id" value="<?php echo (int) $game['id']; ?>">

                <label for="edit_game_number">Название/номер игры</label>
                <input type="text" id="edit_game_number" name="game_number" value="<?php echo htmlspecialchars($game['game_number']); ?>" required>

                <label for="edit_game_date">Дата (YYYY-MM-DD)</label>
                <input type="date" id="edit_game_date" name="game_date" value="<?php echo htmlspecialchars($game['game_date']); ?>" required>

                <label for="edit_start_time">Время начала (HH:MM)</label>
                <input type="time" id="edit_start_time" name="start_time" value="<?php echo htmlspecialchars($game['start_time']); ?>" required>

                <label for="edit_location">Локация</label>
                <input type="text" id="edit_location" name="location" value="<?php echo htmlspecialchars($game['location']); ?>" required>

                <label for="edit_price">Стоимость</label>
                <input type="text" id="edit_price" name="price" value="<?php echo htmlspecialchars($game['price']); ?>" required>

                <label for="edit_type">Тип игры</label>
                <select id="edit_type" name="type" required>
                    <option value="">-- выберите --</option>
                    <option value="quiz" <?php echo $game['type'] === 'quiz' ? 'selected' : ''; ?>>quiz</option>
                    <option value="lightquiz" <?php echo $game['type'] === 'lightquiz' ? 'selected' : ''; ?>>lightquiz</option>
                    <option value="detective" <?php echo $game['type'] === 'detective' ? 'selected' : ''; ?>>detective</option>
                    <option value="quest" <?php echo $game['type'] === 'quest' ? 'selected' : ''; ?>>quest</option>
                </select>

                <label for="edit_status">Статус регистрации</label>
                <select id="edit_status" name="status" required>
                    <option value="1" <?php echo (int) $game['status'] === 1 ? 'selected' : ''; ?>>Есть места</option>
                    <option value="2" <?php echo (int) $game['status'] === 2 ? 'selected' : ''; ?>>Только резерв</option>
                    <option value="3" <?php echo (int) $game['status'] === 3 ? 'selected' : ''; ?>>Регистрация закрыта</option>
                </select>

                <button type="submit">Сохранить изменения</button>
                <p id="game-edit-status"></p>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="section-header">
            <h3>Регистрации</h3>
            <p class="muted-small">Все данные собраны в таблице, значения можно редактировать и сохранять.</p>
        </div>
        <?php if (!$registrations): ?>
            <p class="muted" id="registrations-empty">Нет регистраций на эту игру.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table id="registrations-table">
                    <thead>
                    <tr>
                        <th>Команда</th>
                        <th>Количество</th>
                        <th>Пользователь</th>
                        <th>Telegram</th>
                        <th>Создано</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($registrations as $reg): ?>
                        <tr data-registration-id="<?php echo (int) $reg['id']; ?>">
                            <td>
                                <input type="text" class="reg-team" value="<?php echo htmlspecialchars($reg['team'] ?? ''); ?>" placeholder="Без названия">
                            </td>
                            <td>
                                <input type="text" class="reg-quantity" value="<?php echo htmlspecialchars($reg['quantity'] ?? ''); ?>" placeholder="—">
                            </td>
                            <td><?php echo htmlspecialchars($reg['user_label'] ?? ''); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($reg['telegram_id'] ?? ''); ?></div>
                                <a class="link" href="admin_dialogues.php?user_id=<?php echo (int) $reg['user_id']; ?>">Открыть диалог</a>
                            </td>
                            <td><?php echo htmlspecialchars($reg['created_at']); ?></td>
                            <td>
                                <button type="button" class="outline-btn save-registration">Сохранить</button>
                                <div class="muted-small row-status"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
    const gameData = <?php echo json_encode($game, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let registrations = <?php echo json_encode($registrations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function getStatusLabel(status) {
        switch (Number(status)) {
            case 2:
                return 'Только резерв';
            case 3:
                return 'Регистрация закрыта';
            default:
                return 'Есть места';
        }
    }

    function updateMeta(game) {
        const title = document.getElementById('page-title');
        if (title) {
            title.textContent = `Игра: ${game.game_number}`;
        }
        const date = document.getElementById('meta-date');
        if (date) date.textContent = game.game_date;
        const time = document.getElementById('meta-time');
        if (time) time.textContent = game.start_time;
        const statusBadge = document.getElementById('meta-status');
        if (statusBadge) {
            statusBadge.textContent = getStatusLabel(game.status);
            statusBadge.className = `badge status-${game.status}`;
        }
    }

    function fillGameForm(game) {
        document.getElementById('edit_game_id').value = game.id;
        document.getElementById('edit_game_number').value = game.game_number;
        document.getElementById('edit_game_date').value = game.game_date;
        document.getElementById('edit_start_time').value = game.start_time;
        document.getElementById('edit_location').value = game.location;
        document.getElementById('edit_price').value = game.price;
        document.getElementById('edit_type').value = game.type || '';
        document.getElementById('edit_status').value = game.status || '1';
        updateMeta(game);
    }

    function showRowStatus(row, message, isError = false) {
        const status = row.querySelector('.row-status');
        if (status) {
            status.textContent = message;
            status.className = `muted-small ${isError ? 'error' : 'success'}`;
        }
    }

    const gameEditForm = document.getElementById('game-edit-form');
    if (gameEditForm) {
        gameEditForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const status = document.getElementById('game-edit-status');
            status.textContent = 'Сохраняем...';
            status.className = 'muted';

            const formData = new FormData(gameEditForm);
            formData.append('action', 'update_game');
            formData.append('game_id', gameData.id);

            fetch('admin_actions.php', {
                method: 'POST',
                body: formData,
            }).then(async (res) => {
                const data = await res.json();
                if (res.ok && data.success) {
                    status.textContent = 'Изменения сохранены';
                    status.className = 'success';
                    Object.assign(gameData, data.game);
                    fillGameForm(gameData);
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

    const registrationsTable = document.getElementById('registrations-table');
    if (registrationsTable) {
        registrationsTable.addEventListener('click', (event) => {
            const btn = event.target.closest('.save-registration');
            if (!btn) return;
            const row = btn.closest('tr');
            const regId = row ? Number(row.dataset.registrationId || 0) : 0;
            if (!row || !regId) return;

            const teamInput = row.querySelector('.reg-team');
            const quantityInput = row.querySelector('.reg-quantity');

            const formData = new FormData();
            formData.append('action', 'update_registration');
            formData.append('registration_id', regId);
            formData.append('team', teamInput ? teamInput.value : '');
            formData.append('quantity', quantityInput ? quantityInput.value : '');

            showRowStatus(row, 'Сохраняем...');

            fetch('admin_actions.php', {
                method: 'POST',
                body: formData,
            }).then(async (res) => {
                const data = await res.json();
                if (res.ok && data.success) {
                    showRowStatus(row, 'Сохранено');
                    const regIndex = registrations.findIndex(r => Number(r.id) === regId);
                    if (regIndex !== -1) {
                        registrations[regIndex].team = data.registration.team;
                        registrations[regIndex].quantity = data.registration.quantity;
                    }
                } else {
                    showRowStatus(row, data.error || 'Не удалось сохранить', true);
                }
            }).catch(() => {
                showRowStatus(row, 'Ошибка сети при сохранении', true);
            });
        });
    }

    fillGameForm(gameData);
    </script>
<?php render_admin_layout_end(); ?>
