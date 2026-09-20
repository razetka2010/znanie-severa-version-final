<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

requireTeacher();

$pdo = getDatabaseConnection();
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['user_school_id'];

// Совместимость с существующей базой: связываем оценки с настройками школы.
try {
    $grade_type_column = $pdo->query("SHOW COLUMNS FROM grades LIKE 'grade_type_id'")->fetch();
    if (!$grade_type_column) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN grade_type_id INT NULL AFTER grade_value");
    }
    $grade_weight_column = $pdo->query("SHOW COLUMNS FROM grades LIKE 'grade_weight_id'")->fetch();
    if (!$grade_weight_column) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN grade_weight_id INT NULL AFTER grade_type_id");
    }
} catch (PDOException $e) {
    error_log('Ошибка обновления структуры grades: ' . $e->getMessage());
}

// Создаем таблицы если их нет
try {
    // Таблица предметов
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS subjects (
            id INT PRIMARY KEY AUTO_INCREMENT,
            school_id INT NOT NULL,
            name VARCHAR(100) NOT NULL,
            short_name VARCHAR(20),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (school_id) REFERENCES schools(id)
        )
    ");

    // Добавляем базовые предметы если их нет
    $check_subjects = $pdo->prepare("SELECT COUNT(*) as count FROM subjects WHERE school_id = ?");
    $check_subjects->execute([$school_id]);
    $subjects_count = $check_subjects->fetch()['count'];

    if ($subjects_count == 0) {
        $base_subjects = [
            ['name' => 'Математика', 'short_name' => 'Матем'],
            ['name' => 'Русский язык', 'short_name' => 'Рус яз'],
            ['name' => 'Литература', 'short_name' => 'Лит-ра'],
            ['name' => 'История', 'short_name' => 'Ист'],
            ['name' => 'Обществознание', 'short_name' => 'Общ'],
            ['name' => 'География', 'short_name' => 'Геогр'],
            ['name' => 'Биология', 'short_name' => 'Биол'],
            ['name' => 'Физика', 'short_name' => 'Физ'],
            ['name' => 'Химия', 'short_name' => 'Хим'],
            ['name' => 'Английский язык', 'short_name' => 'Англ'],
            ['name' => 'Информатика', 'short_name' => 'Инф'],
            ['name' => 'Физкультура', 'short_name' => 'Физ-ра'],
            ['name' => 'Музыка', 'short_name' => 'Муз'],
            ['name' => 'ИЗО', 'short_name' => 'ИЗО'],
            ['name' => 'Технология', 'short_name' => 'Техн']
        ];

        $stmt = $pdo->prepare("INSERT INTO subjects (school_id, name, short_name) VALUES (?, ?, ?)");
        foreach ($base_subjects as $subject) {
            $stmt->execute([$school_id, $subject['name'], $subject['short_name']]);
        }
    }

    // Таблица оценок
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grades (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            teacher_id INT NOT NULL,
            subject_id INT NOT NULL,
            grade_value VARCHAR(10),
            lesson_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id),
            FOREIGN KEY (teacher_id) REFERENCES users(id),
            FOREIGN KEY (subject_id) REFERENCES subjects(id)
        )
    ");
} catch (PDOException $e) {
    error_log("Ошибка при создании таблиц: " . $e->getMessage());
}

// Получаем информацию о учителе
$teacher_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$teacher_stmt->execute([$teacher_id]);
$teacher = $teacher_stmt->fetch();

$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$selected_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

$grade_types = [];
$grade_weights = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, min_score, max_score, color FROM grade_types WHERE school_id = ? AND is_active = 1 ORDER BY min_score DESC, name");
    $stmt->execute([$school_id]);
    $grade_types = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name, weight FROM grade_weights WHERE school_id = ? AND is_active = 1 ORDER BY weight, name");
    $stmt->execute([$school_id]);
    $grade_weights = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Ошибка получения настроек оценок: ' . $e->getMessage());
}

$default_grade_type_id = $grade_types[0]['id'] ?? null;
$default_grade_weight_id = $grade_weights[0]['id'] ?? null;

// Получаем предметы
$subjects = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE school_id = ? ORDER BY name");
    $stmt->execute([$school_id]);
    $subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении предметов: " . $e->getMessage());
}

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

// Получаем даты уроков
$lesson_dates = [];
if ($selected_class_id > 0 && $selected_subject_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT lesson_date 
            FROM schedule 
            WHERE class_id = ? AND subject_id = ? AND teacher_id = ?
            ORDER BY lesson_date ASC
        ");
        $stmt->execute([$selected_class_id, $selected_subject_id, $teacher_id]);
        $dates = $stmt->fetchAll();

        foreach ($dates as $date_row) {
            $lesson_dates[] = [
                'full_date' => $date_row['lesson_date'],
                'display_date' => date('j', strtotime($date_row['lesson_date'])),
                'month_name' => date('F', strtotime($date_row['lesson_date']))
            ];
        }

    } catch (PDOException $e) {
        error_log("Ошибка при получении дат уроков: " . $e->getMessage());
    }
}

// Получаем учеников
$class_students = [];
if ($selected_class_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name 
            FROM users u 
            WHERE u.class_id = ? AND u.role_id IN (SELECT id FROM roles WHERE name = 'student') AND u.is_active = 1
            ORDER BY u.full_name
        ");
        $stmt->execute([$selected_class_id]);
        $class_students = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Ошибка при получении учеников класса: " . $e->getMessage());
    }
}

// Получаем существующие оценки
$existing_grades = [];
if ($selected_class_id > 0 && $selected_subject_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT g.*, u.full_name as student_name,
                   gt.name AS grade_type_name, gw.name AS grade_weight_name, gw.weight AS grade_weight
            FROM grades g
            JOIN users u ON g.student_id = u.id
            LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = ?
            LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = ?
            WHERE u.class_id = ? AND g.subject_id = ? AND g.teacher_id = ?
        ");
        $stmt->execute([$school_id, $school_id, $selected_class_id, $selected_subject_id, $teacher_id]);
        $existing_grades = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Ошибка при получении оценок: " . $e->getMessage());
    }
}

// Обработка выставления оценки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_grade') {
    verify_csrf_token();
    $student_id = intval($_POST['student_id']);
    $lesson_date = $_POST['lesson_date'];
    $grade_value = !empty($_POST['grade_value']) ? trim($_POST['grade_value']) : null;
    $grade_type_id = !empty($_POST['grade_type_id']) ? intval($_POST['grade_type_id']) : $default_grade_type_id;
    $grade_weight_id = !empty($_POST['grade_weight_id']) ? intval($_POST['grade_weight_id']) : $default_grade_weight_id;

    try {
        $allowed_grade_values = ['2', '3', '4', '5', 'Б', 'От', 'УП'];
        if ($grade_value !== null && !in_array($grade_value, $allowed_grade_values, true)) {
            throw new InvalidArgumentException('Недопустимое значение оценки.');
        }

        $type_stmt = $pdo->prepare("SELECT min_score, max_score FROM grade_types WHERE id = ? AND school_id = ? AND is_active = 1");
        $type_stmt->execute([$grade_type_id, $school_id]);
        $grade_type = $type_stmt->fetch();
        if (!$grade_type) {
            throw new InvalidArgumentException('Выберите активный тип оценки.');
        }
        $weight_stmt = $pdo->prepare("SELECT id FROM grade_weights WHERE id = ? AND school_id = ? AND is_active = 1");
        $weight_stmt->execute([$grade_weight_id, $school_id]);
        if (!$weight_stmt->fetchColumn()) {
            throw new InvalidArgumentException('Выберите активный вес оценки.');
        }
        if ($grade_value !== null && is_numeric($grade_value) && ((int)$grade_value < $grade_type['min_score'] || (int)$grade_value > $grade_type['max_score'])) {
            throw new InvalidArgumentException('Оценка не входит в диапазон выбранного типа.');
        }

        $date_object = DateTime::createFromFormat('Y-m-d', $lesson_date);
        if (!$date_object || $date_object->format('Y-m-d') !== $lesson_date) {
            throw new InvalidArgumentException('Некорректная дата урока.');
        }

        $access_stmt = $pdo->prepare("\n            SELECT 1\n            FROM users student\n            JOIN schedule sch ON sch.class_id = student.class_id\n                AND sch.subject_id = ?\n                AND sch.teacher_id = ?\n                AND sch.school_id = ?\n                AND sch.lesson_date = ?\n            WHERE student.id = ?\n                AND student.class_id = ?\n                AND student.school_id = ?\n                AND student.is_active = 1\n            LIMIT 1\n        ");
        $access_stmt->execute([
            $selected_subject_id,
            $teacher_id,
            $school_id,
            $lesson_date,
            $student_id,
            $selected_class_id,
            $school_id
        ]);
        if (!$access_stmt->fetchColumn()) {
            throw new InvalidArgumentException('У вас нет права выставлять оценку этому ученику на выбранном уроке.');
        }

        // Проверяем, есть ли уже оценка
        $check_stmt = $pdo->prepare("
            SELECT id FROM grades 
            WHERE student_id = ? AND teacher_id = ? AND subject_id = ? AND lesson_date = ?
        ");
        $check_stmt->execute([$student_id, $teacher_id, $selected_subject_id, $lesson_date]);
        $existing_grade = $check_stmt->fetch();

        if ($existing_grade) {
            if ($grade_value) {
                // Обновляем существующую оценку
                $stmt = $pdo->prepare("
                    UPDATE grades 
                    SET grade_value = ?, grade_type_id = ?, grade_weight_id = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$grade_value, $grade_type_id, $grade_weight_id, $existing_grade['id']]);
            } else {
                // Удаляем оценку
                $stmt = $pdo->prepare("DELETE FROM grades WHERE id = ?");
                $stmt->execute([$existing_grade['id']]);
            }
        } else {
            if ($grade_value) {
                // Добавляем новую оценку
                $stmt = $pdo->prepare("
                    INSERT INTO grades (student_id, teacher_id, subject_id, grade_value, grade_type_id, grade_weight_id, lesson_date, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$student_id, $teacher_id, $selected_subject_id, $grade_value, $grade_type_id, $grade_weight_id, $lesson_date]);
            }
        }

        logUserAction($pdo, 'grade_changed', json_encode([
            'student_id' => $student_id,
            'subject_id' => $selected_subject_id,
            'lesson_date' => $lesson_date,
            'value' => $grade_value
        ], JSON_UNESCAPED_UNICODE));

        $_SESSION['success_message'] = "Оценка успешно сохранена!";
        header('Location: grades.php?class_id=' . $selected_class_id . '&subject_id=' . $selected_subject_id);
        exit;
    } catch (InvalidArgumentException $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: grades.php?class_id=' . $selected_class_id . '&subject_id=' . $selected_subject_id);
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Ошибка при сохранении оценки: " . $e->getMessage();
        header('Location: grades.php?class_id=' . $selected_class_id . '&subject_id=' . $selected_subject_id);
        exit;
    }
}

// Рассчитываем средние оценки
$student_averages = [];
foreach ($class_students as $student) {
    $student_grades = array_filter($existing_grades, function($grade) use ($student) {
        return $grade['student_id'] == $student['id'] && !empty($grade['grade_value']) && is_numeric($grade['grade_value']);
    });

    if (count($student_grades) > 0) {
        $weighted_sum = 0;
        $weight_total = 0;
        foreach ($student_grades as $student_grade) {
            $weight = (float)($student_grade['grade_weight'] ?? 1);
            $weighted_sum += (float)$student_grade['grade_value'] * $weight;
            $weight_total += $weight;
        }
        $student_averages[$student['id']] = $weight_total > 0 ? round($weighted_sum / $weight_total, 2) : null;
    } else {
        $student_averages[$student['id']] = null;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Журнал оценок - Учитель</title>
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

        .journal-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
            margin-top: 20px;
        }

        .journal-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .journal-table th, .journal-table td {
            padding: 12px 8px;
            border: 1px solid #e0e0e0;
            text-align: center;
            vertical-align: middle;
        }

        .journal-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .student-name-cell {
            text-align: left;
            font-weight: 500;
            min-width: 200px;
            background: #f8f9fa;
            position: sticky;
            left: 0;
            z-index: 10;
        }

        .date-header {
            background: #e3f2fd;
            font-weight: 500;
            min-width: 60px;
            cursor: pointer;
            color: #1976d2;
        }

        .month-header {
            background: #1976d2;
            color: white;
            font-weight: bold;
            text-align: center;
            font-size: 0.9em;
        }

        .grade-select {
            width: 60px;
            padding: 8px 5px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            text-align: center;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
            background: white;
            cursor: pointer;
        }

        .grade-select:focus {
            border-color: #1976d2;
            outline: none;
            box-shadow: 0 0 5px rgba(25, 118, 210, 0.3);
            background: #f8fdff;
        }

        .grade-select.empty {
            color: #7f8c8d;
            font-style: italic;
        }

        .grade-select.option-5 { background: #d4edda; color: #155724; border-color: #28a745; }
        .grade-select.option-4 { background: #d1ecf1; color: #0c5460; border-color: #17a2b8; }
        .grade-select.option-3 { background: #fff3cd; color: #856404; border-color: #ffc107; }
        .grade-select.option-2 { background: #f8d7da; color: #721c24; border-color: #dc3545; }
        .grade-select.option-Б { background: #f8d7da; color: #721c24; border-color: #dc3545; }
        .grade-select.option-От { background: #e2e3e5; color: #383d41; border-color: #6c757d; }
        .grade-select.option-УП { background: #fff3cd; color: #856404; border-color: #ffc107; }

        .average-cell {
            background: #e8f5e8;
            font-weight: bold;
            color: #2e7d32;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 15px;
            align-items: end;
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

        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1em;
            transition: border-color 0.3s;
            background: white;
        }

        .form-group select:focus {
            border-color: #3498db;
            outline: none;
        }

        .grade-form {
            margin: 0;
            display: flex;
            justify-content: center;
        }
    </style>
    <link rel="stylesheet" href="../css/teacher.css">
</head>
<body>
<div class="dashboard-container">
    <aside class="sidebar">
        <!-- Боковая панель как в dashboard.php -->
        <div class="sidebar-header">
            <h1>Знание Севера</h1>
            <p>Электронный дневник</p>
        </div>
        <nav class="sidebar-nav">
            <div class="user-info">
                <strong><?= htmlspecialchars($teacher['full_name']) ?></strong>
                <span class="role-badge">Учитель</span>
            </div>
            <ul class="nav-menu">
                <li><a href="dashboard.php" class="nav-link">📊 Главная</a></li>
                <li class="nav-section">Учебный процесс</li>
                <li><a href="grades.php" class="nav-link active">📝 Журнал оценок</a></li>
                <li><a href="class_journal.php" class="nav-link">📋 Классный журнал</a></li>
                <li><a href="homework.php" class="nav-link">📚 Домашние задания</a></li>
                <li><a href="schedule.php" class="nav-link">📅 Моё расписание</a></li>
                <li><a href="calendar.php" class="nav-link">🗓️ Календарь</a></li>
                <li><a href="reports.php" class="nav-link">📈 Отчеты</a></li>
                <li><a href="reports_advanced.php" class="nav-link">📊 Расширенные отчёты</a></li>
                <li class="nav-section">Общее</li>
                <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Журнал оценок</h1>
                <p>Выставление и просмотр оценок</p>
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
            <div class="section">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Класс:</label>
                        <select name="class_id" required onchange="this.form.submit()">
                            <option value="">Выберите класс</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $selected_class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['name']) ?> (<?= $class['grade_level'] ?> класс)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Предмет:</label>
                        <select name="subject_id" required onchange="this.form.submit()">
                            <option value="">Выберите предмет</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $selected_subject_id == $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <a href="grades.php" class="btn btn-secondary">Сбросить</a>
                    </div>
                </form>
            </div>

            <!-- Таблица оценок -->
            <?php if ($selected_class_id && $selected_subject_id): ?>
                <div class="journal-container">
                    <div style="text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;">
                        <h2 style="color: #2c3e50; margin-bottom: 5px;">Класс: <?= htmlspecialchars($classes[array_search($selected_class_id, array_column($classes, 'id'))]['name'] ?? 'Неизвестный класс') ?></h2>
                        <h3 style="color: #7f8c8d; font-weight: normal;">Предмет: <?= htmlspecialchars($subjects[array_search($selected_subject_id, array_column($subjects, 'id'))]['name'] ?? 'Неизвестный предмет') ?></h3>
                    </div>

                    <?php if (empty($class_students)): ?>
                        <div class="empty-state">
                            <div class="icon">👨‍🎓</div>
                            <h3>В классе нет учеников</h3>
                            <p>Обратитесь к администратору для добавления учеников</p>
                        </div>
                    <?php elseif (empty($lesson_dates)): ?>
                        <div class="empty-state">
                            <div class="icon">📅</div>
                            <h3>Нет уроков по расписанию</h3>
                            <p>Для выбранного класса и предмета нет запланированных уроков</p>
                        </div>
                    <?php else: ?>
                        <table class="journal-table">
                            <thead>
                            <tr>
                                <th class="student-name-cell">Ученики</th>
                                <?php
                                // Группируем даты по месяцам
                                $months = [];
                                foreach ($lesson_dates as $date) {
                                    $month = $date['month_name'];
                                    if (!isset($months[$month])) {
                                        $months[$month] = [];
                                    }
                                    $months[$month][] = $date;
                                }
                                ?>

                                <?php foreach ($months as $month_name => $month_dates): ?>
                                    <th colspan="<?= count($month_dates) ?>" class="month-header">
                                        <?= $month_name ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="average-cell">Средний балл</th>
                            </tr>
                            <tr>
                                <th class="student-name-cell">Даты уроков</th>
                                <?php foreach ($lesson_dates as $date): ?>
                                    <th class="date-header" title="<?= date('d.m.Y', strtotime($date['full_date'])) ?>">
                                        <?= $date['display_date'] ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="average-cell">Средняя</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($class_students as $index => $student): ?>
                                <tr>
                                    <td class="student-name-cell">
                                        <strong><?= ($index + 1) ?>. <?= htmlspecialchars($student['full_name']) ?></strong>
                                    </td>
                                    <?php foreach ($lesson_dates as $date):
                                        $grade_value = null;
                                        foreach ($existing_grades as $grade) {
                                            if ($grade['student_id'] == $student['id'] && $grade['lesson_date'] == $date['full_date']) {
                                                $grade_value = $grade['grade_value'];
                                                break;
                                            }
                                        }
                                        ?>
                                        <td>
                                            <form method="POST" class="grade-form">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="set_grade">
                                                <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                                <input type="hidden" name="lesson_date" value="<?= $date['full_date'] ?>">
                                                <?php
                                                $current_grade = null;
                                                foreach ($existing_grades as $grade) {
                                                    if ($grade['student_id'] == $student['id'] && $grade['lesson_date'] == $date['full_date']) {
                                                        $current_grade = $grade;
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <select name="grade_value" class="grade-select <?= $grade_value ? 'option-' . htmlspecialchars($grade_value) : 'empty' ?>" onchange="this.form.submit()">
                                                    <option value="">...</option>
                                                    <option value="5" <?= $grade_value == '5' ? 'selected' : '' ?>>5</option>
                                                    <option value="4" <?= $grade_value == '4' ? 'selected' : '' ?>>4</option>
                                                    <option value="3" <?= $grade_value == '3' ? 'selected' : '' ?>>3</option>
                                                    <option value="2" <?= $grade_value == '2' ? 'selected' : '' ?>>2</option>
                                                    <option value="Б" <?= $grade_value == 'Б' ? 'selected' : '' ?>>Б</option>
                                                    <option value="От" <?= $grade_value == 'От' ? 'selected' : '' ?>>От</option>
                                                    <option value="УП" <?= $grade_value == 'УП' ? 'selected' : '' ?>>УП</option>
                                                </select>
                                                <select name="grade_type_id" class="grade-meta-select" title="Тип оценки">
                                                    <?php foreach ($grade_types as $grade_type): ?>
                                                        <option value="<?= $grade_type['id'] ?>" <?= ((int)($current_grade['grade_type_id'] ?? $default_grade_type_id) === (int)$grade_type['id']) ? 'selected' : '' ?>><?= htmlspecialchars($grade_type['name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <select name="grade_weight_id" class="grade-meta-select" title="Вес оценки">
                                                    <?php foreach ($grade_weights as $grade_weight): ?>
                                                        <option value="<?= $grade_weight['id'] ?>" <?= ((int)($current_grade['grade_weight_id'] ?? $default_grade_weight_id) === (int)$grade_weight['id']) ? 'selected' : '' ?>><?= htmlspecialchars($grade_weight['name']) ?> (<?= $grade_weight['weight'] ?>)</option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="average-cell">
                                        <strong><?= $student_averages[$student['id']] ?? 'н/д' ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                            <p style="margin: 0; font-size: 0.9em;"><strong>Примечание:</strong> Б - Неудовлетворительно, От - Отсутствовал, УП - Удовлетворительно</p>
                            <p style="margin: 5px 0 0 0; font-size: 0.8em; color: #666;">Для выставления оценки выберите значение из выпадающего списка. Оценка сохранится автоматически.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="icon">📝</div>
                    <h3>Выберите класс и предмет</h3>
                    <p>Для просмотра журнала оценок выберите класс и предмет из фильтров выше</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    // Автоматическое изменение цвета селекта при выборе оценки
    document.querySelectorAll('.grade-select').forEach(select => {
        select.addEventListener('change', function() {
            // Удаляем все классы цвета
            this.classList.remove('empty', 'option-5', 'option-4', 'option-3', 'option-2', 'option-Б', 'option-От', 'option-УП');

            // Добавляем соответствующий класс цвета
            if (this.value) {
                this.classList.add('option-' + this.value);
            } else {
                this.classList.add('empty');
            }
        });
    });
</script>
</body>
</html>