<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

requireTeacher();

$pdo = getDatabaseConnection();
$teacher_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['user_school_id'];
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$selected_date = $_GET['date'] ?? date('Y-m-d');
$date_object = DateTime::createFromFormat('Y-m-d', $selected_date);
if (!$date_object || $date_object->format('Y-m-d') !== $selected_date) {
    $selected_date = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $selected_class_id = (int)($_POST['class_id'] ?? 0);
    $selected_date = $_POST['lesson_date'] ?? date('Y-m-d');
    $statuses = $_POST['status'] ?? [];

    if (!canManageClass($selected_class_id, $pdo)) {
        $_SESSION['error_message'] = 'У вас нет доступа к этому классу.';
    } else {
        try {
            $pdo->beginTransaction();
            $student_stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND class_id = ? AND school_id = ? AND is_active = 1");
            $upsert_stmt = $pdo->prepare("\n                INSERT INTO attendance (student_id, teacher_id, lesson_date, status, notes)\n                VALUES (?, ?, ?, ?, ?)\n                ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), status = VALUES(status), notes = VALUES(notes)\n            ");

            foreach ($statuses as $student_id => $status) {
                $status = (string)$status;
                if (!in_array($status, ['present', 'absent', 'late'], true)) {
                    continue;
                }
                $student_stmt->execute([(int)$student_id, $selected_class_id, $school_id]);
                if ($student_stmt->fetchColumn()) {
                    $upsert_stmt->execute([(int)$student_id, $teacher_id, $selected_date, $status, null]);
                }
            }
            $pdo->commit();
            logUserAction($pdo, 'attendance_changed', json_encode([
                'class_id' => $selected_class_id,
                'lesson_date' => $selected_date
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = 'Посещаемость сохранена.';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = 'Ошибка сохранения посещаемости: ' . $e->getMessage();
        }
    }

    header('Location: class_journal.php?class_id=' . $selected_class_id . '&date=' . urlencode($selected_date));
    exit;
}

$classes_stmt = $pdo->prepare("\n    SELECT c.id, c.name, c.grade_level\n    FROM classes c\n    WHERE c.school_id = ? AND c.is_active = 1\n    ORDER BY c.grade_level, c.name\n");
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll();

$students = [];
if ($selected_class_id > 0 && canManageClass($selected_class_id, $pdo)) {
    $students_stmt = $pdo->prepare("\n        SELECT u.id, u.full_name, COALESCE(a.status, 'present') AS attendance_status\n        FROM users u\n        LEFT JOIN attendance a ON a.student_id = u.id AND a.lesson_date = ?\n        JOIN classes c ON c.id = u.class_id AND c.school_id = ?\n        WHERE u.class_id = ? AND u.is_active = 1\n        GROUP BY u.id, u.full_name, a.status\n        ORDER BY u.full_name\n    ");
    $students_stmt->execute([$selected_date, $school_id, $selected_class_id]);
    $students = $students_stmt->fetchAll();
}

$messages = [];
foreach (['success_message', 'error_message'] as $message_key) {
    if (isset($_SESSION[$message_key])) {
        $messages[$message_key] = $_SESSION[$message_key];
        unset($_SESSION[$message_key]);
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Классный журнал - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/teacher.css">
</head>
<body>
<div class="dashboard-container">
    <aside class="sidebar">
        <div class="sidebar-header">
            <h1>Знание Севера</h1>
            <p>Электронный дневник</p>
        </div>
        <nav class="sidebar-nav">
            <div class="user-info">
                <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
                <span class="role-badge">Учитель</span>
            </div>
            <ul class="nav-menu">
                <li><a href="dashboard.php" class="nav-link">📊 Главная</a></li>
                <li class="nav-section">Учебный процесс</li>
                <li><a href="grades.php" class="nav-link">📝 Журнал оценок</a></li>
                <li><a href="class_journal.php" class="nav-link active">📋 Классный журнал</a></li>
                <li><a href="homework.php" class="nav-link">📚 Домашние задания</a></li>
                <li><a href="schedule.php" class="nav-link">📅 Моё расписание</a></li>
                <li><a href="calendar.php" class="nav-link">🗓️ Календарь</a></li>
                <li><a href="reports.php" class="nav-link">📈 Отчёты</a></li>
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
                <h1>Классный журнал</h1>
                <p>Посещаемость учеников по выбранному классу и дате</p>
            </div>
        </header>
        <div class="content-body">
            <?php if (isset($messages['success_message'])): ?><div class="alert alert-success"><?php echo htmlspecialchars($messages['success_message']); ?></div><?php endif; ?>
            <?php if (isset($messages['error_message'])): ?><div class="alert alert-error"><?php echo htmlspecialchars($messages['error_message']); ?></div><?php endif; ?>

            <form method="GET" class="dashboard-card" style="padding: 22px; display: flex; gap: 16px; align-items: end; flex-wrap: wrap;">
                <label>Класс
                    <select name="class_id" required>
                        <option value="">Выберите класс</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id === (int)$class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['name']); ?> (<?php echo $class['grade_level']; ?> класс)</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Дата
                    <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" required>
                </label>
                <button class="btn btn-primary" type="submit">Открыть журнал</button>
            </form>

            <?php if ($selected_class_id > 0): ?>
                <form method="POST" class="dashboard-card" style="padding: 22px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                    <input type="hidden" name="lesson_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                    <div class="card-header" style="margin: -22px -22px 18px;">
                        <h3>Посещаемость за <?php echo date('d.m.Y', strtotime($selected_date)); ?></h3>
                    </div>
                    <?php if (!$students): ?>
                        <p class="empty-state">В выбранном классе нет учеников или у вас нет урока с этим классом.</p>
                    <?php else: ?>
                        <div class="admin-table" style="box-shadow: none;">
                            <table style="width: 100%;">
                                <thead><tr><th>Ученик</th><th>Статус</th></tr></thead>
                                <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td>
                                            <select name="status[<?php echo $student['id']; ?>]">
                                                <option value="present" <?php echo $student['attendance_status'] === 'present' ? 'selected' : ''; ?>>Присутствует</option>
                                                <option value="absent" <?php echo $student['attendance_status'] === 'absent' ? 'selected' : ''; ?>>Отсутствует</option>
                                                <option value="late" <?php echo $student['attendance_status'] === 'late' ? 'selected' : ''; ?>>Опоздал</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <button class="btn btn-primary" type="submit" style="margin-top: 18px;">Сохранить посещаемость</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
