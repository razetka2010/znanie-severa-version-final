<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
require_once __DIR__ . '/../includes/grades_helper.php';
require_once __DIR__ . '/../includes/periods_helper.php';

requireStudent();

$pdo = getDatabaseConnection();
$student_id = $_SESSION['user_id'];
$school_id = $_SESSION['user_school_id'];
$school_periods = getSchoolPeriods($pdo, (int)$school_id);
$current_period = getCurrentPeriod($pdo, (int)$school_id);
$selected_period_id = isset($_GET['period_id']) ? (int)$_GET['period_id'] : (int)($current_period['id'] ?? 0);
$selected_period = null;
foreach ($school_periods as $period) {
    if ((int)$period['id'] === $selected_period_id) {
        $selected_period = $period;
        break;
    }
}

// Получаем информацию о ученике
$student_stmt = $pdo->prepare("
    SELECT u.*, c.name as class_name, c.grade_level 
    FROM users u 
    LEFT JOIN classes c ON u.class_id = c.id 
    WHERE u.id = ? AND u.school_id = ?
");
$student_stmt->execute([$student_id, $school_id]);
$student = $student_stmt->fetch();

// Получаем параметры фильтрации
$selected_subject = isset($_GET['subject']) ? intval($_GET['subject']) : 0;
$selected_month = isset($_GET['month']) ? $_GET['month'] : '';

// Получаем все предметы ученика
$subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id, s.name
        FROM subjects s
        JOIN schedule sch ON s.id = sch.subject_id AND s.school_id = sch.school_id
        WHERE sch.class_id = ? AND sch.school_id = ? AND s.school_id = ?
        ORDER BY s.name
    ");
    $stmt->execute([$student['class_id'], $school_id, $school_id]);
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
            gt.name AS grade_type_name,
            gt.default_weight_id,
            gw.name AS grade_weight_name,
            gw.weight AS grade_weight_value,
            default_gw.name AS default_weight_name,
            default_gw.weight AS default_weight_value,
            default_gw.is_active AS default_weight_active,
            DATE_FORMAT(g.lesson_date, '%Y-%m') as month
        FROM grades g 
        JOIN users student ON student.id = g.student_id AND student.school_id = ?
        JOIN subjects s ON g.subject_id = s.id AND s.school_id = student.school_id
        JOIN users u ON g.teacher_id = u.id AND u.school_id = student.school_id
        LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = student.school_id
        LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = student.school_id
        LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = student.school_id
        WHERE g.student_id = ?
    ";

    $params = [$school_id, $student_id];

    if ($selected_subject > 0) {
        $query .= " AND g.subject_id = ?";
        $params[] = $selected_subject;
    }

    if ($selected_period) {
        $query .= ' AND g.lesson_date BETWEEN ? AND ?';
        $params[] = $selected_period['start_date'];
        $params[] = $selected_period['end_date'];
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
                'numeric_grade_rows' => [],
                'average' => 0,
                'count' => 0
            ];
        }

        if (isNumericGrade($grade['grade_value'])) {
            $subject_grades[$subject_id]['grades'][] = $grade['grade_value'];
            $subject_grades[$subject_id]['numeric_grade_rows'][] = $grade;
        }
    }

    // Рассчитываем средние баллы по предметам
    foreach ($subject_grades as $subject_id => &$data) {
        $data['average'] = calculateWeightedAverage($data['numeric_grade_rows']) ?? 0;
        $data['count'] = count($data['numeric_grade_rows']);
    }

} catch (PDOException $e) {
    error_log("Ошибка при получении оценок: " . $e->getMessage());
}

// Загружаем отметки о пропусках и опозданиях для просмотра в истории.
$attendance_records = [];
try {
    $attendance_query = "
        SELECT a.lesson_date, a.status
        FROM attendance a
        JOIN users u ON u.id = a.student_id AND u.school_id = ?
        WHERE a.student_id = ? AND a.status IN ('absent', 'late')
    ";
    $attendance_params = [$school_id, $student_id];
    if ($selected_period) {
        $attendance_query .= ' AND a.lesson_date BETWEEN ? AND ?';
        $attendance_params[] = $selected_period['start_date'];
        $attendance_params[] = $selected_period['end_date'];
    }
    if ($selected_month !== '') {
        $attendance_query .= " AND DATE_FORMAT(a.lesson_date, '%Y-%m') = ?";
        $attendance_params[] = $selected_month;
    }
    $attendance_query .= ' ORDER BY a.lesson_date DESC';
    $attendance_stmt = $pdo->prepare($attendance_query);
    $attendance_stmt->execute($attendance_params);
    $attendance_records = $attendance_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Ошибка при получении посещаемости ученика: ' . $e->getMessage());
}

$attendance_by_date = [];
foreach ($attendance_records as $attendance_record) {
    $attendance_by_date[$attendance_record['lesson_date']] = $attendance_record['status'];
}

// Сводные оценки рассчитываются отдельно для каждого активного учебного периода.
$period_stats = [];
try {
    $period_stmt = $pdo->prepare("
        SELECT g.grade_value, g.grade_type_id, g.grade_weight_id, g.subject_id, g.lesson_date,
               gt.default_weight_id, gw.weight AS grade_weight_value,
               default_gw.weight AS default_weight_value,
               default_gw.is_active AS default_weight_active
        FROM grades g
        JOIN users u ON u.id = g.student_id AND u.school_id = ?
        LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = u.school_id
        LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = u.school_id
        LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = u.school_id
        WHERE g.student_id = ?
    ");
    $period_stmt->execute([$school_id, $student_id]);
    $period_grade_rows = $period_stmt->fetchAll();

    foreach ($school_periods as $period) {
        $rows = array_values(array_filter($period_grade_rows, static function ($grade) use ($period) {
            return $grade['lesson_date'] >= $period['start_date']
                && $grade['lesson_date'] <= $period['end_date']
                && isNumericGrade($grade['grade_value']);
        }));
        $period_subject_grades = [];
        foreach ($rows as $grade) {
            $period_subject_grades[$grade['subject_id']][] = $grade;
        }
        $period_subject_averages = [];
        foreach ($period_subject_grades as $subject_rows) {
            $period_subject_averages[] = calculateWeightedAverage($subject_rows);
        }

        $period_stats[(int)$period['id']] = [
            'total_grades' => count($rows),
            'average_grade' => calculateOverallAverage($period_subject_averages) ?? 0,
            'excellent' => count(array_filter($rows, static function ($grade) { return $grade['grade_value'] === '5'; })),
            'good' => count(array_filter($rows, static function ($grade) { return $grade['grade_value'] === '4'; })),
            'satisfactory' => count(array_filter($rows, static function ($grade) { return $grade['grade_value'] === '3'; })),
            'poor' => count(array_filter($rows, static function ($grade) { return $grade['grade_value'] === '2'; }))
        ];
    }
} catch (PDOException $e) {
    error_log("Ошибка при получении статистики по учебным периодам: " . $e->getMessage());
}

// Генерируем список месяцев для фильтра
$months = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT DATE_FORMAT(lesson_date, '%Y-%m') as month,
               DATE_FORMAT(lesson_date, '%M %Y') as month_name
        FROM grades g
        JOIN users u ON u.id = g.student_id AND u.school_id = ?
        WHERE g.student_id = ?
        ORDER BY g.lesson_date DESC
    ");
    $stmt->execute([$school_id, $student_id]);
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

        .attendance-record {
            display: inline-block;
            margin: 3px;
            font-weight: 700;
        }

        .attendance-record.absent {
            color: #dc3545;
        }

        .attendance-record.late {
            color: #c88700;
        }

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
    <?php $studentActivePage = 'grades'; require __DIR__ . '/../includes/student_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Мои оценки</h1>
                <p>Просмотр успеваемости и статистики</p>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= consumeFlashMessage('success_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?= consumeFlashMessage('error_message'); ?></div>
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
                        <label>Учебный период:</label>
                        <select name="period_id" onchange="this.form.submit()">
                            <option value="">Все периоды</option>
                            <?php foreach ($school_periods as $period): ?>
                                <option value="<?= (int)$period['id'] ?>" <?= $selected_period_id === (int)$period['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8') ?><?= $period['is_locked'] ? ' 🔒' : '' ?>
                                </option>
                            <?php endforeach; ?>
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
                        $total_grades = [];
                        $subject_averages = [];
                        foreach ($subject_grades as $subject_data) {
                            $total_grades = array_merge($total_grades, $subject_data['grades']);
                            if ($subject_data['count'] > 0) {
                                $subject_averages[] = $subject_data['average'];
                            }
                        }
                        $overall_average = calculateOverallAverage($subject_averages) ?? 0;
                        echo $overall_average ?: 'н/д';
                        ?>
                    </div>
                    <div class="stat-label">Общий средний балл</div>
                    <small>Средний балл учитывает вес оценок: контрольные важнее домашних.</small>
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

            <!-- Статистика по учебным периодам -->
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📅 Успеваемость по учебным периодам</h2>
                </div>

                <div class="quarters-grid">
                    <?php foreach ($school_periods as $period): ?>
                        <?php $stats = $period_stats[(int)$period['id']] ?? ['average_grade' => 0, 'total_grades' => 0]; ?>
                        <div class="quarter-card quarter-<?= (int)$period['id'] % 4 + 1 ?>">
                            <div class="quarter-title"><?= htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8') ?><?= $period['is_locked'] ? ' 🔒' : '' ?></div>
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
                    <?php endforeach; ?>
                    <?php if (!$school_periods): ?>
                        <p>Учебные периоды ещё не настроены.</p>
                    <?php endif; ?>
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
                                        $recent_grades = array_slice($data['numeric_grade_rows'], 0, 10);
                                        foreach ($recent_grades as $grade):
                                            $grade_title = 'Тип работы: ' . ($grade['grade_type_name'] ?: 'не указан')
                                                . '; вес: ' . number_format(effectiveGradeWeight($grade), 2, '.', '');
                                            ?>
                                            <span class="grade-badge grade-<?= htmlspecialchars(strtolower((string)$grade['grade_value']), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($grade_title, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$grade['grade_value'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($data['numeric_grade_rows']) > 10): ?>
                                            <span style="color: #7f8c8d; font-size: 0.9em;">+<?= count($data['numeric_grade_rows']) - 10 ?></span>
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

            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">📅 Посещаемость</h2>
                </div>
                <?php if ($attendance_records): ?>
                    <div class="grades-list">
                        <?php foreach ($attendance_records as $attendance_record): ?>
                            <span class="attendance-record <?= $attendance_record['status'] === 'absent' ? 'absent' : 'late' ?>">
                                <?= $attendance_record['status'] === 'absent' ? 'Н' : 'О' ?>
                                — <?= htmlspecialchars(date('d.m.Y', strtotime($attendance_record['lesson_date'])), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>Нет отметок о пропусках или опозданиях за выбранный период.</p>
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
                                <td>
                                    <?= htmlspecialchars(date('d.m.Y', strtotime($grade['lesson_date'])), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (($attendance_by_date[$grade['lesson_date']] ?? '') === 'absent'): ?>
                                        <span class="attendance-record absent" title="Отсутствовал">Н</span>
                                    <?php elseif (($attendance_by_date[$grade['lesson_date']] ?? '') === 'late'): ?>
                                        <span class="attendance-record late" title="Опоздал">О</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($grade['subject_name']) ?></td>
                                <td>
                                            <span class="grade-badge grade-<?= htmlspecialchars(strtolower((string)$grade['grade_value']), ENT_QUOTES, 'UTF-8') ?>">
                                                <?php
                                                $grade_title = 'Тип работы: ' . ($grade['grade_type_name'] ?: 'не указан')
                                                    . '; вес: ' . number_format(effectiveGradeWeight($grade), 2, '.', '');
                                                ?>
                                                <span title="<?= htmlspecialchars($grade_title, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars((string)$grade['grade_value'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
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
        const filters = document.querySelectorAll('select[name="subject"], select[name="period_id"], select[name="month"]');
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