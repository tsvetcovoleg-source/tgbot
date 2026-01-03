<?php
require_once __DIR__ . '/admin_shared.php';
require_once __DIR__ . '/format_helpers.php';

[$conn, $config] = bootstrap_admin();

$gamesStmt = $conn->query('
    SELECT id, game_number, game_date, start_time, location, price, type, status
    FROM games
    WHERE TIMESTAMP(game_date, start_time) >= NOW()
    ORDER BY game_date DESC, id DESC
');
$games = $gamesStmt->fetchAll(PDO::FETCH_ASSOC);

render_admin_layout_start('Игры — Админка', 'games', 'Игры');
?>
    <div class="card">
        <div class="section-header">
            <h2>Список игр</h2>
            <a class="link" href="admin_create_game.php">Создать новую игру</a>
        </div>
        <div class="table-wrapper">
            <table id="games-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Номер</th>
                    <th>Дата</th>
                    <th>Время</th>
                    <th>Локация</th>
                    <th>Стоимость</th>
                    <th>Тип</th>
                    <th>Статус</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($games as $game): ?>
                    <tr data-game-id="<?php echo (int) $game['id']; ?>">
                        <td class="cell-id"><?php echo (int) $game['id']; ?></td>
                        <td class="cell-number"><?php echo htmlspecialchars($game['game_number']); ?></td>
                        <td class="cell-date"><?php echo htmlspecialchars($game['game_date']); ?></td>
                        <td class="cell-time"><?php echo htmlspecialchars($game['start_time']); ?></td>
                        <td class="cell-location"><?php echo htmlspecialchars($game['location']); ?></td>
                        <td class="cell-price"><?php echo htmlspecialchars($game['price']); ?></td>
                        <td class="cell-type"><span class="badge"><?php echo htmlspecialchars($game['type'] ?: 'unknown'); ?></span></td>
                        <?php $statusDetails = get_game_status_details((int) ($game['status'] ?? 1)); ?>
                        <td class="cell-status">
                            <span class="badge status-<?php echo (int) ($game['status'] ?? 1); ?>">
                                <?php echo htmlspecialchars($statusDetails['label'] ?? ''); ?>
                            </span>
                        </td>
                        <td>
                            <div class="table-actions">
                                <a class="outline-btn link" href="admin_game_details.php?game_id=<?php echo (int) $game['id']; ?>">Открыть</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$games): ?>
            <p class="muted" id="games-empty">Пока нет созданных игр.</p>
        <?php endif; ?>
    </div>
<?php render_admin_layout_end(); ?>
