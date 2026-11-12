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

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$weight_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Обработка добавления веса оценки
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $name = trim($_POST['name']);
        $raw_weight = $_POST['weight'] ?? '';
        $weight = is_scalar($raw_weight) && is_numeric($raw_weight) ? (float)$raw_weight : 0.0;
        $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Валидация
        $errors = [];

        if (empty($name)) {
            $errors[] = "Введите название типа оценки";
        }

        if (!is_finite($weight) || $weight <= 0) {
            $errors[] = "Вес оценки должен быть больше 0";
        }

        if (empty($errors)) {
            try {
                // Проверяем, не существует ли уже такого веса оценки
                $stmt = $pdo->prepare("
                    SELECT id FROM grade_weights 
                    WHERE school_id = ? AND name = ?
                ");
                $stmt->execute([$school_id, $name]);

                if ($stmt->fetch()) {
                    $errors[] = "Вес оценки с таким названием уже существует";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO grade_weights (school_id, name, weight, description, is_active) 
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                            $school_id,
                            $name,
                            $weight,
                            $description,
                            $is_active
                    ]);

                    logUserAction($pdo, 'grade_weight_created', json_encode([
                        'school_id' => $school_id,
                        'name' => $name,
                        'weight' => $weight
                    ], JSON_UNESCAPED_UNICODE));
                    $_SESSION['success_message'] = "Вес оценки успешно добавлен!";
                    header('Location: grade_weights.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    // Обработка редактирования веса оценки
    elseif ($action === 'edit' && $weight_id > 0) {
        $name = trim($_POST['name']);
        $raw_weight = $_POST['weight'] ?? '';
        $weight = is_scalar($raw_weight) && is_numeric($raw_weight) ? (float)$raw_weight : 0.0;
        $description = !empty($_POST['description']) ? trim($_POST['description']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Валидация
        $errors = [];

        if (empty($name)) {
            $errors[] = "Введите название типа оценки";
        }

        if (!is_finite($weight) || $weight <= 0) {
            $errors[] = "Вес оценки должен быть больше 0";
        }

        if (empty($errors)) {
            try {
                // Проверяем, не существует ли уже такого веса оценки у другого ID
                $stmt = $pdo->prepare("
                    SELECT id FROM grade_weights 
                    WHERE school_id = ? AND name = ? AND id != ?
                ");
                $stmt->execute([$school_id, $name, $weight_id]);

                if ($stmt->fetch()) {
                    $errors[] = "Вес оценки с таким названием уже существует";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE grade_weights 
                        SET name = ?, weight = ?, description = ?, is_active = ?
                        WHERE id = ? AND school_id = ?
                    ");

                    $stmt->execute([
                            $name,
                            $weight,
                            $description,
                            $is_active,
                            $weight_id,
                            $school_id
                    ]);

                    logUserAction($pdo, 'grade_weight_updated', json_encode([
                        'school_id' => $school_id,
                        'weight_id' => $weight_id,
                        'weight' => $weight
                    ], JSON_UNESCAPED_UNICODE));
                    $_SESSION['success_message'] = "Вес оценки успешно обновлен!";
                    header('Location: grade_weights.php');
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

// Обработка удаления веса оценки
if ($action === 'delete' && $weight_id > 0) {
    try {
        $usage_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM grades g
            JOIN users u ON u.id = g.student_id AND u.school_id = ?
            WHERE g.grade_weight_id = ?
        ");
        $usage_stmt->execute([$school_id, $weight_id]);
        $type_reference_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM grade_types
            WHERE school_id = ? AND default_weight_id = ?
        ");
        $type_reference_stmt->execute([$school_id, $weight_id]);

        if ((int)$usage_stmt->fetchColumn() > 0 || (int)$type_reference_stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("UPDATE grade_weights SET is_active = 0 WHERE id = ? AND school_id = ?");
            $stmt->execute([$weight_id, $school_id]);
            $_SESSION['success_message'] = "Вес используется оценками или типами работ и отключен. Сохранённые оценки продолжают учитывать его значение.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM grade_weights WHERE id = ? AND school_id = ?");
            $stmt->execute([$weight_id, $school_id]);
            $_SESSION['success_message'] = $stmt->rowCount() > 0
                ? "Вес оценки успешно удален!"
                : "Вес оценки не найден или у вас нет прав для его удаления";
        }
        logUserAction($pdo, 'grade_weight_deleted_or_disabled', json_encode([
            'school_id' => $school_id,
            'weight_id' => $weight_id
        ], JSON_UNESCAPED_UNICODE));
        header('Location: grade_weights.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: grade_weights.php');
        exit;
    }
}

// Получение данных веса оценки для редактирования/просмотра
$weight_data = null;
if (($action === 'edit' || $action === 'view') && $weight_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM grade_weights 
            WHERE id = ? AND school_id = ?
        ");
        $stmt->execute([$weight_id, $school_id]);
        $weight_data = $stmt->fetch();

        if (!$weight_data) {
            $_SESSION['error_message'] = "Вес оценки не найден!";
            header('Location: grade_weights.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: grade_weights.php');
        exit;
    }
}

// Получение списка весов оценок
$grade_weights = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM grade_weights 
        WHERE school_id = ?
        ORDER BY weight DESC, name ASC
    ");
    $stmt->execute([$school_id]);
    $grade_weights = $stmt->fetchAll();
} catch (PDOException $e) {
    $grade_weights = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Веса оценок - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .weights-container {
            margin-top: 20px;
        }

        .weight-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }

        .weight-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .weight-title {
            font-size: 1.1em;
            font-weight: bold;
            color: #2c3e50;
            margin: 0;
        }

        .weight-value {
            margin-bottom: 8px;
        }

        .weight-badge {
            background: #3498db;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.9em;
            display: inline-block;
        }

        .weight-description {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 10px;
        }

        .weight-footer {
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

        .weight-explanation {
            background: #e3f2fd;
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            border-left: 4px solid #2196f3;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $schoolAdminActivePage = 'grade_weights'; require __DIR__ . '/../includes/school_admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Управление весами оценок</h1>
                <p><?php echo htmlspecialchars($school['full_name']); ?></p>
            </div>
            <div class="header-actions">
                <a href="grade_weights.php?action=add" class="btn btn-primary">➕ Добавить вес оценки</a>
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
                <!-- Форма добавления/редактирования веса оценки -->
                <div class="admin-form">
                    <h2><?php echo $action === 'add' ? 'Добавить вес оценки' : 'Редактировать вес оценки'; ?></h2>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$weight_id; ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">Название типа оценки *</label>
                                <input type="text" id="name" name="name"
                                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : (isset($weight_data['name']) ? htmlspecialchars($weight_data['name']) : ''); ?>"
                                       placeholder="Например: Контрольная работа, Домашнее задание" required>
                                <small class="form-hint">Тип работы или активности</small>
                            </div>
                            <div class="form-group">
                                <label for="weight">Вес оценки *</label>
                                <input type="number" id="weight" name="weight"
                                       value="<?php echo isset($_POST['weight']) ? htmlspecialchars($_POST['weight']) : (isset($weight_data['weight']) ? htmlspecialchars($weight_data['weight']) : '1.0'); ?>"
                                       step="0.1" min="0.1" max="10.0" required>
                                <small class="form-hint">От 0.1 до 10.0. Чем выше вес, тем больше влияет на итоговую оценку</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description">Описание</label>
                            <textarea id="description" name="description" rows="4" placeholder="Дополнительная информация о типе оценки..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : (isset($weight_data['description']) ? htmlspecialchars($weight_data['description']) : ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" name="is_active"
                                        <?php echo (!isset($_POST['is_active']) && $action === 'add') || (isset($_POST['is_active']) && $_POST['is_active']) || (isset($weight_data['is_active']) && $weight_data['is_active']) ? 'checked' : ''; ?>>
                                <span class="checkmark"></span>
                                Активный вес оценки
                            </label>
                            <small class="form-hint">Неактивные веса оценок не будут доступны для использования</small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? '➕ Добавить вес оценки' : '💾 Сохранить изменения'; ?>
                            </button>
                            <a href="grade_weights.php" class="btn btn-secondary">❌ Отмена</a>
                        </div>
                    </form>
                </div>

            <?php elseif ($action === 'view' && $weight_data): ?>
                <!-- Просмотр веса оценки -->
                <div class="admin-form">
                    <h2>Просмотр веса оценки</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Название:</label>
                            <span><?php echo htmlspecialchars($weight_data['name']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Вес:</label>
                            <span class="weight-badge
                                <?php
                            if ($weight_data['weight'] >= 2.0) echo 'weight-high';
                            elseif ($weight_data['weight'] >= 1.0) echo 'weight-medium';
                            else echo 'weight-low';
                            ?>
                            "><?php echo $weight_data['weight']; ?>x</span>
                        </div>
                        <div class="info-item">
                            <label>Статус:</label>
                            <span class="status-badge status-<?php echo $weight_data['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $weight_data['is_active'] ? 'Активен' : 'Неактивен'; ?>
                            </span>
                        </div>
                        <?php if (isset($weight_data['created_at'])): ?>
                            <div class="info-item">
                                <label>Дата создания:</label>
                                <span><?php echo date('d.m.Y H:i', strtotime($weight_data['created_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($weight_data['description'])): ?>
                        <div class="form-group">
                            <label>Описание:</label>
                            <div class="description-text"><?php echo nl2br(htmlspecialchars($weight_data['description'])); ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="form-actions">
                        <a href="grade_weights.php?action=edit&id=<?php echo $weight_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                        <a href="grade_weights.php" class="btn btn-secondary">← Назад к весам оценок</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список весов оценок -->
                <div class="weights-container">
                    <div class="weight-explanation">
                        <h3 style="margin: 0 0 8px 0; font-size: 1em;">📊 Как работают веса оценок?</h3>
                        <p style="margin: 0; font-size: 0.9em;">Веса оценок определяют влияние каждой оценки на итоговый балл.</p>
                    </div>

                    <h2>Настроенные веса оценок</h2>

                    <?php if (empty($grade_weights)): ?>
                        <div class="empty-state">
                            <p>Веса оценок не настроены</p>
                            <a href="grade_weights.php?action=add" class="btn btn-primary">➕ Добавить первый вес оценки</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($grade_weights as $weight): ?>
                            <div class="weight-card">
                                <div class="weight-header">
                                    <h3 class="weight-title"><?php echo htmlspecialchars($weight['name']); ?></h3>
                                    <div class="table-actions">
                                        <a href="grade_weights.php?action=edit&id=<?php echo $weight['id']; ?>" class="btn-action">✏️</a>
                                        <?php echo csrf_post_button('delete', (int)$weight['id'], '🗑️', 'btn-action', 'Удалить вес оценки?'); ?>
                                    </div>
                                </div>

                                <div class="weight-value">
                                    <span class="weight-badge"><?php echo $weight['weight']; ?>x</span>
                                    <span style="margin-left: 8px; font-size: 0.9em;">умножает оценку в <?php echo $weight['weight']; ?> раз</span>
                                </div>

                                <?php if (!empty($weight['description'])): ?>
                                    <div class="weight-description">
                                        <?php echo htmlspecialchars($weight['description']); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="weight-footer">
                    <span class="status-badge status-<?php echo $weight['is_active'] ? 'active' : 'inactive'; ?>">
                        <?php echo $weight['is_active'] ? 'Активен' : 'Неактивен'; ?>
                    </span>
                                    <small style="color: #888;">
                                        Создан: <?php echo date('d.m.Y', strtotime($weight['created_at'])); ?>
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
</body>
</html>