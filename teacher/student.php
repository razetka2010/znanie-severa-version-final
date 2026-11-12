<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/grades_helper.php';
require_once __DIR__ . '/../includes/periods_helper.php';

requireTeacher();

$pdo = getDatabaseConnection();
$teacher_id = (int)$_SESSION['user_id'];
$school_id = (int)$_SESSION['user_school_id'];
$student_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$school_periods = getSchoolPeriods($pdo, $school_id);
$current_period = getCurrentPeriod($pdo, $school_id);
$selected_period_id = isset($_GET['period_id']) ? (int)$_GET['period_id'] : (int)($current_period['id'] ?? 0);
$selected_period = null;
foreach ($school_periods as $period) {
    if ((int)$period['id'] === $selected_period_id) {
        $selected_period = $period;
        break;
    }
}

$student_stmt = $pdo->prepare("
    SELECT student.id, student.full_name, student.school_id, student.class_id,
           c.name AS class_name, c.grade_level, info.birth_date, info.address
    FROM users student
    JOIN roles r ON r.id = student.role_id AND r.name = 'student'
    JOIN classes c ON c.id = student.class_id AND c.school_id = student.school_id
    LEFT JOIN student_info info ON info.user_id = student.id
    WHERE student.id = ? AND student.class_id = ? AND student.school_id = ?
        AND student.is_active = 1 AND c.is_active = 1
        AND (
            c.class_teacher_id = ?
            OR EXISTS (
                SELECT 1
                FROM schedule sch
                WHERE sch.class_id = c.id AND sch.teacher_id = ?
                    AND sch.school_id = c.school_id
            )
        )
    LIMIT 1
");
$student_stmt->execute([$student_id, $class_id, $school_id, $teacher_id, $teacher_id]);
$student = $student_stmt->fetch();

if (!$student) {
    header('Location: ../unauthorized.php');
    exit;
}

$return_url = 'student.php?id=' . $student_id . '&class_id=' . $class_id . ($selected_period ? '&period_id=' . (int)$selected_period['id'] : '');
$back_page = ($_GET['back'] ?? '') === 'class_journal' ? 'class_journal' : 'grades';
$back_url = 'grades.php?class_id=' . $class_id . ($selected_period ? '&period_id=' . (int)$selected_period['id'] : '');
if ($back_page === 'class_journal') {
    $back_date = (string)($_GET['date'] ?? '');
    $back_date_object = DateTime::createFromFormat('Y-m-d', $back_date);
    if (!$back_date_object || $back_date_object->format('Y-m-d') !== $back_date) {
        $back_date = date('Y-m-d');
    }
    $back_url = 'class_journal.php?class_id=' . $class_id . '&date=' . urlencode($back_date)
        . ($selected_period ? '&period_id=' . (int)$selected_period['id'] : '');
    $return_url .= '&back=class_journal&date=' . urlencode($back_date);
} else {
    $back_subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: 0;
    if ($back_subject_id > 0) {
        $back_url .= '&subject_id=' . $back_subject_id;
        $return_url .= '&subject_id=' . $back_subject_id;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';
    $posted_student_id = (int)($_POST['student_id'] ?? 0);
    $posted_class_id = (int)($_POST['class_id'] ?? 0);

    if ($posted_student_id !== $student_id || $posted_class_id !== $class_id) {
        header('Location: ../unauthorized.php');
        exit;
    }

    try {
        if ($action === 'add_note') {
            $note = trim((string)($_POST['note'] ?? ''));
            if ($note === '' || mb_strlen($note) > 5000) {
                throw new InvalidArgumentException('Заметка должна содержать от 1 до 5000 символов.');
            }

            $note_stmt = $pdo->prepare("
                INSERT INTO student_notes (school_id, student_id, teacher_id, note)
                VALUES (?, ?, ?, ?)
            ");
            $note_stmt->execute([$school_id, $student_id, $teacher_id, $note]);
            logUserAction($pdo, 'student_note_added', json_encode([
                'student_id' => $student_id,
                'class_id' => $class_id,
                'note_id' => (int)$pdo->lastInsertId()
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = 'Заметка добавлена.';
        } elseif ($action === 'delete_note') {
            $note_id = (int)($_POST['note_id'] ?? 0);
            $delete_stmt = $pdo->prepare("
                DELETE FROM student_notes
                WHERE id = ? AND school_id = ? AND student_id = ? AND teacher_id = ?
            ");
            $delete_stmt->execute([$note_id, $school_id, $student_id, $teacher_id]);
            if ($delete_stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('Заметка не найдена или вы не можете ее удалить.');
            }

            logUserAction($pdo, 'student_note_deleted', json_encode([
                'student_id' => $student_id,
                'class_id' => $class_id,
                'note_id' => $note_id
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = 'Заметка удалена.';
        } else {
            throw new InvalidArgumentException('Неизвестное действие.');
        }
    } catch (InvalidArgumentException $e) {
        $_SESSION['error_message'] = $e->getMessage();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
    }

    header('Location: ' . $return_url);
    exit;
}

$grade_query = "
    SELECT g.grade_value, g.grade_type_id, g.grade_weight_id, g.lesson_date,
           s.id AS subject_id, s.name AS subject_name,
           gt.name AS grade_type_name, gt.default_weight_id,
           gw.name AS grade_weight_name, gw.weight AS grade_weight_value,
           default_gw.name AS default_weight_name, default_gw.weight AS default_weight_value,
           default_gw.is_active AS default_weight_active
    FROM grades g
    JOIN users student ON student.id = g.student_id AND student.school_id = ?
    JOIN subjects s ON s.id = g.subject_id AND s.school_id = student.school_id
    LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = student.school_id
    LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = student.school_id
    LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = student.school_id
    WHERE g.student_id = ?
";
$grade_params = [$school_id, $student_id];
if ($selected_period) {
    $grade_query .= ' AND g.lesson_date BETWEEN ? AND ?';
    $grade_params[] = $selected_period['start_date'];
    $grade_params[] = $selected_period['end_date'];
}
$grade_query .= ' ORDER BY s.name, g.lesson_date DESC, g.id DESC';
$grade_stmt = $pdo->prepare($grade_query);
$grade_stmt->execute($grade_params);
$grade_rows = $grade_stmt->fetchAll();

$performance = [];
foreach ($grade_rows as $grade) {
    $subject_key = (int)$grade['subject_id'];
    if (!isset($performance[$subject_key])) {
        $performance[$subject_key] = [
            'name' => $grade['subject_name'],
            'grades' => [],
            'average' => null,
            'recent_grades' => []
        ];
    }

    if (count($performance[$subject_key]['recent_grades']) < 5) {
        $performance[$subject_key]['recent_grades'][] = [
            'value' => $grade['grade_value'],
            'date' => $grade['lesson_date'],
            'type_name' => $grade['grade_type_name'],
            'weight' => effectiveGradeWeight($grade)
        ];
    }

    if (isNumericGrade($grade['grade_value'])) {
        $performance[$subject_key]['grades'][] = $grade;
    }
}
foreach ($performance as &$subject_performance) {
    $subject_performance['average'] = calculateWeightedAverage($subject_performance['grades']);
}
unset($subject_performance);

$attendance_counts = ['present' => 0, 'absent' => 0, 'late' => 0];
$absence_dates = [];
if ($selected_period) {
    $attendance_stmt = $pdo->prepare("
        SELECT lesson_date, status
        FROM attendance
        WHERE student_id = ? AND lesson_date BETWEEN ? AND ?
        ORDER BY lesson_date DESC
    ");
    $attendance_stmt->execute([$student_id, $selected_period['start_date'], $selected_period['end_date']]);
    foreach ($attendance_stmt->fetchAll() as $attendance) {
        if (isset($attendance_counts[$attendance['status']])) {
            $attendance_counts[$attendance['status']]++;
        }
        if ($attendance['status'] === 'absent') {
            $absence_dates[] = $attendance['lesson_date'];
        }
    }
}

$homework_counts = ['done' => 0, 'not_done' => 0, 'late' => 0];
$homework_query = "
    SELECT COALESCE(completion.status, 'not_done') AS status, COUNT(*) AS total
    FROM homework h
    JOIN classes c ON c.id = h.class_id AND c.school_id = ?
    LEFT JOIN homework_completion completion
        ON completion.homework_id = h.id AND completion.student_id = ?
    WHERE h.class_id = ?
";
$homework_params = [$school_id, $student_id, $class_id];
if ($selected_period) {
    $homework_query .= ' AND DATE(COALESCE(h.due_date, h.created_at)) BETWEEN ? AND ?';
    $homework_params[] = $selected_period['start_date'];
    $homework_params[] = $selected_period['end_date'];
}
$homework_query .= " GROUP BY COALESCE(completion.status, 'not_done')";
$homework_stmt = $pdo->prepare($homework_query);
$homework_stmt->execute($homework_params);
foreach ($homework_stmt->fetchAll() as $homework_count) {
    if (isset($homework_counts[$homework_count['status']])) {
        $homework_counts[$homework_count['status']] = (int)$homework_count['total'];
    }
}

$parents_stmt = $pdo->prepare("
    SELECT parent.full_name, parent.phone, parent.email, links.relationship, links.is_primary
    FROM student_parent_links links
    JOIN users parent ON parent.id = links.parent_id AND parent.school_id = ?
    JOIN roles r ON r.id = parent.role_id AND r.name = 'parent'
    WHERE links.student_id = ? AND parent.is_active = 1
    ORDER BY links.is_primary DESC, parent.full_name
");
$parents_stmt->execute([$school_id, $student_id]);
$parents = $parents_stmt->fetchAll();

$notes_stmt = $pdo->prepare("
    SELECT notes.id, notes.teacher_id, notes.note, notes.created_at, teacher.full_name AS teacher_name
    FROM student_notes notes
    JOIN users teacher ON teacher.id = notes.teacher_id AND teacher.school_id = notes.school_id
    WHERE notes.school_id = ? AND notes.student_id = ?
    ORDER BY notes.created_at DESC, notes.id DESC
");
$notes_stmt->execute([$school_id, $student_id]);
$notes = $notes_stmt->fetchAll();

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
    <title>Ученик — <?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/teacher.css?v=<?= (int)filemtime(__DIR__ . '/../css/teacher.css') ?>">
</head>
<body>
<div class="dashboard-container">
    <?php $teacherActivePage = 'grades'; require __DIR__ . '/../includes/teacher_sidebar.php'; ?>
    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1><?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?></h1>
                <p><?= htmlspecialchars($student['class_name'], ENT_QUOTES, 'UTF-8') ?>, <?= (int)$student['grade_level'] ?> класс</p>
            </div>
            <a class="btn btn-secondary" href="<?= htmlspecialchars($back_url, ENT_QUOTES, 'UTF-8') ?>">← Назад к журналу</a>
        </header>
        <div class="content-body">
            <?php if (isset($messages['success_message'])): ?>
                <div class="alert alert-success"><?= htmlspecialchars($messages['success_message'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if (isset($messages['error_message'])): ?>
                <div class="alert alert-error"><?= htmlspecialchars($messages['error_message'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($school_periods): ?>
                <form method="GET" class="section student-page-section" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="id" value="<?= $student_id ?>">
                    <input type="hidden" name="class_id" value="<?= $class_id ?>">
                    <label for="period_id">Учебный период</label>
                    <select id="period_id" name="period_id" onchange="this.form.submit()">
                        <option value="">Все периоды</option>
                        <?php foreach ($school_periods as $period): ?>
                            <option value="<?= (int)$period['id'] ?>" <?= $selected_period_id === (int)$period['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8') ?><?= $period['is_locked'] ? ' 🔒' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <section class="section student-page-section student-detail-card">
                <h2>Информация об ученике</h2>
                <div class="student-detail-grid">
                    <div><strong>Класс:</strong> <?= htmlspecialchars($student['class_name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div><strong>Дата рождения:</strong>
                        <?= $student['birth_date'] ? htmlspecialchars(date('d.m.Y', strtotime($student['birth_date'])), ENT_QUOTES, 'UTF-8') : '—' ?>
                    </div>
                    <div><strong>Адрес:</strong> <?= nl2br(htmlspecialchars($student['address'] ?? '—', ENT_QUOTES, 'UTF-8')) ?></div>
                </div>
                <h3>Контакты родителей</h3>
                <?php if ($parents): ?>
                    <div class="student-parent-list">
                        <?php foreach ($parents as $parent): ?>
                            <article class="student-parent-card">
                                <strong><?= htmlspecialchars($parent['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ($parent['relationship'] !== ''): ?>
                                    <span><?= htmlspecialchars($parent['relationship'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                                <?php if ($parent['phone']): ?>
                                    <a href="tel:<?= htmlspecialchars($parent['phone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($parent['phone'], ENT_QUOTES, 'UTF-8') ?></a>
                                <?php endif; ?>
                                <?php if ($parent['email']): ?>
                                    <a href="mailto:<?= htmlspecialchars($parent['email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($parent['email'], ENT_QUOTES, 'UTF-8') ?></a>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">Контакты родителей не указаны.</p>
                <?php endif; ?>
            </section>

            <section class="section student-page-section">
                <h2>Успеваемость<?= $selected_period ? ' — ' . htmlspecialchars($selected_period['name'], ENT_QUOTES, 'UTF-8') : '' ?></h2>
                <?php if (!$selected_period): ?>
                    <p class="muted">Показаны оценки за все периоды.</p>
                <?php endif; ?>
                <?php if ($performance): ?>
                    <div class="student-table-wrap">
                        <table class="student-detail-table">
                            <thead><tr><th>Предмет</th><th>Средний балл</th><th>Последние оценки</th></tr></thead>
                            <tbody>
                            <?php foreach ($performance as $subject): ?>
                                <tr>
                                    <td><?= htmlspecialchars($subject['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= $subject['average'] !== null ? number_format($subject['average'], 2, '.', '') : 'н/д' ?></td>
                                    <td>
                                        <?php if ($subject['recent_grades']): ?>
                                            <div class="student-grade-list">
                                                <?php foreach ($subject['recent_grades'] as $recent_grade): ?>
                                                    <span class="student-grade-item">
                                                        <strong title="<?= htmlspecialchars('Тип работы: ' . ($recent_grade['type_name'] ?: 'не указан') . '; вес: ' . number_format($recent_grade['weight'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($recent_grade['value'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <small><?= htmlspecialchars(date('d.m.Y', strtotime($recent_grade['date'])), ENT_QUOTES, 'UTF-8') ?></small>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="muted">Оценок за текущий период пока нет.</p>
                <?php endif; ?>
            </section>

            <section class="section student-page-section">
                <h2>Посещаемость<?= $selected_period ? ' — ' . htmlspecialchars($selected_period['name'], ENT_QUOTES, 'UTF-8') : '' ?></h2>
                <?php if (!$selected_period): ?>
                    <p class="muted">Выберите учебный период для просмотра посещаемости.</p>
                <?php else: ?>
                    <div class="student-count-grid">
                        <div><span>Присутствия</span><strong><?= $attendance_counts['present'] ?></strong></div>
                        <div><span>Пропуски</span><strong><?= $attendance_counts['absent'] ?></strong></div>
                        <div><span>Опоздания</span><strong><?= $attendance_counts['late'] ?></strong></div>
                    </div>
                    <h3>Даты пропусков</h3>
                    <?php if ($absence_dates): ?>
                        <ul class="student-absence-list">
                            <?php foreach ($absence_dates as $absence_date): ?>
                                <li><?= htmlspecialchars(date('d.m.Y', strtotime($absence_date)), ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted">Пропусков за период нет.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="section student-page-section">
                <h2>Домашние задания<?= $selected_period ? ' — ' . htmlspecialchars($selected_period['name'], ENT_QUOTES, 'UTF-8') : '' ?></h2>
                <?php if (!$selected_period): ?>
                    <p class="muted">Показаны задания класса за все периоды.</p>
                <?php endif; ?>
                <div class="student-count-grid">
                    <div><span>Сдано</span><strong><?= $homework_counts['done'] ?></strong></div>
                    <div><span>Не сдано</span><strong><?= $homework_counts['not_done'] ?></strong></div>
                    <div><span>С опозданием</span><strong><?= $homework_counts['late'] ?></strong></div>
                </div>
            </section>

            <section class="section student-page-section">
                <h2>Заметки учителя</h2>
                <form method="POST" class="student-note-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_note">
                    <input type="hidden" name="student_id" value="<?= $student_id ?>">
                    <input type="hidden" name="class_id" value="<?= $class_id ?>">
                    <label for="student-note">Новая заметка</label>
                    <textarea id="student-note" name="note" maxlength="5000" required></textarea>
                    <button class="btn btn-primary" type="submit">Добавить заметку</button>
                </form>

                <?php if ($notes): ?>
                    <div class="student-notes-list">
                        <?php foreach ($notes as $note): ?>
                            <article class="student-note">
                                <div class="student-note-header">
                                    <strong><?= htmlspecialchars($note['teacher_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <time datetime="<?= htmlspecialchars($note['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($note['created_at'])), ENT_QUOTES, 'UTF-8') ?></time>
                                </div>
                                <p><?= nl2br(htmlspecialchars($note['note'], ENT_QUOTES, 'UTF-8')) ?></p>
                                <?php if ((int)$note['teacher_id'] === $teacher_id): ?>
                                    <form method="POST" class="student-note-delete-form" onsubmit="return confirm('Удалить эту заметку?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_note">
                                        <input type="hidden" name="student_id" value="<?= $student_id ?>">
                                        <input type="hidden" name="class_id" value="<?= $class_id ?>">
                                        <input type="hidden" name="note_id" value="<?= (int)$note['id'] ?>">
                                        <button class="btn btn-secondary" type="submit">Удалить свою заметку</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="muted">Заметок пока нет.</p>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
