<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';
$conn = get_connection($config);

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $errorMessage = 'Укажите email и пароль';
    } else {
        $stmt = $conn->prepare('SELECT id FROM admins WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $errorMessage = 'Администратор с таким email уже существует';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $conn->prepare('INSERT INTO admins (email, password_hash) VALUES (:email, :password_hash)');
            $insert->execute([
                ':email' => $email,
                ':password_hash' => $passwordHash,
            ]);

            $successMessage = 'Администратор добавлен';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Добавление администратора</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 24px; }
        .card { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); max-width: 480px; margin: 0 auto; }
        label { display: block; margin: 8px 0 4px; font-weight: bold; }
        input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; }
        button { margin-top: 12px; padding: 10px 16px; border: none; border-radius: 8px; background: #0066ff; color: #fff; cursor: pointer; width: 100%; }
        button:hover { background: #0052cc; }
        .success { color: green; }
        .error { color: #c00; }
        .link { color: #0066ff; text-decoration: none; }
    </style>
</head>
<body>
<div class="card">
    <h1>Добавление администратора</h1>
    <p>Введите email и пароль. Пароль будет сохранён в зашифрованном виде.</p>
    <?php if ($successMessage): ?>
        <p class="success"><?php echo htmlspecialchars($successMessage); ?></p>
    <?php endif; ?>
    <?php if ($errorMessage): ?>
        <p class="error"><?php echo htmlspecialchars($errorMessage); ?></p>
    <?php endif; ?>
    <form method="post">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>

        <label for="password">Пароль</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Добавить</button>
    </form>
    <p><a class="link" href="admin.php">Вернуться в админку</a></p>
</div>
</body>
</html>
