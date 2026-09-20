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

// Получаем все предметы ученика с детальной статистикой
$subjects = [];
$overall_stats = [
    'total_subjects' => 0,
    'total_grades' => 0,
    'average_grade' => 0,
    'excellent_subjects' => 0,
    'good_subjects' => 0,
    'satisfactory_subjects' => 0
];

try {
    // Получаем предметы с расписания
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            s.id,
            s.name,
            s.description,
            s.color,
            COUNT(DISTINCT sch.id) as total_lessons,
            GROUP_CONCAT(DISTINCT u.full_name) as teachers
        FROM subjects s 
        JOIN schedule sch ON s.id = sch.subject_id 
        JOIN users u ON sch.teacher_id = u.id 
        WHERE sch.class_id = ? 
        GROUP BY s.id
        ORDER BY s.name
    ");
    $stmt->execute([$student['class_id']]);
    $subjects_data = $stmt->fetchAll();

    // Для каждого предмета получаем статистику оценок
    foreach ($subjects_data as $subject) {
        $grades_stmt = $pdo->prepare("
            SELECT 
                grade_value,
                COUNT(*) as count,
                lesson_date,
                COALESCE(gw.weight, 1) AS grade_weight
            FROM grades g 
            LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.is_active = 1
            WHERE g.student_id = ? AND g.subject_id = ?
            ORDER BY g.lesson_date DESC
        ");
        $grades_stmt->execute([$student_id, $subject['id']]);
        $grades = $grades_stmt->fetchAll();

        // Рассчитываем статистику по предмету
        $numeric_grades = array_filter($grades, function($grade) {
            return is_numeric($grade['grade_value']);
        });

        $grades_count = count($numeric_grades);
        $average_grade = 0;

        if ($grades_count > 0) {
            $weighted_sum = 0;
            $weight_total = 0;
            foreach ($numeric_grades as $numeric_grade) {
                $weight = (float)($numeric_grade['grade_weight'] ?? 1);
                $weighted_sum += (float)$numeric_grade['grade_value'] * $weight;
                $weight_total += $weight;
            }
            $average_grade = $weight_total > 0 ? round($weighted_sum / $weight_total, 2) : 0;
        }

        // Распределение оценок
        $grade_distribution = [
            '5' => 0,
            '4' => 0,
            '3' => 0,
            '2' => 0,
            'other' => 0
        ];

        foreach ($grades as $grade) {
            if (is_numeric($grade['grade_value'])) {
                $grade_value = $grade['grade_value'];
                if (isset($grade_distribution[$grade_value])) {
                    $grade_distribution[$grade_value]++;
                }
            } else {
                $grade_distribution['other']++;
            }
        }

        // Определяем статус предмета
        $status = 'no_grades';
        $status_color = '#95a5a6';

        if ($grades_count > 0) {
            if ($average_grade >= 4.5) {
                $status = 'excellent';
                $status_color = '#27ae60';
            } elseif ($average_grade >= 3.5) {
                $status = 'good';
                $status_color = '#3498db';
            } elseif ($average_grade >= 2.5) {
                $status = 'satisfactory';
                $status_color = '#f39c12';
            } else {
                $status = 'poor';
                $status_color = '#e74c3c';
            }
        }

        $subjects[] = [
            'id' => $subject['id'],
            'name' => $subject['name'],
            'description' => $subject['description'],
            'color' => $subject['color'],
            'teachers' => $subject['teachers'],
            'total_lessons' => $subject['total_lessons'],
            'grades_count' => $grades_count,
            'average_grade' => $average_grade,
            'grade_distribution' => $grade_distribution,
            'recent_grades' => array_slice($grades, 0, 5),
            'status' => $status,
            'status_color' => $status_color
        ];
    }

    // Рассчитываем общую статистику
    $overall_stats['total_subjects'] = count($subjects);

    $all_grades = [];
    foreach ($subjects as $subject) {
        $overall_stats['total_grades'] += $subject['grades_count'];

        if ($subject['grades_count'] > 0) {
            $all_grades[] = $subject['average_grade'];

            switch ($subject['status']) {
                case 'excellent': $overall_stats['excellent_subjects']++; break;
                case 'good': $overall_stats['good_subjects']++; break;
                case 'satisfactory': $overall_stats['satisfactory_subjects']++; break;
            }
        }
    }

    if (!empty($all_grades)) {
        $overall_stats['average_grade'] = round(array_sum($all_grades) / count($all_grades), 2);
    }

} catch (PDOException $e) {
    error_log("Ошибка при получении данных о предметах: " . $e->getMessage());
}

// Получаем расписание по предметам
$subject_schedule = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            s.id as subject_id,
            s.name as subject_name,
            sch.lesson_date,
            sch.lesson_number,
            sch.room,
            u.full_name as teacher_name,
            s.color
        FROM schedule sch 
        JOIN subjects s ON sch.subject_id = s.id 
        JOIN users u ON sch.teacher_id = u.id 
        WHERE sch.class_id = ? AND sch.lesson_date >= CURDATE()
        ORDER BY sch.lesson_date ASC, sch.lesson_number ASC
        LIMIT 30
    ");
    $stmt->execute([$student['class_id']]);
    $schedule_data = $stmt->fetchAll();

    // Группируем по предметам
    foreach ($schedule_data as $lesson) {
        $subject_id = $lesson['subject_id'];
        if (!isset($subject_schedule[$subject_id])) {
            $subject_schedule[$subject_id] = [];
        }
        $subject_schedule[$subject_id][] = $lesson;
    }
} catch (PDOException $e) {
    error_log("Ошибка при получении расписания: " . $e->getMessage());
}

// Получаем домашние задания по предметам
$subject_homework = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            h.subject_id,
            s.name as subject_name,
            COUNT(*) as homework_count,
            SUM(CASE WHEN h.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
            SUM(CASE WHEN h.due_date = CURDATE() THEN 1 ELSE 0 END) as today_count,
            SUM(CASE WHEN h.due_date = CURDATE() + INTERVAL 1 DAY THEN 1 ELSE 0 END) as tomorrow_count
        FROM homework h 
        JOIN subjects s ON h.subject_id = s.id 
        WHERE h.class_id = ? AND h.due_date >= CURDATE() - INTERVAL 7 DAY
        GROUP BY h.subject_id
    ");
    $stmt->execute([$student['class_id']]);
    $homework_data = $stmt->fetchAll();

    foreach ($homework_data as $hw) {
        $subject_homework[$hw['subject_id']] = $hw;
    }
} catch (PDOException $e) {
    error_log("Ошибка при получении домашних заданий: " . $e->getMessage());
}

// Время уроков
$lesson_times = [
    1 => '08:30-09:15',
    2 => '09:25-10:10',
    3 => '10:25-11:10',
    4 => '11:25-12:10',
    5 => '12:20-13:05',
    6 => '13:15-14:00',
    7 => '14:10-14:55',
    8 => '15:05-15:50'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Предметы - Ученик</title>
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

        /* Общая статистика */
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

        .stat-card.total { border-left-color: #3498db; }
        .stat-card.average { border-left-color: #9b59b6; }
        .stat-card.excellent { border-left-color: #27ae60; }
        .stat-card.good { border-left-color: #3498db; }

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

        /* Фильтры */
        .filters-section {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }

        .status-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .status-filter {
            padding: 8px 16px;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }

        .status-filter.active {
            background: #3498db;
            color: white;
            border-color: #3498db;
        }

        .status-filter:hover:not(.active) {
            border-color: #3498db;
            color: #3498db;
        }

        /* Сетка предметов */
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .subject-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: all 0.3s ease;
            border-top: 4px solid;
        }

        .subject-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .subject-header {
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .subject-title {
            font-size: 1.3em;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .subject-average {
            font-size: 1.8em;
            font-weight: bold;
            text-align: right;
        }

        .subject-average.excellent { color: #27ae60; }
        .subject-average.good { color: #3498db; }
        .subject-average.satisfactory { color: #f39c12; }
        .subject-average.poor { color: #e74c3c; }
        .subject-average.no-grades { color: #95a5a6; }

        .subject-meta {
            padding: 0 20px;
            margin-bottom: 15px;
        }

        .subject-teachers {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 8px;
        }

        .subject-stats {
            display: flex;
            gap: 15px;
            font-size: 0.85em;
            color: #6c757d;
        }

        .subject-body {
            padding: 0 20px 20px;
        }

        /* Распределение оценок */
        .grades-distribution {
            margin-bottom: 15px;
        }

        .distribution-title {
            font-size: 0.9em;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
        }

        .distribution-bars {
            display: flex;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            background: #f8f9fa;
        }

        .distribution-bar {
            height: 100%;
            transition: all 0.3s;
            position: relative;
        }

        .distribution-bar:hover {
            filter: brightness(1.1);
        }

        .bar-5 { background: #27ae60; }
        .bar-4 { background: #3498db; }
        .bar-3 { background: #f39c12; }
        .bar-2 { background: #e74c3c; }
        .bar-other { background: #95a5a6; }

        .distribution-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.7em;
            color: #7f8c8d;
            margin-top: 5px;
        }

        /* Последние оценки */
        .recent-grades {
            margin-bottom: 15px;
        }

        .grades-title {
            font-size: 0.9em;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
        }

        .grades-list {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 0.9em;
            border: 2px solid;
        }

        .grade-5 { background: #d4edda; color: #155724; border-color: #28a745; }
        .grade-4 { background: #d1ecf1; color: #0c5460; border-color: #17a2b8; }
        .grade-3 { background: #fff3cd; color: #856404; border-color: #ffc107; }
        .grade-2 { background: #f8d7da; color: #721c24; border-color: #dc3545; }
        .grade-other { background: #e2e3e5; color: #383d41; border-color: #6c757d; }

        /* Действия */
        .subject-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }

        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8em;
            font-weight: 500;
            transition: all 0.3s;
            gap: 4px;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-outline {
            background: transparent;
            border: 1px solid #3498db;
            color: #3498db;
        }

        .btn-outline:hover {
            background: #3498db;
            color: white;
        }

        /* Расписание предметов */
        .schedule-section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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

        .schedule-list {
            display: grid;
            gap: 15px;
        }

        .schedule-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid;
            transition: transform 0.2s;
        }

        .schedule-item:hover {
            transform: translateX(5px);
        }

        .schedule-subject {
            font-weight: 600;
            color: #2c3e50;
        }

        .schedule-details {
            display: flex;
            gap: 15px;
            color: #7f8c8d;
            font-size: 0.9em;
        }

        .schedule-date {
            text-align: right;
            font-weight: 600;
            color: #2c3e50;
        }

        /* Домашние задания */
        .homework-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7em;
            font-weight: bold;
            margin-left: 8px;
        }

        .homework-normal { background: #3498db; color: white; }
        .homework-urgent { background: #e67e22; color: white; }
        .homework-overdue { background: #e74c3c; color: white; }

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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .subjects-grid {
                grid-template-columns: 1fr;
            }

            .status-filters {
                flex-direction: column;
            }

            .subject-header {
                flex-direction: column;
                gap: 10px;
            }

            .subject-average {
                text-align: left;
            }

            .schedule-item {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }

            .schedule-details {
                flex-direction: column;
                gap: 5px;
            }

            .schedule-date {
                text-align: left;
            }

            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }

        .progress-ring {
            transform: rotate(-90deg);
        }

        .progress-bg {
            fill: none;
            stroke: #f0f0f0;
        }

        .progress-fill {
            fill: none;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.3s;
        }

        .progress-text {
            font-size: 0.8em;
            font-weight: bold;
            fill: #2c3e50;
        }

        .no-grades-message {
            text-align: center;
            color: #7f8c8d;
            font-style: italic;
            padding: 20px;
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
                <li><a href="schedule.php" class="nav-link">📅 Расписание</a></li>
                <li><a href="homework.php" class="nav-link">📚 Домашние задания</a></li>
                <li><a href="subjects.php" class="nav-link active">📖 Предметы</a></li>
                <li class="nav-section">Общее</li>
                <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Мои предметы</h1>
                <p>Обзор успеваемости по всем предметам</p>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <!-- Общая статистика -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?= $overall_stats['total_subjects'] ?></div>
                    <div class="stat-label">Всего предметов</div>
                </div>

                <div class="stat-card average">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number"><?= $overall_stats['average_grade'] ?: 'н/д' ?></div>
                    <div class="stat-label">Средний балл</div>
                </div>

                <div class="stat-card excellent">
                    <div class="stat-icon">⭐</div>
                    <div class="stat-number"><?= $overall_stats['excellent_subjects'] ?></div>
                    <div class="stat-label">Отличных предметов</div>
                </div>

                <div class="stat-card good">
                    <div class="stat-icon">👍</div>
                    <div class="stat-number"><?= $overall_stats['good_subjects'] ?></div>
                    <div class="stat-label">Хороших предметов</div>
                </div>
            </div>

            <!-- Фильтры -->
            <div class="filters-section">
                <div class="status-filters">
                    <button class="status-filter active" onclick="filterSubjects('all')">📚 Все предметы</button>
                    <button class="status-filter" onclick="filterSubjects('excellent')">⭐ Отличные</button>
                    <button class="status-filter" onclick="filterSubjects('good')">👍 Хорошие</button>
                    <button class="status-filter" onclick="filterSubjects('satisfactory')">⚠️ Удовлетворительные</button>
                    <button class="status-filter" onclick="filterSubjects('no_grades')">📝 Без оценок</button>
                </div>
            </div>

            <!-- Сетка предметов -->
            <div class="subjects-grid" id="subjectsGrid">
                <?php foreach ($subjects as $subject): ?>
                    <div class="subject-card" data-status="<?= $subject['status'] ?>" style="border-top-color: <?= $subject['status_color'] ?>">
                        <!-- Заголовок предмета -->
                        <div class="subject-header">
                            <div>
                                <div class="subject-title">
                                    <?= htmlspecialchars($subject['name']) ?>
                                    <?php if (isset($subject_homework[$subject['id']])):
                                        $hw = $subject_homework[$subject['id']];
                                        $badge_class = 'homework-normal';
                                        if ($hw['overdue_count'] > 0) $badge_class = 'homework-overdue';
                                        elseif ($hw['today_count'] > 0 || $hw['tomorrow_count'] > 0) $badge_class = 'homework-urgent';
                                        ?>
                                        <span class="homework-badge <?= $badge_class ?>">
                                                📚 <?= $hw['homework_count'] ?>
                                            </span>
                                    <?php endif; ?>
                                </div>
                                <div class="subject-teachers">
                                    👨‍🏫 <?= htmlspecialchars($subject['teachers']) ?>
                                </div>
                            </div>
                            <div class="subject-average <?= $subject['status'] ?>">
                                <?= $subject['average_grade'] ?: '—' ?>
                            </div>
                        </div>

                        <!-- Мета-информация -->
                        <div class="subject-meta">
                            <div class="subject-stats">
                                <span>📝 <?= $subject['grades_count'] ?> оценок</span>
                                <span>📅 <?= $subject['total_lessons'] ?> уроков</span>
                            </div>
                        </div>

                        <!-- Тело карточки -->
                        <div class="subject-body">
                            <!-- Распределение оценок -->
                            <?php if ($subject['grades_count'] > 0): ?>
                                <div class="grades-distribution">
                                    <div class="distribution-title">Распределение оценок:</div>
                                    <div class="distribution-bars">
                                        <?php
                                        $total_grades = array_sum($subject['grade_distribution']);
                                        $grades_to_show = ['5', '4', '3', '2'];

                                        foreach ($grades_to_show as $grade):
                                            $count = $subject['grade_distribution'][$grade];
                                            $width = $total_grades > 0 ? ($count / $total_grades) * 100 : 0;
                                            ?>
                                            <div class="distribution-bar bar-<?= $grade ?>"
                                                 style="width: <?= $width ?>%"
                                                 title="<?= $grade ?>: <?= $count ?> оценок">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="distribution-labels">
                                        <span>5</span>
                                        <span>4</span>
                                        <span>3</span>
                                        <span>2</span>
                                    </div>
                                </div>

                                <!-- Последние оценки -->
                                <div class="recent-grades">
                                    <div class="grades-title">Последние оценки:</div>
                                    <div class="grades-list">
                                        <?php foreach ($subject['recent_grades'] as $grade):
                                            $grade_class = is_numeric($grade['grade_value']) ? "grade-{$grade['grade_value']}" : 'grade-other';
                                            ?>
                                            <span class="grade-badge <?= $grade_class ?>" title="<?= date('d.m.Y', strtotime($grade['lesson_date'])) ?>">
                                                    <?= $grade['grade_value'] ?>
                                                </span>
                                        <?php endforeach; ?>

                                        <?php if ($subject['grades_count'] > 5): ?>
                                            <span style="color: #7f8c8d; font-size: 0.8em;">
                                                    +<?= $subject['grades_count'] - 5 ?>
                                                </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="no-grades-message">
                                    📝 Оценок пока нет
                                </div>
                            <?php endif; ?>

                            <!-- Действия -->
                            <div class="subject-actions">
                                <a href="grades.php?subject=<?= $subject['id'] ?>" class="btn btn-primary">
                                    📊 Все оценки
                                </a>
                                <a href="homework.php?subject=<?= $subject['id'] ?>" class="btn btn-outline">
                                    📚 Домашние задания
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Ближайшие уроки -->
            <div class="schedule-section">
                <div class="section-header">
                    <h2 class="section-title">📅 Ближайшие уроки</h2>
                    <a href="schedule.php" class="btn btn-primary">📋 Все расписание</a>
                </div>

                <?php if (!empty($schedule_data)): ?>
                    <div class="schedule-list">
                        <?php foreach ($schedule_data as $lesson): ?>
                            <div class="schedule-item" style="border-left-color: <?= $lesson['color'] ?>">
                                <div>
                                    <div class="schedule-subject">
                                        <?= htmlspecialchars($lesson['subject_name']) ?>
                                    </div>
                                    <div class="schedule-details">
                                        <span>👨‍🏫 <?= htmlspecialchars($lesson['teacher_name']) ?></span>
                                        <span>🕐 <?= $lesson_times[$lesson['lesson_number']] ?? '?' ?> урок</span>
                                        <span>🚪 <?= htmlspecialchars($lesson['room']) ?></span>
                                    </div>
                                </div>
                                <div class="schedule-date">
                                    <?= date('d.m.Y', strtotime($lesson['lesson_date'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">📅</div>
                        <h3>Нет предстоящих уроков</h3>
                        <p>Расписание на ближайшее время не найдено</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
    function filterSubjects(status) {
        const subjects = document.querySelectorAll('.subject-card');
        const filters = document.querySelectorAll('.status-filter');

        // Обновляем активный фильтр
        filters.forEach(filter => {
            if (filter.textContent.includes(getFilterText(status))) {
                filter.classList.add('active');
            } else {
                filter.classList.remove('active');
            }
        });

        // Показываем/скрываем предметы
        subjects.forEach(subject => {
            if (status === 'all' || subject.getAttribute('data-status') === status) {
                subject.style.display = 'block';
            } else {
                subject.style.display = 'none';
            }
        });
    }

    function getFilterText(status) {
        const texts = {
            'all': 'Все предметы',
            'excellent': 'Отличные',
            'good': 'Хорошие',
            'satisfactory': 'Удовлетворительные',
            'no_grades': 'Без оценок'
        };
        return texts[status] || 'Все предметы';
    }

    // Анимация появления карточек
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.subject-card');
        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';

            setTimeout(() => {
                card.style.transition = 'all 0.5s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, index * 100);
        });
    });

    // Подсветка при наведении на распределение оценок
    document.addEventListener('DOMContentLoaded', function() {
        const bars = document.querySelectorAll('.distribution-bar');
        bars.forEach(bar => {
            bar.addEventListener('mouseenter', function() {
                const allBars = this.parentElement.querySelectorAll('.distribution-bar');
                allBars.forEach(b => b.style.opacity = '0.6');
                this.style.opacity = '1';
            });

            bar.addEventListener('mouseleave', function() {
                const allBars = this.parentElement.querySelectorAll('.distribution-bar');
                allBars.forEach(b => b.style.opacity = '1');
            });
        });
    });
</script>
</body>
</html>