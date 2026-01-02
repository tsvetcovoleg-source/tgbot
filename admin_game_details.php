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
    SELECT r.id, r.team, r.quantity, r.status, r.created_at, r.user_id, u.telegram_id, u.first_name, u.last_name, u.username
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
        'status' => isset($row['status']) ? (int) $row['status'] : 1,
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

    <style>
        .registrations-table .reg-view { display: block; }
        .registrations-table .reg-edit { display: none; width: 100%; }
        .registrations-table .edit-actions { display: none; gap: 6px; flex-wrap: wrap; }
        .registrations-table .view-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .registrations-table .registration-row.editing .reg-view { display: none; }
        .registrations-table .registration-row.editing .reg-edit { display: block; }
        .registrations-table .registration-row.editing .edit-actions { display: flex; }
        .registrations-table .registration-row.editing .view-actions { display: none; }
        .registrations-table .status-cell { white-space: nowrap; }
        .registrations-table .actions-cell { min-width: 220px; }
    </style>

    <div class="card">
        <div class="section-header">
            <h3>Регистрации</h3>
            <p class="muted-small">Данные отображаются в виде текста для копирования. Редактирование появляется только по кнопке.</p>
        </div>
        <?php if (!$registrations): ?>
            <p class="muted" id="registrations-empty">Нет регистраций на эту игру.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table id="registrations-table" class="registrations-table">
                    <thead>
                    <tr>
                        <th>Команда</th>
                        <th>Количество</th>
                        <th>Статус</th>
                        <th>Пользователь</th>
                        <th>Telegram</th>
                        <th>Создано</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($registrations as $reg): ?>
                        <?php
                            $statusValue = (int) ($reg['status'] ?? 1);
                            $statusLabel = get_game_status_label($statusValue);
                            $teamRaw = $reg['team'] ?? '';
                            $teamDisplay = trim($teamRaw) !== '' ? $teamRaw : 'Без названия';
                            $quantityRaw = $reg['quantity'] ?? '';
                            $quantityDisplay = trim((string) $quantityRaw) !== '' ? $quantityRaw : '—';
                        ?>
                        <tr class="registration-row" data-registration-id="<?php echo (int) $reg['id']; ?>" data-team="<?php echo htmlspecialchars($teamRaw ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" data-quantity="<?php echo htmlspecialchars($quantityRaw ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" data-registration-status="<?php echo $statusValue; ?>">
                            <td>
                                <div class="reg-view team-view"><?php echo htmlspecialchars($teamDisplay); ?></div>
                                <input type="text" class="reg-edit reg-team" value="<?php echo htmlspecialchars($teamRaw ?? ''); ?>" placeholder="Без названия">
                            </td>
                            <td>
                                <div class="reg-view quantity-view"><?php echo htmlspecialchars($quantityDisplay); ?></div>
                                <input type="text" class="reg-edit reg-quantity" value="<?php echo htmlspecialchars($quantityRaw ?? ''); ?>" placeholder="—">
                            </td>
                            <td class="status-cell">
                                <span class="badge status-<?php echo $statusValue; ?> status-badge"><?php echo htmlspecialchars($statusLabel); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($reg['user_label'] ?? ''); ?></td>
                            <td>
                                <div><?php echo htmlspecialchars($reg['telegram_id'] ?? ''); ?></div>
                                <a class="link" href="admin_dialogues.php?user_id=<?php echo (int) $reg['user_id']; ?>">Открыть диалог</a>
                            </td>
                            <td><?php echo htmlspecialchars($reg['created_at']); ?></td>
                            <td class="actions-cell">
                                <div class="view-actions">
                                    <button type="button" class="outline-btn edit-registration">Редактировать</button>
                                    <?php if ($statusValue === 2): ?>
                                        <button type="button" class="outline-btn confirm-reserve">Подтвердить резерв</button>
                                    <?php endif; ?>
                                </div>
                                <div class="edit-actions">
                                    <button type="button" class="outline-btn save-registration">Сохранить</button>
                                    <button type="button" class="ghost-btn cancel-edit">Отменить</button>
                                </div>
                                <div class="muted-small row-status"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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

    <script>
    const gameData = <?php echo json_encode($game, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    let registrations = <?php echo json_encode($registrations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function getStatusLabel(status) {
        switch (Number(status)) {
            case 2:
                return 'Резерв';
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

    function setRowMode(row, mode) {
        if (!row) return;
        row.classList.toggle('editing', mode === 'edit');
        row.dataset.mode = mode;
    }

    function resetRowInputs(row) {
        if (!row) return;
        const teamInput = row.querySelector('.reg-team');
        const quantityInput = row.querySelector('.reg-quantity');

        if (teamInput) teamInput.value = row.dataset.team || '';
        if (quantityInput) quantityInput.value = row.dataset.quantity || '';
    }

    function updateRegistrationView(row, data) {
        if (!row || !data) return;

        if (Object.prototype.hasOwnProperty.call(data, 'team')) {
            row.dataset.team = data.team || '';
            const teamView = row.querySelector('.team-view');
            if (teamView) {
                teamView.textContent = data.team && data.team.trim() !== '' ? data.team : 'Без названия';
            }
        }

        if (Object.prototype.hasOwnProperty.call(data, 'quantity')) {
            row.dataset.quantity = data.quantity ?? '';
            const quantityView = row.querySelector('.quantity-view');
            if (quantityView) {
                const value = data.quantity;
                quantityView.textContent = value && String(value).trim() !== '' ? value : '—';
            }
        }

        if (Object.prototype.hasOwnProperty.call(data, 'status')) {
            row.dataset.registrationStatus = Number(data.status);
            const badge = row.querySelector('.status-badge');
            if (badge) {
                badge.textContent = getStatusLabel(data.status);
                badge.className = `badge status-${data.status} status-badge`;
            }

            const confirmBtn = row.querySelector('.confirm-reserve');
            if (confirmBtn && Number(data.status) !== 2) {
                confirmBtn.remove();
            }
        }
    }

    function updateRegistrationsState(regId, updates) {
        const regIndex = registrations.findIndex(r => Number(r.id) === regId);
        if (regIndex !== -1) {
            registrations[regIndex] = { ...registrations[regIndex], ...updates };
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
            const row = event.target.closest('tr[data-registration-id]');
            if (!row) return;

            const regId = Number(row.dataset.registrationId || 0);
            if (!regId) return;

            if (event.target.closest('.edit-registration')) {
                resetRowInputs(row);
                setRowMode(row, 'edit');
                showRowStatus(row, '');
                return;
            }

            if (event.target.closest('.cancel-edit')) {
                resetRowInputs(row);
                setRowMode(row, 'view');
                showRowStatus(row, '');
                return;
            }

            if (event.target.closest('.save-registration')) {
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
                        updateRegistrationView(row, data.registration);
                        updateRegistrationsState(regId, data.registration);
                        setRowMode(row, 'view');
                    } else {
                        showRowStatus(row, data.error || 'Не удалось сохранить', true);
                    }
                }).catch(() => {
                    showRowStatus(row, 'Ошибка сети при сохранении', true);
                });
                return;
            }

            if (event.target.closest('.confirm-reserve')) {
                const formData = new FormData();
                formData.append('action', 'confirm_reserve');
                formData.append('registration_id', regId);

                showRowStatus(row, 'Отправляем подтверждение...');

                fetch('admin_actions.php', {
                    method: 'POST',
                    body: formData,
                }).then(async (res) => {
                    const data = await res.json();
                    if (res.ok && data.success) {
                        showRowStatus(row, 'Резерв подтверждён, сообщение отправлено');
                        updateRegistrationView(row, data.registration);
                        updateRegistrationsState(regId, data.registration);
                        setRowMode(row, 'view');
                    } else {
                        showRowStatus(row, data.error || 'Не удалось подтвердить резерв', true);
                    }
                }).catch(() => {
                    showRowStatus(row, 'Ошибка сети при подтверждении', true);
                });
            }
        });
    }

    fillGameForm(gameData);
    </script>
<?php render_admin_layout_end(); ?>
