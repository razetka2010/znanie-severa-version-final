<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireSchoolAdmin();

$pdo = getDatabaseConnection();
$context = getSchoolAdminContext($pdo);
$school_id = (int)$context['school_id'];
$school = $context['school'];
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');
$curriculum_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)
    ?: (filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0);

function curriculumRowsForSchool(PDO $pdo, int $curriculum_id, int $school_id): array
{
    $stmt = $pdo->prepare("
        SELECT cs.grade_level, cs.subject_id, cs.hours_per_week, s.name AS subject_name
        FROM curriculum_subjects cs
        JOIN curriculum c ON c.id = cs.curriculum_id
        JOIN subjects s ON s.id = cs.subject_id AND s.school_id = c.school_id
        WHERE cs.curriculum_id = ? AND c.school_id = ?
        ORDER BY cs.grade_level, s.name
    ");
    $stmt->execute([$curriculum_id, $school_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    if (in_array($action, ['add', 'edit'], true)) {
        $name = trim((string)($_POST['name'] ?? ''));
        $academic_year = trim((string)($_POST['academic_year'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $posted_rows = $_POST['curriculum_subjects'] ?? [];
        $rows = [];
        $errors = [];

        if ($name === '') {
            $errors[] = 'Введите название учебного плана.';
        }
        if ($academic_year === '' || strlen($academic_year) > 20) {
            $errors[] = 'Укажите учебный год длиной не более 20 символов.';
        }
        if (!is_array($posted_rows)) {
            $errors[] = 'Некорректный список предметов.';
        } else {
            foreach ($posted_rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $grade_level = filter_var($row['grade_level'] ?? null, FILTER_VALIDATE_INT);
                $subject_id = filter_var($row['subject_id'] ?? null, FILTER_VALIDATE_INT);
                $hours = filter_var($row['hours_per_week'] ?? null, FILTER_VALIDATE_INT);
                if (!$grade_level && !$subject_id && !$hours) {
                    continue;
                }
                if (!$grade_level || !$subject_id || !$hours || $hours < 1 || $hours > 255) {
                    $errors[] = 'Для каждой строки выберите параллель и предмет, часы должны быть от 1 до 255.';
                    continue;
                }
                $key = $grade_level . ':' . $subject_id;
                if (isset($rows[$key])) {
                    $errors[] = 'Параллель и предмет не должны повторяться.';
                    continue;
                }
                $rows[$key] = [
                    'grade_level' => $grade_level,
                    'subject_id' => $subject_id,
                    'hours_per_week' => $hours
                ];
            }
        }

        if ($action === 'edit' && $curriculum_id < 1) {
            $errors[] = 'Учебный план не найден.';
        }

        if (!$errors) {
            try {
                $grades = array_values(array_unique(array_column($rows, 'grade_level')));
                $subject_ids = array_values(array_unique(array_column($rows, 'subject_id')));
                $valid_grades = [];
                $grade_stmt = $pdo->prepare('SELECT DISTINCT grade_level FROM classes WHERE school_id = ? AND is_active = 1');
                $grade_stmt->execute([$school_id]);
                $valid_grades = array_map('intval', $grade_stmt->fetchAll(PDO::FETCH_COLUMN));
                foreach ($grades as $grade_level) {
                    if (!in_array((int)$grade_level, $valid_grades, true)) {
                        throw new InvalidArgumentException('Параллель должна соответствовать активному классу вашей школы.');
                    }
                }
                if ($subject_ids) {
                    $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
                    $subject_stmt = $pdo->prepare("SELECT id FROM subjects WHERE school_id = ? AND id IN ($placeholders)");
                    $subject_stmt->execute(array_merge([$school_id], $subject_ids));
                    if (count($subject_stmt->fetchAll(PDO::FETCH_COLUMN)) !== count($subject_ids)) {
                        throw new InvalidArgumentException('Один или несколько предметов не принадлежат вашей школе.');
                    }
                }

                $pdo->beginTransaction();
                if ($action === 'add') {
                    $stmt = $pdo->prepare("
                        INSERT INTO curriculum (school_id, name, academic_year, description, grades, subjects, is_active, created_by)
                        VALUES (?, ?, ?, ?, '[]', '[]', ?, ?)
                    ");
                    $stmt->execute([$school_id, $name, $academic_year, $description, $is_active, (int)$_SESSION['user_id']]);
                    $curriculum_id = (int)$pdo->lastInsertId();
                } else {
                    $owned_stmt = $pdo->prepare('SELECT id FROM curriculum WHERE id = ? AND school_id = ? FOR UPDATE');
                    $owned_stmt->execute([$curriculum_id, $school_id]);
                    if (!$owned_stmt->fetchColumn()) {
                        throw new InvalidArgumentException('Учебный план не найден в вашей школе.');
                    }
                    $stmt = $pdo->prepare("
                        UPDATE curriculum
                        SET name = ?, academic_year = ?, description = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([$name, $academic_year, $description, $is_active, $curriculum_id, $school_id]);
                    $delete_stmt = $pdo->prepare('DELETE FROM curriculum_subjects WHERE curriculum_id = ?');
                    $delete_stmt->execute([$curriculum_id]);
                }

                $insert_stmt = $pdo->prepare("
                    INSERT INTO curriculum_subjects (curriculum_id, subject_id, grade_level, hours_per_week)
                    VALUES (?, ?, ?, ?)
                ");
                foreach ($rows as $row) {
                    $insert_stmt->execute([
                        $curriculum_id,
                        $row['subject_id'],
                        $row['grade_level'],
                        $row['hours_per_week']
                    ]);
                }

                $legacy_subjects_stmt = $pdo->prepare("
                    SELECT s.name, MIN(cs.hours_per_week) AS hours
                    FROM curriculum_subjects cs
                    JOIN subjects s ON s.id = cs.subject_id AND s.school_id = ?
                    WHERE cs.curriculum_id = ?
                    GROUP BY s.id, s.name
                    ORDER BY s.name
                ");
                $legacy_subjects_stmt->execute([$school_id, $curriculum_id]);
                $legacy_subjects = $legacy_subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
                $legacy_stmt = $pdo->prepare('UPDATE curriculum SET grades = ?, subjects = ? WHERE id = ? AND school_id = ?');
                $legacy_stmt->execute([
                    json_encode($grades, JSON_UNESCAPED_UNICODE),
                    json_encode($legacy_subjects, JSON_UNESCAPED_UNICODE),
                    $curriculum_id,
                    $school_id
                ]);
                $pdo->commit();

                logUserAction($pdo, $action === 'add' ? 'curriculum_created' : 'curriculum_updated', json_encode([
                    'school_id' => $school_id,
                    'curriculum_id' => $curriculum_id,
                    'academic_year' => $academic_year,
                    'subject_rows' => count($rows)
                ], JSON_UNESCAPED_UNICODE));
                $_SESSION['success_message'] = $action === 'add' ? 'Учебный план создан.' : 'Учебный план обновлён.';
                header('Location: curriculum.php');
                exit;
            } catch (InvalidArgumentException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = $e->getMessage();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("\n", $errors);
        }
    } elseif ($action === 'apply' && $curriculum_id > 0) {
        $class_id = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        try {
            $pdo->beginTransaction();
            $plan_stmt = $pdo->prepare('SELECT id FROM curriculum WHERE id = ? AND school_id = ? AND is_active = 1 FOR UPDATE');
            $plan_stmt->execute([$curriculum_id, $school_id]);
            if (!$plan_stmt->fetchColumn()) {
                throw new InvalidArgumentException('Активный учебный план не найден в вашей школе.');
            }
            $class_stmt = $pdo->prepare('SELECT id, grade_level FROM classes WHERE id = ? AND school_id = ? AND is_active = 1 FOR UPDATE');
            $class_stmt->execute([$class_id, $school_id]);
            $class = $class_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$class) {
                throw new InvalidArgumentException('Класс не найден в вашей школе.');
            }
            $apply_stmt = $pdo->prepare("
                INSERT IGNORE INTO class_subjects (school_id, class_id, subject_id, teacher_id, hours_per_week)
                SELECT ?, ?, cs.subject_id, NULL, cs.hours_per_week
                FROM curriculum_subjects cs
                JOIN subjects s ON s.id = cs.subject_id AND s.school_id = ?
                WHERE cs.curriculum_id = ? AND cs.grade_level = ?
            ");
            $apply_stmt->execute([$school_id, $class_id, $school_id, $curriculum_id, (int)$class['grade_level']]);
            $inserted = $apply_stmt->rowCount();
            if ($inserted === 0) {
                $has_rows_stmt = $pdo->prepare('SELECT COUNT(*) FROM curriculum_subjects WHERE curriculum_id = ? AND grade_level = ?');
                $has_rows_stmt->execute([$curriculum_id, (int)$class['grade_level']]);
                if (!(int)$has_rows_stmt->fetchColumn()) {
                    throw new InvalidArgumentException('В плане нет предметов для параллели этого класса.');
                }
            }
            $pdo->commit();
            logUserAction($pdo, 'curriculum_applied_to_class', json_encode([
                'school_id' => $school_id,
                'curriculum_id' => $curriculum_id,
                'class_id' => $class_id,
                'inserted_subjects' => $inserted
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = "План применён. Добавлено предметов: $inserted. Уже назначенные предметы сохранены без изменений.";
            header('Location: curriculum.php?action=view&id=' . $curriculum_id);
            exit;
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = publicErrorMessage($e, basename(__FILE__));
        }
    } elseif ($action === 'delete' && $curriculum_id > 0) {
        try {
            $pdo->beginTransaction();
            $owned_stmt = $pdo->prepare('SELECT id FROM curriculum WHERE id = ? AND school_id = ? FOR UPDATE');
            $owned_stmt->execute([$curriculum_id, $school_id]);
            if (!$owned_stmt->fetchColumn()) {
                throw new InvalidArgumentException('Учебный план не найден в вашей школе.');
            }
            $stmt = $pdo->prepare('DELETE FROM curriculum_subjects WHERE curriculum_id = ?');
            $stmt->execute([$curriculum_id]);
            $stmt = $pdo->prepare('DELETE FROM curriculum WHERE id = ? AND school_id = ?');
            $stmt->execute([$curriculum_id, $school_id]);
            $pdo->commit();
            logUserAction($pdo, 'curriculum_deleted', json_encode([
                'school_id' => $school_id,
                'curriculum_id' => $curriculum_id
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = 'Учебный план удалён.';
            header('Location: curriculum.php');
            exit;
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = publicErrorMessage($e, basename(__FILE__));
        }
    }
}

$classes_stmt = $pdo->prepare('SELECT id, name, grade_level FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY grade_level, name');
$classes_stmt->execute([$school_id]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
$grade_levels = array_values(array_unique(array_map(static fn($class) => (int)$class['grade_level'], $classes)));
sort($grade_levels);

$subjects_stmt = $pdo->prepare('SELECT id, name FROM subjects WHERE school_id = ? ORDER BY is_active DESC, name');
$subjects_stmt->execute([$school_id]);
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

$curriculum_data = null;
$edit_rows = [];
if (in_array($action, ['edit', 'view'], true) && $curriculum_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM curriculum WHERE id = ? AND school_id = ?');
    $stmt->execute([$curriculum_id, $school_id]);
    $curriculum_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$curriculum_data) {
        $_SESSION['error_message'] = 'Учебный план не найден в вашей школе.';
        header('Location: curriculum.php');
        exit;
    }
    $edit_rows = curriculumRowsForSchool($pdo, $curriculum_id, $school_id);
    foreach ($edit_rows as $row) {
        if (!in_array((int)$row['grade_level'], $grade_levels, true)) {
            $grade_levels[] = (int)$row['grade_level'];
        }
    }
    sort($grade_levels);
}

$list_stmt = $pdo->prepare("
    SELECT c.id, c.name, c.academic_year, c.description, c.is_active, c.created_at,
           (SELECT COUNT(*) FROM curriculum_subjects cs WHERE cs.curriculum_id = c.id) AS subject_rows
    FROM curriculum c
    WHERE c.school_id = ?
    ORDER BY c.created_at DESC
");
$list_stmt->execute([$school_id]);
$curriculums = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
$view_rows = ($action === 'view' && $curriculum_data) ? curriculumRowsForSchool($pdo, $curriculum_id, $school_id) : [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учебные планы - Администратор школы</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/curriculum.css?v=20261111g">
</head>
<body>
<div class="dashboard-container">
    <?php $schoolAdminActivePage = 'curriculum'; require __DIR__ . '/../includes/school_admin_sidebar.php'; ?>
    <main class="main-content school-admin-curriculum-page">
        <header class="content-header">
            <div class="header-title">
                <h1>Учебные планы</h1>
                <p><?= htmlspecialchars($school['full_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
            <div class="header-actions">
                <?php if (in_array($action, ['add', 'edit', 'view'], true)): ?>
                    <a href="curriculum.php" class="btn btn-secondary">← К списку планов</a>
                <?php else: ?>
                    <a href="curriculum.php?action=add" class="btn btn-primary">➕ Создать учебный план</a>
                <?php endif; ?>
            </div>
        </header>
        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= consumeFlashMessage('success_message') ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?= consumeFlashMessage('error_message') ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?= nl2br(htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
            <?php endif; ?>

            <?php if (in_array($action, ['add', 'edit'], true)): ?>
                <div class="curriculum-container">
                    <div class="curriculum-form">
                        <h2><?= $action === 'add' ? 'Создание учебного плана' : 'Редактирование учебного плана' ?></h2>
                        <?php if (!$subjects): ?>
                            <div class="alert alert-error">В школе пока нет предметов. Добавьте их до создания плана.</div>
                        <?php endif; ?>
                        <?php if (!$grade_levels): ?>
                            <div class="alert alert-error">В школе пока нет активных классов, поэтому параллели недоступны.</div>
                        <?php endif; ?>
                        <form method="post" id="curriculum-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="id" value="<?= (int)$curriculum_id ?>">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="name">Название плана</label>
                                    <input id="name" name="name" required maxlength="150" value="<?= htmlspecialchars((string)($curriculum_data['name'] ?? $_POST['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                </div>
                                <div class="form-group">
                                    <label for="academic_year">Учебный год</label>
                                    <input id="academic_year" name="academic_year" required maxlength="20" placeholder="2026-2027" value="<?= htmlspecialchars((string)($curriculum_data['academic_year'] ?? $_POST['academic_year'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= (!$curriculum_data || (int)$curriculum_data['is_active'] === 1) ? 'checked' : '' ?>> <span>Активный план</span></label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="description">Описание</label>
                                <textarea id="description" name="description" rows="3"><?= htmlspecialchars((string)($curriculum_data['description'] ?? $_POST['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                            </div>
                            <div class="form-section">
                                <h3>Параллель × предмет → часов в неделю</h3>
                                <div id="curriculum-rows">
                                    <?php
                                    $form_rows = $_POST['curriculum_subjects'] ?? $edit_rows;
                                    if (!$form_rows) {
                                        $form_rows = [['grade_level' => '', 'subject_id' => '', 'hours_per_week' => 1]];
                                    }
                                    foreach ($form_rows as $index => $row):
                                    ?>
                                        <div class="curriculum-row">
                                            <div>
                                                <label>Параллель</label>
                                                <select name="curriculum_subjects[<?= (int)$index ?>][grade_level]">
                                                    <option value="">Выберите</option>
                                                    <?php foreach ($grade_levels as $grade): ?>
                                                        <option value="<?= (int)$grade ?>" <?= (int)($row['grade_level'] ?? 0) === (int)$grade ? 'selected' : '' ?>><?= (int)$grade ?> класс</option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label>Предмет</label>
                                                <select name="curriculum_subjects[<?= (int)$index ?>][subject_id]">
                                                    <option value="">Выберите</option>
                                                    <?php foreach ($subjects as $subject): ?>
                                                        <option value="<?= (int)$subject['id'] ?>" <?= (int)($row['subject_id'] ?? 0) === (int)$subject['id'] ? 'selected' : '' ?>><?= htmlspecialchars($subject['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div>
                                                <label>Часов в неделю</label>
                                                <input type="number" name="curriculum_subjects[<?= (int)$index ?>][hours_per_week]" min="1" max="255" value="<?= (int)($row['hours_per_week'] ?? 1) ?>">
                                            </div>
                                            <button type="button" class="btn btn-secondary" onclick="this.closest('.curriculum-row').remove()">Убрать</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-secondary" id="add-curriculum-row">➕ Добавить строку</button>
                            </div>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary"><?= $action === 'add' ? 'Создать план' : 'Сохранить изменения' ?></button>
                                <a href="curriculum.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>
            <?php elseif ($action === 'view' && $curriculum_data): ?>
                    <div class="curriculum-container admin-curriculum-view">
                    <div class="curriculum-header">
                        <h2><?= htmlspecialchars($curriculum_data['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                        <div class="curriculum-actions">
                            <a class="btn btn-primary" href="curriculum.php?action=edit&id=<?= $curriculum_id ?>">✏️ Редактировать</a>
                            <?= csrf_post_button('delete', $curriculum_id, '🗑️ Удалить', 'btn btn-danger', 'Удалить учебный план?') ?>
                        </div>
                    </div>
                    <div class="view-sections">
                        <section class="view-section">
                            <h3>Основная информация</h3>
                            <div class="info-grid">
                                <div class="info-item"><label>Учебный год</label><span><?= htmlspecialchars((string)$curriculum_data['academic_year'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></div>
                                <div class="info-item"><label>Статус</label><span class="status-badge <?= (int)$curriculum_data['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>"><?= (int)$curriculum_data['is_active'] === 1 ? 'Активный' : 'Неактивный' ?></span></div>
                                <?php if (!empty($curriculum_data['description'])): ?>
                                    <div class="info-item"><label>Описание</label><span><?= nl2br(htmlspecialchars($curriculum_data['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></span></div>
                                <?php endif; ?>
                            </div>
                        </section>
                        <section class="view-section">
                            <h3>Предметы по параллелям</h3>
                            <div class="table-responsive">
                                <table class="curriculum-data-table">
                                    <thead><tr><th>Параллель</th><th>Предмет</th><th>Часов в неделю</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($view_rows as $row): ?>
                                        <tr><td><?= (int)$row['grade_level'] ?> класс</td><td><?= htmlspecialchars($row['subject_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td><td><?= (int)$row['hours_per_week'] ?> ч/нед</td></tr>
                                    <?php endforeach; ?>
                                    <?php if (!$view_rows): ?><tr><td colspan="3">В плане нет предметов.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section class="view-section">
                            <h3>Применить к классу</h3>
                            <p>Уже назначенные предметы и учителя останутся без изменений.</p>
                            <form method="post" class="form-grid">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="apply">
                                <input type="hidden" name="id" value="<?= $curriculum_id ?>">
                                <div class="form-group">
                                    <label for="class_id">Класс</label>
                                    <select id="class_id" name="class_id" required>
                                        <option value="">Выберите класс</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?= (int)$class['id'] ?>"><?= htmlspecialchars($class['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= (int)$class['grade_level'] ?> класс)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Применить к классу</button></div>
                            </form>
                        </section>
                    </div>
                </div>
            <?php else: ?>
                <div class="curriculum-container">
                    <div class="curriculum-table">
                        <div class="table-responsive">
                            <table class="curriculum-data-table">
                                <thead><tr><th>Название</th><th>Учебный год</th><th>Предметных строк</th><th>Статус</th><th>Создан</th><th>Действия</th></tr></thead>
                                <tbody>
                                <?php foreach ($curriculums as $plan): ?>
                                    <tr>
                                        <td><div class="curriculum-name"><?= htmlspecialchars($plan['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php if ($plan['description']): ?><div class="curriculum-description"><?= htmlspecialchars($plan['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?></td>
                                        <td><?= htmlspecialchars((string)$plan['academic_year'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                        <td><?= (int)$plan['subject_rows'] ?></td>
                                        <td><span class="status-badge <?= $plan['is_active'] ? 'status-active' : 'status-inactive' ?>"><?= $plan['is_active'] ? 'Активный' : 'Неактивный' ?></span></td>
                                        <td><?= htmlspecialchars(date('d.m.Y', strtotime($plan['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="action-buttons">
                                            <a class="btn-action btn-view" title="Просмотреть" href="curriculum.php?action=view&id=<?= (int)$plan['id'] ?>">👁️</a>
                                            <a class="btn-action btn-edit" title="Редактировать" href="curriculum.php?action=edit&id=<?= (int)$plan['id'] ?>">✏️</a>
                                            <?= csrf_post_button('delete', (int)$plan['id'], '🗑️', 'btn-action btn-delete', 'Удалить учебный план?') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$curriculums): ?><tr><td colspan="6" class="empty-state">Учебных планов пока нет.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<template id="curriculum-row-template">
    <div class="curriculum-row">
        <div><label>Параллель</label><select data-field="grade_level" required><option value="">Выберите</option><?php foreach ($grade_levels as $grade): ?><option value="<?= (int)$grade ?>"><?= (int)$grade ?> класс</option><?php endforeach; ?></select></div>
        <div><label>Предмет</label><select data-field="subject_id" required><option value="">Выберите</option><?php foreach ($subjects as $subject): ?><option value="<?= (int)$subject['id'] ?>"><?= htmlspecialchars($subject['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option><?php endforeach; ?></select></div>
        <div><label>Часов в неделю</label><input data-field="hours_per_week" type="number" min="1" max="255" value="1" required></div>
        <button type="button" class="btn btn-secondary" onclick="this.closest('.curriculum-row').remove()">Убрать</button>
    </div>
</template>
<script>
const curriculumRowsContainer = document.getElementById('curriculum-rows');
if (curriculumRowsContainer) {
    const rowIndexes = Array.from(curriculumRowsContainer.querySelectorAll('[name^="curriculum_subjects["]'))
        .map(function (field) {
            const match = field.name.match(/^curriculum_subjects\[(\d+)\]/);
            return match ? Number(match[1]) : -1;
        });
    curriculumRowsContainer.dataset.nextIndex = String(Math.max(-1, ...rowIndexes) + 1);
}
document.getElementById('add-curriculum-row')?.addEventListener('click', function () {
    const container = document.getElementById('curriculum-rows');
    const index = Number(container.dataset.nextIndex || container.querySelectorAll('.curriculum-row').length);
    container.dataset.nextIndex = String(index + 1);
    const row = document.getElementById('curriculum-row-template').content.firstElementChild.cloneNode(true);
    row.querySelectorAll('[data-field]').forEach(function (field) {
        field.name = `curriculum_subjects[${index}][${field.dataset.field}]`;
    });
    container.appendChild(row);
});
</script>
</body>
</html>
