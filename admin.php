<?php
require_once __DIR__ . '/admin_auth.php';

if (admin_logged_in()) {
    header('Location: admin_games.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Вход в админку</title>
    <style>
        :root { --blue: #0066ff; --blue-dark: #0052cc; --bg: #f6f7fb; --border: #e5e7eb; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: var(--bg); padding: 0 24px; margin: 0; color: #0f172a; }
        h1 { margin: 32px 0 16px; }
        .auth-wrapper { max-width: 460px; margin: 40px auto; }
        .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
        label { display: block; margin: 8px 0 4px; font-weight: bold; }
        input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        button { padding: 10px 16px; border: none; border-radius: 8px; background: var(--blue); color: #fff; cursor: pointer; font-weight: 600; }
        button:hover { background: var(--blue-dark); }
        .muted { color: #6b7280; }
        .error { color: #c00; }
        .link { color: #0066ff; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="auth-wrapper">
        <h1>Вход в админку MindGames Bot</h1>
        <div class="card">
            <p>Введите email и пароль администратора, чтобы перейти к управлению.</p>
            <form id="login-form">
                <label for="login-email">Email</label>
                <input type="email" id="login-email" name="email" required>

                <label for="login-password">Пароль</label>
                <input type="password" id="login-password" name="password" required>

                <button type="submit" style="margin-top: 12px;">Войти</button>
                <p id="login-status" class="error"></p>
            </form>
            <p class="muted">Нужно создать аккаунт? Используйте страницу <a class="link" href="create_admin.php">добавления администратора</a>.</p>
        </div>
    </div>

    <script>
    const loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const status = document.getElementById('login-status');
            status.textContent = '';

            const payload = {
                email: document.getElementById('login-email').value,
                password: document.getElementById('login-password').value,
            };

            fetch('admin_login.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            }).then(async (res) => {
                const data = await res.json();
                if (res.ok) {
                    window.location.href = 'admin_games.php';
                } else {
                    status.textContent = data.error || 'Ошибка авторизации';
                }
            }).catch(() => {
                status.textContent = 'Ошибка сети при авторизации';
            });
        });
    }
    </script>
</body>
</html>
