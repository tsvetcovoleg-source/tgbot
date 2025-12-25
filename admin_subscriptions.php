<?php
require_once __DIR__ . '/admin_shared.php';
require_once __DIR__ . '/format_helpers.php';

[$conn, $config] = bootstrap_admin();

$formatFilter = isset($_GET['format']) ? trim($_GET['format']) : '';
$dateFrom = isset($_GET['from']) ? trim($_GET['from']) : '';
$dateTo = isset($_GET['to']) ? trim($_GET['to']) : '';

$knownFormats = get_known_game_formats();

$conditions = [];
$params = [];

if ($formatFilter !== '' && in_array($formatFilter, $knownFormats, true)) {
    $conditions[] = 'fs.format = :format';
    $params[':format'] = $formatFilter;
}

if ($dateFrom !== '') {
    $conditions[] = 'fs.created_at >= :from';
    $params[':from'] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $conditions[] = 'fs.created_at <= :to';
    $params[':to'] = $dateTo . ' 23:59:59';
}

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$stmt = $conn->prepare(
    "SELECT fs.id, fs.user_id, fs.format, fs.created_at, u.telegram_id, u.first_name, u.last_name, u.username
     FROM format_subscriptions fs
     INNER JOIN users u ON u.id = fs.user_id
     $where
     ORDER BY fs.created_at DESC, fs.id DESC"
);

$stmt->execute($params);
$subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

render_admin_layout_start('Уведомления о форматах — Админка', 'subscriptions', 'Подписки на форматы');
?>
    <div class="card">
        <div class="section-header">
            <h2>Подписки на уведомления</h2>
            <span class="muted-small">Всего: <?php echo count($subscriptions); ?></span>
        </div>

        <form method="get" class="filters" style="display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); align-items: end;">
            <div>
                <label for="format">Формат</label>
                <select id="format" name="format">
                    <option value="">Все</option>
                    <?php foreach ($knownFormats as $fmt): ?>
                        <option value="<?php echo htmlspecialchars($fmt); ?>" <?php echo $fmt === $formatFilter ? 'selected' : ''; ?>><?php echo htmlspecialchars($fmt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="from">Дата от</label>
                <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
            </div>
            <div>
                <label for="to">Дата до</label>
                <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($dateTo); ?>">
            </div>
            <div>
                <button type="submit">Фильтровать</button>
            </div>
        </form>

        <div class="table-wrapper" style="margin-top: 12px;">
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Пользователь</th>
                    <th>Формат</th>
                    <th>Дата</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($subscriptions as $row): ?>
                    <?php
                        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                        $username = $row['username'] ? ' (@' . $row['username'] . ')' : '';
                        $label = $name !== '' ? $name : 'Без имени';
                        $label .= $username;
                        $label .= ' – ' . $row['telegram_id'];
                        $dialogueUrl = 'admin_dialogues.php?user_id=' . (int) $row['user_id'];
                    ?>
                    <tr>
                        <td><?php echo (int) $row['id']; ?></td>
                        <td><?php echo htmlspecialchars($label); ?></td>
                        <td><span class="badge"><?php echo htmlspecialchars($row['format']); ?></span></td>
                        <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                        <td><a class="link" href="<?php echo $dialogueUrl; ?>">Открыть диалог</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!$subscriptions): ?>
            <p class="muted" style="margin-top: 8px;">Пока нет подписок по заданным фильтрам.</p>
        <?php endif; ?>
    </div>
<?php render_admin_layout_end(); ?>
