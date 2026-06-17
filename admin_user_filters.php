<?php
require_once __DIR__ . '/admin_shared.php';

[$conn] = bootstrap_admin();

function read_filter_values(string $name, array $allowedValues, array $fallbackNames = []): array
{
    $values = [];
    $names = array_merge([$name], $fallbackNames);

    foreach ($names as $requestName) {
        if (isset($_GET[$requestName]) && is_array($_GET[$requestName])) {
            $values = array_merge($values, $_GET[$requestName]);
        }
    }

    return array_values(array_unique(array_intersect($values, $allowedValues)));
}

function read_month_filter_values(string $name, array $fallbackNames = []): array
{
    $values = [];
    $names = array_merge([$name], $fallbackNames);

    foreach ($names as $requestName) {
        if (isset($_GET[$requestName]) && is_array($_GET[$requestName])) {
            $values = array_merge($values, $_GET[$requestName]);
        }
    }

    return array_values(array_unique(array_filter($values, static function (string $month): bool {
        return preg_match('/^\d{4}-\d{2}$/', $month) === 1;
    })));
}

$firstEntryFilterValues = ['saint_twins_detective', 'vibe_quiz', 'quest', 'quiz_bet', 'adult_18', 'other'];
$specialFilterValues = ['visited_quiz_bets', 'interested_vibe_quiz', 'interested_quest'];

$firstEntryIncludeFilters = read_filter_values('first_entry_include', $firstEntryFilterValues, ['first_entry']);
$firstEntryExcludeFilters = read_filter_values('first_entry_exclude', $firstEntryFilterValues);
$firstMessageIncludeMonths = read_month_filter_values('first_message_month_include', ['first_message_month']);
$firstMessageExcludeMonths = read_month_filter_values('first_message_month_exclude');
$lastMessageIncludeMonths = read_month_filter_values('last_message_month_include', ['last_message_month']);
$lastMessageExcludeMonths = read_month_filter_values('last_message_month_exclude');
$specialIncludeFilters = read_filter_values('special_filter_include', $specialFilterValues, ['special_filter']);
$specialExcludeFilters = read_filter_values('special_filter_exclude', $specialFilterValues);

function get_message_date_column(PDO $conn): ?string
{
    foreach (['sent_at', 'created_at', 'timestamp'] as $column) {
        $stmt = $conn->query("SHOW COLUMNS FROM messages LIKE " . $conn->quote($column));
        if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
            return $column;
        }
    }

    return null;
}

$messageDateColumn = get_message_date_column($conn);
$messageDateSelect = $messageDateColumn !== null
    ? ', m.`' . str_replace('`', '``', $messageDateColumn) . '`'
    : ', NULL';

$saintTwinsPrefix = 'Я хочу зарегистрироваться на игру «Saint Twins Detective';
$vibeQuizPrefix = 'Я хочу зарегистрироваться на игру «Vibe Quiz';
$questPrefix = 'Я хочу зарегистрироваться на игру «Квест';
$questQuizPrefix = 'Я хочу зарегистрироваться на игру «Квест Quiz';
$adult18Prefix = 'Я хочу зарегистрироваться на игру «Pub Quiz 18+';
$quizBetPattern = '^/start [0-9]+_lot';
$firstMessageJoin = '
    LEFT JOIN (
        SELECT m.user_id, m.message AS first_message' . $messageDateSelect . ' AS first_message_created_at, m.id AS first_message_id
        FROM messages m
        INNER JOIN (
            SELECT user_id, MIN(id) AS first_message_id
            FROM messages
            WHERE from_bot = 0
            GROUP BY user_id
        ) fm ON fm.user_id = m.user_id AND fm.first_message_id = m.id
    ) first_user_message ON first_user_message.user_id = u.id
';
$lastMessageJoin = '
    LEFT JOIN (
        SELECT m.user_id' . $messageDateSelect . ' AS last_message_created_at, m.id AS last_message_id
        FROM messages m
        INNER JOIN (
            SELECT user_id, MAX(id) AS last_message_id
            FROM messages
            WHERE from_bot = 0
            GROUP BY user_id
        ) lm ON lm.user_id = m.user_id AND lm.last_message_id = m.id
    ) last_user_message ON last_user_message.user_id = u.id
';

$specialCountStmt = $conn->prepare(
    'SELECT
        COUNT(DISTINCT CASE WHEN m.message REGEXP :visited_quiz_bets_pattern THEN u.id END) AS visited_quiz_bets_count,
        COUNT(DISTINCT CASE WHEN m.message LIKE :interested_vibe_quiz_pattern THEN u.id END) AS interested_vibe_quiz_count,
        COUNT(DISTINCT CASE WHEN m.message LIKE :interested_quest_pattern THEN u.id END) AS interested_quest_count
     FROM users u
     INNER JOIN messages m ON m.user_id = u.id
     WHERE m.from_bot = 0'
);
$specialCountStmt->execute([
    ':visited_quiz_bets_pattern' => $quizBetPattern,
    ':interested_vibe_quiz_pattern' => $vibeQuizPrefix . '%',
    ':interested_quest_pattern' => $questQuizPrefix . '%',
]);
$specialCounts = $specialCountStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$specialFilterOptions = [
    [
        'value' => 'visited_quiz_bets',
        'label' => 'Заходили в ставки на квизе',
        'count' => (int) ($specialCounts['visited_quiz_bets_count'] ?? 0),
    ],
    [
        'value' => 'interested_vibe_quiz',
        'label' => 'Интересовались Vibe Quiz',
        'count' => (int) ($specialCounts['interested_vibe_quiz_count'] ?? 0),
    ],
    [
        'value' => 'interested_quest',
        'label' => 'Интересовались Квестом',
        'count' => (int) ($specialCounts['interested_quest_count'] ?? 0),
    ],
];

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

$monthOptions = [];
$lastMonthOptions = [];
if ($messageDateColumn !== null) {
    $monthStmt = $conn->query(
        "SELECT DATE_FORMAT(first_user_message.first_message_created_at, '%Y-%m') AS first_month, COUNT(*) AS user_count
         FROM users u" . $firstMessageJoin . "
         WHERE first_user_message.first_message_created_at IS NOT NULL
         GROUP BY first_month
         ORDER BY first_month ASC"
    );
    $monthOptions = $monthStmt->fetchAll(PDO::FETCH_ASSOC);

    $lastMonthStmt = $conn->query(
        "SELECT DATE_FORMAT(last_user_message.last_message_created_at, '%Y-%m') AS last_month, COUNT(*) AS user_count
         FROM users u" . $lastMessageJoin . "
         WHERE last_user_message.last_message_created_at IS NOT NULL
         GROUP BY last_month
         ORDER BY last_month ASC"
    );
    $lastMonthOptions = $lastMonthStmt->fetchAll(PDO::FETCH_ASSOC);
}

function append_first_entry_filters(array &$whereParts, array &$params, array $filters, string $mode, string $saintTwinsPrefix, string $vibeQuizPrefix, string $questPrefix, string $quizBetPattern, string $adult18Prefix): void
{
    if ($filters === []) {
        return;
    }

    $filterParts = [];
    $paramPrefix = ':filter_' . $mode . '_';
    if (in_array('saint_twins_detective', $filters, true)) {
        $filterParts[] = 'COALESCE(first_user_message.first_message LIKE ' . $paramPrefix . 'saint_twins_pattern, 0)';
        $params[$paramPrefix . 'saint_twins_pattern'] = $saintTwinsPrefix . '%';
    }
    if (in_array('vibe_quiz', $filters, true)) {
        $filterParts[] = 'COALESCE(first_user_message.first_message LIKE ' . $paramPrefix . 'vibe_quiz_pattern, 0)';
        $params[$paramPrefix . 'vibe_quiz_pattern'] = $vibeQuizPrefix . '%';
    }
    if (in_array('quest', $filters, true)) {
        $filterParts[] = 'COALESCE(first_user_message.first_message LIKE ' . $paramPrefix . 'quest_pattern, 0)';
        $params[$paramPrefix . 'quest_pattern'] = $questPrefix . '%';
    }
    if (in_array('quiz_bet', $filters, true)) {
        $filterParts[] = 'COALESCE(first_user_message.first_message REGEXP ' . $paramPrefix . 'quiz_bet_pattern, 0)';
        $params[$paramPrefix . 'quiz_bet_pattern'] = $quizBetPattern;
    }
    if (in_array('adult_18', $filters, true)) {
        $filterParts[] = 'COALESCE(first_user_message.first_message LIKE ' . $paramPrefix . 'adult_18_pattern, 0)';
        $params[$paramPrefix . 'adult_18_pattern'] = $adult18Prefix . '%';
    }
    if (in_array('other', $filters, true)) {
        $filterParts[] = '(first_user_message.first_message IS NULL OR (first_user_message.first_message NOT LIKE ' . $paramPrefix . 'other_saint_twins_pattern AND first_user_message.first_message NOT LIKE ' . $paramPrefix . 'other_vibe_quiz_pattern AND first_user_message.first_message NOT LIKE ' . $paramPrefix . 'other_quest_pattern AND first_user_message.first_message NOT REGEXP ' . $paramPrefix . 'other_quiz_bet_pattern AND first_user_message.first_message NOT LIKE ' . $paramPrefix . 'other_adult_18_pattern))';
        $params[$paramPrefix . 'other_saint_twins_pattern'] = $saintTwinsPrefix . '%';
        $params[$paramPrefix . 'other_vibe_quiz_pattern'] = $vibeQuizPrefix . '%';
        $params[$paramPrefix . 'other_quest_pattern'] = $questPrefix . '%';
        $params[$paramPrefix . 'other_quiz_bet_pattern'] = $quizBetPattern;
        $params[$paramPrefix . 'other_adult_18_pattern'] = $adult18Prefix . '%';
    }

    if ($filterParts !== []) {
        $condition = '(' . implode(' OR ', $filterParts) . ')';
        $whereParts[] = $mode === 'exclude' ? 'NOT ' . $condition : $condition;
    }
}

function append_special_filters(array &$whereParts, array &$params, array $filters, string $mode, string $quizBetPattern, string $vibeQuizPrefix, string $questQuizPrefix): void
{
    foreach ($filters as $filter) {
        $existsSql = '';
        $placeholder = ':special_' . $mode . '_' . $filter . '_pattern';
        if ($filter === 'visited_quiz_bets') {
            $existsSql = 'EXISTS (SELECT 1 FROM messages special_quiz_bet_message WHERE special_quiz_bet_message.user_id = u.id AND special_quiz_bet_message.from_bot = 0 AND special_quiz_bet_message.message REGEXP ' . $placeholder . ')';
            $params[$placeholder] = $quizBetPattern;
        } elseif ($filter === 'interested_vibe_quiz') {
            $existsSql = 'EXISTS (SELECT 1 FROM messages special_vibe_quiz_message WHERE special_vibe_quiz_message.user_id = u.id AND special_vibe_quiz_message.from_bot = 0 AND special_vibe_quiz_message.message LIKE ' . $placeholder . ')';
            $params[$placeholder] = $vibeQuizPrefix . '%';
        } elseif ($filter === 'interested_quest') {
            $existsSql = 'EXISTS (SELECT 1 FROM messages special_quest_message WHERE special_quest_message.user_id = u.id AND special_quest_message.from_bot = 0 AND special_quest_message.message LIKE ' . $placeholder . ')';
            $params[$placeholder] = $questQuizPrefix . '%';
        }

        if ($existsSql !== '') {
            $whereParts[] = $mode === 'exclude' ? 'NOT ' . $existsSql : $existsSql;
        }
    }
}

function append_month_filters(array &$whereParts, array &$params, array $months, string $columnSql, string $paramBase, string $mode): void
{
    if ($months === []) {
        return;
    }

    $placeholders = [];
    foreach ($months as $index => $month) {
        $placeholder = ':' . $paramBase . '_' . $mode . '_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $month;
    }

    $condition = $columnSql . ' IN (' . implode(', ', $placeholders) . ')';
    $whereParts[] = $mode === 'exclude' ? '(' . $columnSql . ' IS NULL OR NOT ' . $condition . ')' : $condition;
}

$whereParts = [];
$params = [];
append_first_entry_filters($whereParts, $params, $firstEntryIncludeFilters, 'include', $saintTwinsPrefix, $vibeQuizPrefix, $questPrefix, $quizBetPattern, $adult18Prefix);
append_first_entry_filters($whereParts, $params, $firstEntryExcludeFilters, 'exclude', $saintTwinsPrefix, $vibeQuizPrefix, $questPrefix, $quizBetPattern, $adult18Prefix);
append_special_filters($whereParts, $params, $specialIncludeFilters, 'include', $quizBetPattern, $vibeQuizPrefix, $questQuizPrefix);
append_special_filters($whereParts, $params, $specialExcludeFilters, 'exclude', $quizBetPattern, $vibeQuizPrefix, $questQuizPrefix);

if ($messageDateColumn !== null) {
    append_month_filters($whereParts, $params, $firstMessageIncludeMonths, "DATE_FORMAT(first_user_message.first_message_created_at, '%Y-%m')", 'first_message_month', 'include');
    append_month_filters($whereParts, $params, $firstMessageExcludeMonths, "DATE_FORMAT(first_user_message.first_message_created_at, '%Y-%m')", 'first_message_month', 'exclude');
    append_month_filters($whereParts, $params, $lastMessageIncludeMonths, "DATE_FORMAT(last_user_message.last_message_created_at, '%Y-%m')", 'last_message_month', 'include');
    append_month_filters($whereParts, $params, $lastMessageExcludeMonths, "DATE_FORMAT(last_user_message.last_message_created_at, '%Y-%m')", 'last_message_month', 'exclude');
}

$whereSql = $whereParts === [] ? '' : ' WHERE ' . implode(' AND ', $whereParts);
$userStmt = $conn->prepare(
    'SELECT
         u.id,
         u.telegram_id,
         u.first_name,
         u.last_name,
         u.username,
         first_user_message.first_message,
         first_user_message.first_message_created_at,
         last_user_message.last_message_created_at,
         lm.last_message_id,
         EXISTS (SELECT 1 FROM messages quiz_bet_message WHERE quiz_bet_message.user_id = u.id AND quiz_bet_message.from_bot = 0 AND quiz_bet_message.message REGEXP :label_visited_quiz_bets_pattern) AS has_visited_quiz_bets,
         EXISTS (SELECT 1 FROM messages vibe_quiz_message WHERE vibe_quiz_message.user_id = u.id AND vibe_quiz_message.from_bot = 0 AND vibe_quiz_message.message LIKE :label_interested_vibe_quiz_pattern) AS has_interested_vibe_quiz,
         EXISTS (SELECT 1 FROM messages quest_message WHERE quest_message.user_id = u.id AND quest_message.from_bot = 0 AND quest_message.message LIKE :label_interested_quest_pattern) AS has_interested_quest
     FROM users u' . $firstMessageJoin . $lastMessageJoin . '
     LEFT JOIN (
         SELECT user_id, MAX(id) AS last_message_id
         FROM messages
         GROUP BY user_id
     ) lm ON lm.user_id = u.id' . $whereSql . '
     ORDER BY lm.last_message_id DESC, u.id DESC'
);
$params[':label_visited_quiz_bets_pattern'] = $quizBetPattern;
$params[':label_interested_vibe_quiz_pattern'] = $vibeQuizPrefix . '%';
$params[':label_interested_quest_pattern'] = $questQuizPrefix . '%';
$userStmt->execute($params);
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

function user_filter_checked(array $filters, string $value): string
{
    return in_array($value, $filters, true) ? ' checked' : '';
}

function render_filter_choice(string $name, array $includeFilters, array $excludeFilters, array $option): void
{
    ?>
    <div class="filter-choice">
        <div class="filter-choice-main">
            <span class="filter-choice-title"><?php echo htmlspecialchars($option['label']); ?></span>
            <span class="filter-choice-count"><?php echo (int) $option['count']; ?> пользователей</span>
        </div>
        <div class="filter-choice-actions" aria-label="Режим фильтра">
            <label class="filter-toggle include" title="Выбрать пользователей с этим признаком">
                <input type="checkbox" name="<?php echo htmlspecialchars($name); ?>_include[]" value="<?php echo htmlspecialchars($option['value']); ?>"<?php echo user_filter_checked($includeFilters, $option['value']); ?>>
                +
            </label>
            <label class="filter-toggle exclude" title="Исключить пользователей с этим признаком">
                <input type="checkbox" name="<?php echo htmlspecialchars($name); ?>_exclude[]" value="<?php echo htmlspecialchars($option['value']); ?>"<?php echo user_filter_checked($excludeFilters, $option['value']); ?>>
                −
            </label>
        </div>
    </div>
    <?php
}

function render_month_filter_choice(string $name, string $month, int $count, array $includeMonths, array $excludeMonths): void
{
    ?>
    <div class="filter-choice">
        <div class="filter-choice-main">
            <span class="filter-choice-title"><?php echo htmlspecialchars($month); ?></span>
            <span class="filter-choice-count"><?php echo $count; ?> пользователей</span>
        </div>
        <div class="filter-choice-actions" aria-label="Режим фильтра">
            <label class="filter-toggle include" title="Выбрать пользователей за этот месяц">
                <input type="checkbox" name="<?php echo htmlspecialchars($name); ?>_include[]" value="<?php echo htmlspecialchars($month); ?>"<?php echo in_array($month, $includeMonths, true) ? ' checked' : ''; ?>>
                +
            </label>
            <label class="filter-toggle exclude" title="Исключить пользователей за этот месяц">
                <input type="checkbox" name="<?php echo htmlspecialchars($name); ?>_exclude[]" value="<?php echo htmlspecialchars($month); ?>"<?php echo in_array($month, $excludeMonths, true) ? ' checked' : ''; ?>>
                −
            </label>
        </div>
    </div>
    <?php
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

function filter_text_starts_with(string $text, string $prefix): bool
{
    return substr($text, 0, strlen($prefix)) === $prefix;
}

function render_user_filter_labels(array $user, string $saintTwinsPrefix, string $vibeQuizPrefix, string $questPrefix, string $adult18Prefix, string $quizBetPattern): array
{
    $labels = [];
    $firstMessage = trim($user['first_message'] ?? '');

    if ($firstMessage === '') {
        $labels[] = 'Первый вход: другое';
    } elseif (filter_text_starts_with($firstMessage, $saintTwinsPrefix)) {
        $labels[] = 'Первый вход: Saint Twins Detective';
    } elseif (filter_text_starts_with($firstMessage, $vibeQuizPrefix)) {
        $labels[] = 'Первый вход: Vibe Quiz';
    } elseif (filter_text_starts_with($firstMessage, $questPrefix)) {
        $labels[] = 'Первый вход: квест';
    } elseif (preg_match('/' . str_replace('/', '\/', $quizBetPattern) . '/', $firstMessage) === 1) {
        $labels[] = 'Первый вход: ставка на квизе';
    } elseif (filter_text_starts_with($firstMessage, $adult18Prefix)) {
        $labels[] = 'Первый вход: 18+';
    } else {
        $labels[] = 'Первый вход: другое';
    }

    if (!empty($user['has_visited_quiz_bets'])) {
        $labels[] = 'Заходил в ставки на квизе';
    }
    if (!empty($user['has_interested_vibe_quiz'])) {
        $labels[] = 'Интересовался Vibe Quiz';
    }
    if (!empty($user['has_interested_quest'])) {
        $labels[] = 'Интересовался квестом';
    }

    if (!empty($user['first_message_created_at'])) {
        $labels[] = 'Первое сообщение: ' . substr((string) $user['first_message_created_at'], 0, 7);
    }
    if (!empty($user['last_message_created_at'])) {
        $labels[] = 'Последнее сообщение: ' . substr((string) $user['last_message_created_at'], 0, 7);
    }

    return $labels;
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
                <p class="filter-hint">Нажмите <strong>+</strong>, чтобы выбрать пользователей с признаком, или <strong>−</strong>, чтобы исключить их из выдачи.</p>

                <fieldset class="filter-group">
                    <legend>Первый вход</legend>
                    <?php foreach ($firstEntryOptions as $option): ?>
                        <?php render_filter_choice('first_entry', $firstEntryIncludeFilters, $firstEntryExcludeFilters, $option); ?>
                    <?php endforeach; ?>
                </fieldset>

                <fieldset class="filter-group">
                    <legend>Специальные фильтры</legend>
                    <?php foreach ($specialFilterOptions as $option): ?>
                        <?php render_filter_choice('special_filter', $specialIncludeFilters, $specialExcludeFilters, $option); ?>
                    <?php endforeach; ?>
                </fieldset>

                <fieldset class="filter-group">
                    <legend>Месяц первого сообщения</legend>
                    <?php if ($messageDateColumn === null): ?>
                        <p class="muted-small">В таблице messages нет колонки sent_at, created_at или timestamp</p>
                    <?php elseif ($monthOptions === []): ?>
                        <p class="muted-small">Нет сообщений с датой</p>
                    <?php endif; ?>
                    <?php foreach ($monthOptions as $option): ?>
                        <?php $month = $option['first_month']; ?>
                        <?php render_month_filter_choice('first_message_month', $month, (int) $option['user_count'], $firstMessageIncludeMonths, $firstMessageExcludeMonths); ?>
                    <?php endforeach; ?>
                </fieldset>

                <fieldset class="filter-group">
                    <legend>Месяц последнего сообщения</legend>
                    <?php if ($messageDateColumn === null): ?>
                        <p class="muted-small">В таблице messages нет колонки sent_at, created_at или timestamp</p>
                    <?php elseif ($lastMonthOptions === []): ?>
                        <p class="muted-small">Нет сообщений с датой</p>
                    <?php endif; ?>
                    <?php foreach ($lastMonthOptions as $option): ?>
                        <?php $month = $option['last_month']; ?>
                        <?php render_month_filter_choice('last_message_month', $month, (int) $option['user_count'], $lastMessageIncludeMonths, $lastMessageExcludeMonths); ?>
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
                            $filterLabels = render_user_filter_labels($user, $saintTwinsPrefix, $vibeQuizPrefix, $questPrefix, $adult18Prefix, $quizBetPattern);
                        ?>
                        <a class="filtered-user" href="admin_dialogues.php?user_id=<?php echo (int) $user['id']; ?>">
                            <strong><?php echo htmlspecialchars($label); ?></strong>
                            <div class="filter-label-list" aria-label="Метки пользователя">
                                <?php foreach ($filterLabels as $filterLabel): ?>
                                    <span class="user-filter-label"><?php echo htmlspecialchars($filterLabel); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </form>
    </div>
<?php render_admin_layout_end(); ?>
