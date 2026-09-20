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

// Получаем статистику ученика
$stats = [
    'average_grade' => 0,
    'total_grades' => 0,
    'excellent_grades' => 0,
    'good_grades' => 0,
    'today_lessons' => 0
];

try {
    // Средняя оценка
    $stmt = $pdo->prepare("
        SELECT SUM(CAST(g.grade_value AS DECIMAL) * COALESCE(gw.weight, 1)) / NULLIF(SUM(COALESCE(gw.weight, 1)), 0) as avg_grade
        FROM grades g
        LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.is_active = 1
        WHERE g.student_id = ? AND g.grade_value REGEXP '^[0-9]+$'
    ");
    $stmt->execute([$student_id]);
    $stats['average_grade'] = round($stmt->fetch()['avg_grade'] ?? 0, 2);

    // Общее количество оценок
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM grades WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $stats['total_grades'] = $stmt->fetch()['count'];

    // Отличные оценки (5)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM grades WHERE student_id = ? AND grade_value = '5'");
    $stmt->execute([$student_id]);
    $stats['excellent_grades'] = $stmt->fetch()['count'];

    // Хорошие оценки (4)
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM grades WHERE student_id = ? AND grade_value = '4'");
    $stmt->execute([$student_id]);
    $stats['good_grades'] = $stmt->fetch()['count'];

    // Уроки на сегодня
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM schedule s 
        WHERE s.class_id = ? AND s.lesson_date = CURDATE()
    ");
    $stmt->execute([$student['class_id']]);
    $stats['today_lessons'] = $stmt->fetch()['count'];

} catch (PDOException $e) {
    error_log("Ошибка при получении статистики ученика: " . $e->getMessage());
}

// Получаем последние оценки
$recent_grades = [];
try {
    $stmt = $pdo->prepare("
        SELECT g.*, s.name as subject_name, u.full_name as teacher_name 
        FROM grades g 
        JOIN subjects s ON g.subject_id = s.id 
        JOIN users u ON g.teacher_id = u.id 
        WHERE g.student_id = ? 
        ORDER BY g.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$student_id]);
    $recent_grades = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении последних оценок: " . $e->getMessage());
}

// Получаем расписание на сегодня
$today_schedule = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, sub.name as subject_name, u.full_name as teacher_name 
        FROM schedule s 
        JOIN subjects sub ON s.subject_id = sub.id 
        JOIN users u ON s.teacher_id = u.id 
        WHERE s.class_id = ? AND s.lesson_date = CURDATE() 
        ORDER BY s.lesson_number ASC
    ");
    $stmt->execute([$student['class_id']]);
    $today_schedule = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении расписания на сегодня: " . $e->getMessage());
}

// Получаем домашние задания
$homework = [];
try {
    $stmt = $pdo->prepare("
        SELECT h.*, s.name as subject_name, u.full_name as teacher_name 
        FROM homework h 
        JOIN subjects s ON h.subject_id = s.id 
        JOIN users u ON h.teacher_id = u.id 
        WHERE h.class_id = ? AND h.due_date >= CURDATE() 
        ORDER BY h.due_date ASC 
        LIMIT 5
    ");
    $stmt->execute([$student['class_id']]);
    $homework = $stmt->fetchAll();
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
    <title>Главная - Ученик</title>
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

        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.primary { border-left-color: #3498db; }
        .stat-card.success { border-left-color: #27ae60; }
        .stat-card.warning { border-left-color: #f39c12; }
        .stat-card.info { border-left-color: #9b59b6; }

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

        /* Карточки */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .lesson-card, .homework-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
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

        .grade-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.9em;
        }

        .grade-5 { background: #d4edda; color: #155724; }
        .grade-4 { background: #d1ecf1; color: #0c5460; }
        .grade-3 { background: #fff3cd; color: #856404; }
        .grade-2 { background: #f8d7da; color: #721c24; }

        /* Кнопки */
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

            .card-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .quick-action-btn {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px 15px;
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
            font-size: 2em;
            margin-bottom: 10px;
            display: block;
        }

        .subject-grade {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .subject-grade:last-child {
            border-bottom: none;
        }

        .progress-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: #3498db;
            border-radius: 4px;
        }

        :root {
            --bg: #f3f7ff;
            --panel: #ffffff;
            --panel-soft: #f8fafc;
            --sidebar: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
            --primary: #2563eb;
            --primary-strong: #1d4ed8;
            --line: #e2e8f0;
            --text: #1f2937;
            --muted: #64748b;
            --shadow: 0 12px 28px rgba(15, 23, 42, 0.10);
        }

        body {
            background: linear-gradient(180deg, #eef4ff 0%, #f8fafc 100%);
            color: var(--text);
        }

        .sidebar {
            background: var(--sidebar);
            box-shadow: 12px 0 30px rgba(15, 23, 42, 0.12);
        }

        .nav-link {
            color: rgba(255,255,255,0.82);
            border-radius: 0 12px 12px 0;
            margin-right: 12px;
            transition: all 0.25s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.08);
            border-left-color: #7dd3fc;
            color: white;
        }

        .content-header {
            background: rgba(255,255,255,0.95);
            box-shadow: 0 2px 12px rgba(15, 23, 42, 0.05);
        }

        .stat-card,
        .section,
        .lesson-card,
        .homework-card {
            background: var(--panel);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
        }

        .stat-card {
            border-left: 4px solid var(--primary);
            padding: 24px 18px;
            border-radius: 16px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-icon {
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #60a5fa, #2563eb);
            border-radius: 14px;
            color: white;
            margin-bottom: 0;
            font-size: 1.8em;
        }

        .stat-number {
            font-size: 2rem;
            color: var(--text);
            line-height: 1;
        }

        .stat-label {
            color: var(--muted);
            font-size: 0.8rem;
            margin-top: 4px;
        }

        .section {
            border-radius: 18px;
            padding: 25px;
        }

        .section-header {
            padding-bottom: 12px;
            border-bottom: 1px solid var(--line);
        }

        .data-table th {
            background: var(--panel-soft);
        }

        .btn,
        .btn-primary,
        .btn-secondary {
            border-radius: 10px;
            box-shadow: 0 8px 18px rgba(37, 99, 235, 0.16);
        }

        .btn,
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-strong) 100%);
            color: white;
        }

        .quick-action-btn {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
        }

        .quick-action-btn:hover {
            border-color: rgba(37, 99, 235, 0.35);
            transform: translateY(-3px);
            box-shadow: 0 16px 28px rgba(37, 99, 235, 0.12);
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
                <li><a href="dashboard.php" class="nav-link active">📊 Главная</a></li>
                <li class="nav-section">Учебный процесс</li>
                <li><a href="grades.php" class="nav-link">📝 Мои оценки</a></li>
                <li><a href="schedule.php" class="nav-link">📅 Расписание</a></li>
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
                <h1>Главная панель</h1>
                <p>Добро пожаловать, <?= htmlspecialchars($student['full_name']) ?>!</p>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <!-- Статистика -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number"><?= $stats['average_grade'] ?: 'н/д' ?></div>
                    <div class="stat-label">Средний балл</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">⭐</div>
                    <div class="stat-number"><?= $stats['excellent_grades'] ?></div>
                    <div class="stat-label">Пятерок</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">📝</div>
                    <div class="stat-number"><?= $stats['total_grades'] ?></div>
                    <div class="stat-label">Всего оценок</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?= $stats['today_lessons'] ?></div>
                    <div class="stat-label">Уроков сегодня</div>
                </div>
            </div>

            <!-- Быстрые действия -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">⚡ Быстрые действия</h2>
                </div>

                <div class="quick-actions">
                    <a href="grades.php" class="quick-action-btn">
                        <span class="quick-action-icon">📝</span>
                        <strong>Мои оценки</strong>
                    </a>

                    <a href="schedule.php" class="quick-action-btn">
                        <span class="quick-action-icon">📅</span>
                        <strong>Расписание</strong>
                    </a>

                    <a href="homework.php" class="quick-action-btn">
                        <span class="quick-action-icon">📚</span>
                        <strong>Домашние задания</strong>
                    </a>

                    <a href="subjects.php" class="quick-action-btn">
                        <span class="quick-action-icon">📖</span>
                        <strong>Предметы</strong>
                    </a>
                </div>
            </div>

            <div class="card-grid">
                <!-- Расписание на сегодня -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">📅 Сегодняшние уроки</h2>
                        <a href="schedule.php" class="btn btn-primary">Все расписание</a>
                    </div>

                    <?php if (!empty($today_schedule)): ?>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($today_schedule as $lesson): ?>
                                <div class="lesson-card">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                        <div>
                                            <strong><?= htmlspecialchars($lesson['subject_name']) ?></strong>
                                            <div style="font-size: 0.9em; color: #666; margin-top: 4px;">
                                                <?= $lesson['lesson_number'] ?> урок | <?= htmlspecialchars($lesson['room']) ?>
                                            </div>
                                        </div>
                                        <span style="background: #3498db; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8em;">
                                                <?= $lesson['lesson_number'] ?> урок
                                            </span>
                                    </div>
                                    <div style="font-size: 0.8em; color: #7f8c8d;">
                                        Учитель: <?= htmlspecialchars($lesson['teacher_name']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="icon">🎉</div>
                            <h3>Уроков нет!</h3>
                            <p>Сегодня можно отдохнуть</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Домашние задания -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">📚 Ближайшие ДЗ</h2>
                        <a href="homework.php" class="btn btn-primary">Все задания</a>
                    </div>

                    <?php if (!empty($homework)): ?>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($homework as $hw):
                                $days_left = floor((strtotime($hw['due_date']) - time()) / (60 * 60 * 24));
                                $card_class = $days_left <= 1 ? 'urgent' : ($days_left <= 3 ? 'warning' : '');
                                ?>
                                <div class="homework-card <?= $card_class ?>">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                        <div>
                                            <strong><?= htmlspecialchars($hw['subject_name']) ?></strong>
                                            <div style="font-size: 0.9em; color: #666; margin-top: 4px;">
                                                До: <?= date('d.m.Y', strtotime($hw['due_date'])) ?>
                                            </div>
                                        </div>
                                        <?php if ($days_left <= 1): ?>
                                            <span style="background: #e74c3c; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8em;">
                                                    СРОЧНО
                                                </span>
                                        <?php elseif ($days_left <= 3): ?>
                                            <span style="background: #f39c12; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8em;">
                                                    Скоро
                                                </span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 0.9em; margin-top: 8px;">
                                        <?= nl2br(htmlspecialchars($hw['description'])) ?>
                                    </div>
                                    <div style="font-size: 0.8em; color: #7f8c8d; margin-top: 8px;">
                                        Учитель: <?= htmlspecialchars($hw['teacher_name']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="icon">✅</div>
                            <h3>Нет домашних заданий</h3>
                            <p>Все задания выполнены!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Последние оценки -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📈 Последние оценки</h2>
                    <a href="grades.php" class="btn btn-primary">Все оценки</a>
                </div>

                <?php if (!empty($recent_grades)): ?>
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Предмет</th>
                            <th>Оценка</th>
                            <th>Учитель</th>
                            <th>Дата</th>
                            <th>Тип</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_grades as $grade): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($grade['subject_name']) ?></strong>
                                </td>
                                <td>
                                            <span class="grade-badge grade-<?= $grade['grade_value'] ?>">
                                                <?= htmlspecialchars($grade['grade_value']) ?>
                                            </span>
                                </td>
                                <td><?= htmlspecialchars($grade['teacher_name']) ?></td>
                                <td><?= date('d.m.Y', strtotime($grade['created_at'])) ?></td>
                                <td>
                                            <span style="font-size: 0.8em; background: #e9ecef; padding: 2px 6px; border-radius: 3px;">
                                                Ответ на уроке
                                            </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">📝</div>
                        <h3>Оценок пока нет</h3>
                        <p>Оценки появятся здесь после уроков</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Успеваемость по предметам -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📊 Успеваемость по предметам</h2>
                </div>

                <?php
                // Получаем средние оценки по предметам
                $subject_grades = [];
                try {
                    $stmt = $pdo->prepare("
                            SELECT s.name as subject_name, 
                                SUM(CAST(g.grade_value AS DECIMAL) * COALESCE(gw.weight, 1)) / NULLIF(SUM(COALESCE(gw.weight, 1)), 0) as avg_grade,
                                   COUNT(g.id) as grade_count
                            FROM grades g
                            LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.is_active = 1
                            JOIN subjects s ON g.subject_id = s.id 
                            WHERE g.student_id = ? AND g.grade_value REGEXP '^[0-9]+$'
                            GROUP BY s.id, s.name 
                            ORDER BY avg_grade DESC
                        ");
                    $stmt->execute([$student_id]);
                    $subject_grades = $stmt->fetchAll();
                } catch (PDOException $e) {
                    error_log("Ошибка при получении оценок по предметам: " . $e->getMessage());
                }
                ?>

                <?php if (!empty($subject_grades)): ?>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($subject_grades as $subject): ?>
                            <div class="subject-grade">
                                <div style="flex: 1;">
                                    <strong><?= htmlspecialchars($subject['subject_name']) ?></strong>
                                    <div style="font-size: 0.8em; color: #666; margin-top: 2px;">
                                        <?= $subject['grade_count'] ?> оценок
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= ($subject['avg_grade'] / 5) * 100 ?>%"></div>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 1.2em; font-weight: bold; color: #2c3e50;">
                                        <?= round($subject['avg_grade'], 2) ?>
                                    </div>
                                    <div style="font-size: 0.8em; color: #7f8c8d;">
                                        средний балл
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">📊</div>
                        <h3>Нет данных по предметам</h3>
                        <p>Здесь появится статистика успеваемости</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
    // Автоматическое скрытие сообщений через 5 секунд
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        });
    }, 5000);

    // Анимация появления элементов
    document.addEventListener('DOMContentLoaded', function() {
        const elements = document.querySelectorAll('.stat-card, .lesson-card, .homework-card');
        elements.forEach((element, index) => {
            element.style.opacity = '0';
            element.style.transform = 'translateY(20px)';
            element.style.transition = 'all 0.5s ease';

            setTimeout(() => {
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });
</script>
</body>
</html>
