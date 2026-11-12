<?php
require_once __DIR__ . '/config/session.php';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Для выхода требуется POST-запрос.');
}

// Если сессии уже нет (например, истекла) — просто показываем страницу выхода, а не ошибку CSRF
if (isset($_SESSION['user_id'])) {
    verify_csrf_token();
}

// Сохраняем информацию о пользователе для сообщения
$user_name = $_SESSION['user_name'] ?? 'Пользователь';
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$current_session_id = session_id();
$session_cleanup_failed = false;

destroyAppSession();

if ($user_id > 0) {
    try {
        // Запись в user_sessions НЕ удаляем: таблица используется как история входов
        // (статистика в профилях и на дашборде супер-админа).
    } catch (Throwable $e) {
        error_log('Failed to close user session record: ' . $e->getMessage());
        $session_cleanup_failed = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Выход из системы - Знание Севера</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logout-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
            width: 90%;
        }

        .logout-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .logout-message {
            margin-bottom: 30px;
        }

        .logout-message h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .logout-message p {
            color: #666;
            line-height: 1.5;
        }

        .btn-login {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.2s ease;
        }

        .btn-login:hover {
            transform: translateY(-2px);
        }

        .auto-redirect {
            margin-top: 20px;
            color: #666;
            font-size: 14px;
        }

        .cleanup-warning {
            margin-top: 20px;
            padding: 12px;
            border: 1px solid #f0ad4e;
            border-radius: 8px;
            background: #fff8e6;
            color: #795b18;
            line-height: 1.5;
        }
    </style>
</head>
<body>
<div class="logout-container">
    <div class="logout-icon">👋</div>

    <div class="logout-message">
        <h1>Выход выполнен</h1>
        <p>Вы успешно вышли из системы.<br>До свидания, <?php echo htmlspecialchars($user_name); ?>!</p>
        <?php if ($session_cleanup_failed): ?>
            <p class="cleanup-warning" role="status">Не удалось очистить запись сессии в базе данных. Выход из системы выполнен, но обратитесь к администратору, если проблема повторится.</p>
        <?php endif; ?>
    </div>

    <a href="login.php" class="btn-login">Войти снова</a>

    <div class="auto-redirect">
        Автоматический переход через <span id="countdown">5</span> секунд...
    </div>
</div>

<script>
    // Автоматический редирект через 5 секунд
    let seconds = 5;
    const countdownElement = document.getElementById('countdown');

    const countdown = setInterval(function() {
        seconds--;
        countdownElement.textContent = seconds;

        if (seconds <= 0) {
            clearInterval(countdown);
            window.location.href = 'login.php';
        }
    }, 1000);
</script>
</body>
</html>