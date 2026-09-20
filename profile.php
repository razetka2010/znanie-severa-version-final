<?php
session_start();
require_once 'config/database.php';
require_once 'config/auth.php';

requireAuth();

$pdo = getDatabaseConnection();
$user_id = $_SESSION['user_id'];

// Получение данных пользователя
$stmt = $pdo->prepare("
    SELECT u.*, r.name as role_name, s.full_name as school_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    LEFT JOIN schools s ON u.school_id = s.id 
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Обработка обновления профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    if (isset($_POST['update_profile'])) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $position = trim($_POST['position']);

        // Валидация
        $errors = [];

        if (empty($full_name)) {
            $errors[] = "ФИО обязательно для заполнения";
        }

        if (empty($email)) {
            $errors[] = "Email обязателен для заполнения";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Некорректный формат email";
        }

        // Проверка уникальности email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = "Этот email уже используется другим пользователем";
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, phone = ?, position = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?
                ");
                $stmt->execute([$full_name, $email, $phone, $position, $user_id]);

                logUserAction($pdo, 'profile_updated', json_encode([
                    'fields' => ['full_name', 'email', 'phone', 'position']
                ], JSON_UNESCAPED_UNICODE));

                // Обновляем данные в сессии
                $_SESSION['user_name'] = $full_name;
                $_SESSION['user_email'] = $email;

                $_SESSION['success_message'] = "Профиль успешно обновлен!";
                header('Location: profile.php');
                exit;
            } catch (PDOException $e) {
                $errors[] = "Ошибка при обновлении профиля: " . $e->getMessage();
            }
        }
    }

    // Обработка смены пароля
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        $errors_password = [];

        // Проверка текущего пароля
        if (!password_verify($current_password, $user['password_hash'])) {
            $errors_password[] = "Текущий пароль указан неверно";
        }

        if (empty($new_password)) {
            $errors_password[] = "Новый пароль обязателен для заполнения";
        } elseif (strlen($new_password) < 6) {
            $errors_password[] = "Новый пароль должен содержать минимум 6 символов";
        }

        if ($new_password !== $confirm_password) {
            $errors_password[] = "Пароли не совпадают";
        }

        if (empty($errors_password)) {
            try {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$new_password_hash, $user_id]);

                $_SESSION['success_message'] = "Пароль успешно изменен!";
                header('Location: profile.php');
                exit;
            } catch (PDOException $e) {
                $errors_password[] = "Ошибка при изменении пароля: " . $e->getMessage();
            }
        }
    }
}

// Получение статистики активности пользователя
$activity_stats = [];

try {
    // Создаем таблицу user_sessions если её нет
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            session_id VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Количество входов за последние 30 дней
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM user_sessions 
        WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    $activity_stats['logins_30_days'] = $result ? $result['count'] : 0;

    // Последний вход
    $stmt = $pdo->prepare("SELECT MAX(created_at) as last_login FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    $activity_stats['last_login'] = $result ? $result['last_login'] : null;

    // Общее количество входов
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_logins FROM user_sessions WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    $activity_stats['total_logins'] = $result ? $result['total_logins'] : 0;

} catch (PDOException $e) {
    // Если произошла ошибка, устанавливаем значения по умолчанию
    $activity_stats['logins_30_days'] = 0;
    $activity_stats['last_login'] = null;
    $activity_stats['total_logins'] = 0;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Профиль - Знание Севера</title>
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/profile.css">
</head>
<body>
<div class="dashboard-container">
    <!-- Боковая панель -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h1>Знание Севера</h1>
            <p>Электронный дневник</p>
        </div>

        <nav class="sidebar-nav">
            <div class="user-info">
                <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
                <span class="role-badge"><?php echo htmlspecialchars($_SESSION['user_role']); ?></span>
            </div>

            <ul class="nav-menu">
                <?php if ($_SESSION['user_role'] === 'super_admin'): ?>
                    <li><a href="admin/super_dashboard.php" class="nav-link">📊 Обзор</a></li>
                    <li class="nav-section">Администрирование</li>
                    <li><a href="admin/schools.php" class="nav-link">🏫 Учебные заведения</a></li>
                    <li><a href="admin/users.php" class="nav-link">👥 Пользователи</a></li>
                    <li><a href="admin/roles.php" class="nav-link">🔐 Роли и права</a></li>
                    <li><a href="admin/curriculum.php" class="nav-link">📚 Учебные планы</a></li>
                    <li><a href="admin/academic_periods.php" class="nav-link">📅 Учебные периоды</a></li>
                    <li><a href="admin/reports.php" class="nav-link">📈 Отчеты</a></li>
                <?php elseif ($_SESSION['user_role'] === 'school_admin'): ?>
                    <li><a href="school_admin/dashboard.php" class="nav-link">📊 Обзор</a></li>
                    <li><a href="school_admin/reports.php" class="nav-link">📈 Отчеты школы</a></li>
                <?php elseif (in_array($_SESSION['user_role'], ['teacher', 'class_teacher'])): ?>
                    <li><a href="teacher/dashboard.php" class="nav-link">📊 Обзор</a></li>
                    <li><a href="teacher/reports.php" class="nav-link">📈 Отчеты</a></li>
                <?php elseif ($_SESSION['user_role'] === 'student'): ?>
                    <li><a href="student/dashboard.php" class="nav-link">📊 Обзор</a></li>
                <?php elseif ($_SESSION['user_role'] === 'parent'): ?>
                    <li><a href="parent/dashboard.php" class="nav-link">📊 Обзор</a></li>
                <?php endif; ?>
                <li class="nav-section">Общее</li>
                <li><a href="profile.php" class="nav-link active">👤 Профиль</a></li>
                <li><a href="logout.php" class="nav-link">🚪 Выход</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Мой профиль</h1>
                <p><?php echo htmlspecialchars($_SESSION['user_role']); ?> • <?php echo htmlspecialchars($_SESSION['user_login']); ?></p>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>

            <div class="profile-container">
                <!-- Основная информация -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2>Основная информация</h2>
                    </div>
                    <div class="profile-card">
                        <div class="profile-avatar">
                            <div class="avatar-placeholder">
                                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                            </div>
                        </div>
                        <div class="profile-info">
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>ФИО:</label>
                                    <span><?php echo htmlspecialchars($user['full_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Логин:</label>
                                    <span><?php echo htmlspecialchars($user['login']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Роль:</label>
                                    <span class="role-badge"><?php echo htmlspecialchars($user['role_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Учебное заведение:</label>
                                    <span><?php echo $user['school_name'] ? htmlspecialchars($user['school_name']) : '—'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Должность:</label>
                                    <span><?php echo $user['position'] ? htmlspecialchars($user['position']) : '—'; ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Дата регистрации:</label>
                                    <span><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Статистика активности -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2>Статистика активности</h2>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">🔐</div>
                            <div class="stat-info">
                                <h3>Входов за 30 дней</h3>
                                <span class="stat-number"><?php echo $activity_stats['logins_30_days']; ?></span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">🕒</div>
                            <div class="stat-info">
                                <h3>Последний вход</h3>
                                <span class="stat-text">
                                        <?php echo $activity_stats['last_login'] ? date('d.m.Y H:i', strtotime($activity_stats['last_login'])) : '—'; ?>
                                    </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Редактирование профиля -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2>Редактирование профиля</h2>
                    </div>
                    <div class="form-card">
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="update_profile" value="1">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="full_name">ФИО *</label>
                                    <input type="text" id="full_name" name="full_name"
                                           value="<?php echo htmlspecialchars($user['full_name']); ?>"
                                           required>
                                </div>
                                <div class="form-group">
                                    <label for="email">Email *</label>
                                    <input type="email" id="email" name="email"
                                           value="<?php echo htmlspecialchars($user['email']); ?>"
                                           required>
                                </div>
                                <div class="form-group">
                                    <label for="phone">Телефон</label>
                                    <input type="tel" id="phone" name="phone"
                                           value="<?php echo htmlspecialchars($user['phone']); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="position">Должность</label>
                                    <input type="text" id="position" name="position"
                                           value="<?php echo htmlspecialchars($user['position']); ?>">
                                </div>
                            </div>

                            <?php if (isset($errors) && !empty($errors)): ?>
                                <div class="alert alert-error">
                                    <ul>
                                        <?php foreach ($errors as $error): ?>
                                            <li><?php echo $error; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">💾 Сохранить изменения</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Смена пароля -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2>Смена пароля</h2>
                    </div>
                    <div class="form-card">
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="change_password" value="1">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="current_password">Текущий пароль *</label>
                                    <input type="password" id="current_password" name="current_password" required>
                                </div>
                                <div class="form-group">
                                    <label for="new_password">Новый пароль *</label>
                                    <input type="password" id="new_password" name="new_password" required>
                                    <small class="form-hint">Минимум 6 символов</small>
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Подтверждение пароля *</label>
                                    <input type="password" id="confirm_password" name="confirm_password" required>
                                </div>
                            </div>

                            <?php if (isset($errors_password) && !empty($errors_password)): ?>
                                <div class="alert alert-error">
                                    <ul>
                                        <?php foreach ($errors_password as $error): ?>
                                            <li><?php echo $error; ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">🔐 Сменить пароль</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Информация о системе -->
                <div class="profile-section">
                    <div class="section-header">
                        <h2>Системная информация</h2>
                    </div>
                    <div class="info-card">
                        <div class="info-grid">
                            <div class="info-item">
                                <label>ID пользователя:</label>
                                <span class="monospace"><?php echo $user['id']; ?></span>
                            </div>
                            <div class="info-item">
                                <label>Статус:</label>
                                <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                        <?php echo $user['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                    </span>
                            </div>
                            <div class="info-item">
                                <label>Дата создания:</label>
                                <span><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></span>
                            </div>
                            <div class="info-item">
                                <label>Последнее обновление:</label>
                                <span><?php echo $user['updated_at'] ? date('d.m.Y H:i', strtotime($user['updated_at'])) : '—'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    // Валидация форм
    document.addEventListener('DOMContentLoaded', function() {
        // Валидация формы профиля
        const profileForm = document.querySelector('form[name="update_profile"]');
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                const fullName = document.getElementById('full_name').value.trim();
                const email = document.getElementById('email').value.trim();

                if (!fullName) {
                    e.preventDefault();
                    showNotification('ФИО обязательно для заполнения', 'error');
                    return;
                }

                if (!email) {
                    e.preventDefault();
                    showNotification('Email обязателен для заполнения', 'error');
                    return;
                }

                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    e.preventDefault();
                    showNotification('Некорректный формат email', 'error');
                    return;
                }
            });
        }

        // Валидация формы смены пароля
        const passwordForm = document.querySelector('form[name="change_password"]');
        if (passwordForm) {
            passwordForm.addEventListener('submit', function(e) {
                const currentPassword = document.getElementById('current_password').value;
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;

                if (!currentPassword) {
                    e.preventDefault();
                    showNotification('Введите текущий пароль', 'error');
                    return;
                }

                if (!newPassword) {
                    e.preventDefault();
                    showNotification('Введите новый пароль', 'error');
                    return;
                }

                if (newPassword.length < 6) {
                    e.preventDefault();
                    showNotification('Новый пароль должен содержать минимум 6 символов', 'error');
                    return;
                }

                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    showNotification('Пароли не совпадают', 'error');
                    return;
                }
            });
        }

        // Показать/скрыть пароли
        const passwordInputs = document.querySelectorAll('input[type="password"]');
        passwordInputs.forEach(input => {
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.innerHTML = '👁️';
            toggle.className = 'password-toggle';
            toggle.style.cssText = `
                    position: absolute;
                    right: 10px;
                    top: 50%;
                    transform: translateY(-50%);
                    background: none;
                    border: none;
                    cursor: pointer;
                    font-size: 16px;
                `;

            input.parentNode.style.position = 'relative';
            input.style.paddingRight = '40px';
            input.parentNode.appendChild(toggle);

            toggle.addEventListener('click', function() {
                if (input.type === 'password') {
                    input.type = 'text';
                    toggle.innerHTML = '🔒';
                } else {
                    input.type = 'password';
                    toggle.innerHTML = '👁️';
                }
            });
        });
    });

    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                color: white;
                font-weight: 600;
                z-index: 10000;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            `;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.remove();
        }, 3000);
    }
</script>
</body>
</html>