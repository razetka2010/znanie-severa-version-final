<?php
session_start();

// Если пользователь уже авторизован, редиректим на соответствующую страницу
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    switch ($_SESSION['user_role']) {
        case 'super_admin':
            header('Location: admin/super_dashboard.php');
            exit;
        case 'school_admin':
            header('Location: school_admin/dashboard.php');
            exit;
        case 'teacher':
        case 'class_teacher':
            header('Location: teacher/dashboard.php');
            exit;
        case 'student':
            header('Location: student/dashboard.php');
            exit;
        case 'parent':
            header('Location: parent/dashboard.php');
            exit;
        default:
            // Если роль неизвестна, разлогиниваем
            session_unset();
            session_destroy();
            break;
    }
}

// Подключение к базе данных
require_once 'config/database.php';
$pdo = getDatabaseConnection();

$error = '';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($login) || empty($password)) {
        $error = "Введите логин и пароль";
    } else {
        try {
            // Ищем пользователя по логину или email
            $stmt = $pdo->prepare("
                SELECT u.*, r.name as role_name, s.id as school_id, s.full_name as school_name 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id 
                LEFT JOIN schools s ON u.school_id = s.id 
                WHERE (u.login = ? OR u.email = ?) AND u.is_active = TRUE
            ");
            $stmt->execute([$login, $login]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Успешная авторизация
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_login'] = $user['login'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['role_name'];
                $_SESSION['user_school_id'] = $user['school_id'] ?? null; // Добавляем ?? null
                $_SESSION['school_name'] = $user['school_name'] ?? '';
                $_SESSION['last_activity'] = time();

                $pdo->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user['id']]);
                $pdo->prepare("\n                    INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent)\n                    VALUES (?, ?, ?, ?)\n                ")->execute([
                    $user['id'],
                    session_id(),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);

                // Редирект в зависимости от роли
                switch ($user['role_name']) {
                    case 'super_admin':
                        header('Location: admin/super_dashboard.php');
                        exit;
                    case 'school_admin':
                        header('Location: school_admin/dashboard.php');
                        exit;
                    case 'teacher':
                    case 'class_teacher':
                        header('Location: teacher/dashboard.php');
                        exit;
                    case 'student':
                        header('Location: student/dashboard.php');
                        exit;
                    case 'parent':
                        header('Location: parent/dashboard.php');
                        exit;
                    default:
                        $error = "Неизвестная роль пользователя";
                        break;
                }
            } else {
                $error = "Неверный логин или пароль";
            }
        } catch (PDOException $e) {
            $error = "Ошибка при входе в систему";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему - Знание Севера</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-strong: #1d4ed8;
            --bg: #eff6ff;
            --panel: #ffffff;
            --text: #1f2937;
            --muted: #64748b;
            --line: #dfeafc;
            --danger: #dc2626;
            --danger-bg: #fef2f2;
            --shadow: 0 20px 45px rgba(37, 99, 235, 0.16);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #e0edff 0%, #f8fbff 40%, #eff6ff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--text);
        }

        .login-container {
            background: var(--panel);
            padding: 40px 34px;
            border-radius: 22px;
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 420px;
            border: 1px solid var(--line);
        }

        .logo {
            text-align: center;
            margin-bottom: 28px;
        }

        .logo h1 {
            color: var(--primary-strong);
            font-size: 2rem;
            margin-bottom: 6px;
            letter-spacing: -0.02em;
        }

        .logo p {
            color: var(--muted);
            font-size: 0.96rem;
            letter-spacing: 0.03em;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--line);
            border-radius: 12px;
            font-size: 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            background: #f8fbff;
        }

        .form-group input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
            background: white;
        }

        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-strong) 100%);
            color: white;
            border: none;
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.2);
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 26px rgba(37, 99, 235, 0.25);
        }

        .error-message {
            background: var(--danger-bg);
            color: var(--danger);
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid rgba(220, 38, 38, 0.18);
            text-align: center;
            font-size: 0.92rem;
            font-weight: 600;
        }

        .system-info {
            text-align: center;
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.75rem;
            line-height: 1.6;
        }
    </style>
</head>
<body>
<div class="login-container">
    <div class="logo">
        <h1>Знание Севера</h1>
        <p>Электронный дневник</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="error-message">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="form-group">
            <label for="login">Логин или Email:</label>
            <input type="text" id="login" name="login" required autofocus>
        </div>

        <div class="form-group">
            <label for="password">Пароль:</label>
            <input type="password" id="password" name="password" required>
        </div>

        <button type="submit" class="btn-login">Войти в систему</button>
    </form>

    <div class="system-info">
        Система электронного документооборота образовательного учреждения
    </div>
</div>
</body>
</html>