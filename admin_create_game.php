<?php
require_once __DIR__ . '/admin_shared.php';

[$conn, $config] = bootstrap_admin();

render_admin_layout_start('Создать игру — Админка', 'create', 'Создать игру');
?>
    <div class="card">
        <div class="section-header">
            <h2>Новая игра</h2>
            <a class="link" href="admin_games.php">К списку игр</a>
        </div>
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
