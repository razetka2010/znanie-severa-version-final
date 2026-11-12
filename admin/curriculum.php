<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
requireSuperAdmin();

$pdo = getDatabaseConnection();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$curriculum_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

function saveCurriculumSubjects(PDO $pdo, int $curriculum_id, int $school_id, array $grades, array $subjects): void
{
    $valid_grades = [];
    foreach ($grades as $grade) {
        if (!is_scalar($grade)) {
            throw new InvalidArgumentException('Укажите корректные параллели.');
        }
        $grade_level = filter_var($grade, FILTER_VALIDATE_INT);
        if ($grade_level === false || $grade_level < 1 || $grade_level > 11) {
            throw new InvalidArgumentException('Параллель должна быть от 1 до 11.');
        }
        $valid_grades[] = $grade_level;
    }
    $valid_grades = array_values(array_unique($valid_grades));
    $insert = $pdo->prepare('
        INSERT INTO curriculum_subjects (curriculum_id, subject_id, grade_level, hours_per_week)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE hours_per_week = VALUES(hours_per_week)
    ');
    $subject_lookup = $pdo->prepare('SELECT id FROM subjects WHERE school_id = ? AND LOWER(name) = LOWER(?) LIMIT 1');
    foreach ($subjects as $subject) {
        if (!is_array($subject)) {
            throw new InvalidArgumentException('Некорректная запись предмета.');
        }
        $name_value = $subject['name'] ?? '';
        $hours_value = $subject['hours'] ?? null;
        if (!is_scalar($name_value) || ($hours_value !== null && !is_scalar($hours_value))) {
            throw new InvalidArgumentException('Некорректное название предмета или число часов.');
        }
        $subject_name = trim((string)$name_value);
        $hours = filter_var($hours_value, FILTER_VALIDATE_INT);
        if ($subject_name === '' && ($hours === false || $hours === null || $hours === 0)) {
            continue;
        }
        if ($subject_name === '' || $hours === false || $hours < 1 || $hours > 255) {
            throw new InvalidArgumentException('Для каждого предмета укажите название и число часов от 1 до 255.');
        }
        $subject_lookup->execute([$school_id, $subject_name]);
        $subject_id = $subject_lookup->fetchColumn();
        if (!$subject_id && $valid_grades) {
            throw new InvalidArgumentException('Предмет «' . $subject_name . '» не найден в выбранной школе. Сначала добавьте его в список предметов школы.');
        }
        if ($subject_id) {
            foreach ($valid_grades as $grade) {
                $insert->execute([$curriculum_id, (int)$subject_id, $grade, $hours]);
            }
        }
    }
}

function decodeCurriculumSubjects(?string $json): array
{
    $subjects = json_decode($json ?? '[]', true);

    if (!is_array($subjects)) {
        return [];
    }

    $normalized = [];
    foreach ($subjects as $subject) {
        if (is_array($subject)) {
            $normalized[] = [
                'name' => (string)($subject['name'] ?? ''),
                'hours' => (string)($subject['hours'] ?? '')
            ];
        } elseif (is_scalar($subject)) {
            $normalized[] = [
                'name' => (string)$subject,
                'hours' => ''
            ];
        }
    }

    return $normalized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    if ($action === 'add' || ($action === 'edit' && $curriculum_id > 0)) {
        $name = trim((string)($_POST['name'] ?? ''));
        $school_id = filter_var($_POST['school_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $academic_year = trim((string)($_POST['academic_year'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $grade_values = is_array($_POST['grades'] ?? null) ? $_POST['grades'] : [];
        $subject_values = is_array($_POST['subjects'] ?? null) ? $_POST['subjects'] : [];
        $grades = json_encode($grade_values, JSON_UNESCAPED_UNICODE);
        $subjects = json_encode($subject_values, JSON_UNESCAPED_UNICODE);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $school_stmt = $pdo->prepare('SELECT id FROM schools WHERE id = ?');
        $school_stmt->execute([$school_id]);
        if ($name === '' || $school_id < 1 || !$school_stmt->fetchColumn() || $academic_year === '' || strlen($academic_year) > 20) {
            $error = 'Проверьте название, школу и учебный год (не более 20 символов).';
        } else {
        $replace_relations = true;
        if ($action === 'edit') {
            $existing_stmt = $pdo->prepare('SELECT school_id, grades, subjects FROM curriculum WHERE id = ?');
            $existing_stmt->execute([$curriculum_id]);
            $existing = $existing_stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $decoded_old_grades = json_decode($existing['grades'] ?? '[]', true);
                $old_grades = is_array($decoded_old_grades) ? array_map('intval', $decoded_old_grades) : [];
                $new_grades = array_map('intval', $grade_values);
                sort($old_grades);
                sort($new_grades);
                $old_subjects = array_values(array_filter(decodeCurriculumSubjects($existing['subjects']), static fn($subject) => $subject['name'] !== ''));
                $new_subjects = array_values(array_filter(decodeCurriculumSubjects($subjects), static fn($subject) => $subject['name'] !== ''));
                $replace_relations = (int)$existing['school_id'] !== $school_id
                    || $old_grades !== $new_grades
                    || $old_subjects !== $new_subjects;
            }
        }
        try {
            $pdo->beginTransaction();
            if ($action === 'add') {
                $stmt = $pdo->prepare("
                    INSERT INTO curriculum (name, school_id, academic_year, description, grades, subjects, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $school_id, $academic_year, $description, $grades, $subjects, $is_active, (int)$_SESSION['user_id']]);
                $curriculum_id = (int)$pdo->lastInsertId();
            } else {
                $owned_stmt = $pdo->prepare('SELECT id FROM curriculum WHERE id = ? FOR UPDATE');
                $owned_stmt->execute([$curriculum_id]);
                if (!$owned_stmt->fetchColumn()) {
                    throw new InvalidArgumentException('Учебный план не найден.');
                }
                $stmt = $pdo->prepare("
                    UPDATE curriculum
                    SET name = ?, school_id = ?, academic_year = ?, description = ?, grades = ?, subjects = ?,
                        is_active = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$name, $school_id, $academic_year, $description, $grades, $subjects, $is_active, $curriculum_id]);
                if ($replace_relations) {
                    $stmt = $pdo->prepare('DELETE FROM curriculum_subjects WHERE curriculum_id = ?');
                    $stmt->execute([$curriculum_id]);
                }
            }
            if ($action === 'add' || $replace_relations) {
                saveCurriculumSubjects($pdo, $curriculum_id, $school_id, $grade_values, $subject_values);
            }
            $pdo->commit();
            logUserAction($pdo, $action === 'add' ? 'curriculum_created' : 'curriculum_updated', json_encode([
                'curriculum_id' => $curriculum_id,
                'school_id' => $school_id,
                'academic_year' => $academic_year
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = $action === 'add' ? 'Учебный план успешно создан!' : 'Учебный план успешно обновлен!';
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

    if ($action === 'delete' && $curriculum_id > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('DELETE FROM curriculum_subjects WHERE curriculum_id = ?');
            $stmt->execute([$curriculum_id]);
            $stmt = $pdo->prepare('DELETE FROM curriculum WHERE id = ?');
            $stmt->execute([$curriculum_id]);
            $pdo->commit();
            logUserAction($pdo, 'curriculum_deleted', json_encode(['curriculum_id' => $curriculum_id], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = 'Учебный план успешно удален!';
            header('Location: curriculum.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
            header('Location: curriculum.php');
            exit;
        }
    }

    if ($action === 'apply' && $curriculum_id > 0) {
        $class_id = filter_var($_POST['class_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        try {
            $pdo->beginTransaction();
            $plan_stmt = $pdo->prepare('SELECT school_id FROM curriculum WHERE id = ? AND is_active = 1 FOR UPDATE');
            $plan_stmt->execute([$curriculum_id]);
            $plan_school_id = $plan_stmt->fetchColumn();
            $class_stmt = $pdo->prepare('SELECT grade_level FROM classes WHERE id = ? AND school_id = ? AND is_active = 1 FOR UPDATE');
            $class_stmt->execute([$class_id, (int)$plan_school_id]);
            $grade_level = $class_stmt->fetchColumn();
            if ($plan_school_id === false || $grade_level === false) {
                throw new InvalidArgumentException('Активный план или класс не найден в одной школе.');
            }
            $stmt = $pdo->prepare("
                INSERT IGNORE INTO class_subjects (school_id, class_id, subject_id, teacher_id, hours_per_week)
                SELECT ?, ?, cs.subject_id, NULL, cs.hours_per_week
                FROM curriculum_subjects cs
                JOIN subjects s ON s.id = cs.subject_id AND s.school_id = ?
                WHERE cs.curriculum_id = ? AND cs.grade_level = ?
            ");
            $stmt->execute([(int)$plan_school_id, $class_id, (int)$plan_school_id, $curriculum_id, (int)$grade_level]);
            $inserted = $stmt->rowCount();
            if ($inserted === 0) {
                $grade_stmt = $pdo->prepare('SELECT COUNT(*) FROM curriculum_subjects WHERE curriculum_id = ? AND grade_level = ?');
                $grade_stmt->execute([$curriculum_id, (int)$grade_level]);
                if (!(int)$grade_stmt->fetchColumn()) {
                    throw new InvalidArgumentException('В плане нет предметов для параллели этого класса.');
                }
            }
            $pdo->commit();
            logUserAction($pdo, 'curriculum_applied_to_class', json_encode([
                'school_id' => (int)$plan_school_id,
                'curriculum_id' => $curriculum_id,
                'class_id' => $class_id,
                'inserted_subjects' => $inserted
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = "План применён. Добавлено предметов: $inserted. Ранее назначенные предметы сохранены.";
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
    }
}

$curriculum_data = null;
if (($action === 'edit' || $action === 'view') && $curriculum_id > 0) {
    $stmt = $pdo->prepare("SELECT c.*, s.full_name as school_name 
                          FROM curriculum c 
                          LEFT JOIN schools s ON c.school_id = s.id 
                          WHERE c.id = ?");
    $stmt->execute([$curriculum_id]);
    $curriculum_data = $stmt->fetch();

    if (!$curriculum_data) {
        $_SESSION['error_message'] = "Учебный план не найден!";
        header('Location: curriculum.php');
        exit;
    }
}

// Получение списка школ для выпадающего списка
$schools_stmt = $pdo->prepare("SELECT id, full_name FROM schools WHERE status = ? ORDER BY full_name");
$schools_stmt->execute(['active']);
$schools = $schools_stmt->fetchAll();

// Получение списка учебных планов из БД
$sql = "SELECT c.*, s.full_name as school_name 
        FROM curriculum c 
        LEFT JOIN schools s ON c.school_id = s.id 
        ORDER BY c.created_at DESC";
$curriculums_stmt = $pdo->prepare($sql);
$curriculums_stmt->execute();
$curriculums = $curriculums_stmt->fetchAll();

$relations_stmt = $pdo->prepare("
    SELECT cs.curriculum_id, cs.grade_level, cs.subject_id, cs.hours_per_week, s.name AS subject_name
    FROM curriculum_subjects cs
    JOIN curriculum c ON c.id = cs.curriculum_id
    JOIN subjects s ON s.id = cs.subject_id AND s.school_id = c.school_id
    ORDER BY cs.grade_level, s.name
");
$relations_stmt->execute();
$relations_by_plan = [];
foreach ($relations_stmt->fetchAll(PDO::FETCH_ASSOC) as $relation) {
    $relations_by_plan[(int)$relation['curriculum_id']][] = $relation;
}
$plan_classes = [];
if ($action === 'view' && $curriculum_data) {
    $class_stmt = $pdo->prepare('SELECT id, name, grade_level FROM classes WHERE school_id = ? AND is_active = 1 ORDER BY grade_level, name');
    $class_stmt->execute([(int)$curriculum_data['school_id']]);
    $plan_classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учебные планы - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/curriculum.css?v=20261111f">
</head>
<body>
<div class="dashboard-container">
    <?php $adminActivePage = 'curriculum'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Учебные планы</h1>
                <p>Главный Администратор • <?php echo htmlspecialchars($_SESSION['user_login']); ?></p>
            </div>
            <div class="header-actions">
                <?php if ($action === 'add' || $action === 'edit' || $action === 'view'): ?>
                    <a href="curriculum.php" class="btn btn-secondary">← Назад к списку</a>
                <?php else: ?>
                    <a href="curriculum.php?action=add" class="btn btn-primary">➕ Создать учебный план</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo consumeFlashMessage('success_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?php echo consumeFlashMessage('error_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo nl2br(htmlspecialchars(str_replace('<br>', "\n", $error), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- Форма добавления/редактирования учебного плана -->
                <div class="curriculum-container">
                    <div class="curriculum-form">
                        <h2><?php echo $action === 'add' ? 'Создание учебного плана' : 'Редактирование учебного плана'; ?></h2>
                        <form method="POST" id="curriculum-form" onsubmit="return validateCurriculumForm()">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$curriculum_id; ?>">
                            <div class="form-section">
                                <h3>Основная информация</h3>
                                <div class="form-grid">
                                    <div class="form-group required">
                                        <label>Название учебного плана</label>
                                        <input type="text" name="name" value="<?php echo htmlspecialchars((string)($curriculum_data['name'] ?? $_POST['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                                    </div>
                                    <div class="form-group required">
                                        <label>Школа</label>
                                        <select name="school_id" required>
                                            <option value="">Выберите школу</option>
                                            <?php foreach ($schools as $school): ?>
                                                <option value="<?php echo $school['id']; ?>"
                                                        <?php echo ($curriculum_data && $curriculum_data['school_id'] == $school['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($school['full_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group required">
                                        <label>Учебный год</label>
                                        <input type="text" name="academic_year" maxlength="20" placeholder="Например, 2026-2027"
                                               value="<?php echo htmlspecialchars((string)($curriculum_data['academic_year'] ?? $_POST['academic_year'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Статус</label>
                                        <label class="checkbox-label">
                                            <input type="checkbox" name="is_active" value="1"
                                                    <?php echo (!$curriculum_data || $curriculum_data['is_active']) ? 'checked' : ''; ?>>
                                            Активный
                                        </label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Описание</label>
                                    <textarea name="description" rows="3" placeholder="Описание учебного плана..."><?php echo $curriculum_data ? htmlspecialchars($curriculum_data['description']) : ''; ?></textarea>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3>Классы</h3>
                                <p>Выберите классы, для которых предназначен этот учебный план:</p>
                                <div class="grades-selection" id="grades-selection">
                                    <?php
                                    $selected_grades = is_array($_POST['grades'] ?? null)
                                        ? $_POST['grades']
                                        : ($curriculum_data ? json_decode($curriculum_data['grades'], true) : []);
                                    for ($grade = 1; $grade <= 11; $grade++):
                                        ?>
                                        <label class="grade-checkbox">
                                            <input type="checkbox" name="grades[]" value="<?php echo $grade; ?>"
                                                    <?php echo in_array($grade, $selected_grades ?: []) ? 'checked' : ''; ?>>
                                            <span class="grade-label"><?php echo $grade; ?> класс</span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3>Предметы</h3>
                                <div id="subjects-container">
                                    <?php
                                    $subjects = is_array($_POST['subjects'] ?? null)
                                        ? decodeCurriculumSubjects(json_encode($_POST['subjects'], JSON_UNESCAPED_UNICODE))
                                        : ($curriculum_data ? decodeCurriculumSubjects($curriculum_data['subjects']) : []);
                                    if (!$subjects) {
                                        $subjects = [['name' => '', 'hours' => '']];
                                    }
                                    foreach ($subjects as $index => $subject):
                                        ?>
                                        <div class="subject-row" data-index="<?php echo $index; ?>">
                                            <input type="text"
                                                   name="subjects[<?php echo $index; ?>][name]"
                                                   class="subject-input"
                                                   placeholder="Название предмета"
                                                   value="<?php echo htmlspecialchars($subject['name']); ?>"
                                                   required>
                                            <input type="number"
                                                   name="subjects[<?php echo $index; ?>][hours]"
                                                   class="hours-input"
                                                   placeholder="Часы в неделю"
                                                   value="<?php echo htmlspecialchars($subject['hours']); ?>"
                                                   min="1"
                                                   max="20"
                                                   required>
                                            <?php if ($index > 0): ?>
                                                <button type="button" class="btn-remove-subject" onclick="removeSubject(<?php echo $index; ?>)">
                                                    ✕ Удалить
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn-add-subject" onclick="addSubject()">
                                    ➕ Добавить предмет
                                </button>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <?php echo $action === 'add' ? 'Создать учебный план' : 'Сохранить изменения'; ?>
                                </button>
                                <a href="curriculum.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'view' && $curriculum_data): ?>
                <!-- Просмотр учебного плана -->
                <div class="curriculum-container admin-curriculum-view">
                    <div class="curriculum-header">
                        <h2><?php echo htmlspecialchars($curriculum_data['name']); ?></h2>
                        <div class="curriculum-actions">
                            <a href="curriculum.php?action=edit&id=<?php echo $curriculum_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                            <?php echo csrf_post_button('delete', (int)$curriculum_data['id'], '🗑️ Удалить', 'btn btn-danger', 'Вы уверены, что хотите удалить этот учебный план?'); ?>
                        </div>
                    </div>

                    <div class="view-sections">
                        <div class="view-section">
                            <h3>Основная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Название:</label>
                                    <span><?php echo htmlspecialchars($curriculum_data['name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Школа:</label>
                                    <span><?php echo htmlspecialchars($curriculum_data['school_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Учебный год:</label>
                                    <span><?php echo htmlspecialchars((string)$curriculum_data['academic_year'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Статус:</label>
                                    <span class="status-badge <?php echo $curriculum_data['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $curriculum_data['is_active'] ? 'Активный' : 'Неактивный'; ?>
                                        </span>
                                </div>
                                <?php if ($curriculum_data['description']): ?>
                                    <div class="info-item">
                                        <label>Описание:</label>
                                        <span><?php echo nl2br(htmlspecialchars($curriculum_data['description'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Классы</h3>
                            <div class="curriculum-grades">
                                <?php
                                $plan_relations = $relations_by_plan[(int)$curriculum_id] ?? [];
                                $grades = array_values(array_unique(array_map(static fn($row) => (int)$row['grade_level'], $plan_relations)));
                                if (!$grades) {
                                    $grades = json_decode($curriculum_data['grades'], true);
                                }
                                if ($grades && is_array($grades)):
                                    foreach ($grades as $grade):
                                        ?>
                                        <span class="grade-badge"><?php echo $grade; ?> класс</span>
                                    <?php
                                    endforeach;
                                else:
                                    echo '<p>Классы не указаны</p>';
                                endif;
                                ?>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Предметы</h3>
                            <div class="subjects-list">
                                <?php
                                $subjects = [];
                                foreach ($plan_relations as $relation) {
                                    $key = (int)$relation['subject_id'];
                                    if (!isset($subjects[$key])) {
                                        $subjects[$key] = ['name' => $relation['subject_name'], 'hours' => (int)$relation['hours_per_week']];
                                    }
                                }
                                if ($subjects):
                                    foreach ($subjects as $subject):
                                        ?>
                                        <div class="subject-item">
                                            <span class="subject-name"><?php echo htmlspecialchars($subject['name']); ?></span>
                                            <span class="subject-hours"><?php echo $subject['hours'] !== '' ? htmlspecialchars($subject['hours']) . ' ч/нед' : 'Часы не указаны'; ?></span>
                                        </div>
                                    <?php
                                    endforeach;
                                else:
                                    echo '<p>Предметы не добавлены</p>';
                                endif;
                                ?>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Применить к классу</h3>
                            <p>Уже назначенные предметы не будут перезаписаны.</p>
                            <form method="POST" class="form-grid">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="apply">
                                <input type="hidden" name="id" value="<?php echo (int)$curriculum_id; ?>">
                                <div class="form-group">
                                    <label for="apply-class">Класс</label>
                                    <select id="apply-class" name="class_id" required>
                                        <option value="">Выберите класс</option>
                                        <?php foreach ($plan_classes as $class): ?>
                                            <option value="<?php echo (int)$class['id']; ?>"><?php echo htmlspecialchars($class['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?> (<?php echo (int)$class['grade_level']; ?> класс)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group"><label>&nbsp;</label><button class="btn btn-primary" type="submit">Применить к классу</button></div>
                            </form>
                        </div>

                        <div class="view-section">
                            <h3>Системная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Дата создания:</label>
                                    <span><?php echo date('d.m.Y H:i', strtotime($curriculum_data['created_at'])); ?></span>
                                </div>
                                <?php if ($curriculum_data['updated_at'] != $curriculum_data['created_at']): ?>
                                    <div class="info-item">
                                        <label>Последнее обновление:</label>
                                        <span><?php echo date('d.m.Y H:i', strtotime($curriculum_data['updated_at'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список учебных планов -->
                <div class="curriculum-container">
                    <div class="curriculum-filters">
                        <form id="curriculum-filters">
                            <div class="filter-grid">
                                <div class="filter-group">
                                    <label>Школа</label>
                                    <select name="school">
                                        <option value="all">Все школы</option>
                                        <?php foreach ($schools as $school): ?>
                                            <option value="<?php echo $school['id']; ?>"><?php echo htmlspecialchars($school['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Статус</label>
                                    <select name="status">
                                        <option value="all">Все статусы</option>
                                        <option value="active">Активные</option>
                                        <option value="inactive">Неактивные</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Поиск</label>
                                    <input type="text" name="search" placeholder="Поиск по названию...">
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">Применить</button>
                                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">Сбросить</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="curriculum-table">
                        <div class="table-responsive">
                            <table class="curriculum-data-table" id="curriculum-table">
                                <thead>
                                <tr>
                                    <th>Название</th>
                                    <th>Школа</th>
                                    <th>Классы</th>
                                    <th>Предметы</th>
                                    <th>Статус</th>
                                    <th>Дата создания</th>
                                    <th>Действия</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($curriculums)): ?>
                                    <tr>
                                        <td colspan="7" class="empty-state">
                                            <div class="empty-state-icon">📚</div>
                                            <h3>Учебные планы не найдены</h3>
                                            <p>Создайте первый учебный план для вашей школы</p>
                                            <a href="curriculum.php?action=add" class="btn btn-primary">Создать учебный план</a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($curriculums as $curriculum):
                                        $plan_relations = $relations_by_plan[(int)$curriculum['id']] ?? [];
                                        $grades = array_values(array_unique(array_map(static fn($row) => (int)$row['grade_level'], $plan_relations)));
                                        if (!$grades) {
                                            $grades = json_decode($curriculum['grades'], true) ?: [];
                                        }
                                        $subjects = [];
                                        foreach ($plan_relations as $relation) {
                                            $key = (int)$relation['subject_id'];
                                            if (!isset($subjects[$key])) {
                                                $subjects[$key] = ['name' => $relation['subject_name'], 'hours' => (int)$relation['hours_per_week']];
                                            }
                                        }
                                        if (!$subjects) {
                                            $subjects = decodeCurriculumSubjects($curriculum['subjects']);
                                        }
                                        ?>
                                        <tr data-id="<?php echo $curriculum['id']; ?>" data-school-id="<?php echo $curriculum['school_id']; ?>" data-grades='<?php echo json_encode($grades); ?>'>
                                            <td>
                                                <div class="curriculum-name"><?php echo htmlspecialchars($curriculum['name']); ?></div>
                                                <div class="curriculum-description"><?php echo htmlspecialchars((string)$curriculum['academic_year'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
                                                <?php if ($curriculum['description']): ?>
                                                    <div class="curriculum-description"><?php echo htmlspecialchars($curriculum['description']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($curriculum['school_name']); ?></td>
                                            <td>
                                                <div class="curriculum-grades">
                                                    <?php foreach (array_slice($grades, 0, 3) as $grade): ?>
                                                        <span class="grade-badge"><?php echo $grade; ?> кл</span>
                                                    <?php endforeach; ?>
                                                    <?php if (count($grades) > 3): ?>
                                                        <span class="grade-badge">+<?php echo count($grades) - 3; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="curriculum-subjects">
                                                    <?php foreach (array_slice($subjects, 0, 2) as $subject): ?>
                                                        <div class="subject-item">
                                                            <span class="subject-name"><?php echo htmlspecialchars($subject['name']); ?></span>
                                                            <span class="subject-hours"><?php echo $subject['hours']; ?>ч</span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (count($subjects) > 2): ?>
                                                        <div class="subject-more">+<?php echo count($subjects) - 2; ?> предметов</div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                        <span class="status-badge <?php echo $curriculum['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                                            <?php echo $curriculum['is_active'] ? 'Активный' : 'Неактивный'; ?>
                                                        </span>
                                            </td>
                                            <td><?php echo date('d.m.Y', strtotime($curriculum['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-action btn-edit" title="Редактировать" onclick="editCurriculum(<?php echo $curriculum['id']; ?>)">
                                                        ✏️
                                                    </button>
                                                    <button class="btn-action btn-view" title="Просмотреть" onclick="viewCurriculum(<?php echo $curriculum['id']; ?>)">
                                                        👁️
                                                    </button>
                                                    <?php echo csrf_post_button('delete', (int)$curriculum['id'], '🗑️', 'btn-action btn-delete', 'Вы уверены, что хотите удалить этот учебный план?'); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../js/curriculum.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
// Создаем мобильное представление если на маленьком экране
if (window.innerWidth <= 768) {
createMobileCardsView();
}

// Обработчик изменения размера окна
window.addEventListener('resize', function() {
if (window.innerWidth <= 768) {
createMobileCardsView();
} else {
removeMobileCardsView();
}
});
});

function escapeCardHtml(value) {
return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch) {
return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch];
});
}

function createMobileCardsView() {
// Если уже создано, выходим
if (document.querySelector('.mobile-cards-view')) {
return;
}

const table = document.querySelector('.curriculum-data-table');
if (!table) return;

const tbody = table.querySelector('tbody');
const rows = tbody.querySelectorAll('tr');

// Создаем контейнер для карточек
const cardsContainer = document.createElement('div');
cardsContainer.className = 'mobile-cards-view';

rows.forEach(row => {
if (row.querySelector('.empty-state')) {
// Если это строка с пустым состоянием, просто копируем её
cardsContainer.appendChild(row.cloneNode(true));
return;
}

// Создаем карточку
const card = document.createElement('div');
card.className = 'curriculum-card';

// Получаем данные из строки таблицы
const cells = row.querySelectorAll('td');
const nameCell = cells[0];
const schoolCell = cells[1];
const gradesCell = cells[2];
const subjectsCell = cells[3];
const statusCell = cells[4];
const dateCell = cells[5];
const actionsCell = cells[6];

// Заполняем карточку
card.innerHTML = `
<div class="curriculum-card-header">
    <div class="curriculum-card-title">${escapeCardHtml(nameCell.querySelector('.curriculum-name').textContent)}</div>
    <div class="curriculum-card-school">${escapeCardHtml(schoolCell.textContent)}</div>
    ${nameCell.querySelector('.curriculum-description') ?
    `<div class="curriculum-card-description">${escapeCardHtml(nameCell.querySelector('.curriculum-description').textContent)}</div>` : ''}
</div>

<div class="curriculum-card-grades">
    ${gradesCell.innerHTML}
</div>

<div class="curriculum-card-subjects">
    ${subjectsCell.innerHTML.replace(/curriculum-subjects/g, '')}
</div>

<div class="curriculum-card-footer">
                <span class="curriculum-card-status ${statusCell.querySelector('.status-badge').classList.contains('status-active') ? 'status-active' : 'status-inactive'}">
                    ${statusCell.textContent.trim()}
                </span>
    <div class="curriculum-card-actions">
        ${actionsCell.innerHTML.replace(/action-buttons/g, 'curriculum-card-actions').replace(/btn-action/g, 'btn-card-action')}
    </div>
</div>
`;

cardsContainer.appendChild(card);
});

// Заменяем таблицу карточками
const tableContainer = table.closest('.table-responsive');
tableContainer.style.display = 'none';
tableContainer.parentNode.insertBefore(cardsContainer, tableContainer);
}

function removeMobileCardsView() {
const cardsView = document.querySelector('.mobile-cards-view');
if (cardsView) {
cardsView.remove();
}

const tableContainer = document.querySelector('.table-responsive');
if (tableContainer) {
tableContainer.style.display = 'block';
}
}
</script>
</body>
</html>