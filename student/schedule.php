<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

requireStudent();

$pdo = getDatabaseConnection();
$student_id = $_SESSION['user_id'];
$school_id = $_SESSION['user_school_id'];

// Получаем информацию о ученике
$student_stmt = $pdo->prepare("
    SELECT u.*, c.name as class_name, c.grade_level 
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id 
    WHERE u.id = ?
");
$student_stmt->execute([$student_id]);
$student = $student_stmt->fetch();

// Получаем параметры фильтрации
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$selected_week = isset($_GET['week']) ? $_GET['week'] : date('Y-W');
$view_type = isset($_GET['view']) ? $_GET['view'] : 'day';

// Время уроков (стандартное расписание)
$lesson_times = [
    1 => ['start' => '08:30', 'end' => '09:15'],
    2 => ['start' => '09:25', 'end' => '10:10'],
    3 => ['start' => '10:25', 'end' => '11:10'],
    4 => ['start' => '11:25', 'end' => '12:10'],
    5 => ['start' => '12:20', 'end' => '13:05'],
    6 => ['start' => '13:15', 'end' => '14:00'],
    7 => ['start' => '14:10', 'end' => '14:55'],
    8 => ['start' => '15:05', 'end' => '15:50']
];

// Получаем расписание в зависимости от выбранного вида
$schedule = [];
$week_dates = [];

if ($view_type === 'day') {
    // Расписание на один день
    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.*, 
                sub.name as subject_name,
                u.full_name as teacher_name,
                s.room
            FROM schedule s 
            JOIN subjects sub ON s.subject_id = sub.id 
            JOIN users u ON s.teacher_id = u.id 
            WHERE s.class_id = ? AND s.lesson_date = ? 
            ORDER BY s.lesson_number ASC
        ");
        $stmt->execute([$student['class_id'], $selected_date]);
        $schedule = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Ошибка при получении расписания на день: " . $e->getMessage());
    }
} else {
    // Расписание на неделю
    list($year, $week) = explode('-', $selected_week);
    $week_start = new DateTime();
    $week_start->setISODate($year, $week);
    $week_start = $week_start->format('Y-m-d');

    $week_end = new DateTime($week_start);
    $week_end->modify('+6 days');
    $week_end = $week_end->format('Y-m-d');

    // Генерируем даты недели
    $current_date = new DateTime($week_start);
    for ($i = 0; $i < 7; $i++) {
        $week_dates[] = $current_date->format('Y-m-d');
        $current_date->modify('+1 day');
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.*, 
                sub.name as subject_name,
                u.full_name as teacher_name,
                s.room
            FROM schedule s 
            JOIN subjects sub ON s.subject_id = sub.id 
            JOIN users u ON s.teacher_id = u.id 
            WHERE s.class_id = ? AND s.lesson_date BETWEEN ? AND ? 
            ORDER BY s.lesson_date ASC, s.lesson_number ASC
        ");
        $stmt->execute([$student['class_id'], $week_start, $week_end]);
        $all_lessons = $stmt->fetchAll();

        // Группируем уроки по датам
        foreach ($all_lessons as $lesson) {
            $date = $lesson['lesson_date'];
            if (!isset($schedule[$date])) {
                $schedule[$date] = [];
            }
            $schedule[$date][] = $lesson;
        }
    } catch (PDOException $e) {
        error_log("Ошибка при получении расписания на неделю: " . $e->getMessage());
    }
}

// Получаем домашние задания на выбранную дату/неделю
$homework = [];
try {
    if ($view_type === 'day') {
        $stmt = $pdo->prepare("
            SELECT 
                h.*, 
                s.name as subject_name,
                u.full_name as teacher_name
            FROM homework h 
            JOIN subjects s ON h.subject_id = s.id 
            JOIN users u ON h.teacher_id = u.id 
            WHERE h.class_id = ? AND h.due_date = ? 
            ORDER BY h.subject_id ASC
        ");
        $stmt->execute([$student['class_id'], $selected_date]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                h.*, 
                s.name as subject_name,
                u.full_name as teacher_name
            FROM homework h 
            JOIN subjects s ON h.subject_id = s.id 
            JOIN users u ON h.teacher_id = u.id 
            WHERE h.class_id = ? AND h.due_date BETWEEN ? AND ? 
            ORDER BY h.due_date ASC, h.subject_id ASC
        ");
        $stmt->execute([$student['class_id'], $week_start, $week_end]);
    }
    $homework = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении домашних заданий: " . $e->getMessage());
}

// Генерируем список недель для выбора
$weeks = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            YEARWEEK(lesson_date) as week_id,
            CONCAT(YEAR(lesson_date), '-W', LPAD(WEEK(lesson_date, 1), 2, '0')) as week_format,
            MIN(lesson_date) as week_start,
            MAX(lesson_date) as week_end
        FROM schedule 
        WHERE class_id = ? 
        GROUP BY YEARWEEK(lesson_date)
        ORDER BY lesson_date DESC
        LIMIT 10
    ");
    $stmt->execute([$student['class_id']]);
    $weeks = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении списка недель: " . $e->getMessage());
}

// Получаем замены уроков
$substitutions = [];
try {
    if ($view_type === 'day') {
        $stmt = $pdo->prepare("
            SELECT 
                sub.*,
                s_old.name as old_subject_name,
                s_new.name as new_subject_name,
                u_old.full_name as old_teacher_name,
                u_new.full_name as new_teacher_name
            FROM lesson_substitutions sub
            LEFT JOIN subjects s_old ON sub.old_subject_id = s_old.id
            LEFT JOIN subjects s_new ON sub.new_subject_id = s_new.id
            LEFT JOIN users u_old ON sub.old_teacher_id = u_old.id
            LEFT JOIN users u_new ON sub.new_teacher_id = u_new.id
            WHERE sub.class_id = ? AND sub.lesson_date = ?
            ORDER BY sub.lesson_number ASC
        ");
        $stmt->execute([$student['class_id'], $selected_date]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                sub.*,
                s_old.name as old_subject_name,
                s_new.name as new_subject_name,
                u_old.full_name as old_teacher_name,
                u_new.full_name as new_teacher_name
            FROM lesson_substitutions sub
            LEFT JOIN subjects s_old ON sub.old_subject_id = s_old.id
            LEFT JOIN subjects s_new ON sub.new_subject_id = s_new.id
            LEFT JOIN users u_old ON sub.old_teacher_id = u_old.id
            LEFT JOIN users u_new ON sub.new_teacher_id = u_new.id
            WHERE sub.class_id = ? AND sub.lesson_date BETWEEN ? AND ?
            ORDER BY sub.lesson_date ASC, sub.lesson_number ASC
        ");
        $stmt->execute([$student['class_id'], $week_start, $week_end]);
    }
    $substitutions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении замен: " . $e->getMessage());
}

// Функция для получения урока по номеру и дате
function getLessonByNumber($lessons, $lesson_number) {
    foreach ($lessons as $lesson) {
        if ($lesson['lesson_number'] == $lesson_number) {
            return $lesson;
        }
    }
    return null;
}

// Функция для получения замены по номеру и дате
function getSubstitutionByNumber($substitutions, $lesson_date, $lesson_number) {
    foreach ($substitutions as $sub) {
        if ($sub['lesson_date'] == $lesson_date && $sub['lesson_number'] == $lesson_number) {
            return $sub;
        }
    }
    return null;
}
?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <link rel="shortcut icon" href="../logo.png" />
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Расписание - Ученик</title>
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

            .user-details {
                margin-top: 10px;
                font-size: 0.9em;
                opacity: 0.9;
            }

            .role-badge {
                display: inline-block;
                background: #27ae60;
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

            /* Фильтры и навигация */
            .filters-section {
                background: white;
                border-radius: 10px;
                padding: 20px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 25px;
            }

            .filters-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                align-items: end;
            }

            .view-toggle {
                display: flex;
                gap: 10px;
                margin-bottom: 15px;
            }

            .view-btn {
                padding: 8px 16px;
                border: 2px solid #e0e0e0;
                background: white;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.3s;
                font-weight: 500;
            }

            .view-btn.active {
                background: #3498db;
                color: white;
                border-color: #3498db;
            }

            .view-btn:hover:not(.active) {
                border-color: #3498db;
                color: #3498db;
            }

            .form-group {
                margin-bottom: 0;
            }

            .form-group label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                color: #2c3e50;
                font-size: 0.9em;
            }

            .form-group select,
            .form-group input {
                width: 100%;
                padding: 10px 12px;
                border: 2px solid #e0e0e0;
                border-radius: 6px;
                font-size: 0.9em;
                transition: border-color 0.3s;
                background: white;
            }

            .form-group select:focus,
            .form-group input:focus {
                border-color: #3498db;
                outline: none;
                box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            }

            /* Расписание */
            .schedule-section {
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

            /* Расписание на день */
            .day-schedule {
                display: grid;
                gap: 15px;
            }

            .lesson-card {
                background: #f8f9fa;
                border-radius: 8px;
                padding: 20px;
                border-left: 4px solid #3498db;
                transition: transform 0.3s;
            }

            .lesson-card:hover {
                transform: translateX(5px);
            }

            .lesson-card.current {
                background: #e3f2fd;
                border-left-color: #2196F3;
            }

            .lesson-card.substituted {
                background: #fff3cd;
                border-left-color: #ffc107;
            }

            .lesson-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 10px;
            }

            .lesson-time {
                font-weight: 600;
                color: #2c3e50;
                font-size: 1.1em;
            }

            .lesson-number {
                background: #3498db;
                color: white;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.8em;
                font-weight: bold;
            }

            .lesson-subject {
                font-size: 1.2em;
                font-weight: 600;
                color: #2c3e50;
                margin-bottom: 5px;
            }

            .lesson-details {
                color: #7f8c8d;
                font-size: 0.9em;
            }

            .substitution-badge {
                background: #ffc107;
                color: #856404;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 0.8em;
                font-weight: bold;
                margin-top: 8px;
                display: inline-block;
            }

            /* Расписание на неделю */
            .week-schedule {
                overflow-x: auto;
            }

            .week-grid {
                display: grid;
                grid-template-columns: 100px repeat(7, 1fr);
                gap: 1px;
                background: #e0e0e0;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                overflow: hidden;
                min-width: 800px;
            }

            .week-header {
                background: #343a40;
                color: white;
                padding: 15px;
                text-align: center;
                font-weight: 600;
            }

            .week-day-header {
                background: #495057;
                color: white;
                padding: 12px;
                text-align: center;
                font-weight: 600;
            }

            .week-cell {
                background: white;
                padding: 10px;
                min-height: 80px;
                position: relative;
            }

            .week-time {
                background: #f8f9fa;
                font-weight: 600;
                color: #2c3e50;
                text-align: center;
                padding: 15px 5px;
            }

            .week-lesson {
                background: #e3f2fd;
                border-radius: 4px;
                padding: 8px;
                margin-bottom: 5px;
                font-size: 0.8em;
                border-left: 3px solid #2196F3;
            }

            .week-lesson.substituted {
                background: #fff3cd;
                border-left-color: #ffc107;
            }

            .week-lesson.current {
                background: #d4edda;
                border-left-color: #28a745;
            }

            .lesson-subject-small {
                font-weight: 600;
                margin-bottom: 2px;
            }

            .lesson-teacher-small {
                font-size: 0.7em;
                color: #6c757d;
            }

            /* Домашние задания */
            .homework-section {
                background: white;
                border-radius: 10px;
                padding: 25px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }

            .homework-list {
                display: grid;
                gap: 15px;
            }

            .homework-card {
                background: #f8f9fa;
                border-radius: 8px;
                padding: 15px;
                border-left: 4px solid #3498db;
            }

            .homework-card.urgent {
                border-left-color: #e74c3c;
                background: #fdf2f2;
            }

            .homework-card.warning {
                border-left-color: #f39c12;
                background: #fef9e7;
            }

            .homework-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 8px;
            }

            .homework-subject {
                font-weight: 600;
                color: #2c3e50;
                font-size: 1.1em;
            }

            .homework-due {
                font-size: 0.9em;
                color: #7f8c8d;
            }

            .homework-teacher {
                font-size: 0.9em;
                color: #6c757d;
                margin-bottom: 8px;
            }

            .homework-description {
                color: #2c3e50;
                line-height: 1.5;
            }

            .urgent-badge {
                background: #e74c3c;
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 0.7em;
                font-weight: bold;
            }

            /* Кнопки */
            .btn {
                padding: 8px 16px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 0.9em;
                font-weight: 500;
                transition: all 0.3s;
                gap: 5px;
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

            /* Сообщения */
            .alert {
                padding: 15px 20px;
                border-radius: 8px;
                margin-bottom: 20px;
                border-left: 4px solid;
            }

            .alert-info {
                background: #d1ecf1;
                color: #0c5460;
                border-left-color: #17a2b8;
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

                .filters-grid {
                    grid-template-columns: 1fr;
                }

                .section-header {
                    flex-direction: column;
                    gap: 15px;
                    align-items: flex-start;
                }

                .lesson-header {
                    flex-direction: column;
                    gap: 10px;
                }

                .week-schedule {
                    font-size: 0.8em;
                }
            }

            .date-navigation {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 15px;
            }

            .nav-btn {
                background: #3498db;
                color: white;
                border: none;
                border-radius: 6px;
                padding: 8px 12px;
                cursor: pointer;
                transition: background 0.3s;
            }

            .nav-btn:hover {
                background: #2980b9;
            }

            .current-date {
                font-weight: 600;
                color: #2c3e50;
            }

            .today-badge {
                background: #27ae60;
                color: white;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 0.7em;
                font-weight: bold;
                margin-left: 5px;
            }
        </style>
        <link rel="stylesheet" href="../css/student.css">
    </head>
    <body>
    <div class="dashboard-container">
        <!-- Боковая панель навигации -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>Электронный дневник</h1>
                <p>Ученик</p>
            </div>
            <nav class="sidebar-nav">
                <div class="user-info">
                    <strong><?= htmlspecialchars($student['full_name']) ?></strong>
                    <span class="role-badge">Ученик</span>
                    <div class="user-details">
                        <?= htmlspecialchars($student['class_name']) ?> класс
                    </div>
                </div>
                <ul class="nav-menu">
                    <li><a href="dashboard.php" class="nav-link">📊 Главная</a></li>
                    <li class="nav-section">Учебный процесс</li>
                    <li><a href="grades.php" class="nav-link">📝 Мои оценки</a></li>
                    <li><a href="schedule.php" class="nav-link active">📅 Расписание</a></li>
                    <li><a href="homework.php" class="nav-link">📚 Домашние задания</a></li>
                    <li><a href="subjects.php" class="nav-link">📖 Предметы</a></li>
                    <li class="nav-section">Общее</li>
                    <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                    <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <header class="content-header">
                <div class="header-title">
                    <h1>Расписание уроков</h1>
                    <p>Просмотр расписания и домашних заданий</p>
                </div>
            </header>

            <div class="content-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-error"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
                <?php endif; ?>

                <!-- Фильтры и навигация -->
                <div class="filters-section">
                    <div class="view-toggle">
                        <button class="view-btn <?= $view_type === 'day' ? 'active' : '' ?>" onclick="changeView('day')">
                            📅 День
                        </button>
                        <button class="view-btn <?= $view_type === 'week' ? 'active' : '' ?>" onclick="changeView('week')">
                            📆 Неделя
                        </button>
                    </div>

                    <form method="GET" class="filters-grid">
                        <input type="hidden" name="view" value="<?= $view_type ?>">

                        <?php if ($view_type === 'day'): ?>
                            <div class="form-group">
                                <label>Дата:</label>
                                <input type="date" name="date" value="<?= $selected_date ?>" onchange="this.form.submit()">
                            </div>

                            <div class="date-navigation">
                                <button type="button" class="nav-btn" onclick="changeDate(-1)">◀</button>
                                <span class="current-date">
                                    <?= date('d.m.Y', strtotime($selected_date)) ?>
                                    <?= $selected_date == date('Y-m-d') ? '<span class="today-badge">Сегодня</span>' : '' ?>
                                </span>
                                <button type="button" class="nav-btn" onclick="changeDate(1)">▶</button>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label>Неделя:</label>
                                <select name="week" onchange="this.form.submit()">
                                    <?php foreach ($weeks as $week): ?>
                                        <option value="<?= $week['week_format'] ?>" <?= $selected_week == $week['week_format'] ? 'selected' : '' ?>>
                                            <?= date('d.m.Y', strtotime($week['week_start'])) ?> - <?= date('d.m.Y', strtotime($week['week_end'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="form-group">
                            <a href="schedule.php?view=<?= $view_type ?>" class="btn btn-secondary">Сегодня</a>
                        </div>
                    </form>
                </div>

                <!-- Расписание -->
                <div class="schedule-section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <?php if ($view_type === 'day'): ?>
                                📅 Расписание на <?= date('d.m.Y', strtotime($selected_date)) ?>
                            <?php else: ?>
                                📆 Расписание на неделю
                            <?php endif; ?>
                        </h2>
                        <span class="btn btn-secondary" style="cursor: default;">
                            Уроков:
                            <?php
                            if ($view_type === 'day') {
                                echo count($schedule);
                            } else {
                                $total_lessons = 0;
                                foreach ($schedule as $day_lessons) {
                                    $total_lessons += count($day_lessons);
                                }
                                echo $total_lessons;
                            }
                            ?>
                        </span>
                    </div>

                    <?php if ($view_type === 'day'): ?>
                        <!-- Расписание на день -->
                        <?php if (!empty($schedule)): ?>
                            <div class="day-schedule">
                                <?php foreach ($schedule as $lesson): ?>
                                    <?php
                                    $substitution = getSubstitutionByNumber($substitutions, $selected_date, $lesson['lesson_number']);
                                    $is_current = ($selected_date == date('Y-m-d') && date('H:i') >= $lesson_times[$lesson['lesson_number']]['start'] && date('H:i') <= $lesson_times[$lesson['lesson_number']]['end']);
                                    ?>
                                    <div class="lesson-card <?= $is_current ? 'current' : '' ?> <?= $substitution ? 'substituted' : '' ?>">
                                        <div class="lesson-header">
                                            <div class="lesson-time">
                                                <?= $lesson_times[$lesson['lesson_number']]['start'] ?> - <?= $lesson_times[$lesson['lesson_number']]['end'] ?>
                                                <?php if ($is_current): ?>
                                                    <span class="today-badge">Сейчас</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="lesson-number">
                                                <?= $lesson['lesson_number'] ?> урок
                                            </div>
                                        </div>

                                        <?php if ($substitution): ?>
                                            <div class="lesson-subject">
                                                🔄 <?= htmlspecialchars($substitution['new_subject_name'] ?: 'Замена') ?>
                                            </div>
                                            <div class="lesson-details">
                                                Вместо: <?= htmlspecialchars($substitution['old_subject_name']) ?><br>
                                                Учитель: <?= htmlspecialchars($substitution['new_teacher_name'] ?: $substitution['old_teacher_name']) ?><br>
                                                Кабинет: <?= htmlspecialchars($substitution['new_room'] ?: $lesson['room']) ?>
                                            </div>
                                            <div class="substitution-badge">
                                                🔄 Замена
                                            </div>
                                        <?php else: ?>
                                            <div class="lesson-subject">
                                                <?= htmlspecialchars($lesson['subject_name']) ?>
                                            </div>
                                            <div class="lesson-details">
                                                Учитель: <?= htmlspecialchars($lesson['teacher_name']) ?><br>
                                                Кабинет: <?= htmlspecialchars($lesson['room']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="icon">📅</div>
                                <h3>Нет уроков</h3>
                                <p>На выбранную дату уроки не расписаны</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Расписание на неделю -->
                        <?php if (!empty($schedule) || !empty($week_dates)): ?>
                            <div class="week-schedule">
                                <div class="week-grid">
                                    <!-- Заголовок времени -->
                                    <div class="week-header">Время</div>

                                    <!-- Заголовки дней -->
                                    <?php foreach ($week_dates as $date): ?>
                                        <div class="week-day-header">
                                            <?= date('d.m', strtotime($date)) ?><br>
                                            <?= getRussianDayName(date('D', strtotime($date))) ?>
                                            <?= $date == date('Y-m-d') ? '<div style="font-size: 0.7em; color: #27ae60;">Сегодня</div>' : '' ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <!-- Уроки по времени -->
                                    <?php for ($lesson_num = 1; $lesson_num <= 8; $lesson_num++): ?>
                                        <div class="week-time">
                                            <?= $lesson_times[$lesson_num]['start'] ?><br>-<br>
                                            <?= $lesson_times[$lesson_num]['end'] ?>
                                        </div>

                                        <?php foreach ($week_dates as $date): ?>
                                            <div class="week-cell">
                                                <?php
                                                $day_lessons = $schedule[$date] ?? [];
                                                $lesson = getLessonByNumber($day_lessons, $lesson_num);
                                                $substitution = getSubstitutionByNumber($substitutions, $date, $lesson_num);
                                                $is_current = ($date == date('Y-m-d') && date('H:i') >= $lesson_times[$lesson_num]['start'] && date('H:i') <= $lesson_times[$lesson_num]['end']);
                                                ?>

                                                <?php if ($substitution): ?>
                                                    <div class="week-lesson substituted">
                                                        <div class="lesson-subject-small">
                                                            🔄 <?= htmlspecialchars($substitution['new_subject_name'] ?: 'Замена') ?>
                                                        </div>
                                                        <div class="lesson-teacher-small">
                                                            <?= htmlspecialchars($substitution['new_teacher_name'] ?: $substitution['old_teacher_name']) ?>
                                                        </div>
                                                        <div class="lesson-teacher-small">
                                                            <?= htmlspecialchars($substitution['new_room'] ?: $lesson['room']) ?>
                                                        </div>
                                                    </div>
                                                <?php elseif ($lesson): ?>
                                                    <div class="week-lesson <?= $is_current ? 'current' : '' ?>">
                                                        <div class="lesson-subject-small">
                                                            <?= htmlspecialchars($lesson['subject_name']) ?>
                                                        </div>
                                                        <div class="lesson-teacher-small">
                                                            <?= htmlspecialchars($lesson['teacher_name']) ?>
                                                        </div>
                                                        <div class="lesson-teacher-small">
                                                            <?= htmlspecialchars($lesson['room']) ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="icon">📅</div>
                                <h3>Нет уроков</h3>
                                <p>На выбранную неделю уроки не расписаны</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Домашние задания -->
                <div class="homework-section">
                    <div class="section-header">
                        <h2 class="section-title">📚 Домашние задания</h2>
                        <span class="btn btn-secondary" style="cursor: default;">
                            Заданий: <?= count($homework) ?>
                        </span>
                    </div>

                    <?php if (!empty($homework)): ?>
                        <div class="homework-list">
                            <?php foreach ($homework as $hw): ?>
                                <?php
                                $is_urgent = strtotime($hw['due_date']) <= strtotime('+1 day');
                                $is_warning = strtotime($hw['due_date']) <= strtotime('+3 days');
                                ?>
                                <div class="homework-card <?= $is_urgent ? 'urgent' : ($is_warning ? 'warning' : '') ?>">
                                    <div class="homework-header">
                                        <div class="homework-subject">
                                            <?= htmlspecialchars($hw['subject_name']) ?>
                                        </div>
                                        <div class="homework-due">
                                            📅 <?= date('d.m.Y', strtotime($hw['due_date'])) ?>
                                            <?php if ($is_urgent): ?>
                                                <span class="urgent-badge">СРОЧНО</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="homework-teacher">
                                        Учитель: <?= htmlspecialchars($hw['teacher_name']) ?>
                                    </div>
                                    <div class="homework-description">
                                        <?= nl2br(htmlspecialchars($hw['description'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="icon">📚</div>
                            <h3>Нет домашних заданий</h3>
                            <p>На выбранный период домашние задания не заданы</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function changeView(viewType) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', viewType);

            if (viewType === 'day') {
                url.searchParams.set('date', '<?= date('Y-m-d') ?>');
                url.searchParams.delete('week');
            } else {
                url.searchParams.set('week', '<?= date('Y-W') ?>');
                url.searchParams.delete('date');
            }

            window.location.href = url.toString();
        }

        function changeDate(days) {
            const currentDate = new Date('<?= $selected_date ?>');
            currentDate.setDate(currentDate.getDate() + days);

            const newDate = currentDate.toISOString().split('T')[0];
            const url = new URL(window.location.href);
            url.searchParams.set('date', newDate);
            window.location.href = url.toString();
        }

        // Автоматическое обновление страницы каждые 5 минут
        setInterval(() => {
            // Обновляем только если смотрим на сегодняшний день
            if ('<?= $selected_date ?>' === '<?= date('Y-m-d') ?>') {
                window.location.reload();
            }
        }, 300000); // 5 минут
    </script>
    </body>
    </html>

<?php
// Функция для получения русского названия дня недели
function getRussianDayName($englishDayName) {
    $days = [
        'Mon' => 'Пн',
        'Tue' => 'Вт',
        'Wed' => 'Ср',
        'Thu' => 'Чт',
        'Fri' => 'Пт',
        'Sat' => 'Сб',
        'Sun' => 'Вс'
    ];
    return $days[$englishDayName] ?? $englishDayName;
}
?>