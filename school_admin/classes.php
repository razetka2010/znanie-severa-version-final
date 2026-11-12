<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';

// Проверка прав - только school_admin
requireSchoolAdmin();

$pdo = getDatabaseConnection();

$schoolContext = getSchoolAdminContext($pdo);
$school_id = $schoolContext['school_id'];
$school = $schoolContext['school'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$class_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Обработка добавления класса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $name = trim($_POST['name']);
        $grade_level = intval($_POST['grade_level']);
        $class_teacher_id = !empty($_POST['class_teacher_id']) ? intval($_POST['class_teacher_id']) : null;

        // Валидация
        $errors = [];

        if (empty($name)) {
            $errors[] = "Введите название класса";
        }

        if (empty($grade_level) || $grade_level < 1 || $grade_level > 11) {
            $errors[] = "Укажите корректный класс (1-11)";
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO classes (school_id, name, grade_level, class_teacher_id) 
                    VALUES (?, ?, ?, ?)
                ");

                $stmt->execute([
                        $school_id,
                        $name,
                        $grade_level,
                        $class_teacher_id
                ]);

                $_SESSION['success_message'] = "Класс успешно добавлен!";
                header('Location: classes.php');
                exit;
            } catch (PDOException $e) {
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
    // Обработка редактирования класса
    elseif ($action === 'edit' && $class_id > 0) {
        $name = trim($_POST['name']);
        $grade_level = intval($_POST['grade_level']);
        $class_teacher_id = !empty($_POST['class_teacher_id']) ? intval($_POST['class_teacher_id']) : null;

        // Валидация
        $errors = [];

        if (empty($name)) {
            $errors[] = "Введите название класса";
        }

        if (empty($grade_level) || $grade_level < 1 || $grade_level > 11) {
            $errors[] = "Укажите корректный класс (1-11)";
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE classes 
                    SET name = ?, grade_level = ?, class_teacher_id = ?
                    WHERE id = ? AND school_id = ?
                ");

                $stmt->execute([
                        $name,
                        $grade_level,
                        $class_teacher_id,
                        $class_id,
                        $school_id
                ]);

                $_SESSION['success_message'] = "Класс успешно обновлен!";
                header('Location: classes.php');
                exit;
            } catch (PDOException $e) {
                $error = publicErrorMessage($e, basename(__FILE__));
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// Обработка удаления класса
if ($action === 'delete' && $class_id > 0) {
    try {
        // Проверяем, есть ли связанные данные
        $related_data = [];

        // Проверяем расписание
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM schedule WHERE class_id = ?");
        $stmt->execute([$class_id]);
        $schedule_count = $stmt->fetch()['count'];
        if ($schedule_count > 0) {
            $related_data[] = "расписание ($schedule_count записей)";
        }

        // Проверяем учеников
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE class_id = ?");
        $stmt->execute([$class_id]);
        $students_count = $stmt->fetch()['count'];
        if ($students_count > 0) {
            $related_data[] = "ученики ($students_count человек)";
        }

        // Проверяем домашние задания
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM homework WHERE class_id = ?");
        $stmt->execute([$class_id]);
        $homework_count = $stmt->fetch()['count'];
        if ($homework_count > 0) {
            $related_data[] = "домашние задания ($homework_count записей)";
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM class_subjects WHERE class_id = ? AND school_id = ?');
        $stmt->execute([$class_id, $school_id]);
        $class_subject_count = (int)$stmt->fetchColumn();
        if ($class_subject_count > 0) {
            $related_data[] = "предметы класса ($class_subject_count назначений)";
        }

        // Если есть связанные данные, используем мягкое удаление
        if (!empty($related_data)) {
            // Мягкое удаление - деактивируем класс
            $stmt = $pdo->prepare("UPDATE classes SET is_active = 0, name = CONCAT(name, '_deleted_', ?) WHERE id = ? AND school_id = ?");
            $deleted_suffix = time();
            $stmt->execute([$deleted_suffix, $class_id, $school_id]);

            if ($stmt->rowCount() > 0) {
                $_SESSION['success_message'] = "Класс деактивирован! Нельзя было удалить полностью из-за связанных данных: " . implode(', ', $related_data);
            } else {
                $_SESSION['error_message'] = "Класс не найден или у вас нет прав для его удаления";
            }
        } else {
            // Если нет связанных данных, удаляем полностью
            $stmt = $pdo->prepare("DELETE FROM classes WHERE id = ? AND school_id = ?");
            $stmt->execute([$class_id, $school_id]);

            if ($stmt->rowCount() > 0) {
                $_SESSION['success_message'] = "Класс успешно удален!";
            } else {
                $_SESSION['error_message'] = "Класс не найден или у вас нет прав для его удаления";
            }
        }

        header('Location: classes.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: classes.php');
        exit;
    }
}

// Получение данных класса для редактирования/просмотра
$class_data = null;
if (($action === 'edit' || $action === 'view') && $class_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, u.full_name as teacher_name
            FROM classes c
            LEFT JOIN users u ON c.class_teacher_id = u.id
            WHERE c.id = ? AND c.school_id = ?
        ");
        $stmt->execute([$class_id, $school_id]);
        $class_data = $stmt->fetch();

        if (!$class_data) {
            $_SESSION['error_message'] = "Класс не найден!";
            header('Location: classes.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: classes.php');
        exit;
    }
}

// Получение списка классов школы (только активные)
$classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name as teacher_name, 
               COUNT(s.id) as student_count
        FROM classes c
        LEFT JOIN users u ON c.class_teacher_id = u.id
        LEFT JOIN users s ON s.class_id = c.id AND s.role_id IN (SELECT id FROM roles WHERE name = 'student')
        WHERE c.school_id = ? AND c.is_active = TRUE
        GROUP BY c.id
        ORDER BY c.grade_level, c.name
    ");
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll();
} catch (PDOException $e) {
    $classes = [];
}

// Получение списка учителей для выбора классного руководителя
$teachers = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, full_name 
        FROM users 
        WHERE school_id = ? AND role_id IN (SELECT id FROM roles WHERE name IN ('teacher', 'class_teacher')) AND is_active = TRUE
        ORDER BY full_name
    ");
    $stmt->execute([$school_id]);
    $teachers = $stmt->fetchAll();
} catch (PDOException $e) {
    $teachers = [];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Классы - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .data-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #e0e0e0;
        }

        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        .table-actions {
            display: flex;
            gap: 8px;
        }

        .btn-action {
            background: none;
            border: none;
            padding: 8px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-view:hover {
            background: #e8f5e8;
        }

        .btn-edit:hover {
            background: #fff3cd;
        }

        .btn-delete:hover {
            background: #ffeaea;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #dee2e6;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
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
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #2c3e50;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #3498db;
            outline: none;
        }

        .form-hint {
            display: block;
            margin-top: 5px;
            color: #7f8c8d;
            font-size: 0.85em;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-item label {
            font-weight: bold;
            color: #2c3e50;
        }

        .info-item span {
            color: #5a6c7d;
        }

        .soft-delete-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php
    $schoolAdminActivePage = 'classes';
    require __DIR__ . '/../includes/school_admin_sidebar.php';
    ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Управление классами</h1>
                <p><?php echo htmlspecialchars($school['full_name']); ?></p>
            </div>
            <div class="header-actions">
                <a href="classes.php?action=add" class="btn btn-primary">➕ Добавить класс</a>
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
                <!-- Форма добавления/редактирования класса -->
                <div class="admin-form">
                    <h2><?php echo $action === 'add' ? 'Добавить класс' : 'Редактировать класс'; ?></h2>
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$class_id; ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="name">Название класса *</label>
                                <input type="text" id="name" name="name"
                                       value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : (isset($class_data['name']) ? htmlspecialchars($class_data['name']) : ''); ?>"
                                       placeholder="Например: 5А" required>
                                <small class="form-hint">Буквенное обозначение класса</small>
                            </div>
                            <div class="form-group">
                                <label for="grade_level">Класс (число) *</label>
                                <select id="grade_level" name="grade_level" required>
                                    <option value="">Выберите класс</option>
                                    <?php for ($i = 1; $i <= 11; $i++): ?>
                                        <option value="<?php echo $i; ?>"
                                                <?php echo (isset($_POST['grade_level']) && $_POST['grade_level'] == $i) || (isset($class_data['grade_level']) && $class_data['grade_level'] == $i) ? 'selected' : ''; ?>>
                                            <?php echo $i; ?> класс
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="class_teacher_id">Классный руководитель</label>
                                <select id="class_teacher_id" name="class_teacher_id">
                                    <option value="">Не назначен</option>
                                    <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo $teacher['id']; ?>"
                                                <?php echo (isset($_POST['class_teacher_id']) && $_POST['class_teacher_id'] == $teacher['id']) || (isset($class_data['class_teacher_id']) && $class_data['class_teacher_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($teacher['full_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? '➕ Добавить класс' : '💾 Сохранить изменения'; ?>
                            </button>
                            <a href="classes.php" class="btn btn-secondary">❌ Отмена</a>
                        </div>
                    </form>
                </div>

            <?php elseif ($action === 'view' && $class_data): ?>
                <!-- Просмотр класса -->
                <div class="admin-form">
                    <h2>Просмотр класса</h2>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Название:</label>
                            <span><?php echo htmlspecialchars($class_data['name']); ?></span>
                        </div>
                        <div class="info-item">
                            <label>Класс:</label>
                            <span><?php echo $class_data['grade_level']; ?> класс</span>
                        </div>
                        <div class="info-item">
                            <label>Классный руководитель:</label>
                            <span><?php echo !empty($class_data['teacher_name']) ? htmlspecialchars($class_data['teacher_name']) : '—'; ?></span>
                        </div>
                        <?php if (isset($class_data['created_at'])): ?>
                            <div class="info-item">
                                <label>Дата создания:</label>
                                <span><?php echo date('d.m.Y H:i', strtotime($class_data['created_at'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="form-actions">
                        <a href="classes.php?action=edit&id=<?php echo $class_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                        <a href="classes.php" class="btn btn-secondary">← Назад к классам</a>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список классов -->
                <div class="classes-container">
                    <h2>Список классов</h2>

                    <div class="soft-delete-info">
                        <strong>💡 Информация:</strong> При удалении классов с связанными данными (расписание, ученики и т.д.)
                        используется "мягкое удаление" - класс деактивируется, а его название изменяется.
                    </div>

                    <?php if (empty($classes)): ?>
                        <div class="empty-state">
                            <p>Классы не добавлены</p>
                            <a href="classes.php?action=add" class="btn btn-primary">➕ Добавить первый класс</a>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Название</th>
                                <th>Класс</th>
                                <th>Классный руководитель</th>
                                <th>Учеников</th>
                                <th>Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($classes as $class): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($class['name']); ?></td>
                                    <td><?php echo $class['grade_level']; ?> класс</td>
                                    <td><?php echo !empty($class['teacher_name']) ? htmlspecialchars($class['teacher_name']) : '—'; ?></td>
                                    <td><?php echo $class['student_count']; ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="classes.php?action=view&id=<?php echo $class['id']; ?>" class="btn-action btn-view" title="Просмотр">👁️</a>
                                            <a href="classes.php?action=edit&id=<?php echo $class['id']; ?>" class="btn-action btn-edit" title="Редактировать">✏️</a>
                                            <?php echo csrf_post_button('delete', (int)$class['id'], '🗑️', 'btn-action btn-delete', 'Вы уверены, что хотите удалить этот класс?'); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
    // Функция подтверждения удаления
    function confirmDelete(className) {
        return confirm('Вы уверены, что хотите удалить класс "' + className + '"?\n\n' +
            'Если у класса есть связанные данные (расписание, ученики и т.д.), ' +
            'то будет выполнено "мягкое удаление" - класс будет деактивирован.');
    }

    // Автоматическое скрытие уведомлений через 5 секунд
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                alert.style.transition = 'opacity 0.5s ease';
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
    });
</script>
</body>
</html>
