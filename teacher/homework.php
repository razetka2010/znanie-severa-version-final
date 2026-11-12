<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';

requireTeacher();

$pdo = getDatabaseConnection();
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['user_school_id'];
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$selected_subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: 0;
$selected_lesson_date = '';
$selected_due_date = '';

if (isset($_GET['lesson_date']) && is_string($_GET['lesson_date'])) {
    $lesson_date_check = DateTime::createFromFormat('Y-m-d', $_GET['lesson_date']);
    if ($lesson_date_check && $lesson_date_check->format('Y-m-d') === $_GET['lesson_date']
        && $selected_class_id > 0 && $selected_subject_id > 0) {
        $lesson_access_stmt = $pdo->prepare("
            SELECT 1
            FROM schedule
            WHERE teacher_id = ? AND school_id = ? AND class_id = ? AND subject_id = ? AND lesson_date = ?
            LIMIT 1
        ");
        $lesson_access_stmt->execute([
            $teacher_id,
            $school_id,
            $selected_class_id,
            $selected_subject_id,
            $_GET['lesson_date']
        ]);
        if ($lesson_access_stmt->fetchColumn()) {
            $selected_lesson_date = $_GET['lesson_date'];
            $selected_due_date = $selected_lesson_date;
        } else {
            $_SESSION['error_message'] = 'Выбранный урок не найден или недоступен.';
        }
    } else {
        $_SESSION['error_message'] = 'Некорректно указана дата урока.';
    }
}

// Получаем информацию о учителе
$teacher_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$teacher_stmt->execute([$teacher_id]);
$teacher = $teacher_stmt->fetch();

// Получаем классы учителя
$classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, c.grade_level 
        FROM classes c 
        WHERE c.school_id = ? AND c.is_active = 1
        ORDER BY c.grade_level, c.name
    ");
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении классов: " . $e->getMessage());
}

// Получаем предметы
$subjects = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE school_id = ? ORDER BY name");
    $stmt->execute([$school_id]);
    $subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении предметов: " . $e->getMessage());
}

// Обработка добавления домашнего задания
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_token();
    if ($_POST['action'] === 'add_homework') {
        $class_id = intval($_POST['class_id']);
        $subject_id = intval($_POST['subject_id']);
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $due_date = is_string($_POST['due_date'] ?? null) ? trim($_POST['due_date']) : '';
        $lesson_date = is_string($_POST['lesson_date'] ?? null) ? trim($_POST['lesson_date']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';

        try {
            $due_date_check = DateTime::createFromFormat('Y-m-d', $due_date);
            if ($title === '' || $description === '' || !$due_date_check || $due_date_check->format('Y-m-d') !== $due_date) {
                throw new InvalidArgumentException('Заполните название, описание и срок сдачи задания.');
            }
            if ($lesson_date !== '') {
                $lesson_date_check = DateTime::createFromFormat('Y-m-d', $lesson_date);
                if (!$lesson_date_check || $lesson_date_check->format('Y-m-d') !== $lesson_date) {
                    throw new InvalidArgumentException('Некорректно указана дата урока.');
                }
            }

            $access_query = "SELECT 1 FROM schedule WHERE teacher_id = ? AND school_id = ? AND class_id = ? AND subject_id = ?";
            $access_params = [$teacher_id, $school_id, $class_id, $subject_id];
            if ($lesson_date !== '') {
                $access_query .= " AND lesson_date = ?";
                $access_params[] = $lesson_date;
            }
            $access_query .= " LIMIT 1";
            $access_stmt = $pdo->prepare($access_query);
            $access_stmt->execute($access_params);
            if (!$access_stmt->fetchColumn()) {
                throw new InvalidArgumentException('Выбранные класс и предмет не назначены этому учителю.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO homework (teacher_id, class_id, subject_id, title, due_date, lesson_date, description, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$teacher_id, $class_id, $subject_id, $title, $due_date, $lesson_date !== '' ? $lesson_date : null, $description]);
            logUserAction($pdo, 'homework_created', json_encode([
                'class_id' => $class_id,
                'subject_id' => $subject_id,
                'title' => $title
            ], JSON_UNESCAPED_UNICODE));

            $_SESSION['success_message'] = "Домашнее задание успешно добавлено!";
            header('Location: homework.php');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
            header('Location: homework.php');
            exit;
        } catch (InvalidArgumentException $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: homework.php');
            exit;
        }
    }

    // Удаление домашнего задания
    if ($_POST['action'] === 'delete_homework') {
        $homework_id = intval($_POST['homework_id']);

        try {
            $stmt = $pdo->prepare("DELETE FROM homework WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$homework_id, $teacher_id]);
            logUserAction($pdo, 'homework_deleted', json_encode(['homework_id' => $homework_id]));

            $_SESSION['success_message'] = "Домашнее задание удалено!";
            header('Location: homework.php');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
            header('Location: homework.php');
            exit;
        }
    }
}

// Получаем домашние задания
$homework_list = [];
try {
    $stmt = $pdo->prepare("
        SELECT h.*, c.name as class_name, s.name as subject_name
        FROM homework h
        JOIN classes c ON h.class_id = c.id
        JOIN subjects s ON h.subject_id = s.id
        WHERE h.teacher_id = ?
        ORDER BY h.due_date DESC, h.created_at DESC
    ");
    $stmt->execute([$teacher_id]);
    $homework_list = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении домашних заданий: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Домашние задания - Учитель</title>
    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            color: #2c3e50;
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Сайдбар */
        .sidebar {
            width: 280px;
            background: #2c3e50;
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px;
            background: #34495e;
            border-bottom: 1px solid #4a6278;
        }

        .sidebar-header h1 {
            font-size: 1.2em;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.9em;
            opacity: 0.8;
        }

        .sidebar-nav {
            padding: 0;
        }

        .user-info {
            padding: 15px 20px;
            background: #34495e;
            border-bottom: 1px solid #4a6278;
        }

        .role-badge {
            display: inline-block;
            background: #e74c3c;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            margin-left: 8px;
        }

        .nav-menu {
            list-style: none;
            padding: 0;
        }

        .nav-section {
            padding: 12px 20px;
            font-size: 0.8em;
            text-transform: uppercase;
            opacity: 0.7;
            border-bottom: 1px solid #4a6278;
        }

        .nav-link {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }

        .nav-link:hover {
            background: #34495e;
            border-left-color: #3498db;
        }

        .nav-link.active {
            background: #34495e;
            border-left-color: #3498db;
            font-weight: bold;
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
        }

        .content-header {
            background: white;
            padding: 20px 30px;
            border-bottom: 1px solid #e0e0e0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .header-title h1 {
            font-size: 1.8em;
            margin-bottom: 5px;
            color: #2c3e50;
        }

        .header-title p {
            color: #7f8c8d;
        }

        .content-body {
            padding: 30px;
        }

        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5em;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.9em;
        }

        /* Секции */
        .section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }

        .section-title {
            font-size: 1.3em;
            color: #2c3e50;
            margin: 0;
        }

        /* Таблицы */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 0.9em;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #219653;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }

        .empty-state .icon {
            font-size: 3em;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .quick-action-btn {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 25px 20px;
            text-align: center;
            text-decoration: none;
            color: #2c3e50;
            transition: all 0.3s;
            display: block;
        }

        .quick-action-btn:hover {
            border-color: #3498db;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .quick-action-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
            display: block;
        }

        .homework-form {
            display: grid;
            gap: 15px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-group select,
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1em;
            transition: border-color 0.3s;
            background: white;
        }

        .form-group select:focus,
        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #3498db;
            outline: none;
        }

        .homework-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #3498db;
            transition: transform 0.3s;
        }

        .homework-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .homework-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .homework-meta {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 10px;
        }

        .homework-actions {
            display: flex;
            gap: 10px;
        }

        .btn-danger {
            background: #e74c3c;
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .due-date {
            background: #fff3cd;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: 600;
        }

        .due-date.overdue {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
    <link rel="stylesheet" href="../css/teacher.css">
</head>
<body>
<div class="dashboard-container">
    <?php $teacherActivePage = 'homework'; require __DIR__ . '/../includes/teacher_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Домашние задания</h1>
                <p>Управление домашними заданиями</p>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= consumeFlashMessage('success_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?= consumeFlashMessage('error_message'); ?></div>
            <?php endif; ?>

            <!-- Форма добавления задания -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📝 Добавить домашнее задание</h2>
                </div>

                <form method="POST" class="homework-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="add_homework">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Класс:</label>
                            <select name="class_id" required>
                                <option value="">Выберите класс</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>" <?= $selected_class_id === (int)$class['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($class['name']) ?> (<?= $class['grade_level'] ?> класс)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Предмет:</label>
                            <select name="subject_id" required>
                                <option value="">Выберите предмет</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?= $subject['id'] ?>" <?= $selected_subject_id === (int)$subject['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($subject['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Название задания:</label>
                            <input type="text" name="title" required maxlength="255" placeholder="Например: Упражнения по теме" value="<?= htmlspecialchars($selected_lesson_date !== '' ? 'Домашнее задание к уроку ' . date('d.m.Y', strtotime($selected_lesson_date)) : '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="form-group">
                            <label>Срок сдачи:</label>
                            <input type="date" name="due_date" required min="<?= $selected_due_date !== '' ? htmlspecialchars($selected_due_date, ENT_QUOTES, 'UTF-8') : date('Y-m-d') ?>" value="<?= htmlspecialchars($selected_due_date, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Дата урока:</label>
                        <input type="date" name="lesson_date" value="<?= htmlspecialchars($selected_lesson_date, ENT_QUOTES, 'UTF-8') ?>">
                    </div>

                    <div class="form-group">
                        <label>Описание задания:</label>
                        <textarea name="description" required rows="4" placeholder="Опишите домашнее задание..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-success" style="justify-self: start;">
                        ➕ Добавить задание
                    </button>
                </form>
            </div>

            <!-- Список заданий -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📚 Активные задания</h2>
                    <span class="btn btn-secondary" style="cursor: default;">
                            Всего: <?= count($homework_list) ?>
                        </span>
                </div>

                <?php if (!empty($homework_list)): ?>
                    <div>
                        <?php foreach ($homework_list as $homework):
                            $is_overdue = strtotime($homework['due_date']) < strtotime(date('Y-m-d'));
                            ?>
                            <div class="homework-card">
                                <div class="homework-header">
                                    <div>
                                        <h3 style="margin: 0 0 5px 0; color: #2c3e50;">
                                            <?= htmlspecialchars($homework['title']) ?>
                                            <small><?= htmlspecialchars($homework['class_name']) ?> - <?= htmlspecialchars($homework['subject_name']) ?></small>
                                        </h3>
                                        <div class="homework-meta">
                                            Добавлено: <?= date('d.m.Y', strtotime($homework['created_at'])) ?>
                                            |
                                            <span class="due-date <?= $is_overdue ? 'overdue' : '' ?>">
                                                    Сдать до: <?= date('d.m.Y', strtotime($homework['due_date'])) ?>
                                                <?= $is_overdue ? ' (Просрочено)' : '' ?>
                                                </span>
                                        </div>
                                    </div>
                                    <div class="homework-actions">
                                        <form method="POST" style="display: inline;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_homework">
                                            <input type="hidden" name="homework_id" value="<?= $homework['id'] ?>">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить это задание?')">
                                                🗑️ Удалить
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <div style="background: white; padding: 15px; border-radius: 6px; border: 1px solid #e0e0e0;">
                                    <p style="margin: 0; line-height: 1.6;"><?= nl2br(htmlspecialchars($homework['description'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">📚</div>
                        <h3>Нет активных заданий</h3>
                        <p>Добавьте первое домашнее задание используя форму выше</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
    // Установка минимальной даты как сегодня
    const dueDateInput = document.querySelector('input[name="due_date"]');
    if (!dueDateInput.value) {
        dueDateInput.min = new Date().toISOString().split('T')[0];
    }

    // Подтверждение удаления
    document.querySelectorAll('form[action="delete_homework"]').forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!confirm('Вы уверены, что хотите удалить это задание?')) {
                e.preventDefault();
            }
        });
    });
</script>
</body>
</html>