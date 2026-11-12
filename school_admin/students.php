<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';

// Проверка прав - только school_admin
requireSchoolAdmin();

$pdo = getDatabaseConnection();

$schoolContext = getSchoolAdminContext($pdo);
$school_id = $schoolContext['school_id'];
$school = $schoolContext['school'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$student_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Получаем ID роли ученика (если есть)
$student_role_id = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'student' LIMIT 1");
    $stmt->execute();
    $role = $stmt->fetch();
    $student_role_id = $role['id'] ?? null;
} catch (PDOException $e) {
    // Игнорируем ошибку, будем работать без проверки роли
}

// Обработка добавления ученика
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $full_name = trim($_POST['full_name']);
        $login = trim($_POST['login']);
        $email = trim($_POST['email']);
        $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
        $class_id = !empty($_POST['class_id']) ? intval($_POST['class_id']) : null;
        $birth_date = !empty($_POST['birth_date']) ? trim($_POST['birth_date']) : null;
        $address = !empty($_POST['address']) ? trim($_POST['address']) : null;
        $password = $_POST['password'];
        $password_confirm = $_POST['password_confirm'];

        // Валидация
        $errors = [];

        if (empty($full_name)) {
            $errors[] = "Введите ФИО ученика";
        }

        if (empty($login)) {
            $errors[] = "Введите логин";
        }

        if (empty($email)) {
            $errors[] = "Введите email";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Введите корректный email";
        }

        if (empty($password)) {
            $errors[] = "Введите пароль";
        }

        if ($password !== $password_confirm) {
            $errors[] = "Пароли не совпадают";
        }

        if (strlen($password) < 6) {
            $errors[] = "Пароль должен содержать минимум 6 символов";
        }

        // Проверка уникальности логина и email
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM users WHERE login = ? OR email = ?");
                $stmt->execute([$login, $email]);
                if ($stmt->fetch()) {
                    $errors[] = "Пользователь с таким логином или email уже существует";
                }
            } catch (PDOException $e) {
                $errors[] = "Ошибка при проверке уникальности данных";
            }
        }

        if (empty($errors)) {
            try {
                // Создаем пользователя
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (school_id, role_id, class_id, full_name, login, email, phone, password_hash, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE)
                ");

                $stmt->execute([
                        $school_id,
                        $student_role_id,
                        $class_id,
                        $full_name,
                        $login,
                        $email,
                        $phone,
                        $password_hash
                ]);

                $user_id = $pdo->lastInsertId();

                // Добавляем дополнительную информацию об ученике
                if ($birth_date || $address) {
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO student_info (user_id, birth_date, address) 
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$user_id, $birth_date, $address]);
                    } catch (PDOException $e) {
                        // Игнорируем ошибки с дополнительной информацией
                    }
                }

                $_SESSION['success_message'] = "Ученик успешно добавлен!";
                header('Location: students.php');
                exit;
            } catch (PDOException $e) {
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    // Обработка редактирования ученика
    elseif ($action === 'edit' && $student_id > 0) {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
        $class_id = !empty($_POST['class_id']) ? intval($_POST['class_id']) : null;
        $birth_date = !empty($_POST['birth_date']) ? trim($_POST['birth_date']) : null;
        $address = !empty($_POST['address']) ? trim($_POST['address']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Валидация
        $errors = [];

        if (empty($full_name)) {
            $errors[] = "Введите ФИО ученика";
        }

        if (empty($email)) {
            $errors[] = "Введите email";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Введите корректный email";
        }

        if (empty($errors)) {
            try {
                // Обновляем данные пользователя
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, phone = ?, class_id = ?, is_active = ?
                    WHERE id = ? AND school_id = ?
                ");

                $stmt->execute([
                        $full_name,
                        $email,
                        $phone,
                        $class_id,
                        $is_active,
                        $student_id,
                        $school_id
                ]);

                // Обновляем дополнительную информацию
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO student_info (user_id, birth_date, address) 
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                        birth_date = VALUES(birth_date), 
                        address = VALUES(address)
                    ");
                    $stmt->execute([$student_id, $birth_date, $address]);
                } catch (PDOException $e) {
                    // Игнорируем ошибки с дополнительной информацией
                }

                $_SESSION['success_message'] = "Данные ученика успешно обновлены!";
                header('Location: students.php');
                exit;
            } catch (PDOException $e) {
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Обработка удаления ученика
if ($action === 'delete' && $student_id > 0) {
    try {
        $deactivated = deactivateSchoolUser($pdo, $student_id, (int)$school_id);

        if ($deactivated) {
            $_SESSION['success_message'] = "Ученик успешно удален!";
        } else {
            $_SESSION['error_message'] = "Ученик не найден или у вас нет прав для его удаления";
        }
        header('Location: students.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: students.php');
        exit;
    }
}

// Получение данных ученика для редактирования/просмотра
$student_data = null;
if (($action === 'edit' || $action === 'view') && $student_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.*, c.name as class_name,
                   si.birth_date, si.address
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            LEFT JOIN student_info si ON u.id = si.user_id
            WHERE u.id = ? AND u.school_id = ?
        ");
        $stmt->execute([$student_id, $school_id]);
        $student_data = $stmt->fetch();

        if (!$student_data) {
            $_SESSION['error_message'] = "Ученик не найден!";
            header('Location: students.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: students.php');
        exit;
    }
}

// Получение списка учеников школы
$students = [];
try {
    // Если роль найдена, используем фильтр по роли, иначе показываем всех пользователей школы
    if ($student_role_id) {
        $stmt = $pdo->prepare("
            SELECT u.*, c.name as class_name
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE u.school_id = ? AND u.role_id = ?
            ORDER BY c.name, u.full_name
        ");
        $stmt->execute([$school_id, $student_role_id]);
    } else {
        // Если роль не найдена, показываем всех пользователей школы
        $stmt = $pdo->prepare("
            SELECT u.*, c.name as class_name
            FROM users u
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE u.school_id = ?
            ORDER BY c.name, u.full_name
        ");
        $stmt->execute([$school_id]);
    }
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    $students = [];
    error_log("Ошибка при получении списка учеников: " . $e->getMessage());
}

// Получение списка классов для выбора
$classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, name, grade_level 
        FROM classes 
        WHERE school_id = ?
        ORDER BY grade_level, name
    ");
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll();
} catch (PDOException $e) {
    $classes = [];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ученики - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .data-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e0e0e0;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .table-actions {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            background: none;
            border: none;
            padding: 8px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-view:hover {
            background: #e8f5e8;
        }

        .btn-edit:hover {
            background: #fff3cd;
        }

        .btn-delete:hover {
            background: #ffeaea;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #dee2e6;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #2c3e50;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #3498db;
            outline: none;
        }

        .form-hint {
            display: block;
            margin-top: 5px;
            color: #7f8c8d;
            font-size: 0.85em;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-item label {
            font-weight: bold;
            color: #2c3e50;
        }

        .info-item span {
            color: #5a6c7d;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php
    $schoolAdminActivePage = 'students';
    require __DIR__ . '/../includes/school_admin_sidebar.php';
    ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Управление учениками</h1>
                <p><?php echo htmlspecialchars($school['full_name']); ?></p>
            </div>
            <div class="header-actions">
                <a href="students.php?action=add" class="btn btn-primary">➕ Добавить ученика</a>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo consumeFlashMessage('success_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?php echo consumeFlashMessage('error_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo nl2br(htmlspecialchars(str_replace('<br>', "\n", $error), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- Форма добавления/редактирования ученика -->
                <div class="admin-form">
                    <h2><?php echo $action === 'add' ? 'Добавить ученика' : 'Редактировать данные ученика'; ?></h2>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$student_id; ?>">
                        <div class="form-section">
                            <h3>Основная информация</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="full_name">ФИО *</label>
                                    <input type="text" id="full_name" name="full_name"
                                           value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : (isset($student_data['full_name']) ? htmlspecialchars($student_data['full_name']) : ''); ?>"
                                           placeholder="Иванов Иван Иванович" required>
                                </div>
                                <?php if ($action === 'add'): ?>
                                    <div class="form-group">
                                        <label for="login">Логин *</label>
                                        <input type="text" id="login" name="login"
                                               value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login']) : ''; ?>"
                                               placeholder="student123" required>
                                    </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label for="email">Email *</label>
                                    <input type="email" id="email" name="email"
                                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : (isset($student_data['email']) ? htmlspecialchars($student_data['email']) : ''); ?>"
                                           placeholder="student@school.ru" required>
                                </div>
                                <div class="form-group">
                                    <label for="phone">Телефон</label>
                                    <input type="tel" id="phone" name="phone"
                                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : (isset($student_data['phone']) ? htmlspecialchars($student_data['phone']) : ''); ?>"
                                           placeholder="+7 (999) 123-45-67">
                                </div>
                                <div class="form-group">
                                    <label for="class_id">Класс</label>
                                    <select id="class_id" name="class_id">
                                        <option value="">Не назначен</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>"
                                                    <?php echo (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) || (isset($student_data['class_id']) && $student_data['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['name'] . ' (' . $class['grade_level'] . ' класс)'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php if ($action === 'add'): ?>
                                    <div class="form-group">
                                        <label for="password">Пароль *</label>
                                        <input type="password" id="password" name="password" required>
                                        <small class="form-hint">Минимум 6 символов</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="password_confirm">Подтверждение пароля *</label>
                                        <input type="password" id="password_confirm" name="password_confirm" required>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>Дополнительная информация</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="birth_date">Дата рождения</label>
                                    <input type="date" id="birth_date" name="birth_date"
                                           value="<?php echo isset($_POST['birth_date']) ? htmlspecialchars($_POST['birth_date']) : (isset($student_data['birth_date']) ? htmlspecialchars($student_data['birth_date']) : ''); ?>">
                                </div>
                                <div class="form-group full-width">
                                    <label for="address">Адрес</label>
                                    <textarea id="address" name="address" rows="3" placeholder="Адрес проживания..."><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : (isset($student_data['address']) ? htmlspecialchars($student_data['address']) : ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <?php if ($action === 'edit'): ?>
                            <div class="form-section">
                                <h3>Настройки аккаунта</h3>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" name="is_active"
                                                    <?php echo (!isset($_POST['is_active']) && $action === 'edit') || (isset($_POST['is_active']) && $_POST['is_active']) || (isset($student_data['is_active']) && $student_data['is_active']) ? 'checked' : ''; ?>>
                                            <span class="checkmark"></span>
                                            Активный аккаунт
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? '➕ Добавить ученика' : '💾 Сохранить изменения'; ?>
                            </button>
                            <a href="students.php" class="btn btn-secondary">❌ Отмена</a>
                        </div>
                    </form>
                </div>

            <?php elseif ($action === 'view' && $student_data): ?>
                <!-- Просмотр ученика -->
                <div class="admin-form">
                    <h2>Просмотр данных ученика</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>ФИО:</label>
                            <span><?php echo htmlspecialchars($student_data['full_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Логин:</label>
                            <span><?php echo htmlspecialchars($student_data['login']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Email:</label>
                            <span><?php echo htmlspecialchars($student_data['email']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Телефон:</label>
                            <span><?php echo !empty($student_data['phone']) ? htmlspecialchars($student_data['phone']) : '—'; ?></span>
                        </div>
                        <div class="info-item">
                            <label>Класс:</label>
                            <span><?php echo !empty($student_data['class_name']) ? htmlspecialchars($student_data['class_name']) : '—'; ?></span>
                        </div>
                        <?php if (!empty($student_data['birth_date'])): ?>
                            <div class="info-item">
                                <label>Дата рождения:</label>
                                <span><?php echo date('d.m.Y', strtotime($student_data['birth_date'])); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($student_data['address'])): ?>
                            <div class="info-item full-width">
                                <label>Адрес:</label>
                                <span><?php echo htmlspecialchars($student_data['address']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <label>Статус:</label>
                            <span class="status-badge status-<?php echo $student_data['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $student_data['is_active'] ? 'Активен' : 'Неактивен'; ?>
                            </span>
                        </div>
                        <?php if (isset($student_data['created_at'])): ?>
                            <div class="info-item">
                                <label>Дата регистрации:</label>
                                <span><?php echo date('d.m.Y H:i', strtotime($student_data['created_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-actions">
                        <a href="students.php?action=edit&id=<?php echo $student_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                        <a href="students.php" class="btn btn-secondary">← Назад к ученикам</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список учеников -->
                <div class="students-container">
                    <h2>Список учеников</h2>

                    <?php if (empty($students)): ?>
                        <div class="empty-state">
                            <p>Ученики не добавлены</p>
                            <a href="students.php?action=add" class="btn btn-primary">➕ Добавить первого ученика</a>
                        </div>
                    <?php else: ?>
                        <div class="admin-filters">
                            <div class="filter-group">
                                <label for="studentTableSearch">Поиск ученика</label>
                                <input type="search" id="studentTableSearch" placeholder="ФИО, логин, email или класс..." autocomplete="off">
                            </div>
                        </div>
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Логин</th>
                                <th>Email</th>
                                <th>Класс</th>
                                <th>Телефон</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['login']); ?></td>
                                    <td><?php echo htmlspecialchars($student['email']); ?></td>
                                    <td><?php echo !empty($student['class_name']) ? htmlspecialchars($student['class_name']) : '—'; ?></td>
                                    <td><?php echo !empty($student['phone']) ? htmlspecialchars($student['phone']) : '—'; ?></td>
                                    <td>
                                            <span class="status-badge status-<?php echo $student['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $student['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                            </span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="students.php?action=view&id=<?php echo $student['id']; ?>" class="btn-action btn-view" title="Просмотр">👁️</a>
                                            <a href="students.php?action=edit&id=<?php echo $student['id']; ?>" class="btn-action btn-edit" title="Редактировать">✏️</a>
                                            <?php echo csrf_post_button('delete', (int)$student['id'], '🗑️', 'btn-action btn-delete', 'Вы уверены, что хотите удалить этого ученика?'); ?>
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
    const studentTableSearch = document.getElementById('studentTableSearch');
    if (studentTableSearch) {
        studentTableSearch.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            document.querySelectorAll('.students-container .data-table tbody tr').forEach(row => {
                row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
            });
        });
    }
</script>
</body>
</html>