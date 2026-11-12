<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
require_once __DIR__ . '/../includes/grades_helper.php';
require_once __DIR__ . '/../includes/periods_helper.php';

requireParent();

$pdo = getDatabaseConnection();
$user_id = $_SESSION['user_id'];
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

$children = [];
$children_ids = [];
$children_by_id = [];

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.class_id, c.name AS class_name
        FROM student_parent_links spl
        JOIN users u ON spl.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id AND c.school_id = u.school_id
        WHERE spl.parent_id = ? AND u.school_id = ? AND u.is_active = 1
        ORDER BY u.full_name ASC
    ");
    $stmt->execute([$user_id, $school_id]);
    $children = $stmt->fetchAll();
    $children_ids = array_map(static fn($student) => (int)$student['id'], $children);
    foreach ($children as $child) {
        $children_by_id[(int)$child['id']] = $child['full_name'];
    }
} catch (PDOException $e) {
    error_log('Ошибка загрузки детей: ' . $e->getMessage());
}

$recent_grades = [];
$average_by_child = [];
$attendance_by_child_date = [];
$recent_attendance = [];
if (!empty($children_ids)) {
    $placeholders = implode(',', array_fill(0, count($children_ids), '?'));
    try {
        $recent_grade_sql = "
            SELECT g.id, g.student_id, g.subject_id, g.grade_value, g.lesson_date,
                   g.grade_type_id, g.grade_weight_id,
                   u.full_name AS student_name, s.name AS subject_name,
                   gt.name AS grade_type_name, gt.default_weight_id,
                   gw.name AS grade_weight_name, gw.weight AS grade_weight_value,
                   default_gw.name AS default_weight_name, default_gw.weight AS default_weight_value,
                   default_gw.is_active AS default_weight_active
            FROM grades g
            JOIN users u ON g.student_id = u.id AND u.school_id = ?
            JOIN subjects s ON g.subject_id = s.id AND s.school_id = u.school_id
            LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = u.school_id
            LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = u.school_id
            LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = u.school_id
            WHERE g.student_id IN ($placeholders)
        ";
        $recent_grade_params = array_merge([$school_id], $children_ids);
        if ($selected_period) {
            $recent_grade_sql .= ' AND g.lesson_date BETWEEN ? AND ?';
            $recent_grade_params[] = $selected_period['start_date'];
            $recent_grade_params[] = $selected_period['end_date'];
        } else {
            $recent_grade_sql .= ' AND g.lesson_date >= CURRENT_DATE - INTERVAL 30 DAY';
        }
        $recent_grade_sql .= ' ORDER BY g.lesson_date DESC, g.id DESC LIMIT 12';
        $stmt = $pdo->prepare($recent_grade_sql);
        $stmt->execute($recent_grade_params);
        $recent_grades = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Ошибка загрузки оценок: ' . $e->getMessage());
    }

    try {
        $average_query = "
            SELECT g.student_id, g.subject_id, g.grade_value, g.grade_type_id, g.grade_weight_id,
                   gt.default_weight_id, gw.weight AS grade_weight_value,
                   default_gw.weight AS default_weight_value,
                   default_gw.is_active AS default_weight_active
            FROM grades g
            JOIN users u ON u.id = g.student_id AND u.school_id = ?
            JOIN subjects s ON s.id = g.subject_id AND s.school_id = u.school_id
            LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = u.school_id
            LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = u.school_id
            LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = u.school_id
            WHERE g.student_id IN ($placeholders)
        ";
        $average_params = array_merge([$school_id], $children_ids);
        if ($selected_period) {
            $average_query .= ' AND g.lesson_date BETWEEN ? AND ?';
            $average_params[] = $selected_period['start_date'];
            $average_params[] = $selected_period['end_date'];
        }
        $stmt = $pdo->prepare($average_query);
        $stmt->execute($average_params);
        $grades_by_child_subject = [];
        foreach ($stmt->fetchAll() as $grade) {
            $grades_by_child_subject[$grade['student_id']][$grade['subject_id']][] = $grade;
        }
        foreach ($children_ids as $child_id) {
            $subject_averages = [];
            foreach ($grades_by_child_subject[$child_id] ?? [] as $subject_grades) {
                $subject_averages[] = calculateWeightedAverage($subject_grades);
            }
            $average_by_child[$child_id] = calculateOverallAverage($subject_averages);
        }
    } catch (PDOException $e) {
        error_log('Ошибка расчёта средних оценок детей: ' . $e->getMessage());
    }

    try {
        $attendance_query = "
            SELECT a.student_id, a.lesson_date, a.status
            FROM attendance a
            JOIN users u ON u.id = a.student_id AND u.school_id = ?
            WHERE a.student_id IN ($placeholders)
                AND a.status IN ('absent', 'late')
        ";
        $attendance_params = array_merge([$_SESSION['user_school_id']], $children_ids);
        if ($selected_period) {
            $attendance_query .= ' AND a.lesson_date BETWEEN ? AND ?';
            $attendance_params[] = $selected_period['start_date'];
            $attendance_params[] = $selected_period['end_date'];
        } else {
            $attendance_query .= ' AND a.lesson_date >= CURRENT_DATE - INTERVAL 30 DAY';
        }
        $stmt = $pdo->prepare($attendance_query);
        $stmt->execute($attendance_params);
        $recent_attendance = $stmt->fetchAll();
        foreach ($recent_attendance as $attendance) {
            $attendance_by_child_date[$attendance['student_id'] . ':' . $attendance['lesson_date']] = $attendance['status'];
        }
    } catch (PDOException $e) {
        error_log('Ошибка загрузки посещаемости детей: ' . $e->getMessage());
    }
}

$recent_homework = [];
if (!empty($children_ids)) {
    $class_ids_sql = implode(',', array_fill(0, count($children_ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT h.id, h.title, h.description, h.due_date, h.created_at, h.class_id, h.subject_id,
                   u.full_name AS student_name, c.name AS class_name, s.name AS subject_name
            FROM homework h
            JOIN classes c ON h.class_id = c.id
            JOIN subjects s ON h.subject_id = s.id
            JOIN users u ON u.class_id = h.class_id
            WHERE u.id IN ($class_ids_sql) AND u.school_id = ? AND c.school_id = ? AND s.school_id = ?
            ORDER BY h.created_at DESC
            LIMIT 10
        ");
        $stmt->execute(array_merge($children_ids, [$school_id, $school_id, $school_id]));
        $recent_homework = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Ошибка загрузки домашних заданий: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет родителя</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <style>
        :root {
            --bg: #f3f7ff;
            --panel: #ffffff;
            --panel-soft: #f8fafc;
            --primary: #2563eb;
            --primary-strong: #1d4ed8;
            --text: #1f2937;
            --muted: #64748b;
            --line: #e2e8f0;
            --shadow: 0 12px 28px rgba(15, 23, 42, 0.10);
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(180deg, #eef4ff 0%, #f8fafc 100%);
            color: var(--text);
        }

        .page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 20px 40px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            gap: 16px;
        }

        .topbar h1 {
            font-size: 2rem;
            margin-bottom: 8px;
            color: var(--text);
        }

        .topbar p {
            color: var(--muted);
        }

        .card {
            background: var(--panel);
            border-radius: 18px;
            box-shadow: var(--shadow);
            border: 1px solid var(--line);
            padding: 24px;
            margin-bottom: 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 22px;
        }

        .stat {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-strong) 100%);
            color: #fff;
            border-radius: 18px;
            padding: 22px 20px;
            box-shadow: 0 14px 28px rgba(37, 99, 235, 0.18);
        }

        .stat strong {
            display: block;
            font-size: 2rem;
            margin-top: 10px;
        }

        .list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .list li {
            border-bottom: 1px solid var(--line);
            padding: 12px 0;
        }

        .list li:last-child {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            background: #dbeafe;
            color: var(--primary-strong);
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            margin-left: 8px;
            font-weight: 600;
        }

        .btn {
            display: inline-block;
            padding: 10px 18px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-strong) 100%);
            color: white;
            text-decoration: none;
            font-weight: 600;
            box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2);
        }

        .nav {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .nav a {
            text-decoration: none;
            color: var(--text);
            background: var(--panel);
            padding: 10px 16px;
            border-radius: 12px;
            border: 1px solid var(--line);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
            font-weight: 600;
        }

        .attendance-marker {
            margin-left: 6px;
            font-weight: 800;
        }

        .attendance-marker.absent {
            color: #dc3545;
        }

        .attendance-marker.late {
            color: #c88700;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1>Личный кабинет родителя</h1>
            <p>Здравствуйте, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Родитель'); ?>!</p>
        </div>
        <?= logoutForm('../logout.php', 'btn') ?>
    </div>

    <nav class="nav">
        <a href="dashboard.php">Главная</a>
        <a href="../profile.php">Профиль</a>
    </nav>

    <div class="card">
        <form method="GET" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            <label for="period_id">Учебный период:</label>
            <select id="period_id" name="period_id" onchange="this.form.submit()">
                <option value="">Все периоды</option>
                <?php foreach ($school_periods as $period): ?>
                    <option value="<?php echo (int)$period['id']; ?>" <?php echo $selected_period_id === (int)$period['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8'); ?><?php echo $period['is_locked'] ? ' 🔒' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="grid">
        <div class="stat">
            <span>Детей в системе</span>
            <strong><?php echo count($children); ?></strong>
        </div>
        <div class="stat" style="background: linear-gradient(135deg, #10b981, #059669);">
            <span><?php echo $selected_period ? 'Оценок за период' : 'Оценок за последние 30 дней'; ?></span>
            <strong><?php echo count($recent_grades); ?></strong>
        </div>
        <div class="stat" style="background: linear-gradient(135deg, #f59e0b, #f97316);">
            <span>Домашних заданий</span>
            <strong><?php echo count($recent_homework); ?></strong>
        </div>
    </div>

    <div class="card">
        <h2>Мои дети</h2>
        <?php if (empty($children)): ?>
            <p>У вас пока нет привязанных детей.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($children as $student): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($student['full_name']); ?></strong>
                        <span class="badge"><?php echo htmlspecialchars($student['class_name'] ?: 'Класс не назначен'); ?></span>
                        <small style="display:block;margin-top:5px" title="Среднее по предметам с учетом веса каждой оценки">
                            Средний балл<?php echo $selected_period ? ' за ' . htmlspecialchars($selected_period['name'], ENT_QUOTES, 'UTF-8') : ''; ?>: <?php echo $average_by_child[(int)$student['id']] ?? 'н/д'; ?>.
                            Средний балл учитывает вес оценок: контрольные важнее домашних.
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Последние оценки</h2>
        <?php if (empty($recent_grades)): ?>
            <p>Пока нет оценок.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recent_grades as $grade): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($grade['student_name']); ?></strong>
                        — <?php echo htmlspecialchars($grade['subject_name']); ?>
                        — <b title="<?php
                            $grade_title = 'Тип работы: ' . ($grade['grade_type_name'] ?: 'не указан')
                                . '; вес: ' . number_format(effectiveGradeWeight($grade), 2, '.', '');
                            echo htmlspecialchars($grade_title, ENT_QUOTES, 'UTF-8');
                        ?>"><?php echo htmlspecialchars($grade['grade_value'], ENT_QUOTES, 'UTF-8'); ?></b>
                        <small>(<?php echo htmlspecialchars(date('d.m.Y', strtotime($grade['lesson_date']))); ?>)</small>
                        <?php $attendance_status = $attendance_by_child_date[$grade['student_id'] . ':' . $grade['lesson_date']] ?? ''; ?>
                        <?php if ($attendance_status === 'absent'): ?>
                            <span class="attendance-marker absent" title="Отсутствовал">Н</span>
                        <?php elseif ($attendance_status === 'late'): ?>
                            <span class="attendance-marker late" title="Опоздал">О</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Посещаемость<?php echo $selected_period ? ' за ' . htmlspecialchars($selected_period['name'], ENT_QUOTES, 'UTF-8') : ' за последние 30 дней'; ?></h2>
        <?php if (empty($recent_attendance)): ?>
            <p>Нет отметок о пропусках или опозданиях.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recent_attendance as $attendance): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($children_by_id[(int)$attendance['student_id']] ?? 'Ученик'); ?></strong>
                        <small>(<?php echo htmlspecialchars(date('d.m.Y', strtotime($attendance['lesson_date']))); ?>)</small>
                        <span class="attendance-marker <?php echo $attendance['status'] === 'absent' ? 'absent' : 'late'; ?>">
                            <?php echo $attendance['status'] === 'absent' ? 'Н' : 'О'; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Домашние задания</h2>
        <?php if (empty($recent_homework)): ?>
            <p>Домашние задания не найдены.</p>
        <?php else: ?>
            <ul class="list">
                <?php foreach ($recent_homework as $homework): ?>
                    <li>
                        <strong><?php echo htmlspecialchars($homework['title']); ?></strong>
                        <small> — <?php echo htmlspecialchars($homework['subject_name']); ?>, <?php echo htmlspecialchars($homework['class_name']); ?></small>
                        <small> — сдать до <?php echo htmlspecialchars(date('d.m.Y', strtotime($homework['due_date']))); ?></small>
                        <div><?php echo htmlspecialchars($homework['description'] ?: 'Описание отсутствует'); ?></div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
