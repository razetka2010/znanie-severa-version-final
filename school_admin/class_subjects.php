<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireSchoolAdmin();

$pdo = getDatabaseConnection();
$context = getSchoolAdminContext($pdo);
$school_id = (int)$context['school_id'];
$school = $context['school'];
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $class_id = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $subject_id = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $teacher_id = filter_var($_POST['teacher_id'] ?? null, FILTER_VALIDATE_INT);
    $teacher_id = $teacher_id ?: null;
    $hours_per_week = filter_var($_POST['hours_per_week'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    $mapping_id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;

    try {
        if ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM class_subjects WHERE id = ? AND school_id = ?');
            $stmt->execute([$mapping_id, $school_id]);
            if ($stmt->rowCount() !== 1) {
                throw new InvalidArgumentException('Назначение предмета не найдено в вашей школе.');
            }
            logUserAction($pdo, 'class_subject_deleted', json_encode([
                'school_id' => $school_id,
                'class_subject_id' => $mapping_id
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = 'Предмет удалён из нагрузки класса.';
        } elseif (in_array($action, ['add', 'update'], true)) {
            if ($class_id < 1 || $subject_id < 1 || $hours_per_week < 1 || $hours_per_week > 255) {
                throw new InvalidArgumentException('Выберите класс и предмет, часы должны быть от 1 до 255.');
            }
            $class_stmt = $pdo->prepare('SELECT id FROM classes WHERE id = ? AND school_id = ? AND is_active = 1');
            $class_stmt->execute([$class_id, $school_id]);
            if (!$class_stmt->fetchColumn()) {
                throw new InvalidArgumentException('Класс не найден или неактивен.');
            }
            $subject_stmt = $pdo->prepare('SELECT id FROM subjects WHERE id = ? AND school_id = ?');
            $subject_stmt->execute([$subject_id, $school_id]);
            if (!$subject_stmt->fetchColumn()) {
                throw new InvalidArgumentException('Предмет не принадлежит вашей школе.');
            }
            if ($teacher_id !== null) {
                $teacher_stmt = $pdo->prepare("
                    SELECT u.id
                    FROM users u
                    JOIN roles r ON r.id = u.role_id
                    WHERE u.id = ? AND u.school_id = ? AND u.is_active = 1
                      AND r.name IN ('teacher', 'class_teacher')
                ");
                $teacher_stmt->execute([$teacher_id, $school_id]);
                if (!$teacher_stmt->fetchColumn()) {
                    throw new InvalidArgumentException('Учитель не найден или не относится к вашей школе.');
                }
            }

            if ($action === 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO class_subjects (school_id, class_id, subject_id, teacher_id, hours_per_week)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$school_id, $class_id, $subject_id, $teacher_id, $hours_per_week]);
                $mapping_id = (int)$pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare("
                    UPDATE class_subjects
                    SET class_id = ?, subject_id = ?, teacher_id = ?, hours_per_week = ?
                    WHERE id = ? AND school_id = ?
                ");
                $stmt->execute([$class_id, $subject_id, $teacher_id, $hours_per_week, $mapping_id, $school_id]);
                if ($stmt->rowCount() < 1) {
                    $check_stmt = $pdo->prepare('SELECT id FROM class_subjects WHERE id = ? AND school_id = ?');
                    $check_stmt->execute([$mapping_id, $school_id]);
                    if (!$check_stmt->fetchColumn()) {
                        throw new InvalidArgumentException('Назначение предмета не найдено в вашей школе.');
                    }
                }
            }
            logUserAction($pdo, $action === 'add' ? 'class_subject_created' : 'class_subject_updated', json_encode([
                'school_id' => $school_id,
                'class_subject_id' => $mapping_id,
                'class_id' => $class_id,
                'subject_id' => $subject_id,
                'teacher_id' => $teacher_id,
                'hours_per_week' => $hours_per_week
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = $action === 'add' ? 'Предмет добавлен в нагрузку класса.' : 'Нагрузка класса обновлена.';
        }
        header('Location: class_subjects.php');
        exit;
    } catch (InvalidArgumentException $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: class_subjects.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: class_subjects.php');
        exit;
    }
}

$classes_stmt = $pdo->prepare('SELECT id, name, grade_level FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY grade_level, name');
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
$subjects_stmt = $pdo->prepare('SELECT id, name FROM subjects WHERE school_id = ? ORDER BY name');
$subjects_stmt->execute([$school_id]);
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
$teachers_stmt = $pdo->prepare("
    SELECT u.id, u.full_name
    FROM users u
    JOIN roles r ON r.id = u.role_id
    WHERE u.school_id = ? AND u.is_active = 1 AND r.name IN ('teacher', 'class_teacher')
    ORDER BY u.full_name
");
$teachers_stmt->execute([$school_id]);
$teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);

$filter_class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$week_start = new DateTimeImmutable('monday this week');
$week_end = $week_start->modify('+6 days');
$sql = "
    SELECT cs.id, cs.class_id, cs.subject_id, cs.teacher_id, cs.hours_per_week,
           c.name AS class_name, c.grade_level, s.name AS subject_name, u.full_name AS teacher_name,
           COUNT(sc.id) AS scheduled_this_week
    FROM class_subjects cs
    JOIN classes c ON c.id = cs.class_id AND c.school_id = cs.school_id
    JOIN subjects s ON s.id = cs.subject_id AND s.school_id = cs.school_id
    LEFT JOIN users u ON u.id = cs.teacher_id AND u.school_id = cs.school_id
    LEFT JOIN schedule sc ON sc.school_id = cs.school_id AND sc.class_id = cs.class_id
        AND sc.subject_id = cs.subject_id AND sc.lesson_date BETWEEN ? AND ?
    WHERE cs.school_id = ?
";
$params = [$week_start->format('Y-m-d'), $week_end->format('Y-m-d'), $school_id];
if ($filter_class_id > 0) {
    $sql .= ' AND cs.class_id = ?';
    $params[] = $filter_class_id;
}
$sql .= ' GROUP BY cs.id, cs.class_id, cs.subject_id, cs.teacher_id, cs.hours_per_week, c.name, c.grade_level, s.name, u.full_name ORDER BY c.grade_level, c.name, s.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Предметы классов - Администратор школы</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/curriculum.css?v=20261111e">
</head>
<body>
<div class="dashboard-container">
    <?php $schoolAdminActivePage = 'class_subjects'; require __DIR__ . '/../includes/school_admin_sidebar.php'; ?>
    <main class="main-content school-admin-class-subjects-page">
        <header class="content-header">
            <div class="header-title">
                <h1>Предметы и нагрузка классов</h1>
                <p><?= htmlspecialchars($school['full_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
        </header>
        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?><div class="alert alert-success"><?= consumeFlashMessage('success_message') ?></div><?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?><div class="alert alert-error"><?= consumeFlashMessage('error_message') ?></div><?php endif; ?>
            <section class="curriculum-container">
                <h2>Добавить предмет классу</h2>
                <form method="post" class="class-subjects-form class-subjects-add-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <div><label for="add-class">Класс</label><select id="add-class" name="class_id" required><option value="">Выберите</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>"><?= htmlspecialchars($class['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= (int)$class['grade_level'] ?> кл.)</option><?php endforeach; ?></select></div>
                    <div><label for="add-subject">Предмет</label><select id="add-subject" name="subject_id" required><option value="">Выберите</option><?php foreach ($subjects as $subject): ?><option value="<?= (int)$subject['id'] ?>"><?= htmlspecialchars($subject['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                    <div><label for="add-hours">Часов по плану</label><input id="add-hours" type="number" name="hours_per_week" min="1" max="255" value="1" required></div>
                    <div><label for="add-teacher">Учитель (необязательно)</label><select id="add-teacher" name="teacher_id"><option value="">Не назначен</option><?php foreach ($teachers as $teacher): ?><option value="<?= (int)$teacher['id'] ?>"><?= htmlspecialchars($teacher['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                    <button class="btn btn-primary" type="submit">Добавить</button>
                </form>
            </section>
            <section class="curriculum-container">
                <form method="get" class="form-grid class-subjects-filter">
                    <div class="form-group"><label for="class-filter">Фильтр по классу</label><select id="class-filter" name="class_id"><option value="">Все классы</option><?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>" <?= $filter_class_id === (int)$class['id'] ? 'selected' : '' ?>><?= htmlspecialchars($class['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>&nbsp;</label><button class="btn btn-secondary" type="submit">Показать</button></div>
                </form>
                <div class="table-responsive">
                    <table class="curriculum-data-table class-subjects-table">
                        <thead><tr><th>Класс</th><th>Предмет</th><th>По плану</th><th>В расписании на этой неделе</th><th>Учитель</th><th>Действия</th></tr></thead>
                        <tbody>
                        <?php foreach ($mappings as $mapping): ?>
                            <tr>
                                <td><span class="class-name"><?= htmlspecialchars($mapping['class_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><span class="class-grade"><?= (int)$mapping['grade_level'] ?> класс</span></td>
                                <td class="subject-cell"><?= htmlspecialchars($mapping['subject_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td><span class="load-value"><?= (int)$mapping['hours_per_week'] ?></span><span class="load-unit">ч/нед</span></td>
                                <td class="<?= (int)$mapping['scheduled_this_week'] > (int)$mapping['hours_per_week'] ? 'load-warning' : '' ?>"><span class="load-value"><?= (int)$mapping['scheduled_this_week'] ?></span><?php if ((int)$mapping['scheduled_this_week'] > (int)$mapping['hours_per_week']): ?><span class="load-exceeded">Выше нормы</span><?php endif; ?></td>
                                <td class="teacher-cell"><?= htmlspecialchars($mapping['teacher_name'] ?? 'Не назначен', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                <td class="class-subject-actions">
                                    <button
                                        type="button"
                                        class="btn btn-secondary class-subject-edit-trigger"
                                        data-id="<?= (int)$mapping['id'] ?>"
                                        data-class-id="<?= (int)$mapping['class_id'] ?>"
                                        data-class-name="<?= htmlspecialchars($mapping['class_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                        data-subject-id="<?= (int)$mapping['subject_id'] ?>"
                                        data-subject-name="<?= htmlspecialchars($mapping['subject_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                                        data-hours="<?= (int)$mapping['hours_per_week'] ?>"
                                        data-teacher-id="<?= (int)($mapping['teacher_id'] ?? 0) ?>"
                                    >Изменить</button>
                                    <?= csrf_post_button('delete', (int)$mapping['id'], 'Удалить', 'btn btn-danger', 'Удалить предмет из нагрузки класса?') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$mappings): ?><tr><td colspan="6" class="empty-state">Назначений предметов пока нет.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <dialog class="class-subject-modal" id="class-subject-modal" aria-labelledby="class-subject-modal-title">
                <div class="class-subject-modal-header">
                    <div>
                        <h2 id="class-subject-modal-title">Изменить нагрузку</h2>
                        <p id="class-subject-modal-description"></p>
                    </div>
                    <button type="button" class="class-subject-modal-close" aria-label="Закрыть" data-close-class-subject-modal>&times;</button>
                </div>
                <form method="post" class="class-subject-modal-form" id="class-subject-modal-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id">
                    <input type="hidden" name="class_id">
                    <input type="hidden" name="subject_id">
                    <div class="form-group">
                        <label for="modal-hours">Часов по плану в неделю</label>
                        <input id="modal-hours" type="number" name="hours_per_week" min="1" max="255" required>
                    </div>
                    <div class="form-group">
                        <label for="modal-teacher">Учитель</label>
                        <select id="modal-teacher" name="teacher_id">
                            <option value="">Не назначен</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int)$teacher['id'] ?>"><?= htmlspecialchars($teacher['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="class-subject-modal-actions">
                        <button type="button" class="btn btn-secondary" data-close-class-subject-modal>Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </form>
            </dialog>
        </div>
    </main>
</div>
<script>
const classSubjectModal = document.getElementById('class-subject-modal');
const classSubjectModalForm = document.getElementById('class-subject-modal-form');

document.querySelectorAll('.class-subject-edit-trigger').forEach(function (button) {
    button.addEventListener('click', function () {
        classSubjectModalForm.elements.id.value = button.dataset.id;
        classSubjectModalForm.elements.class_id.value = button.dataset.classId;
        classSubjectModalForm.elements.subject_id.value = button.dataset.subjectId;
        classSubjectModalForm.elements.hours_per_week.value = button.dataset.hours;
        classSubjectModalForm.elements.teacher_id.value = button.dataset.teacherId === '0' ? '' : button.dataset.teacherId;
        document.getElementById('class-subject-modal-description').textContent =
            button.dataset.className + ' · ' + button.dataset.subjectName;
        classSubjectModal.showModal();
    });
});

document.querySelectorAll('[data-close-class-subject-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        classSubjectModal.close();
    });
});

classSubjectModal.addEventListener('click', function (event) {
    if (event.target === classSubjectModal) {
        classSubjectModal.close();
    }
});
</script>
</body>
</html>
