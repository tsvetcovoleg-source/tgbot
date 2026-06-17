<?php
require_once __DIR__ . '/admin_shared.php';

[$conn] = bootstrap_admin();

$firstEntryFilters = isset($_GET['first_entry']) && is_array($_GET['first_entry'])
    ? array_values(array_intersect($_GET['first_entry'], ['saint_twins_detective', 'vibe_quiz', 'quest', 'quiz_bet', 'adult_18', 'other']))
    : [];

$saintTwinsPrefix = 'Я хочу зарегистрироваться на игру «Saint Twins Detective';
$vibeQuizPrefix = 'Я хочу зарегистрироваться на игру «Vibe Quiz';
$questPrefix = 'Я хочу зарегистрироваться на игру «Квест';
$adult18Prefix = 'Я хочу зарегистрироваться на игру «Pub Quiz 18+';
$quizBetPattern = '^/start [0-9]+_lot';
$firstMessageJoin = '
    LEFT JOIN (
        SELECT m.user_id, m.message AS first_message, m.id AS first_message_id
        FROM messages m
        INNER JOIN (
            SELECT user_id, MIN(id) AS first_message_id
            FROM messages
            WHERE from_bot = 0
            GROUP BY user_id
        ) fm ON fm.user_id = m.user_id AND fm.first_message_id = m.id
    ) first_user_message ON first_user_message.user_id = u.id
';

$countStmt = $conn->prepare(
    'SELECT
        SUM(CASE WHEN first_user_message.first_message LIKE :count_saint_twins_pattern THEN 1 ELSE 0 END) AS saint_twins_detective_count,
        SUM(CASE WHEN first_user_message.first_message LIKE :count_vibe_quiz_pattern THEN 1 ELSE 0 END) AS vibe_quiz_count,
        SUM(CASE WHEN first_user_message.first_message LIKE :count_quest_pattern THEN 1 ELSE 0 END) AS quest_count,
        SUM(CASE WHEN first_user_message.first_message REGEXP :count_quiz_bet_pattern THEN 1 ELSE 0 END) AS quiz_bet_count,
        SUM(CASE WHEN first_user_message.first_message LIKE :count_adult_18_pattern THEN 1 ELSE 0 END) AS adult_18_count,
        SUM(CASE WHEN first_user_message.first_message IS NULL OR (first_user_message.first_message NOT LIKE :count_other_saint_twins_pattern AND first_user_message.first_message NOT LIKE :count_other_vibe_quiz_pattern AND first_user_message.first_message NOT LIKE :count_other_quest_pattern AND first_user_message.first_message NOT REGEXP :count_other_quiz_bet_pattern AND first_user_message.first_message NOT LIKE :count_other_adult_18_pattern) THEN 1 ELSE 0 END) AS other_count,
        COUNT(*) AS total_count
     FROM users u' . $firstMessageJoin
);
$countStmt->execute([
    ':count_saint_twins_pattern' => $saintTwinsPrefix . '%',
    ':count_vibe_quiz_pattern' => $vibeQuizPrefix . '%',
    ':count_quest_pattern' => $questPrefix . '%',
    ':count_quiz_bet_pattern' => $quizBetPattern,
    ':count_adult_18_pattern' => $adult18Prefix . '%',
    ':count_other_saint_twins_pattern' => $saintTwinsPrefix . '%',
    ':count_other_vibe_quiz_pattern' => $vibeQuizPrefix . '%',
    ':count_other_quest_pattern' => $questPrefix . '%',
    ':count_other_quiz_bet_pattern' => $quizBetPattern,
    ':count_other_adult_18_pattern' => $adult18Prefix . '%',
]);
$counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$firstEntryOptions = [
    [
        'value' => 'saint_twins_detective',
        'label' => 'Saint Twins Detective',
        'count' => (int) ($counts['saint_twins_detective_count'] ?? 0),
    ],
    [
        'value' => 'vibe_quiz',
        'label' => 'Vibe Quiz',
        'count' => (int) ($counts['vibe_quiz_count'] ?? 0),
    ],
    [
        'value' => 'quest',
        'label' => 'Квест',
        'count' => (int) ($counts['quest_count'] ?? 0),
    ],
    [
        'value' => 'quiz_bet',
        'label' => 'Ставка на квизе',
        'count' => (int) ($counts['quiz_bet_count'] ?? 0),
    ],
    [
        'value' => 'adult_18',
        'label' => '18+',
        'count' => (int) ($counts['adult_18_count'] ?? 0),
    ],
    [
        'value' => 'other',
        'label' => 'Другое',
        'count' => (int) ($counts['other_count'] ?? 0),
    ],
];
usort($firstEntryOptions, static function (array $left, array $right): int {
    return $right['count'] <=> $left['count'];
});

$whereParts = [];
$params = [];
if ($firstEntryFilters !== []) {
    $filterParts = [];
    if (in_array('saint_twins_detective', $firstEntryFilters, true)) {
        $filterParts[] = 'first_user_message.first_message LIKE :filter_saint_twins_pattern';
        $params[':filter_saint_twins_pattern'] = $saintTwinsPrefix . '%';
    }
    if (in_array('vibe_quiz', $firstEntryFilters, true)) {
        $filterParts[] = 'first_user_message.first_message LIKE :filter_vibe_quiz_pattern';
        $params[':filter_vibe_quiz_pattern'] = $vibeQuizPrefix . '%';
    }
    if (in_array('quest', $firstEntryFilters, true)) {
        $filterParts[] = 'first_user_message.first_message LIKE :filter_quest_pattern';
        $params[':filter_quest_pattern'] = $questPrefix . '%';
    }
    if (in_array('quiz_bet', $firstEntryFilters, true)) {
        $filterParts[] = 'first_user_message.first_message REGEXP :filter_quiz_bet_pattern';
        $params[':filter_quiz_bet_pattern'] = $quizBetPattern;
    }
    if (in_array('adult_18', $firstEntryFilters, true)) {
        $filterParts[] = 'first_user_message.first_message LIKE :filter_adult_18_pattern';
        $params[':filter_adult_18_pattern'] = $adult18Prefix . '%';
    }
    if (in_array('other', $firstEntryFilters, true)) {
        $filterParts[] = '(first_user_message.first_message IS NULL OR (first_user_message.first_message NOT LIKE :filter_other_saint_twins_pattern AND first_user_message.first_message NOT LIKE :filter_other_vibe_quiz_pattern AND first_user_message.first_message NOT LIKE :filter_other_quest_pattern AND first_user_message.first_message NOT REGEXP :filter_other_quiz_bet_pattern AND first_user_message.first_message NOT LIKE :filter_other_adult_18_pattern))';
        $params[':filter_other_saint_twins_pattern'] = $saintTwinsPrefix . '%';
        $params[':filter_other_vibe_quiz_pattern'] = $vibeQuizPrefix . '%';
        $params[':filter_other_quest_pattern'] = $questPrefix . '%';
        $params[':filter_other_quiz_bet_pattern'] = $quizBetPattern;
        $params[':filter_other_adult_18_pattern'] = $adult18Prefix . '%';
    }
    if ($filterParts !== []) {
        $whereParts[] = '(' . implode(' OR ', $filterParts) . ')';
    }
}

$whereSql = $whereParts === [] ? '' : ' WHERE ' . implode(' AND ', $whereParts);
$userStmt = $conn->prepare(
    'SELECT u.id, u.telegram_id, u.first_name, u.last_name, u.username, first_user_message.first_message, lm.last_message_id
     FROM users u' . $firstMessageJoin . '
     LEFT JOIN (
         SELECT user_id, MAX(id) AS last_message_id
         FROM messages
         GROUP BY user_id
     ) lm ON lm.user_id = u.id' . $whereSql . '
     ORDER BY lm.last_message_id DESC, u.id DESC'
);
$userStmt->execute($params);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

function user_filter_checked(array $filters, string $value): string
{
    return in_array($value, $filters, true) ? ' checked' : '';
}

function render_filter_user_label(array $user): string
{
    $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $username = !empty($user['username']) ? ' (@' . $user['username'] . ')' : '';
    $label = $name !== '' ? $name : 'Без имени';
    $label .= $username;
    $label .= ' – ' . ($user['telegram_id'] ?? '');

    return $label;
}

render_admin_layout_start('Фильтр пользователей — Админка', 'user-filters', 'Фильтр пользователей');
?>
    <div class="card">
        <form class="filters-layout" method="get">
            <aside class="filters-sidebar">
                <div class="section-header">
                    <h2>Фильтры</h2>
                    <button type="submit">Применить</button>
                </div>

                <fieldset class="filter-group">
                    <legend>Первый вход</legend>
                    <?php foreach ($firstEntryOptions as $option): ?>
                        <label class="checkbox-row">
                            <input type="checkbox" name="first_entry[]" value="<?php echo htmlspecialchars($option['value']); ?>"<?php echo user_filter_checked($firstEntryFilters, $option['value']); ?>>
                            <span><?php echo htmlspecialchars($option['label']); ?> (<?php echo (int) $option['count']; ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <a class="link" href="admin_user_filters.php">Сбросить фильтры</a>
            </aside>

            <section class="filtered-users">
                <div class="section-header">
                    <h2>Пользователи</h2>
                    <span class="muted-small">Показано: <?php echo count($users); ?> из <?php echo (int) ($counts['total_count'] ?? 0); ?></span>
                </div>

                <div class="filtered-user-list">
                    <?php if ($users === []): ?>
                        <div class="dialogue-empty">Нет пользователей по выбранным фильтрам</div>
                    <?php endif; ?>

                    <?php foreach ($users as $user): ?>
                        <?php
                            $label = render_filter_user_label($user);
                            $firstMessage = trim($user['first_message'] ?? '');
                        ?>
                        <a class="filtered-user" href="admin_dialogues.php?user_id=<?php echo (int) $user['id']; ?>">
                            <strong><?php echo htmlspecialchars($label); ?></strong>
                            <span class="muted-small">Первое сообщение: <?php echo htmlspecialchars($firstMessage !== '' ? $firstMessage : 'нет пользовательских сообщений'); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </form>
    </div>
<?php render_admin_layout_end(); ?>
