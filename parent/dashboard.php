<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

requireParent();

$pdo = getDatabaseConnection();
$user_id = $_SESSION['user_id'];

$children = [];
$children_ids = [];

try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.class_id, c.name AS class_name
        FROM student_parent_links spl
        JOIN users u ON spl.student_id = u.id
        LEFT JOIN classes c ON u.class_id = c.id
        WHERE spl.parent_id = ? AND u.is_active = 1
        ORDER BY u.full_name ASC
    ");
    $stmt->execute([$user_id]);
    $children = $stmt->fetchAll();
    $children_ids = array_map(static fn($student) => (int)$student['id'], $children);
} catch (PDOException $e) {
    error_log('Ошибка загрузки детей: ' . $e->getMessage());
}

$recent_grades = [];
if (!empty($children_ids)) {
    $placeholders = implode(',', array_fill(0, count($children_ids), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT g.id, g.student_id, g.subject_id, g.grade_value, g.lesson_date,
                   u.full_name AS student_name, s.name AS subject_name
            FROM grades g
            JOIN users u ON g.student_id = u.id
            JOIN subjects s ON g.subject_id = s.id
                        WHERE g.student_id IN ($placeholders)
                            AND g.lesson_date >= CURRENT_DATE - INTERVAL 30 DAY
            ORDER BY g.lesson_date DESC, g.id DESC
            LIMIT 12
        ");
        $stmt->execute($children_ids);
        $recent_grades = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Ошибка загрузки оценок: ' . $e->getMessage());
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
            WHERE u.id IN ($class_ids_sql)
            ORDER BY h.created_at DESC
            LIMIT 10
        ");
        $stmt->execute($children_ids);
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
    </style>
</head>
<body>
<div class="page">
    <div class="topbar">
        <div>
            <h1>Личный кабинет родителя</h1>
            <p>Здравствуйте, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Родитель'); ?>!</p>
        </div>
        <a class="btn" href="../logout.php">Выход</a>
    </div>

    <nav class="nav">
        <a href="dashboard.php">Главная</a>
        <a href="../profile.php">Профиль</a>
    </nav>

    <div class="grid">
        <div class="stat">
            <span>Детей в системе</span>
            <strong><?php echo count($children); ?></strong>
        </div>
        <div class="stat" style="background: linear-gradient(135deg, #10b981, #059669);">
            <span>Оценок за последние 30 дней</span>
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
                        — <b><?php echo htmlspecialchars($grade['grade_value']); ?></b>
                        <small>(<?php echo htmlspecialchars(date('d.m.Y', strtotime($grade['lesson_date']))); ?>)</small>
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
