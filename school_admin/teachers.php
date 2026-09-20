<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

// Проверка прав - только school_admin
requireSchoolAdmin();

$pdo = getDatabaseConnection();

// Получаем school_id из сессии с проверкой
$school_id = $_SESSION['user_school_id'] ?? null;
if (!$school_id) {
    $_SESSION['error_message'] = "Школа не определена. Обратитесь к администратору.";
    header('Location: dashboard.php');
    exit;
}

// Получаем информацию о школе
$school_stmt = $pdo->prepare("SELECT full_name, short_name FROM schools WHERE id = ?");
$school_stmt->execute([$school_id]);
$school = $school_stmt->fetch();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$teacher_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Создаем таблицу teachers если её нет
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS teachers (
            id INT PRIMARY KEY AUTO_INCREMENT,
            school_id INT NOT NULL,
            user_id INT NOT NULL,
            subjects TEXT,
            qualification VARCHAR(255),
            experience_years INT,
            education TEXT,
            specialization VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_teacher_school (user_id, school_id)
        )
    ");

    // Восстанавливаем профиль учителя для пользователей, созданных супер-администратором.
    $sync_stmt = $pdo->prepare("\n        INSERT INTO teachers (school_id, user_id)\n        SELECT u.school_id, u.id\n        FROM users u\n        JOIN roles r ON r.id = u.role_id\n        WHERE u.school_id = ?\n          AND u.is_active = 1\n          AND r.name IN ('teacher', 'class_teacher')\n          AND NOT EXISTS (\n              SELECT 1 FROM teachers t WHERE t.school_id = u.school_id AND t.user_id = u.id\n          )\n    ");
    $sync_stmt->execute([$school_id]);
} catch (PDOException $e) {
    error_log("Ошибка при создании таблицы teachers: " . $e->getMessage());
}

// Получаем ID роли учителя
$teacher_role_id = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'teacher' LIMIT 1");
    $stmt->execute();
    $role = $stmt->fetch();
    $teacher_role_id = $role['id'] ?? null;
} catch (PDOException $e) {
    error_log("Ошибка при получении роли учителя: " . $e->getMessage());
}

// Обработка добавления учителя
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $full_name = trim($_POST['full_name']);
        $login = trim($_POST['login']);
        $email = trim($_POST['email']);
        $phone = !empty($_POST['phone']) ? trim($_POST['phone']) : null;
        $password = $_POST['password'];
        $password_confirm = $_POST['password_confirm'];

        $subjects = !empty($_POST['subjects']) ? trim($_POST['subjects']) : null;
        $qualification = !empty($_POST['qualification']) ? trim($_POST['qualification']) : null;
        $experience_years = !empty($_POST['experience_years']) ? intval($_POST['experience_years']) : null;
        $education = !empty($_POST['education']) ? trim($_POST['education']) : null;
        $specialization = !empty($_POST['specialization']) ? trim($_POST['specialization']) : null;

        // Валидация
        $errors = [];

        if (empty($full_name)) {
            $errors[] = "Введите ФИО учителя";
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
                        $teacher_role_id,
                        $full_name,
                        $login,
                        $email,
                        $phone,
                        $password_hash
                ]);

                $user_id = $pdo->lastInsertId();

                // Добавляем запись в таблицу teachers
                $stmt = $pdo->prepare("
                    INSERT INTO teachers (school_id, user_id, subjects, qualification, experience_years, education, specialization) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                        $school_id,
                        $user_id,
                        $subjects,
                        $qualification,
                        $experience_years,
                        $education,
                        $specialization
                ]);

                $pdo->commit();

                $_SESSION['success_message'] = "Учитель успешно добавлен!";
                header('Location: teachers.php');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Ошибка при добавлении учителя: " . $e->getMessage();
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    // Обработка редактирования учителя
    elseif ($action === 'edit' && $teacher_id > 0) {
        $subjects = !empty($_POST['subjects']) ? trim($_POST['subjects']) : null;
        $qualification = !empty($_POST['qualification']) ? trim($_POST['qualification']) : null;
        $experience_years = !empty($_POST['experience_years']) ? intval($_POST['experience_years']) : null;
        $education = !empty($_POST['education']) ? trim($_POST['education']) : null;
        $specialization = !empty($_POST['specialization']) ? trim($_POST['specialization']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        try {
            // Обновляем данные в таблице teachers
            $stmt = $pdo->prepare("
                UPDATE teachers 
                SET subjects = ?, qualification = ?, experience_years = ?, education = ?, specialization = ?
                WHERE id = ? AND school_id = ?
            ");

            $stmt->execute([
                    $subjects,
                    $qualification,
                    $experience_years,
                    $education,
                    $specialization,
                    $teacher_id,
                    $school_id
            ]);

            // Обновляем статус пользователя
            if ($teacher_data && isset($teacher_data['user_id'])) {
                $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ? AND school_id = ?");
                $stmt->execute([$is_active, $teacher_data['user_id'], $school_id]);
            }

            $_SESSION['success_message'] = "Данные учителя успешно обновлены!";
            header('Location: teachers.php');
            exit;
        } catch (PDOException $e) {
            $error = "Ошибка при обновлении данных учителя: " . $e->getMessage();
        }
    }
}

// Обработка удаления учителя
if ($action === 'delete' && $teacher_id > 0) {
    try {
        // Получаем user_id перед удалением
        $stmt = $pdo->prepare("SELECT user_id FROM teachers WHERE id = ? AND school_id = ?");
        $stmt->execute([$teacher_id, $school_id]);
        $teacher = $stmt->fetch();

        if ($teacher) {
            // Удаляем запись из teachers
            $stmt = $pdo->prepare("DELETE FROM teachers WHERE id = ? AND school_id = ?");
            $stmt->execute([$teacher_id, $school_id]);

            // Деактивируем пользователя (не удаляем полностью)
            $stmt = $pdo->prepare("UPDATE users SET is_active = FALSE WHERE id = ? AND school_id = ?");
            $stmt->execute([$teacher['user_id'], $school_id]);

            $_SESSION['success_message'] = "Учитель успешно удален!";
        } else {
            $_SESSION['error_message'] = "Учитель не найден или у вас нет прав для его удаления";
        }
        header('Location: teachers.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Ошибка при удалении учителя: " . $e->getMessage();
        header('Location: teachers.php');
        exit;
    }
}

// Получение данных учителя для редактирования/просмотра
$teacher_data = null;
if (($action === 'edit' || $action === 'view') && $teacher_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*, u.full_name, u.email, u.phone, u.login, u.is_active
            FROM teachers t
            JOIN users u ON t.user_id = u.id
            WHERE t.id = ? AND t.school_id = ?
        ");
        $stmt->execute([$teacher_id, $school_id]);
        $teacher_data = $stmt->fetch();

        if (!$teacher_data) {
            $_SESSION['error_message'] = "Учитель не найден!";
            header('Location: teachers.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Ошибка при получении данных учителя: " . $e->getMessage();
        header('Location: teachers.php');
        exit;
    }
}

// Получение списка учителей школы
$teachers = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name, u.email, u.phone, u.login, u.is_active,
               COUNT(DISTINCT c.id) as class_count
        FROM teachers t
        JOIN users u ON t.user_id = u.id
        LEFT JOIN classes c ON t.user_id = c.class_teacher_id
        WHERE t.school_id = ?
        GROUP BY t.id
        ORDER BY u.full_name
    ");
    $stmt->execute([$school_id]);
    $teachers = $stmt->fetchAll();
} catch (PDOException $e) {
    $teachers = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учителя - Знание Севера</title>
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
    <aside class="sidebar">
        <div class="sidebar-header">
            <h1>Знание Севера</h1>
            <p>Электронный дневник</p>
            <?php if ($school): ?>
                <div class="school-info">
                    <strong><?php echo htmlspecialchars($school['short_name'] ?: $school['full_name']); ?></strong>
                </div>
            <?php endif; ?>
        </div>
        <nav class="sidebar-nav">
            <div class="user-info">
                <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
                <span class="role-badge school-admin">Администратор школы</span>
            </div>
            <ul class="nav-menu">
                <li><a href="dashboard.php" class="nav-link">🏠 Главная</a></li>
                <li class="nav-section">Управление школой</li>
                <li><a href="classes.php" class="nav-link">👨‍🏫 Классы</a></li>
                <li><a href="teachers.php" class="nav-link active">👥 Учителя</a></li>
                <li><a href="students.php" class="nav-link">🎓 Ученики</a></li>
                <li><a href="parents.php" class="nav-link">👨‍👩‍👧‍👦 Родители</a></li>
                <li><a href="schedule.php" class="nav-link">📅 Расписание</a></li>
                <li><a href="grade_types.php" class="nav-link">📊 Система оценок</a></li>
                <li><a href="grade_weights.php" class="nav-link">⚖️ Веса оценок</a></li>
                <li><a href="reports.php" class="nav-link">📈 Отчеты</a></li>
                <li class="nav-section">Общее</li>
                <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Управление учителями</h1>
                <p><?php echo htmlspecialchars($school['full_name']); ?></p>
            </div>
            <div class="header-actions">
                <a href="teachers.php?action=add" class="btn btn-primary">➕ Добавить учителя</a>
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
                <!-- Форма добавления/редактирования учителя -->
                <div class="admin-form">
                    <h2><?php echo $action === 'add' ? 'Добавить учителя' : 'Редактировать данные учителя'; ?></h2>
                    <form method="POST">
                        <?php if ($action === 'add'): ?>
                            <div class="form-section">
                                <h3>Основная информация</h3>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label for="full_name">ФИО *</label>
                                        <input type="text" id="full_name" name="full_name"
                                               value="<?php echo isset($_POST['full_name']) ? htmlspecialchars($_POST['full_name']) : ''; ?>"
                                               placeholder="Иванов Иван Иванович" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="login">Логин *</label>
                                        <input type="text" id="login" name="login"
                                               value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login']) : ''; ?>"
                                               placeholder="teacher123" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="email">Email *</label>
                                        <input type="email" id="email" name="email"
                                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                               placeholder="teacher@school.ru" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="phone">Телефон</label>
                                        <input type="tel" id="phone" name="phone"
                                               value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                                               placeholder="+7 (999) 123-45-67">
                                    </div>
                                    <div class="form-group">
                                        <label for="password">Пароль *</label>
                                        <input type="password" id="password" name="password" required>
                                        <small class="form-hint">Минимум 6 символов</small>
                                    </div>
                                    <div class="form-group">
                                        <label for="password_confirm">Подтверждение пароля *</label>
                                        <input type="password" id="password_confirm" name="password_confirm" required>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-section">
                            <h3>Профессиональная информация</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="subjects">Преподаваемые предметы</label>
                                    <textarea id="subjects" name="subjects" rows="3" placeholder="Математика, Физика, Информатика"><?php echo isset($_POST['subjects']) ? htmlspecialchars($_POST['subjects']) : (isset($teacher_data['subjects']) ? htmlspecialchars($teacher_data['subjects']) : ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="qualification">Квалификация</label>
                                    <input type="text" id="qualification" name="qualification"
                                           value="<?php echo isset($_POST['qualification']) ? htmlspecialchars($_POST['qualification']) : (isset($teacher_data['qualification']) ? htmlspecialchars($teacher_data['qualification']) : ''); ?>"
                                           placeholder="Например: Высшая категория">
                                </div>
                                <div class="form-group">
                                    <label for="experience_years">Стаж работы (лет)</label>
                                    <input type="number" id="experience_years" name="experience_years"
                                           value="<?php echo isset($_POST['experience_years']) ? htmlspecialchars($_POST['experience_years']) : (isset($teacher_data['experience_years']) ? htmlspecialchars($teacher_data['experience_years']) : ''); ?>"
                                           min="0" max="50" placeholder="0">
                                </div>
                                <div class="form-group">
                                    <label for="education">Образование</label>
                                    <textarea id="education" name="education" rows="3" placeholder="Высшее образование, специальность..."><?php echo isset($_POST['education']) ? htmlspecialchars($_POST['education']) : (isset($teacher_data['education']) ? htmlspecialchars($teacher_data['education']) : ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="specialization">Специализация</label>
                                    <input type="text" id="specialization" name="specialization"
                                           value="<?php echo isset($_POST['specialization']) ? htmlspecialchars($_POST['specialization']) : (isset($teacher_data['specialization']) ? htmlspecialchars($teacher_data['specialization']) : ''); ?>"
                                           placeholder="Например: Математика и информатика">
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
                                                    <?php echo (!isset($_POST['is_active']) && $action === 'edit') || (isset($_POST['is_active']) && $_POST['is_active']) || (isset($teacher_data['is_active']) && $teacher_data['is_active']) ? 'checked' : ''; ?>>
                                            <span class="checkmark"></span>
                                            Активный аккаунт
                                        </label>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? '➕ Добавить учителя' : '💾 Сохранить изменения'; ?>
                            </button>
                            <a href="teachers.php" class="btn btn-secondary">❌ Отмена</a>
                        </div>
                    </form>
                </div>

            <?php elseif ($action === 'view' && $teacher_data): ?>
                <!-- Просмотр учителя -->
                <div class="admin-form">
                    <h2>Просмотр данных учителя</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>ФИО:</label>
                            <span><?php echo htmlspecialchars($teacher_data['full_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Логин:</label>
                            <span><?php echo htmlspecialchars($teacher_data['login']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Email:</label>
                            <span><?php echo htmlspecialchars($teacher_data['email']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Телефон:</label>
                            <span><?php echo !empty($teacher_data['phone']) ? htmlspecialchars($teacher_data['phone']) : '—'; ?></span>
                        </div>
                        <?php if (!empty($teacher_data['subjects'])): ?>
                            <div class="info-item">
                                <label>Преподаваемые предметы:</label>
                                <span><?php echo htmlspecialchars($teacher_data['subjects']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($teacher_data['qualification'])): ?>
                            <div class="info-item">
                                <label>Квалификация:</label>
                                <span><?php echo htmlspecialchars($teacher_data['qualification']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($teacher_data['experience_years'])): ?>
                            <div class="info-item">
                                <label>Стаж работы:</label>
                                <span><?php echo $teacher_data['experience_years']; ?> лет</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($teacher_data['education'])): ?>
                            <div class="info-item">
                                <label>Образование:</label>
                                <span><?php echo nl2br(htmlspecialchars($teacher_data['education'])); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($teacher_data['specialization'])): ?>
                            <div class="info-item">
                                <label>Специализация:</label>
                                <span><?php echo htmlspecialchars($teacher_data['specialization']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <label>Статус:</label>
                            <span class="status-badge status-<?php echo $teacher_data['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $teacher_data['is_active'] ? 'Активен' : 'Неактивен'; ?>
                            </span>
                        </div>
                        <?php if (isset($teacher_data['created_at'])): ?>
                            <div class="info-item">
                                <label>Дата добавления:</label>
                                <span><?php echo date('d.m.Y H:i', strtotime($teacher_data['created_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-actions">
                        <a href="teachers.php?action=edit&id=<?php echo $teacher_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                        <a href="teachers.php" class="btn btn-secondary">← Назад к учителям</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список учителей -->
                <div class="teachers-container">
                    <h2>Список учителей</h2>

                    <div class="admin-filters">
                        <div class="filter-group">
                            <label for="teacherSearch">Поиск учителя</label>
                            <input type="search" id="teacherSearch" placeholder="ФИО, логин, email или предмет..." autocomplete="off">
                        </div>
                    </div>

                    <?php if (empty($teachers)): ?>
                        <div class="empty-state">
                            <p>Учителя не добавлены</p>
                            <a href="teachers.php?action=add" class="btn btn-primary">➕ Добавить первого учителя</a>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Логин</th>
                                <th>Email</th>
                                <th>Предметы</th>
                                <th>Классов</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($teachers as $teacher): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($teacher['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['login']); ?></td>
                                    <td><?php echo htmlspecialchars($teacher['email']); ?></td>
                                    <td><?php echo !empty($teacher['subjects']) ? htmlspecialchars($teacher['subjects']) : '—'; ?></td>
                                    <td><?php echo $teacher['class_count']; ?></td>
                                    <td>
                                            <span class="status-badge status-<?php echo $teacher['is_active'] ? 'active' : 'inactive'; ?>">
                                                <?php echo $teacher['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                            </span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="teachers.php?action=view&id=<?php echo $teacher['id']; ?>" class="btn-action btn-view" title="Просмотр">👁️</a>
                                            <a href="teachers.php?action=edit&id=<?php echo $teacher['id']; ?>" class="btn-action btn-edit" title="Редактировать">✏️</a>
                                            <a href="teachers.php?action=delete&id=<?php echo $teacher['id']; ?>" class="btn-action btn-delete" title="Удалить" onclick="return confirm('Вы уверены, что хотите удалить учителя &laquo;<?php echo htmlspecialchars($teacher['full_name']); ?>&raquo;?')">🗑️</a>
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
    const teacherSearch = document.getElementById('teacherSearch');
    if (teacherSearch) {
        teacherSearch.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            document.querySelectorAll('.data-table tbody tr').forEach(row => {
                row.hidden = query !== '' && !row.textContent.toLowerCase().includes(query);
            });
        });
    }
</script>
</body>
</html>