<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

// Проверка прав - только super_admin
requireSuperAdmin();

$pdo = getDatabaseConnection();
$action = isset($_GET['action']) ? $_GET['action'] : '';
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

function getPostedSchoolId(): ?int
{
    $school_id = filter_input(INPUT_POST, 'school_id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1]
    ]);

    return $school_id ?: null;
}

function validateSchoolId(PDO $pdo, ?int $school_id): ?string
{
    if ($school_id === null) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM schools WHERE id = ?');
    $stmt->execute([$school_id]);

    return $stmt->fetchColumn() === false ? 'Выбранная школа не существует. Обновите список школ и повторите попытку.' : null;
}

// Обработка добавления пользователя
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    if ($action === 'add') {
        $login = trim($_POST['login']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $position = trim($_POST['position']);
        $role_id = intval($_POST['role_id']);
        $school_id = getPostedSchoolId();
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Валидация
        $errors = [];

        if (empty($login)) {
            $errors[] = "Логин обязателен для заполнения";
        }

        if (empty($password)) {
            $errors[] = "Пароль обязателен для заполнения";
        } elseif (strlen($password) < 6) {
            $errors[] = "Пароль должен содержать минимум 6 символов";
        }

        if (empty($full_name)) {
            $errors[] = "ФИО обязательно для заполнения";
        }

        if (empty($email)) {
            $errors[] = "Email обязателен для заполнения";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Некорректный формат email";
        }

        $school_error = validateSchoolId($pdo, $school_id);
        if ($school_error !== null) {
            $errors[] = $school_error;
        }

        // Проверка уникальности логина
        $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ?");
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            $errors[] = "Этот логин уже используется";
        }

        // Проверка уникальности email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = "Этот email уже используется";
        }

        if (empty($errors)) {
            try {
                // Хешируем пароль
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Проверяем, что пароль хешируется корректно
                if (!$password_hash) {
                    throw new Exception("Ошибка при создании пароля");
                }

                $stmt = $pdo->prepare("INSERT INTO users (login, password_hash, full_name, email, phone, position, role_id, school_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$login, $password_hash, $full_name, $email, $phone, $position, $role_id, $school_id, $is_active]);

                $_SESSION['success_message'] = "Пользователь успешно создан! Логин: " . htmlspecialchars($login);
                header('Location: users.php');
                exit;
            } catch (PDOException $e) {
                $error = "Ошибка при создании пользователя: " . $e->getMessage();
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    // Обработка редактирования пользователя
    elseif ($action === 'edit' && $user_id > 0) {
        $login = trim($_POST['login']);
        $password = $_POST['password'];
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $position = trim($_POST['position']);
        $role_id = intval($_POST['role_id']);
        $school_id = getPostedSchoolId();
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Валидация
        $errors = [];

        if (empty($login)) {
            $errors[] = "Логин обязателен для заполнения";
        }

        if (empty($full_name)) {
            $errors[] = "ФИО обязательно для заполнения";
        }

        if (empty($email)) {
            $errors[] = "Email обязателен для заполнения";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Некорректный формат email";
        }

        $school_error = validateSchoolId($pdo, $school_id);
        if ($school_error !== null) {
            $errors[] = $school_error;
        }

        // Проверка уникальности логина
        $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ? AND id != ?");
        $stmt->execute([$login, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = "Этот логин уже используется другим пользователем";
        }

        // Проверка уникальности email
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $errors[] = "Этот email уже используется другим пользователем";
        }

        if (empty($errors)) {
            try {
                if (!empty($password)) {
                    // Если пароль указан, хешируем и обновляем
                    if (strlen($password) < 6) {
                        throw new Exception("Пароль должен содержать минимум 6 символов");
                    }

                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    if (!$password_hash) {
                        throw new Exception("Ошибка при создании пароля");
                    }

                    $stmt = $pdo->prepare("UPDATE users SET login = ?, password_hash = ?, full_name = ?, email = ?, phone = ?, position = ?, role_id = ?, school_id = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$login, $password_hash, $full_name, $email, $phone, $position, $role_id, $school_id, $is_active, $user_id]);
                } else {
                    // Если пароль не указан, не обновляем его
                    $stmt = $pdo->prepare("UPDATE users SET login = ?, full_name = ?, email = ?, phone = ?, position = ?, role_id = ?, school_id = ?, is_active = ? WHERE id = ?");
                    $stmt->execute([$login, $full_name, $email, $phone, $position, $role_id, $school_id, $is_active, $user_id]);
                }

                $_SESSION['success_message'] = "Пользователь успешно обновлен!";
                header('Location: users.php');
                exit;
            } catch (PDOException $e) {
                $error = "Ошибка при обновлении пользователя: " . $e->getMessage();
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Обработка удаления пользователя
if ($action === 'delete' && $user_id > 0) {
    try {
        // Не позволяем удалить самого себя
        if ($user_id == $_SESSION['user_id']) {
            $_SESSION['error_message'] = "Вы не можете удалить свой собственный аккаунт!";
            header('Location: users.php');
            exit;
        }

        // Проверяем, есть ли связанные данные
        $related_data = [];

        // Проверяем расписание
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM schedule WHERE teacher_id = ?");
        $stmt->execute([$user_id]);
        $schedule_count = $stmt->fetch()['count'];
        if ($schedule_count > 0) {
            $related_data[] = "расписание ($schedule_count записей)";
        }

        // Проверяем оценки
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM grades WHERE teacher_id = ? OR student_id = ?");
        $stmt->execute([$user_id, $user_id]);
        $grades_count = $stmt->fetch()['count'];
        if ($grades_count > 0) {
            $related_data[] = "оценки ($grades_count записей)";
        }

        // Проверяем домашние задания
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM homework WHERE teacher_id = ?");
        $stmt->execute([$user_id]);
        $homework_count = $stmt->fetch()['count'];
        if ($homework_count > 0) {
            $related_data[] = "домашние задания ($homework_count записей)";
        }

        // Если есть связанные данные, используем мягкое удаление
        if (!empty($related_data)) {
            // Мягкое удаление - деактивируем пользователя
            $stmt = $pdo->prepare("UPDATE users SET is_active = 0, login = CONCAT(login, '_deleted_', ?), email = CONCAT(email, '_deleted_', ?) WHERE id = ?");
            $deleted_suffix = time();
            $stmt->execute([$deleted_suffix, $deleted_suffix, $user_id]);

            $_SESSION['success_message'] = "Пользователь деактивирован! Нельзя было удалить полностью из-за связанных данных: " . implode(', ', $related_data);
        } else {
            // Если нет связанных данных, удаляем полностью
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);

            $_SESSION['success_message'] = "Пользователь успешно удален!";
        }

        header('Location: users.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Ошибка при удалении пользователя: " . $e->getMessage();
        header('Location: users.php');
        exit;
    }
}

// Получение данных пользователя для редактирования/просмотра
$user_data = null;
if (($action === 'edit' || $action === 'view') && $user_id > 0) {
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name, s.full_name as school_name 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        LEFT JOIN schools s ON u.school_id = s.id 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();

    if (!$user_data) {
        $_SESSION['error_message'] = "Пользователь не найден!";
        header('Location: users.php');
        exit;
    }
}

// Получение списка ролей для выпадающего списка
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll();

// Получение списка школ для выпадающего списка
$schools = $pdo->query("SELECT id, full_name FROM schools WHERE status = 'active' ORDER BY full_name")->fetchAll();

// Получение списка пользователей из БД
$sql = "
    SELECT u.*, r.name as role_name, s.full_name as school_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    LEFT JOIN schools s ON u.school_id = s.id 
    ORDER BY u.created_at DESC
";
$users = $pdo->query($sql)->fetchAll();

// Определяем school_id для использования в HTML части
$current_school_id = isset($_SESSION['school_id']) ? $_SESSION['school_id'] : null;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Пользователи системы - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .related-data-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            font-size: 0.9em;
        }

        .related-data-list {
            margin: 10px 0;
            padding-left: 20px;
        }

        .related-data-list li {
            margin: 5px 0;
        }

        .soft-delete-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            font-size: 0.9em;
        }
    </style>
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
                <span class="role-badge super-admin">Главный администратор</span>
            </div>
            <ul class="nav-menu">
                <li><a href="super_dashboard.php" class="nav-link">🏠 Главная</a></li>
                <li class="nav-section">Системное управление</li>
                <li><a href="schools.php" class="nav-link">🏫 Учебные заведения</a></li>
                <li><a href="users.php" class="nav-link active">👥 Пользователи системы</a></li>
                <li><a href="roles.php" class="nav-link">🔐 Роли и права</a></li>
                <li><a href="curriculum.php" class="nav-link">📚 Учебные планы</a></li>
                <li><a href="academic_periods.php" class="nav-link">📅 Учебные периоды</a></li>
                <li><a href="reports.php" class="nav-link">📈 Системные отчеты</a></li>
                <li class="nav-section">Общее</li>
                <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Пользователи системы</h1>
                <p>Управление администраторами школ и пользователями системы</p>
            </div>
            <div class="header-actions">
                <a href="users.php?action=add" class="btn btn-primary">👥 Добавить пользователя</a>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- Форма добавления/редактирования пользователя -->
                <div class="admin-form">
                    <h2><?php echo $action === 'add' ? 'Добавить пользователя' : 'Редактировать пользователя'; ?></h2>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="login">Логин *</label>
                                <input type="text" id="login" name="login"
                                       value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login']) : (isset($user_data['login']) ? htmlspecialchars($user_data['login']) : ''); ?>"
                                       required>
                                <small class="form-hint">Уникальный логин для входа в систему</small>
                            </div>
                            <div class="form-group">
                                <label for="password">Пароль <?php echo $action === 'add' ? '*' : ''; ?></label>
                                <input type="password" id="password" name="password"
                                    <?php echo $action === 'add' ? 'required' : ''; ?>
                                       placeholder="<?php echo $action === 'edit' ? 'Оставьте пустым, если не хотите менять' : ''; ?>">
                                <small class="form-hint">Минимум 6 символов</small>
                            </div>
                            <div class="form-group">
                                <label for="full_name">ФИО *</label>
                                <input type="text" id="full_name" name="full_name"
                                       value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : (isset($user_data['full_name']) ? htmlspecialchars($user_data['full_name']) : ''); ?>"
                                       required>
                            </div>
                            <div class="form-group">
                                <label for="email">Email *</label>
                                <input type="email" id="email" name="email"
                                       value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : (isset($user_data['email']) ? htmlspecialchars($user_data['email']) : ''); ?>"
                                       required>
                            </div>
                            <div class="form-group">
                                <label for="phone">Телефон</label>
                                <input type="tel" id="phone" name="phone"
                                       value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : (isset($user_data['phone']) ? htmlspecialchars($user_data['phone']) : ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="position">Должность</label>
                                <input type="text" id="position" name="position"
                                       value="<?php echo isset($_POST['position']) ? htmlspecialchars($_POST['position']) : (isset($user_data['position']) ? htmlspecialchars($user_data['position']) : ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="role_id">Роль *</label>
                                <select id="role_id" name="role_id" required>
                                    <option value="">Выберите роль</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>"
                                            <?php echo (isset($_POST['role_id']) && $_POST['role_id'] == $role['id']) || (isset($user_data['role_id']) && $user_data['role_id'] == $role['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($role['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="school_id">Школа</label>
                                <select id="school_id" name="school_id">
                                    <option value="">Без привязки к школе</option>
                                    <?php foreach ($schools as $school): ?>
                                        <option value="<?php echo $school['id']; ?>"
                                            <?php echo (isset($_POST['school_id']) && $_POST['school_id'] == $school['id']) || (isset($user_data['school_id']) && $user_data['school_id'] == $school['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($school['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-hint">Для администраторов школ обязательно</small>
                            </div>
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_active"
                                        <?php echo (!isset($_POST['is_active']) && $action === 'add') || (isset($_POST['is_active']) && $_POST['is_active']) || (isset($user_data['is_active']) && $user_data['is_active']) ? 'checked' : ''; ?>>
                                    <span class="checkmark"></span>
                                    Активный пользователь
                                </label>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? '➕ Добавить пользователя' : '💾 Сохранить изменения'; ?>
                            </button>
                            <a href="users.php" class="btn btn-secondary">❌ Отмена</a>
                        </div>
                    </form>
                </div>
            <?php elseif ($action === 'view' && $user_data): ?>
                <!-- Просмотр пользователя -->
                <div class="admin-form">
                    <h2>Просмотр пользователя</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Логин:</label>
                            <span><?php echo htmlspecialchars($user_data['login']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>ФИО:</label>
                            <span><?php echo htmlspecialchars($user_data['full_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Email:</label>
                            <span><?php echo htmlspecialchars($user_data['email']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Телефон:</label>
                            <span><?php echo htmlspecialchars($user_data['phone'] ?: '—'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Должность:</label>
                            <span><?php echo htmlspecialchars($user_data['position'] ?: '—'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Роль:</label>
                            <span class="role-badge <?php echo $user_data['role_name']; ?>">
                                <?php echo htmlspecialchars($user_data['role_name']); ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <label>Школа:</label>
                            <span><?php echo htmlspecialchars($user_data['school_name'] ?: '—'); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Статус:</label>
                            <span class="status-badge status-<?php echo $user_data['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $user_data['is_active'] ? 'Активен' : 'Неактивен'; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <label>Дата регистрации:</label>
                            <span><?php echo date('d.m.Y H:i', strtotime($user_data['created_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Последний вход:</label>
                            <span><?php echo $user_data['last_login'] ? date('d.m.Y H:i', strtotime($user_data['last_login'])) : '—'; ?></span>
                        </div>
                    </div>
                    <div class="form-actions">
                        <a href="users.php?action=edit&id=<?php echo $user_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                        <a href="users.php" class="btn btn-secondary">← Назад к списку</a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Список пользователей -->
                <div class="admin-table-container">
                    <h2>Все пользователи системы</h2>

                    <div class="admin-filters">
                        <div class="filter-group">
                            <label for="userSearch">Поиск пользователя</label>
                            <input type="search" id="userSearch" placeholder="ФИО, логин, email или школа..." autocomplete="off">
                        </div>
                    </div>

                    <div class="soft-delete-info">
                        <strong>💡 Информация:</strong> При удалении пользователей с связанными данными (расписание, оценки и т.д.)
                        используется "мягкое удаление" - пользователь деактивируется, а его логин и email изменяются.
                    </div>

                    <?php if (empty($users)): ?>
                        <div class="empty-state">
                            <p>Нет добавленных пользователей</p>
                            <a href="users.php?action=add" class="btn btn-primary">👥 Добавить первого пользователя</a>
                        </div>
                    <?php else: ?>
                        <table class="admin-table">
                            <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Логин</th>
                                <th>Email</th>
                                <th>Роль</th>
                                <th>Школа</th>
                                <th>Статус</th>
                                <th>Дата регистрации</th>
                                <th>Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                        <?php if ($user['position']): ?>
                                            <br><small><?php echo htmlspecialchars($user['position']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['login']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="role-badge <?php echo $user['role_name']; ?>">
                                            <?php echo htmlspecialchars($user['role_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['school_name'] ?: '—'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $user['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="users.php?action=view&id=<?php echo $user['id']; ?>" class="btn-action btn-view" title="Просмотр">👁️</a>
                                            <a href="users.php?action=edit&id=<?php echo $user['id']; ?>" class="btn-action btn-edit" title="Редактировать">✏️</a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <a href="users.php?action=delete&id=<?php echo $user['id']; ?>" class="btn-action btn-delete" title="Удалить" onclick="return confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')">🗑️</a>
                                            <?php else: ?>
                                                <span class="btn-action btn-disabled" title="Нельзя удалить себя">🚫</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    // Автоматическое скрытие уведомлений через 5 секунд
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });

        const userSearch = document.getElementById('userSearch');
        if (userSearch) {
            userSearch.addEventListener('input', function() {
                const query = this.value.trim().toLowerCase();
                document.querySelectorAll('.admin-table tbody tr').forEach(row => {
                    row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
                });
            });
        }

        // Подсказка для поля пароля при редактировании
        const passwordField = document.getElementById('password');
        if (passwordField && !passwordField.required) {
            passwordField.addEventListener('focus', function() {
                this.placeholder = 'Введите новый пароль (минимум 6 символов)';
            });
            passwordField.addEventListener('blur', function() {
                this.placeholder = 'Оставьте пустым, если не хотите менять';
            });
        }
    });

    // Функция подтверждения удаления
    function confirmDelete(userId, userName) {
        return confirm('Вы уверены, что хотите удалить пользователя "' + userName + '"?\n\n' +
            'Если у пользователя есть связанные данные (расписание, оценки и т.д.), ' +
            'то будет выполнено "мягкое удаление" - пользователь будет деактивирован.');
    }
</script>
</body>
</html>