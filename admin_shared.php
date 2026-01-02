<?php
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/db.php';

function require_admin_page(PDO $conn): array
{
    if (!admin_logged_in()) {
        header('Location: admin.php');
        exit;
    }

    $admin = find_admin_by_email($conn, $_SESSION['admin_email']);
    if (!$admin) {
        session_destroy();
        header('Location: admin.php');
        exit;
    }

    return $admin;
}

function bootstrap_admin(): array
{
    $config = require __DIR__ . '/config.php';
    $conn = get_connection($config);
    $admin = require_admin_page($conn);

    return [$conn, $config, $admin];
}

function render_admin_layout_start(string $title, string $activeNav, string $heading): void
{
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?></title>
        <style>
            :root { --blue: #0066ff; --blue-dark: #0052cc; --bg: #f6f7fb; --border: #e5e7eb; }
            * { box-sizing: border-box; }
            body { font-family: Arial, sans-serif; background: var(--bg); padding: 0 24px 40px; margin: 0; color: #0f172a; }
            a { color: inherit; }
            h1 { margin: 0; }
            h2 { margin: 0 0 12px; }
            h3 { margin: 0 0 10px; }
            .topbar { display: flex; align-items: center; justify-content: space-between; padding: 16px 0; gap: 16px; }
            .brand { font-weight: 800; font-size: 18px; color: #0f172a; }
            .nav { display: flex; gap: 8px; flex-wrap: wrap; }
            .nav-link { padding: 10px 14px; border-radius: 10px; border: 1px solid transparent; text-decoration: none; color: #0f172a; background: transparent; font-weight: 600; }
            .nav-link:hover { border-color: var(--border); background: #fff; }
            .nav-link.active { background: var(--blue); color: #fff; border-color: var(--blue); }
            .nav-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
            .page { display: flex; flex-direction: column; gap: 16px; }
            .page-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
            .muted { color: #6b7280; }
            .muted-small { color: #6b7280; font-size: 13px; }
            .card { background: #fff; padding: 18px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
            label { display: block; margin: 8px 0 4px; font-weight: bold; }
            input, select, textarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
            button { padding: 10px 16px; border: none; border-radius: 8px; background: var(--blue); color: #fff; cursor: pointer; font-weight: 600; }
            button:hover { background: var(--blue-dark); }
            .ghost-btn { background: transparent; border: 1px solid var(--border); color: #0f172a; }
            .ghost-btn:hover { border-color: var(--blue); color: var(--blue); }
            .link { color: #0066ff; text-decoration: none; font-weight: 600; }
            .link:hover { text-decoration: underline; }
            .success { color: #059669; }
            .error { color: #c00; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 10px 8px; border-bottom: 1px solid var(--border); text-align: left; font-size: 14px; }
            th { font-size: 12px; color: #6b7280; letter-spacing: 0.04em; text-transform: uppercase; }
            tr:hover td { background: #f8fafc; }
            .table-wrapper { overflow-x: auto; }
            .table-actions { display: flex; align-items: center; gap: 8px; }
            .tabs { display: flex; gap: 8px; margin: 14px 0 10px; }
            .tab-btn { border: 1px solid var(--border); background: #f8fafc; color: #0f172a; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-weight: 600; }
            .tab-btn.active { border-color: var(--blue); background: #e0edff; color: var(--blue-dark); }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            .games-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
            .game { border: 1px solid var(--border); padding: 12px; border-radius: 10px; background: #fafafa; display: flex; flex-direction: column; gap: 6px; }
            .badge { display: inline-block; padding: 4px 8px; border-radius: 6px; background: #eef2ff; color: #3730a3; font-size: 12px; margin-bottom: 6px; width: fit-content; }
            .badge.status-1 { background: #ecfdf3; color: #166534; }
            .badge.status-2 { background: #fffbeb; color: #92400e; }
            .badge.status-3 { background: #fef2f2; color: #b91c1c; }
            .game-actions { margin-top: 4px; display: flex; gap: 8px; flex-wrap: wrap; }
            .outline-btn { background: transparent; color: var(--blue); border: 1px solid var(--blue); }
            .outline-btn:hover { background: #f0f6ff; }
            .game-detail { display: grid; grid-template-columns: minmax(280px, 360px) 1fr; gap: 20px; align-items: start; }
            .meta { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
            .meta-item { background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; font-size: 13px; }
            .registrations { border: 1px solid var(--border); border-radius: 10px; padding: 10px; background: #fafafa; max-height: 480px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
            .registration { border-bottom: 1px solid var(--border); padding-bottom: 8px; }
            .registration:last-child { border-bottom: none; }
            .user-layout { display: grid; gap: 14px; grid-template-columns: 300px 1fr; align-items: start; }
            .user-list { max-height: 640px; overflow-y: auto; border: 1px solid var(--border); border-radius: 12px; padding: 8px; background: #fff; }
            .user-btn { width: 100%; text-align: left; padding: 10px; border: 1px solid transparent; border-radius: 10px; background: transparent; cursor: pointer; margin-bottom: 6px; color: #111; }
            .user-btn:hover, .user-btn.active { border-color: var(--blue); background: #f0f6ff; }
            .dialogue { border: 1px solid var(--border); border-radius: 12px; padding: 12px; background: #fff; min-height: 240px; max-height: 520px; overflow-y: auto; }
            .bubble { padding: 10px 12px; border-radius: 12px; margin-bottom: 10px; max-width: 80%; white-space: pre-wrap; }
            .bubble.user { background: #eef2ff; margin-right: auto; }
            .bubble.bot { background: #ecfdf3; margin-left: auto; }
            .dialogue-empty { color: #666; text-align: center; padding: 20px; }
            .section-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        </style>
    </head>
    <body>
        <header class="topbar">
            <div class="brand"><a class="link" href="admin_games.php">Админка MindGames Bot</a></div>
            <nav class="nav">
                <a class="nav-link <?php echo $activeNav === 'games' ? 'active' : ''; ?>" href="admin_games.php">Игры</a>
                <a class="nav-link <?php echo $activeNav === 'dialogues' ? 'active' : ''; ?>" href="admin_dialogues.php">Диалоги</a>
                <a class="nav-link <?php echo $activeNav === 'subscriptions' ? 'active' : ''; ?>" href="admin_subscriptions.php">Уведомления</a>
                <a class="nav-link <?php echo $activeNav === 'create' ? 'active' : ''; ?>" href="admin_create_game.php">Создать игру</a>
            </nav>
            <div class="nav-actions">
                <span class="muted-small">Вы вошли как <strong><?php echo htmlspecialchars($_SESSION['admin_email'] ?? ''); ?></strong></span>
                <button type="button" id="logout-btn" class="ghost-btn">Выйти</button>
            </div>
        </header>
        <main class="page">
            <div class="page-heading">
                <h1><?php echo htmlspecialchars($heading); ?></h1>
            </div>
    <?php
}

function render_admin_layout_end(): void
{
    ?>
        </main>
        <script>
        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', () => {
                fetch('admin_logout.php', {method: 'POST'})
                    .then(() => window.location.href = 'admin.php')
                    .catch(() => window.location.href = 'admin.php');
            });
        }
        </script>
    </body>
    </html>
    <?php
}
