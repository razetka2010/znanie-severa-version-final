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
$selected_subject = isset($_GET['subject']) ? intval($_GET['subject']) : 0;
$selected_quarter = isset($_GET['quarter']) ? intval($_GET['quarter']) : 0;
$selected_month = isset($_GET['month']) ? $_GET['month'] : '';

// Получаем все предметы ученика
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id, s.name 
        FROM subjects s 
        JOIN schedule sch ON s.id = sch.subject_id 
        WHERE sch.class_id = ? 
        ORDER BY s.name
    ");
    $stmt->execute([$student['class_id']]);
    $subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении предметов: " . $e->getMessage());
}

// Получаем оценки ученика
$grades = [];
$subject_grades = [];
$quarter_grades = [];

try {
    $query = "
        SELECT 
            g.*, 
            s.name as subject_name,
            s.id as subject_id,
            u.full_name as teacher_name,
            COALESCE(gw.weight, 1) AS grade_weight,
            DATE_FORMAT(g.lesson_date, '%Y-%m') as month,
            CASE 
                WHEN MONTH(g.lesson_date) BETWEEN 9 AND 12 THEN 1
                WHEN MONTH(g.lesson_date) BETWEEN 1 AND 3 THEN 2
                WHEN MONTH(g.lesson_date) BETWEEN 4 AND 5 THEN 3
                WHEN MONTH(g.lesson_date) BETWEEN 6 AND 8 THEN 4
                ELSE 0
            END as quarter
        FROM grades g 
        JOIN subjects s ON g.subject_id = s.id 
        JOIN users u ON g.teacher_id = u.id 
        LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.is_active = 1
        WHERE g.student_id = ? 
    ";

    $params = [$student_id];

    if ($selected_subject > 0) {
        $query .= " AND g.subject_id = ?";
        $params[] = $selected_subject;
    }

    if ($selected_quarter > 0) {
        $query .= " AND CASE 
            WHEN MONTH(g.lesson_date) BETWEEN 9 AND 12 THEN 1
            WHEN MONTH(g.lesson_date) BETWEEN 1 AND 3 THEN 2
            WHEN MONTH(g.lesson_date) BETWEEN 4 AND 5 THEN 3
            WHEN MONTH(g.lesson_date) BETWEEN 6 AND 8 THEN 4
            ELSE 0
        END = ?";
        $params[] = $selected_quarter;
    }

    if ($selected_month) {
        $query .= " AND DATE_FORMAT(g.lesson_date, '%Y-%m') = ?";
        $params[] = $selected_month;
    }

    $query .= " ORDER BY g.lesson_date DESC, g.created_at DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $grades = $stmt->fetchAll();

    // Группируем оценки по предметам для статистики
    foreach ($grades as $grade) {
        $subject_id = $grade['subject_id'];
        if (!isset($subject_grades[$subject_id])) {
            $subject_grades[$subject_id] = [
                'subject_name' => $grade['subject_name'],
                'grades' => [],
                'weighted_grades' => [],
                'average' => 0,
                'count' => 0
            ];
        }

        if (is_numeric($grade['grade_value'])) {
            $subject_grades[$subject_id]['grades'][] = $grade['grade_value'];
            $subject_grades[$subject_id]['weighted_grades'][] = [
                'value' => (float)$grade['grade_value'],
                'weight' => (float)$grade['grade_weight']
            ];
        }
    }

    // Рассчитываем средние баллы по предметам
    foreach ($subject_grades as $subject_id => &$data) {
        if (!empty($data['grades'])) {
            $weighted_sum = 0;
            $weight_total = 0;
            foreach ($data['weighted_grades'] as $weighted_grade) {
                $weighted_sum += $weighted_grade['value'] * $weighted_grade['weight'];
                $weight_total += $weighted_grade['weight'];
            }
            $data['average'] = $weight_total > 0 ? round($weighted_sum / $weight_total, 2) : 0;
            $data['count'] = count($data['grades']);
        }
    }

    // Группируем оценки по четвертям
    foreach ($grades as $grade) {
        $quarter = $grade['quarter'];
        if (!isset($quarter_grades[$quarter])) {
            $quarter_grades[$quarter] = [];
        }
        $quarter_grades[$quarter][] = $grade;
    }

} catch (PDOException $e) {
    error_log("Ошибка при получении оценок: " . $e->getMessage());
}

// Получаем статистику по четвертям
$quarter_stats = [];
try {
    for ($q = 1; $q <= 4; $q++) {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_grades,
                AVG(CAST(grade_value AS DECIMAL)) as average_grade,
                COUNT(CASE WHEN grade_value = '5' THEN 1 END) as excellent,
                COUNT(CASE WHEN grade_value = '4' THEN 1 END) as good,
                COUNT(CASE WHEN grade_value = '3' THEN 1 END) as satisfactory,
                COUNT(CASE WHEN grade_value = '2' THEN 1 END) as poor
            FROM grades 
            WHERE student_id = ? 
            AND (
                CASE 
                    WHEN MONTH(lesson_date) BETWEEN 9 AND 12 THEN 1
                    WHEN MONTH(lesson_date) BETWEEN 1 AND 3 THEN 2
                    WHEN MONTH(lesson_date) BETWEEN 4 AND 5 THEN 3
                    WHEN MONTH(lesson_date) BETWEEN 6 AND 8 THEN 4
                    ELSE 0
                END = ?
            )
            AND grade_value REGEXP '^[0-9]+$'
        ");
        $stmt->execute([$student_id, $q]);
        $stats = $stmt->fetch();

        $quarter_stats[$q] = [
            'total_grades' => $stats['total_grades'] ?? 0,
            'average_grade' => round($stats['average_grade'] ?? 0, 2),
            'excellent' => $stats['excellent'] ?? 0,
            'good' => $stats['good'] ?? 0,
            'satisfactory' => $stats['satisfactory'] ?? 0,
            'poor' => $stats['poor'] ?? 0
        ];
    }
} catch (PDOException $e) {
    error_log("Ошибка при получении статистики по четвертям: " . $e->getMessage());
}

// Генерируем список месяцев для фильтра
$months = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT DATE_FORMAT(lesson_date, '%Y-%m') as month,
               DATE_FORMAT(lesson_date, '%M %Y') as month_name
        FROM grades 
        WHERE student_id = ? 
        ORDER BY lesson_date DESC
    ");
    $stmt->execute([$student_id]);
    $months = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении месяцев: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои оценки - Ученик</title>
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

        /* Фильтры */
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

        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9em;
            transition: border-color 0.3s;
            background: white;
        }

        .form-group select:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
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
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
            border-left: 4px solid;
        }

        .stat-card:hover {
            transform: translateY(-3px);
        }

        .stat-card.primary { border-left-color: #3498db; }
        .stat-card.success { border-left-color: #27ae60; }
        .stat-card.warning { border-left-color: #f39c12; }
        .stat-card.info { border-left-color: #9b59b6; }

        .stat-icon {
            font-size: 2em;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 1.8em;
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

        /* Оценки */
        .grade-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9em;
            min-width: 40px;
            text-align: center;
        }

        .grade-5 { background: #d4edda; color: #155724; border: 2px solid #28a745; }
        .grade-4 { background: #d1ecf1; color: #0c5460; border: 2px solid #17a2b8; }
        .grade-3 { background: #fff3cd; color: #856404; border: 2px solid #ffc107; }
        .grade-2 { background: #f8d7da; color: #721c24; border: 2px solid #dc3545; }
        .grade-ot { background: #e2e3e5; color: #383d41; border: 2px solid #6c757d; }

        /* Предметы */
        .subjects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .subject-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
            transition: transform 0.3s;
        }

        .subject-card:hover {
            transform: translateY(-3px);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .subject-name {
            font-size: 1.1em;
            font-weight: 600;
            color: #2c3e50;
        }

        .subject-average {
            font-size: 1.3em;
            font-weight: bold;
            color: #3498db;
        }

        .grades-list {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 10px;
        }

        .progress-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            height: 100%;
            background: #3498db;
            border-radius: 4px;
            transition: width 0.3s;
        }

        /* Четверти */
        .quarters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .quarter-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border-top: 4px solid;
        }

        .quarter-1 { border-top-color: #3498db; }
        .quarter-2 { border-top-color: #27ae60; }
        .quarter-3 { border-top-color: #f39c12; }
        .quarter-4 { border-top-color: #9b59b6; }

        .quarter-title {
            font-size: 1.1em;
            font-weight: 600;
            margin-bottom: 15px;
            color: #2c3e50;
        }

        .quarter-average {
            font-size: 2em;
            font-weight: bold;
            margin: 10px 0;
        }

        .quarter-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 15px;
        }

        .quarter-stat {
            text-align: center;
        }

        .quarter-stat-number {
            font-size: 1.2em;
            font-weight: bold;
            color: #2c3e50;
        }

        .quarter-stat-label {
            font-size: 0.8em;
            color: #7f8c8d;
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

            .subjects-grid {
                grid-template-columns: 1fr;
            }

            .quarters-grid {
                grid-template-columns: 1fr;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }

        .grade-details {
            font-size: 0.8em;
            color: #7f8c8d;
            margin-top: 2px;
        }

        .grade-type {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.7em;
            color: #6c757d;
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
                <li><a href="grades.php" class="nav-link active">📝 Мои оценки</a></li>
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
                <h1>Мои оценки</h1>
                <p>Просмотр успеваемости и статистики</p>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <!-- Фильтры -->
            <div class="filters-section">
                <form method="GET" class="filters-grid">
                    <div class="form-group">
                        <label>Предмет:</label>
                        <select name="subject" onchange="this.form.submit()">
                            <option value="">Все предметы</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $selected_subject == $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Четверть:</label>
                        <select name="quarter" onchange="this.form.submit()">
                            <option value="">Все четверти</option>
                            <option value="1" <?= $selected_quarter == 1 ? 'selected' : '' ?>>1 четверть</option>
                            <option value="2" <?= $selected_quarter == 2 ? 'selected' : '' ?>>2 четверть</option>
                            <option value="3" <?= $selected_quarter == 3 ? 'selected' : '' ?>>3 четверть</option>
                            <option value="4" <?= $selected_quarter == 4 ? 'selected' : '' ?>>4 четверть</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Месяц:</label>
                        <select name="month" onchange="this.form.submit()">
                            <option value="">Все месяцы</option>
                            <?php foreach ($months as $month): ?>
                                <option value="<?= $month['month'] ?>" <?= $selected_month == $month['month'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($month['month_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <a href="grades.php" class="btn btn-secondary">Сбросить фильтры</a>
                    </div>
                </form>
            </div>

            <!-- Общая статистика -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="stat-icon">📊</div>
                    <div class="stat-number">
                        <?php
                        $total_grades = array_merge(...array_column($subject_grades, 'grades'));
                        $overall_average = !empty($total_grades) ? round(array_sum($total_grades) / count($total_grades), 2) : 0;
                        echo $overall_average ?: 'н/д';
                        ?>
                    </div>
                    <div class="stat-label">Общий средний балл</div>
                </div>

                <div class="stat-card success">
                    <div class="stat-icon">📝</div>
                    <div class="stat-number"><?= count($grades) ?></div>
                    <div class="stat-label">Всего оценок</div>
                </div>

                <div class="stat-card warning">
                    <div class="stat-icon">📚</div>
                    <div class="stat-number"><?= count($subject_grades) ?></div>
                    <div class="stat-label">Предметов</div>
                </div>

                <div class="stat-card info">
                    <div class="stat-icon">⭐</div>
                    <div class="stat-number">
                        <?= count(array_filter($total_grades, function($grade) { return $grade == 5; })) ?>
                    </div>
                    <div class="stat-label">Пятерок</div>
                </div>
            </div>

            <!-- Статистика по четвертям -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📅 Успеваемость по четвертям</h2>
                </div>

                <div class="quarters-grid">
                    <?php for ($q = 1; $q <= 4; $q++): ?>
                        <?php $stats = $quarter_stats[$q] ?? ['average_grade' => 0, 'total_grades' => 0]; ?>
                        <div class="quarter-card quarter-<?= $q ?>">
                            <div class="quarter-title"><?= $q ?> четверть</div>
                            <div class="quarter-average" style="color: <?= $stats['average_grade'] >= 4.5 ? '#27ae60' : ($stats['average_grade'] >= 3.5 ? '#f39c12' : '#e74c3c') ?>">
                                <?= $stats['average_grade'] ?: 'н/д' ?>
                            </div>
                            <div class="quarter-stats">
                                <div class="quarter-stat">
                                    <div class="quarter-stat-number"><?= $stats['excellent'] ?? 0 ?></div>
                                    <div class="quarter-stat-label">5</div>
                                </div>
                                <div class="quarter-stat">
                                    <div class="quarter-stat-number"><?= $stats['good'] ?? 0 ?></div>
                                    <div class="quarter-stat-label">4</div>
                                </div>
                                <div class="quarter-stat">
                                    <div class="quarter-stat-number"><?= $stats['satisfactory'] ?? 0 ?></div>
                                    <div class="quarter-stat-label">3</div>
                                </div>
                                <div class="quarter-stat">
                                    <div class="quarter-stat-number"><?= $stats['poor'] ?? 0 ?></div>
                                    <div class="quarter-stat-label">2</div>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Оценки по предметам -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📚 Оценки по предметам</h2>
                    <span class="btn btn-secondary" style="cursor: default;">
                            Предметов: <?= count($subject_grades) ?>
                        </span>
                </div>

                <?php if (!empty($subject_grades)): ?>
                    <div class="subjects-grid">
                        <?php foreach ($subject_grades as $subject_id => $data): ?>
                            <div class="subject-card">
                                <div class="subject-header">
                                    <div class="subject-name"><?= htmlspecialchars($data['subject_name']) ?></div>
                                    <div class="subject-average" style="color: <?= $data['average'] >= 4.5 ? '#27ae60' : ($data['average'] >= 3.5 ? '#f39c12' : '#e74c3c') ?>">
                                        <?= $data['average'] ?: 'н/д' ?>
                                    </div>
                                </div>

                                <div>Оценок: <?= $data['count'] ?></div>

                                <?php if ($data['count'] > 0): ?>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= min(($data['average'] / 5) * 100, 100) ?>%"></div>
                                    </div>

                                    <div class="grades-list">
                                        <?php
                                        $recent_grades = array_slice($data['grades'], 0, 10);
                                        foreach ($recent_grades as $grade):
                                            ?>
                                            <span class="grade-badge grade-<?= $grade ?>"><?= $grade ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($data['grades']) > 10): ?>
                                            <span style="color: #7f8c8d; font-size: 0.9em;">+<?= count($data['grades']) - 10 ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="color: #7f8c8d; font-style: italic;">Нет оценок</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">📚</div>
                        <h3>Нет оценок</h3>
                        <p>Оценки появятся здесь после их выставления учителями</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- История оценок -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📋 История оценок</h2>
                    <span class="btn btn-secondary" style="cursor: default;">
                            Всего: <?= count($grades) ?>
                        </span>
                </div>

                <?php if (!empty($grades)): ?>
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Предмет</th>
                            <th>Оценка</th>
                            <th>Учитель</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($grades as $grade): ?>
                            <tr>
                                <td><?= date('d.m.Y', strtotime($grade['lesson_date'])) ?></td>
                                <td><?= htmlspecialchars($grade['subject_name']) ?></td>
                                <td>
                                            <span class="grade-badge grade-<?= strtolower($grade['grade_value']) ?>">
                                                <?= $grade['grade_value'] ?>
                                            </span>
                                </td>
                                <td><?= htmlspecialchars($grade['teacher_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="icon">📝</div>
                        <h3>Нет оценок</h3>
                        <p>Выберите другие параметры фильтрации или дождитесь выставления оценок</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<script>
    // Функция для показа деталей оценки
    function showGradeDetails(gradeId) {
        // Здесь можно реализовать модальное окно с детальной информацией об оценке
        console.log('Показать детали оценки:', gradeId);
    }

    // Автоматическое обновление страницы при изменении фильтров (если нужно)
    document.addEventListener('DOMContentLoaded', function() {
        const filters = document.querySelectorAll('select[name="subject"], select[name="quarter"], select[name="month"]');
        filters.forEach(filter => {
            filter.addEventListener('change', function() {
                // Можно добавить индикатор загрузки
                this.form.submit();
            });
        });
    });
</script>
</body>
</html>