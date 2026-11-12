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

function validateParentStudentIds(PDO $pdo, $submittedIds, int $schoolId): array|false
{
    if (!is_array($submittedIds)) {
        return false;
    }

    $studentIds = [];
    foreach ($submittedIds as $submittedId) {
        $studentId = filter_var($submittedId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        if ($studentId === false) {
            return false;
        }
        $studentIds[] = $studentId;
    }

    $studentIds = array_values(array_unique($studentIds));
    if (!$studentIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.school_id = ? AND u.is_active = TRUE AND r.name = 'student' AND u.id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$schoolId], $studentIds));

    return (int)$stmt->fetchColumn() === count($studentIds) ? $studentIds : false;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$parent_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Получаем ID роли родителя
$parent_role_id = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'parent' LIMIT 1");
    $stmt->execute();
    $role = $stmt->fetch();
    $parent_role_id = $role['id'] ?? null;
} catch (PDOException $e) {
    error_log("Ошибка при получении роли родителя: " . $e->getMessage());
}

// Получаем ID роли ученика
$student_role_id = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'student' LIMIT 1");
    $stmt->execute();
    $role = $stmt->fetch();
    $student_role_id = $role['id'] ?? null;
} catch (PDOException $e) {
    error_log("Ошибка при получении роли ученика: " . $e->getMessage());
}

// Обработка добавления родителя
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $full_name = trim($_POST['full_name']);
        $login = trim($_POST['login']);
        $email = trim($_POST['email']);
        $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
        $password = $_POST['password'];
        $password_confirm = $_POST['password_confirm'];
        $student_ids = validateParentStudentIds($pdo, $_POST['student_ids'] ?? [], (int)$school_id);

        // Валидация
        $errors = [];

        if (empty($full_name)) {
            $errors[] = "Введите ФИО родителя";
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

        if ($student_ids === false) {
            $errors[] = "Можно привязывать только активных учеников этой школы.";
            $student_ids = [];
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
                // Начинаем транзакцию
                $pdo->beginTransaction();

                // Создаем пользователя
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (school_id, role_id, full_name, login, email, phone, password_hash, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)
                ");

                $stmt->execute([
                    $school_id,
                    $parent_role_id,
                    $full_name,
                    $login,
                    $email,
                    $phone,
                    $password_hash
                ]);

                $user_id = $pdo->lastInsertId();

                // Добавляем связи с учениками
                foreach ($student_ids as $student_id) {
                    $student_id = intval($student_id);
                    if ($student_id > 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO student_parent_links (parent_id, student_id) 
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$user_id, $student_id]);
                    }
                }

                $pdo->commit();

                $_SESSION['success_message'] = "Родитель успешно добавлен!";
                header('Location: parents.php');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    // Обработка редактирования родителя
    elseif ($action === 'edit' && $parent_id > 0) {
        // Защита от межшкольного изменения: редактируемый пользователь должен быть родителем этой школы
        $parent_owner_stmt = $pdo->prepare("
            SELECT 1 FROM users u JOIN roles r ON r.id = u.role_id
            WHERE u.id = ? AND u.school_id = ? AND r.name = 'parent'
        ");
        $parent_owner_stmt->execute([$parent_id, $school_id]);
        if (!$parent_owner_stmt->fetchColumn()) {
            $_SESSION['error_message'] = 'Родитель не найден в вашей школе.';
            header('Location: parents.php');
            exit;
        }
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
        $student_ids = validateParentStudentIds($pdo, $_POST['student_ids'] ?? [], (int)$school_id);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Валидация
        $errors = [];

        if (empty($full_name)) {
            $errors[] = "Введите ФИО родителя";
        }

        if (empty($email)) {
            $errors[] = "Введите email";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Введите корректный email";
        }

        if ($student_ids === false) {
            $errors[] = "Можно привязывать только активных учеников этой школы.";
            $student_ids = [];
        }

        if (empty($errors)) {
            try {
                // Начинаем транзакцию
                $pdo->beginTransaction();

                // Обновляем данные пользователя
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET full_name = ?, email = ?, phone = ?, is_active = ?
                    WHERE id = ? AND school_id = ?
                ");

                $stmt->execute([
                    $full_name,
                    $email,
                    $phone,
                    $is_active,
                    $parent_id,
                    $school_id
                ]);

                // Обновляем связи с учениками
                // Сначала удаляем старые связи
                $stmt = $pdo->prepare("DELETE FROM student_parent_links WHERE parent_id = ?");
                $stmt->execute([$parent_id]);

                // Добавляем новые связи
                foreach ($student_ids as $student_id) {
                    $student_id = intval($student_id);
                    if ($student_id > 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO student_parent_links (parent_id, student_id) 
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$parent_id, $student_id]);
                    }
                }

                $pdo->commit();

                $_SESSION['success_message'] = "Данные родителя успешно обновлены!";
                header('Location: parents.php');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Обработка удаления родителя
if ($action === 'delete' && $parent_id > 0) {
    try {
        $deactivated = deactivateSchoolUser($pdo, $parent_id, (int)$school_id);

        if ($deactivated) {
            $_SESSION['success_message'] = "Родитель успешно удален!";
        } else {
            $_SESSION['error_message'] = "Родитель не найден или у вас нет прав для его удаления";
        }
        header('Location: parents.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: parents.php');
        exit;
    }
}

// Получение данных родителя для редактирования/просмотра
$parent_data = null;
$parent_students = [];
if (($action === 'edit' || $action === 'view') && $parent_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.*
            FROM users u
            WHERE u.id = ? AND u.school_id = ?
        ");
        $stmt->execute([$parent_id, $school_id]);
        $parent_data = $stmt->fetch();

        if (!$parent_data) {
            $_SESSION['error_message'] = "Родитель не найден!";
            header('Location: parents.php');
            exit;
        }

        // Получаем привязанных учеников
        $stmt = $pdo->prepare("
            SELECT spl.student_id, u.full_name, c.name as class_name
            FROM student_parent_links spl
            JOIN users u ON spl.student_id = u.id
            LEFT JOIN classes c ON u.class_id = c.id
            WHERE spl.parent_id = ?
        ");
        $stmt->execute([$parent_id]);
        $parent_students = $stmt->fetchAll();

    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: parents.php');
        exit;
    }
}

// Получение списка родителей школы
$parents = [];
try {
    // Если роль найдена, используем фильтр по роли, иначе показываем всех пользователей школы
    if ($parent_role_id) {
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   COUNT(spl.student_id) as student_count
            FROM users u
            LEFT JOIN student_parent_links spl ON u.id = spl.parent_id
            WHERE u.school_id = ? AND u.role_id = ?
            GROUP BY u.id
            ORDER BY u.full_name
        ");
        $stmt->execute([$school_id, $parent_role_id]);
    } else {
        // Если роль не найдена, показываем всех пользователей школы
        $stmt = $pdo->prepare("
            SELECT u.*, 
                   COUNT(spl.student_id) as student_count
            FROM users u
            LEFT JOIN student_parent_links spl ON u.id = spl.parent_id
            WHERE u.school_id = ?
            GROUP BY u.id
            ORDER BY u.full_name
        ");
        $stmt->execute([$school_id]);
    }
    $parents = $stmt->fetchAll();
} catch (PDOException $e) {
    $parents = [];
    error_log("Ошибка при получении списка родителей: " . $e->getMessage());
}

// Получение списка учеников для привязки
$students = [];
try {
    // Сначала проверяем есть ли столбец class_id в таблице users
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, 
               COALESCE(c.name, 'Не назначен') as class_name
        FROM users u
        JOIN roles r ON r.id = u.role_id AND r.name = 'student'
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE u.school_id = ? AND u.is_active = TRUE
        ORDER BY c.name, u.full_name
    ");
    $stmt->execute([$school_id]);
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    // Если ошибка из-за отсутствия class_id, делаем простой запрос
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, 'Не назначен' as class_name
            FROM users u
            JOIN roles r ON r.id = u.role_id AND r.name = 'student'
            WHERE u.school_id = ? AND u.is_active = TRUE
            ORDER BY u.full_name
        ");
        $stmt->execute([$school_id]);
        $students = $stmt->fetchAll();
    } catch (PDOException $e2) {
        $students = [];
        error_log("Ошибка при получении списка учеников: " . $e2->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Родители - Знание Севера</title>
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

        .students-list {
            margin-top: 10px;
        }

        .student-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 8px;
            border-left: 4px solid #3498db;
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-weight: 500;
            color: #2c3e50;
        }

        .student-class {
            font-size: 0.85em;
            color: #7f8c8d;
            margin-top: 2px;
        }

        .students-selection {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px;
            max-height: 300px;
            overflow-y: auto;
            overflow-x: hidden;
            min-width: 0;
            width: 100%;
        }

        .search-box {
            margin-bottom: 15px;
            position: relative;
        }

        .search-box input {
            width: 100%;
            padding: 10px 40px 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }

        .search-box:after {
            content: '🔍';
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
        }

        .students-checkbox-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }

        .student-checkbox-item {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            width: 100%;
            padding: 10px;
            border: 1px solid #f0f0f0;
            border-radius: 6px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .student-checkbox-item:hover {
            background: #f8f9fa;
            border-color: #3498db;
        }

        .student-checkbox-item.selected {
            background: #e3f2fd;
            border-color: #3498db;
        }

        .student-checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            margin: 0;
            padding: 0;
            transform: scale(1.2);
        }

        .student-checkbox-info {
            flex: 1;
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .student-checkbox-name {
            font-weight: 500;
            color: #2c3e50;
        }

        .student-checkbox-class {
            font-size: 0.85em;
            color: #7f8c8d;
            margin-top: 2px;
        }

        .no-students {
            text-align: center;
            padding: 20px;
            color: #7f8c8d;
            font-style: italic;
        }

        .selection-stats {
            margin-top: 10px;
            padding: 10px;
            background: #e3f2fd;
            border-radius: 6px;
            font-size: 0.9em;
            color: #1976d2;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php
    $schoolAdminActivePage = 'parents';
    require __DIR__ . '/../includes/school_admin_sidebar.php';
    ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Управление родителями</h1>
                <p><?php echo htmlspecialchars($school['full_name']); ?></p>
            </div>
            <div class="header-actions">
                <a href="parents.php?action=add" class="btn btn-primary">➕ Добавить родителя</a>
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
                <!-- Форма добавления/редактирования родителя -->
                <div class="admin-form">
                    <h2><?php echo $action === 'add' ? 'Добавить родителя' : 'Редактировать данные родителя'; ?></h2>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$parent_id; ?>">
                        <div class="form-section">
                            <h3>Основная информация</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="full_name">ФИО *</label>
                                    <input type="text" id="full_name" name="full_name"
                                           value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : (isset($parent_data['full_name']) ? htmlspecialchars($parent_data['full_name']) : ''); ?>"
                                           placeholder="Иванова Мария Петровна" required>
                                </div>
                                <?php if ($action === 'add'): ?>
                                    <div class="form-group">
                                        <label for="login">Логин *</label>
                                        <input type="text" id="login" name="login"
                                               value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login']) : ''; ?>"
                                               placeholder="parent123" required>
                                    </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label for="email">Email *</label>
                                    <input type="email" id="email" name="email"
                                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : (isset($parent_data['email']) ? htmlspecialchars($parent_data['email']) : ''); ?>"
                                           placeholder="parent@school.ru" required>
                                </div>
                                <div class="form-group">
                                    <label for="phone">Телефон</label>
                                    <input type="tel" id="phone" name="phone"
                                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : (isset($parent_data['phone']) ? htmlspecialchars($parent_data['phone']) : ''); ?>"
                                           placeholder="+7 (999) 123-45-67">
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
                            <h3>Привязанные ученики</h3>
                            <div class="form-group">
                                <label>Выберите учеников:</label>

                                <?php if (empty($students)): ?>
                                    <div class="no-students">
                                        Нет доступных учеников для привязки
                                    </div>
                                <?php else: ?>
                                    <div class="students-selection">
                                        <div class="search-box">
                                            <input type="text" id="studentSearch" placeholder="Поиск ученика по имени или классу...">
                                        </div>

                                        <div class="students-checkbox-list" id="studentsList">
                                            <?php foreach ($students as $student): ?>
                                                <div class="student-checkbox-item" data-name="<?php echo htmlspecialchars(strtolower($student['full_name'] . ' ' . $student['class_name'])); ?>">
                                                    <input type="checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>"
                                                           id="student_<?php echo $student['id']; ?>"
                                                        <?php
                                                        if ($action === 'edit') {
                                                            foreach ($parent_students as $ps) {
                                                                if ($ps['student_id'] == $student['id']) {
                                                                    echo 'checked';
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                        echo (isset($_POST['student_ids']) && in_array($student['id'], $_POST['student_ids'])) ? 'checked' : '';
                                                        ?>>
                                                    <div class="student-checkbox-info">
                                                        <div class="student-checkbox-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                                        <div class="student-checkbox-class"><?php echo !empty($student['class_name']) ? htmlspecialchars($student['class_name']) : '—'; ?></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="selection-stats">
                                            Выбрано: <span id="selectedCount">0</span> учеников
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <small class="form-hint">Выберите учеников, за которыми родитель будет следить</small>
                            </div>
                        </div>

                        <?php if ($action === 'edit'): ?>
                            <div class="form-section">
                                <h3>Настройки аккаунта</h3>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="checkbox-label">
                                            <input type="checkbox" name="is_active"
                                                <?php echo (!isset($_POST['is_active']) && $action === 'edit') || (isset($_POST['is_active']) && $_POST['is_active']) || (isset($parent_data['is_active']) && $parent_data['is_active']) ? 'checked' : ''; ?>>
                                            <span class="checkmark"></span>
                                            Активный аккаунт
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? '➕ Добавить родителя' : '💾 Сохранить изменения'; ?>
                            </button>
                            <a href="parents.php" class="btn btn-secondary">❌ Отмена</a>
                        </div>
                    </form>
                </div>

            <?php elseif ($action === 'view' && $parent_data): ?>
                <!-- Просмотр родителя -->
                <div class="admin-form">
                    <h2>Просмотр данных родителя</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>ФИО:</label>
                            <span><?php echo htmlspecialchars($parent_data['full_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Логин:</label>
                            <span><?php echo htmlspecialchars($parent_data['login']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Email:</label>
                            <span><?php echo htmlspecialchars($parent_data['email']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Телефон:</label>
                            <span><?php echo !empty($parent_data['phone']) ? htmlspecialchars($parent_data['phone']) : '—'; ?></span>
                        </div>
                        <div class="info-item">
                            <label>Статус:</label>
                            <span class="status-badge status-<?php echo $parent_data['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $parent_data['is_active'] ? 'Активен' : 'Неактивен'; ?>
                            </span>
                        </div>
                        <?php if (isset($parent_data['created_at'])): ?>
                            <div class="info-item">
                                <label>Дата регистрации:</label>
                                <span><?php echo date('d.m.Y H:i', strtotime($parent_data['created_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-section">
                        <h3>Привязанные ученики</h3>
                        <?php if (!empty($parent_students)): ?>
                            <div class="students-list">
                                <?php foreach ($parent_students as $student): ?>
                                    <div class="student-item">
                                        <div class="student-info">
                                            <div class="student-name"><?php echo htmlspecialchars($student['full_name']); ?></div>
                                            <div class="student-class"><?php echo !empty($student['class_name']) ? htmlspecialchars($student['class_name']) : '—'; ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p>Нет привязанных учеников</p>
                        <?php endif; ?>
                    </div>

                    <div class="form-actions">
                        <a href="parents.php?action=edit&id=<?php echo $parent_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                        <a href="parents.php" class="btn btn-secondary">← Назад к родителям</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список родителей -->
                <div class="parents-container">
                    <h2>Список родителей</h2>

                    <?php if (empty($parents)): ?>
                        <div class="empty-state">
                            <p>Родители не добавлены</p>
                            <a href="parents.php?action=add" class="btn btn-primary">➕ Добавить первого родителя</a>
                        </div>
                    <?php else: ?>
                        <div class="admin-filters">
                            <div class="filter-group">
                                <label for="parentTableSearch">Поиск родителя</label>
                                <input type="search" id="parentTableSearch" placeholder="ФИО, логин или email..." autocomplete="off">
                            </div>
                        </div>
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Логин</th>
                                <th>Email</th>
                                <th>Телефон</th>
                                <th>Учеников</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($parents as $parent): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($parent['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($parent['login']); ?></td>
                                    <td><?php echo htmlspecialchars($parent['email']); ?></td>
                                    <td><?php echo !empty($parent['phone']) ? htmlspecialchars($parent['phone']) : '—'; ?></td>
                                    <td><?php echo $parent['student_count']; ?></td>
                                    <td>
                                            <span class="status-badge status-<?php echo $parent['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $parent['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                            </span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="parents.php?action=view&id=<?php echo $parent['id']; ?>" class="btn-action btn-view" title="Просмотр">👁️</a>
                                            <a href="parents.php?action=edit&id=<?php echo $parent['id']; ?>" class="btn-action btn-edit" title="Редактировать">✏️</a>
                                            <?php echo csrf_post_button('delete', (int)$parent['id'], '🗑️', 'btn-action btn-delete', 'Вы уверены, что хотите удалить этого родителя?'); ?>
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
    // Поиск учеников
    const studentSearch = document.getElementById('studentSearch');
    if (studentSearch) {
        studentSearch.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const studentItems = document.querySelectorAll('.student-checkbox-item');

            studentItems.forEach(item => {
                const studentName = item.getAttribute('data-name');
                item.style.display = studentName.includes(searchTerm) ? 'flex' : 'none';
            });
        });
    }

    const parentTableSearch = document.getElementById('parentTableSearch');
    if (parentTableSearch) {
        parentTableSearch.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            document.querySelectorAll('.parents-container .data-table tbody tr').forEach(row => {
                row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
            });
        });
    }

    // Подсчет выбранных учеников
    function updateSelectedCount() {
        const selectedCount = document.querySelectorAll('input[name="student_ids[]"]:checked').length;
        const selectedCountElement = document.getElementById('selectedCount');
        if (selectedCountElement) {
            selectedCountElement.textContent = selectedCount;
        }
    }

    // Обработка клика по чекбоксам
    document.addEventListener('change', function(e) {
        if (e.target.name === 'student_ids[]') {
            const item = e.target.closest('.student-checkbox-item');
            if (e.target.checked) {
                item.classList.add('selected');
            } else {
                item.classList.remove('selected');
            }
            updateSelectedCount();
        }
    });

    // Инициализация подсчета при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        updateSelectedCount();

        // Добавляем класс selected к уже выбранным элементам
        document.querySelectorAll('input[name="student_ids[]"]:checked').forEach(checkbox => {
            checkbox.closest('.student-checkbox-item').classList.add('selected');
        });
    });
</script>
</body>
</html>