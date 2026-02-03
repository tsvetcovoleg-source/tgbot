<?php
require_once __DIR__ . '/admin_shared.php';

[$conn, $config] = bootstrap_admin();

$duplicateGameId = (int) ($_GET['duplicate_game_id'] ?? 0);
$prefill = [
    'game_number' => '',
    'game_date' => '',
    'start_time' => '',
    'location' => '',
    'price' => '',
    'type' => '',
    'status' => '1',
];

if ($duplicateGameId > 0) {
    $duplicateStmt = $conn->prepare('SELECT game_number, game_date, start_time, location, price, type, status FROM games WHERE id = :id LIMIT 1');
    $duplicateStmt->execute([':id' => $duplicateGameId]);
    $duplicateGame = $duplicateStmt->fetch(PDO::FETCH_ASSOC);

    if ($duplicateGame) {
        $prefill = array_merge($prefill, $duplicateGame);
    }
}

render_admin_layout_start('Создать игру — Админка', 'create', 'Создать игру');
?>
    <div class="card">
        <div class="section-header">
            <h2>Новая игра</h2>
            <a class="link" href="admin_games.php">К списку игр</a>
        </div>
        <form id="game-form">
            <label for="game_number">Название/номер игры</label>
            <input type="text" id="game_number" name="game_number" value="<?php echo htmlspecialchars($prefill['game_number']); ?>" required>

            <label for="game_date">Дата (YYYY-MM-DD)</label>
            <input type="date" id="game_date" name="game_date" value="<?php echo htmlspecialchars($prefill['game_date']); ?>" required>

            <label for="start_time">Время начала (HH:MM)</label>
            <input type="time" id="start_time" name="start_time" value="<?php echo htmlspecialchars($prefill['start_time']); ?>" required>

            <label for="location">Локация</label>
            <input type="text" id="location" name="location" value="<?php echo htmlspecialchars($prefill['location']); ?>" required>

            <label for="price">Стоимость</label>
            <input type="text" id="price" name="price" value="<?php echo htmlspecialchars($prefill['price']); ?>" required>

            <label for="type">Тип игры (quiz / detective / quest / ...)</label>
            <select id="type" name="type" required>
                <option value="">-- выберите --</option>
                <option value="quiz" <?php echo $prefill['type'] === 'quiz' ? 'selected' : ''; ?>>quiz</option>
                <option value="lightquiz" <?php echo $prefill['type'] === 'lightquiz' ? 'selected' : ''; ?>>lightquiz</option>
                <option value="detective" <?php echo $prefill['type'] === 'detective' ? 'selected' : ''; ?>>detective</option>
                <option value="quest" <?php echo $prefill['type'] === 'quest' ? 'selected' : ''; ?>>quest</option>
            </select>

            <label for="status">Статус регистрации</label>
            <select id="status" name="status" required>
                <option value="1" <?php echo (int) $prefill['status'] === 1 ? 'selected' : ''; ?>>Есть места</option>
                <option value="2" <?php echo (int) $prefill['status'] === 2 ? 'selected' : ''; ?>>Только резерв</option>
                <option value="3" <?php echo (int) $prefill['status'] === 3 ? 'selected' : ''; ?>>Регистрация закрыта</option>
            </select>

            <button type="submit">Создать игру</button>
            <p id="game-status"></p>
        </form>
        <p class="muted" style="margin-top: 6px;">После создания вы сможете открыть игру на странице списка игр и посмотреть регистрации.</p>
    </div>

    <script>
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
                    status.textContent = 'Игра создана (ID: ' + data.id + '). Перейти к списку игр, чтобы увидеть карточку.';
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
    </script>
<?php render_admin_layout_end(); ?>
