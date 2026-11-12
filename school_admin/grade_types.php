<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';

// Проверка прав - только school_admin
requireSchoolAdmin();

$pdo = getDatabaseConnection();

// Получаем school_id из сессии с проверкой
$school_id = $_SESSION['user_school_id'] ?? null;
if (!$school_id) {
    $_SESSION['error_message'] = "Школа не определена. Обратитесь к администратору.";
    header('Location: dashboard.php');
    exit;
}

// Получаем информацию о школе
$school_stmt = $pdo->prepare("SELECT full_name, short_name FROM schools WHERE id = ?");
$school_stmt->execute([$school_id]);
$school = $school_stmt->fetch();

$active_grade_weights = [];
try {
    $weights_stmt = $pdo->prepare("
        SELECT id, name, weight
        FROM grade_weights
        WHERE school_id = ? AND is_active = 1
        ORDER BY weight, name
    ");
    $weights_stmt->execute([$school_id]);
    $active_grade_weights = $weights_stmt->fetchAll();
} catch (PDOException $e) {
    $error = publicErrorMessage($e, basename(__FILE__));
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$grade_type_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Обработка добавления типа оценки
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add_defaults') {
        $default_weights = [
            'Обычный' => 1.0,
            'Важный' => 2.0,
            'Контрольный' => 3.0
        ];
        $default_types = [
            ['Ответ на уроке', 'Обычный'],
            ['Домашняя работа', 'Обычный'],
            ['Самостоятельная', 'Важный'],
            ['Контрольная', 'Контрольный'],
            ['Диктант/Сочинение', 'Контрольный']
        ];

        try {
            $pdo->beginTransaction();
            $weight_ids = [];
            foreach ($default_weights as $weight_name => $weight_value) {
                $stmt = $pdo->prepare("SELECT id FROM grade_weights WHERE school_id = ? AND name = ? LIMIT 1");
                $stmt->execute([$school_id, $weight_name]);
                $weight_id = $stmt->fetchColumn();
                if ($weight_id === false) {
                    $stmt = $pdo->prepare("
                        INSERT INTO grade_weights (school_id, name, weight, is_active)
                        VALUES (?, ?, ?, 1)
                    ");
                    $stmt->execute([$school_id, $weight_name, $weight_value]);
                    $weight_id = $pdo->lastInsertId();
                }
                $weight_ids[$weight_name] = (int)$weight_id;
            }

            foreach ($default_types as [$type_name, $weight_name]) {
                $stmt = $pdo->prepare("SELECT id FROM grade_types WHERE school_id = ? AND name = ? LIMIT 1");
                $stmt->execute([$school_id, $type_name]);
                if ($stmt->fetchColumn() === false) {
                    $stmt = $pdo->prepare("
                        INSERT INTO grade_types (school_id, name, min_score, max_score, default_weight_id, color, is_active)
                        VALUES (?, ?, 2, 5, ?, '#3498db', 1)
                    ");
                    $stmt->execute([$school_id, $type_name, $weight_ids[$weight_name]]);
                }
            }

            $pdo->commit();
            logUserAction($pdo, 'grade_defaults_added', json_encode(['school_id' => $school_id], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = 'Стандартные типы и веса оценок добавлены (уже существующие не изменены).';
            header('Location: grade_types.php');
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = publicErrorMessage($e, basename(__FILE__));
        }
    } elseif ($action === 'add') {
        $name = trim($_POST['name']);
        $min_score = intval($_POST['min_score']);
        $max_score = intval($_POST['max_score']);
        $raw_default_weight_id = $_POST['default_weight_id'] ?? '';
        $default_weight_id = $raw_default_weight_id === ''
            ? 0
            : (is_scalar($raw_default_weight_id) ? filter_var((string)$raw_default_weight_id, FILTER_VALIDATE_INT) : false);
        $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
        $color = !empty($_POST['color']) ? trim($_POST['color']) : '#3498db';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Валидация
        $errors = [];

        if (empty($name)) {
            $errors[] = "Введите название типа оценки";
        }

        if ($min_score < 0) {
            $errors[] = "Минимальный балл не может быть отрицательным";
        }

        if ($max_score <= $min_score) {
            $errors[] = "Максимальный балл должен быть больше минимального";
        }

        if ($raw_default_weight_id !== '' && ($default_weight_id === false || $default_weight_id < 1)) {
            $errors[] = "Некорректно указан вес по умолчанию";
        }

        if ($default_weight_id > 0) {
            $weight_stmt = $pdo->prepare("
                SELECT id FROM grade_weights
                WHERE id = ? AND school_id = ? AND is_active = 1
            ");
            $weight_stmt->execute([$default_weight_id, $school_id]);
            if (!$weight_stmt->fetchColumn()) {
                $errors[] = "Выберите активный вес этой школы";
            }
        } else {
            $default_weight_id = null;
        }

        if (empty($errors)) {
            try {
                // Проверяем, не существует ли уже такого типа оценки
                $stmt = $pdo->prepare("
                    SELECT id FROM grade_types 
                    WHERE school_id = ? AND name = ?
                ");
                $stmt->execute([$school_id, $name]);

                if ($stmt->fetch()) {
                    $errors[] = "Тип оценки с таким названием уже существует";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO grade_types (school_id, name, min_score, max_score, default_weight_id, description, color, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $school_id,
                        $name,
                        $min_score,
                        $max_score,
                        $default_weight_id,
                        $description,
                        $color,
                        $is_active
                    ]);

                    logUserAction($pdo, 'grade_type_created', json_encode([
                        'school_id' => $school_id,
                        'name' => $name,
                        'default_weight_id' => $default_weight_id
                    ], JSON_UNESCAPED_UNICODE));
                    $_SESSION['success_message'] = "Тип оценки успешно добавлен!";
                    header('Location: grade_types.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    // Обработка редактирования типа оценки
    elseif ($action === 'edit' && $grade_type_id > 0) {
        $name = trim($_POST['name']);
        $min_score = intval($_POST['min_score']);
        $max_score = intval($_POST['max_score']);
        $raw_default_weight_id = $_POST['default_weight_id'] ?? '';
        $default_weight_id = $raw_default_weight_id === ''
            ? 0
            : (is_scalar($raw_default_weight_id) ? filter_var((string)$raw_default_weight_id, FILTER_VALIDATE_INT) : false);
        $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
        $color = !empty($_POST['color']) ? trim($_POST['color']) : '#3498db';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Валидация
        $errors = [];

        if (empty($name)) {
            $errors[] = "Введите название типа оценки";
        }

        if ($min_score < 0) {
            $errors[] = "Минимальный балл не может быть отрицательным";
        }

        if ($max_score <= $min_score) {
            $errors[] = "Максимальный балл должен быть больше минимального";
        }

        if ($raw_default_weight_id !== '' && ($default_weight_id === false || $default_weight_id < 1)) {
            $errors[] = "Некорректно указан вес по умолчанию";
        }

        if ($default_weight_id > 0) {
            $weight_stmt = $pdo->prepare("
                SELECT id FROM grade_weights
                WHERE id = ? AND school_id = ?
                    AND (is_active = 1 OR id = (
                        SELECT default_weight_id FROM grade_types
                        WHERE id = ? AND school_id = ?
                    ))
            ");
            $weight_stmt->execute([$default_weight_id, $school_id, $grade_type_id, $school_id]);
            if (!$weight_stmt->fetchColumn()) {
                $errors[] = "Выберите активный вес этой школы";
            }
        } else {
            $default_weight_id = null;
        }

        if (empty($errors)) {
            try {
                // Проверяем, не существует ли уже такого типа оценки у другого ID
                $stmt = $pdo->prepare("
                    SELECT id FROM grade_types 
                    WHERE school_id = ? AND name = ? AND id != ?
                ");
                $stmt->execute([$school_id, $name, $grade_type_id]);

                if ($stmt->fetch()) {
                    $errors[] = "Тип оценки с таким названием уже существует";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE grade_types 
                        SET name = ?, min_score = ?, max_score = ?, default_weight_id = ?, description = ?, color = ?, is_active = ?
                        WHERE id = ? AND school_id = ?
                    ");

                    $stmt->execute([
                        $name,
                        $min_score,
                        $max_score,
                        $default_weight_id,
                        $description,
                        $color,
                        $is_active,
                        $grade_type_id,
                        $school_id
                    ]);

                    logUserAction($pdo, 'grade_type_updated', json_encode([
                        'school_id' => $school_id,
                        'grade_type_id' => $grade_type_id,
                        'default_weight_id' => $default_weight_id
                    ], JSON_UNESCAPED_UNICODE));
                    $_SESSION['success_message'] = "Тип оценки успешно обновлен!";
                    header('Location: grade_types.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Обработка удаления типа оценки
if ($action === 'delete' && $grade_type_id > 0) {
    try {
        $usage_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM grades g
            JOIN users u ON u.id = g.student_id AND u.school_id = ?
            WHERE g.grade_type_id = ?
        ");
        $usage_stmt->execute([$school_id, $grade_type_id]);
        if ((int)$usage_stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE grade_types SET is_active = 0 WHERE id = ? AND school_id = ?");
            $stmt->execute([$grade_type_id, $school_id]);
            $_SESSION['success_message'] = "Тип используется в оценках и отключен. История оценок сохранена.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM grade_types WHERE id = ? AND school_id = ?");
            $stmt->execute([$grade_type_id, $school_id]);
            $_SESSION['success_message'] = $stmt->rowCount() > 0
                ? "Тип оценки успешно удален!"
                : "Тип оценки не найден или у вас нет прав для его удаления";
        }

        logUserAction($pdo, 'grade_type_deleted_or_disabled', json_encode([
            'school_id' => $school_id,
            'grade_type_id' => $grade_type_id
        ], JSON_UNESCAPED_UNICODE));
        header('Location: grade_types.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: grade_types.php');
        exit;
    }
}

// Получение данных типа оценки для редактирования/просмотра
$grade_type_data = null;
if (($action === 'edit' || $action === 'view') && $grade_type_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT gt.*, gw.name AS default_weight_name, gw.weight AS default_weight_value, gw.is_active AS default_weight_active
            FROM grade_types gt
            LEFT JOIN grade_weights gw ON gw.id = gt.default_weight_id AND gw.school_id = gt.school_id
            WHERE gt.id = ? AND gt.school_id = ?
        ");
        $stmt->execute([$grade_type_id, $school_id]);
        $grade_type_data = $stmt->fetch();

        if (!$grade_type_data) {
            $_SESSION['error_message'] = "Тип оценки не найден!";
            header('Location: grade_types.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: grade_types.php');
        exit;
    }
}

// Получение списка типов оценок
$grade_types = [];
try {
    $stmt = $pdo->prepare("
        SELECT gt.*, gw.name AS default_weight_name, gw.weight AS default_weight_value, gw.is_active AS default_weight_active
        FROM grade_types gt
        LEFT JOIN grade_weights gw ON gw.id = gt.default_weight_id AND gw.school_id = gt.school_id
        WHERE gt.school_id = ?
        ORDER BY gt.min_score ASC
    ");
    $stmt->execute([$school_id]);
    $grade_types = $stmt->fetchAll();
} catch (PDOException $e) {
    $grade_types = [];
}
$standard_grade_type_names = ['Ответ на уроке', 'Домашняя работа', 'Самостоятельная', 'Контрольная', 'Диктант/Сочинение'];
$standard_grade_weight_names = ['Обычный', 'Важный', 'Контрольный'];
$existing_grade_type_names = array_column($grade_types, 'name');
$existing_grade_weight_names = [];
try {
    $names_stmt = $pdo->prepare("SELECT name FROM grade_weights WHERE school_id = ?");
    $names_stmt->execute([$school_id]);
    $existing_grade_weight_names = $names_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('Ошибка проверки стандартных весов: ' . $e->getMessage());
}
$show_default_setup = (bool)array_diff($standard_grade_type_names, $existing_grade_type_names)
    || (bool)array_diff($standard_grade_weight_names, $existing_grade_weight_names);
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система оценок - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .grade-types-container {
            margin-top: 20px;
        }

        .grade-type-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }

        .grade-type-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .grade-type-title {
            font-size: 1.1em;
            font-weight: bold;
            color: #2c3e50;
            margin: 0;
        }

        .grade-range {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .score-badge {
            background: #3498db;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8em;
        }

        .grade-type-description {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 10px;
        }

        .grade-type-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }

        .status-badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 0.75em;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .table-actions {
            display: flex;
            gap: 5px;
        }

        .btn-action {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            text-decoration: none;
        }

        .empty-state {
            text-align: center;
            padding: 30px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #dee2e6;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .alert {
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 15px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $schoolAdminActivePage = 'grade_types'; require __DIR__ . '/../includes/school_admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Система оценок</h1>
                <p><?php echo htmlspecialchars($school['full_name']); ?></p>
            </div>
            <div class="header-actions">
                <?php if ($show_default_setup): ?>
                    <form method="POST" style="display:inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="add_defaults">
                        <button type="submit" class="btn btn-secondary">Добавить стандартный набор</button>
                    </form>
                <?php endif; ?>
                <a href="grade_types.php?action=add" class="btn btn-primary">➕ Добавить тип оценки</a>
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
                <!-- Форма добавления/редактирования типа оценки -->
                <div class="admin-form">
                    <h2><?php echo $action === 'add' ? 'Добавить тип оценки' : 'Редактировать тип оценки'; ?></h2>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$grade_type_id; ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">Название типа оценки *</label>
                                <input type="text" id="name" name="name"
                                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : (isset($grade_type_data['name']) ? htmlspecialchars($grade_type_data['name']) : ''); ?>"
                                       placeholder="Например: Отлично, Хорошо, Удовлетворительно" required>
                                <small class="form-hint">Полное название типа оценки</small>
                            </div>
                            <div class="form-group">
                                <label for="min_score">Минимальный балл *</label>
                                <input type="number" id="min_score" name="min_score"
                                       value="<?php echo isset($_POST['min_score']) ? htmlspecialchars($_POST['min_score']) : (isset($grade_type_data['min_score']) ? htmlspecialchars($grade_type_data['min_score']) : '0'); ?>"
                                       min="0" max="10" required>
                                <small class="form-hint">Минимальное значение балла</small>
                            </div>
                            <div class="form-group">
                                <label for="max_score">Максимальный балл *</label>
                                <input type="number" id="max_score" name="max_score"
                                       value="<?php echo isset($_POST['max_score']) ? htmlspecialchars($_POST['max_score']) : (isset($grade_type_data['max_score']) ? htmlspecialchars($grade_type_data['max_score']) : '5'); ?>"
                                       min="1" max="10" required>
                                <small class="form-hint">Максимальное значение балла</small>
                            </div>
                            <div class="form-group">
                                <label for="default_weight_id">Вес по умолчанию</label>
                                <?php
                                $selected_weight_id = isset($_POST['default_weight_id']) && is_scalar($_POST['default_weight_id'])
                                    ? (string)$_POST['default_weight_id']
                                    : ($grade_type_data['default_weight_id'] ?? '');
                                $current_weight_unavailable = !empty($grade_type_data['default_weight_id'])
                                    && !in_array((int)$grade_type_data['default_weight_id'], array_map('intval', array_column($active_grade_weights, 'id')), true);
                                ?>
                                <select id="default_weight_id" name="default_weight_id">
                                    <option value="" <?= $selected_weight_id === '' || (int)$selected_weight_id === 0 ? 'selected' : '' ?>>Без веса — 1.00</option>
                                    <?php if ($current_weight_unavailable): ?>
                                        <option value="<?= (int)$grade_type_data['default_weight_id'] ?>" <?= (int)$selected_weight_id === (int)$grade_type_data['default_weight_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($grade_type_data['default_weight_name'] ?? 'Отключённый вес', ENT_QUOTES, 'UTF-8') ?>
                                            (<?= htmlspecialchars((string)($grade_type_data['default_weight_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, отключён)
                                        </option>
                                    <?php endif; ?>
                                    <?php foreach ($active_grade_weights as $weight): ?>
                                        <option value="<?= (int)$weight['id'] ?>" <?= (int)$selected_weight_id === (int)$weight['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($weight['name'], ENT_QUOTES, 'UTF-8') ?>
                                            (<?= htmlspecialchars((string)$weight['weight'], ENT_QUOTES, 'UTF-8') ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="color">Цвет отображения</label>
                                <input type="color" id="color" name="color"
                                       value="<?php echo isset($_POST['color']) ? htmlspecialchars($_POST['color']) : (isset($grade_type_data['color']) ? htmlspecialchars($grade_type_data['color']) : '#3498db'); ?>">
                                <div class="color-preview" id="colorPreview" style="background-color: <?php echo isset($_POST['color']) ? htmlspecialchars($_POST['color']) : (isset($grade_type_data['color']) ? htmlspecialchars($grade_type_data['color']) : '#3498db'); ?>"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description">Описание</label>
                            <textarea id="description" name="description" rows="4" placeholder="Дополнительная информация о типе оценки..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : (isset($grade_type_data['description']) ? htmlspecialchars($grade_type_data['description']) : ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active" value="1"
                                    <?php echo (!isset($_POST['is_active']) && $action === 'add') || (isset($_POST['is_active']) && $_POST['is_active']) || (isset($grade_type_data['is_active']) && $grade_type_data['is_active']) ? 'checked' : ''; ?>>
                                <span class="checkmark"></span>
                                Активный тип оценки
                            </label>
                            <small class="form-hint">Неактивные типы оценок не будут доступны для использования</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? '➕ Добавить тип оценки' : '💾 Сохранить изменения'; ?>
                            </button>
                            <a href="grade_types.php" class="btn btn-secondary">❌ Отмена</a>
                        </div>
                    </form>
                </div>

            <?php elseif ($action === 'view' && $grade_type_data): ?>
                <!-- Просмотр типа оценки -->
                <div class="admin-form">
                    <h2>Просмотр типа оценки</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Название:</label>
                            <span><?php echo htmlspecialchars($grade_type_data['name']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Диапазон баллов:</label>
                            <span><?php echo $grade_type_data['min_score']; ?> - <?php echo $grade_type_data['max_score']; ?></span>
                        </div>
                        <div class="info-item">
                            <label>Вес по умолчанию:</label>
                            <span>
                                <?= htmlspecialchars($grade_type_data['default_weight_name'] ?? 'Не задан — 1.00', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (isset($grade_type_data['default_weight_value'])): ?>
                                    (<?= htmlspecialchars((string)$grade_type_data['default_weight_value'], ENT_QUOTES, 'UTF-8') ?>)
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <label>Цвет:</label>
                            <div class="color-display">
                                <div class="color-box" style="background-color: <?php echo htmlspecialchars($grade_type_data['color']); ?>"></div>
                                <span><?php echo htmlspecialchars($grade_type_data['color']); ?></span>
                            </div>
                        </div>
                        <div class="info-item">
                            <label>Статус:</label>
                            <span class="status-badge status-<?php echo $grade_type_data['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $grade_type_data['is_active'] ? 'Активен' : 'Неактивен'; ?>
                            </span>
                        </div>
                        <?php if (isset($grade_type_data['created_at'])): ?>
                            <div class="info-item">
                                <label>Дата создания:</label>
                                <span><?php echo date('d.m.Y H:i', strtotime($grade_type_data['created_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($grade_type_data['description'])): ?>
                        <div class="form-group">
                            <label>Описание:</label>
                            <div class="description-text"><?php echo nl2br(htmlspecialchars($grade_type_data['description'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <a href="grade_types.php?action=edit&id=<?php echo $grade_type_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                        <a href="grade_types.php" class="btn btn-secondary">← Назад к типам оценок</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список типов оценок -->
                <div class="grade-types-container">
                    <h2>Типы оценок</h2>

                    <?php if (empty($grade_types)): ?>
                        <div class="empty-state">
                            <p>Типы оценок не настроены</p>
                            <a href="grade_types.php?action=add" class="btn btn-primary">➕ Добавить первый тип оценки</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($grade_types as $grade_type): ?>
                            <div class="grade-type-card">
                                <div class="grade-type-header">
                                    <h3 class="grade-type-title"><?php echo htmlspecialchars($grade_type['name']); ?></h3>
                                    <div class="table-actions">
                                        <a href="grade_types.php?action=edit&id=<?php echo $grade_type['id']; ?>" class="btn-action">✏️</a>
                                        <?php echo csrf_post_button('delete', (int)$grade_type['id'], '🗑️', 'btn-action', 'Удалить тип оценки?'); ?>
                                    </div>
                                </div>

                                <?php if (!empty($grade_type['description'])): ?>
                                    <div class="grade-type-description">
                                        <?php echo htmlspecialchars($grade_type['description']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="grade-type-description">
                                Вес по умолчанию:
                                <?= htmlspecialchars($grade_type['default_weight_name'] ?? 'не задан (1.00)', ENT_QUOTES, 'UTF-8') ?>
                                <?php if (isset($grade_type['default_weight_value'])): ?>
                                    (<?= htmlspecialchars((string)$grade_type['default_weight_value'], ENT_QUOTES, 'UTF-8') ?>)
                                <?php endif; ?>
                                </div>

                                <div class="grade-type-footer">
                    <span class="status-badge status-<?php echo $grade_type['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $grade_type['is_active'] ? 'Активен' : 'Неактивен'; ?>
                    </span>
                                    <small style="color: #888;">
                                        Создан: <?php echo date('d.m.Y', strtotime($grade_type['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    // Обновление предпросмотра цвета
    const colorInput = document.getElementById('color');
    if (colorInput) {
        colorInput.addEventListener('input', function(e) {
            const colorPreview = document.getElementById('colorPreview');
            if (colorPreview) {
                colorPreview.style.backgroundColor = e.target.value;
            }
        });
    }
</script>
</body>
</html>