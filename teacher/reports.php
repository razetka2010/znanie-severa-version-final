<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/report_files.php';
require_once __DIR__ . '/../includes/periods_helper.php';

requireTeacher();

$pdo = getDatabaseConnection();
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['user_school_id'];
$periods = getSchoolPeriods($pdo, (int)$school_id);
$current_period = getCurrentPeriod($pdo, (int)$school_id);
$selected_period_id = isset($_GET['period_id']) ? (int)$_GET['period_id'] : (int)($current_period['id'] ?? 0);
$selected_period = null;
foreach ($periods as $period) {
    if ((int)$period['id'] === $selected_period_id) {
        $selected_period = $period;
        break;
    }
}

// Получаем информацию о учителе
$teacher_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$teacher_stmt->execute([$teacher_id]);
$teacher = $teacher_stmt->fetch();

// Статистика для учителя
$stats = [
    'total_classes' => 0,
    'total_students' => 0,
    'today_lessons' => 0,
    'total_grades' => 0
];

try {
    // Количество классов
    $schedule_period_sql = $selected_period ? ' AND lesson_date BETWEEN ? AND ?' : '';
    $student_period_sql = $selected_period ? ' AND s.lesson_date BETWEEN ? AND ?' : '';
    $period_params = $selected_period ? [$selected_period['start_date'], $selected_period['end_date']] : [];
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT class_id) as count
        FROM schedule
        WHERE teacher_id = ? AND school_id = ? $schedule_period_sql
    ");
    $stmt->execute(array_merge([$teacher_id, $school_id], $period_params));
    $stats['total_classes'] = $stmt->fetch()['count'];

    // Количество учеников
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT u.id) as count
        FROM users u
        JOIN schedule s ON u.class_id = s.class_id AND s.school_id = u.school_id
        WHERE s.teacher_id = ? AND s.school_id = ? 
        AND u.school_id = ?
        AND u.role_id IN (SELECT id FROM roles WHERE name = 'student')
        AND u.is_active = 1 $student_period_sql
    ");
    $stmt->execute(array_merge([$teacher_id, $school_id, $school_id], $period_params));
    $stats['total_students'] = $stmt->fetch()['count'];

    // Уроки на сегодня
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM schedule 
        WHERE teacher_id = ? AND school_id = ? AND lesson_date = CURDATE()
    ");
    $stmt->execute([$teacher_id, $school_id]);
    $stats['today_lessons'] = $stmt->fetch()['count'];

    // Всего выставленных оценок
    $grade_period_sql = $selected_period ? ' AND g.lesson_date BETWEEN ? AND ?' : '';
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM grades g
        JOIN users u ON u.id = g.student_id AND u.school_id = ?
        WHERE g.teacher_id = ? AND g.grade_value IS NOT NULL $grade_period_sql
    ");
    $grade_params = [$school_id, $teacher_id];
    if ($selected_period) {
        $grade_params[] = $selected_period['start_date'];
        $grade_params[] = $selected_period['end_date'];
    }
    $stmt->execute($grade_params);
    $stats['total_grades'] = $stmt->fetch()['count'];

} catch (PDOException $e) {
    error_log("Ошибка при получении статистики: " . $e->getMessage());
}

handleReportFileActions($pdo, (int)$_SESSION['user_id'], basename(__FILE__), true);

// Получение списка прикрепленных файлов (только файлы текущего пользователя)
$stmt = $pdo->prepare("
    SELECT rf.*, u.full_name as uploaded_by_name, u.login as uploaded_by_login 
    FROM report_files rf 
    JOIN users u ON rf.uploaded_by = u.id 
    WHERE rf.uploaded_by = ?
    ORDER BY rf.created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$files = $stmt->fetchAll();

// Получение моих классов
$my_classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, c.grade_level
        FROM classes c
        JOIN schedule sch ON sch.class_id = c.id AND sch.school_id = c.school_id
        WHERE c.school_id = ? AND c.is_active = 1 AND sch.teacher_id = ?
            " . ($selected_period ? "AND sch.lesson_date BETWEEN ? AND ?" : "") . "
        ORDER BY c.grade_level, c.name
    ");
    $stmt->execute($selected_period
        ? [$school_id, $teacher_id, $selected_period['start_date'], $selected_period['end_date']]
        : [$school_id, $teacher_id]);
    $my_classes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении классов: " . $e->getMessage());
}

// Получение моих предметов
$my_subjects = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT s.id, s.name 
        FROM subjects s 
        JOIN schedule sch ON s.id = sch.subject_id 
        WHERE sch.teacher_id = ? AND sch.school_id = ?
            " . ($selected_period ? "AND sch.lesson_date BETWEEN ? AND ?" : "") . "
        ORDER BY s.name
    ");
    $stmt->execute($selected_period
        ? [$teacher_id, $school_id, $selected_period['start_date'], $selected_period['end_date']]
        : [$teacher_id, $school_id]);
    $my_subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении предметов: " . $e->getMessage());
}
?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <link rel="shortcut icon" href="../logo.png" />
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Отчёты - Учитель</title>
        <style>
            * {
                box-sizing: border-box;
                margin: 0;
                padding: 0;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #f5f6fa;
                color: #2c3e50;
                line-height: 1.6;
            }

            .dashboard-container {
                display: flex;
                min-height: 100vh;
            }

            /* Сайдбар */
            .sidebar {
                width: 280px;
                background: #2c3e50;
                color: white;
                position: fixed;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar-header {
                padding: 20px;
                background: #34495e;
                border-bottom: 1px solid #4a6278;
            }

            .sidebar-header h1 {
                font-size: 1.2em;
                margin-bottom: 5px;
            }

            .sidebar-header p {
                font-size: 0.9em;
                opacity: 0.8;
            }

            .sidebar-nav {
                padding: 0;
            }

            .user-info {
                padding: 15px 20px;
                background: #34495e;
                border-bottom: 1px solid #4a6278;
            }

            .role-badge {
                display: inline-block;
                background: #e74c3c;
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 0.8em;
                margin-left: 8px;
            }

            .nav-menu {
                list-style: none;
                padding: 0;
            }

            .nav-section {
                padding: 12px 20px;
                font-size: 0.8em;
                text-transform: uppercase;
                opacity: 0.7;
                border-bottom: 1px solid #4a6278;
            }

            .nav-link {
                display: block;
                padding: 12px 20px;
                color: white;
                text-decoration: none;
                border-left: 3px solid transparent;
                transition: all 0.3s;
            }

            .nav-link:hover {
                background: #34495e;
                border-left-color: #3498db;
            }

            .nav-link.active {
                background: #34495e;
                border-left-color: #3498db;
                font-weight: bold;
            }

            /* Основной контент */
            .main-content {
                flex: 1;
                margin-left: 280px;
                padding: 0;
            }

            .content-header {
                background: white;
                padding: 20px 30px;
                border-bottom: 1px solid #e0e0e0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .header-title h1 {
                font-size: 1.8em;
                margin-bottom: 5px;
                color: #2c3e50;
            }

            .header-title p {
                color: #7f8c8d;
            }

            .content-body {
                padding: 30px;
            }

            /* Статистика */
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }

            .stat-card {
                background: white;
                padding: 25px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                text-align: center;
                transition: transform 0.3s;
            }

            .stat-card:hover {
                transform: translateY(-5px);
            }

            .stat-icon {
                font-size: 2.5em;
                margin-bottom: 15px;
            }

            .stat-number {
                font-size: 2em;
                font-weight: bold;
                color: #2c3e50;
                margin-bottom: 5px;
            }

            .stat-label {
                color: #7f8c8d;
                font-size: 0.9em;
            }

            /* Секции */
            .section {
                background: white;
                border-radius: 10px;
                padding: 25px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 25px;
            }

            .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                padding-bottom: 15px;
                border-bottom: 2px solid #f8f9fa;
            }

            .section-title {
                font-size: 1.3em;
                color: #2c3e50;
                margin: 0;
            }

            /* Формы */
            .upload-form {
                display: grid;
                gap: 20px;
            }

            .form-group {
                margin-bottom: 0;
            }

            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #2c3e50;
            }

            .form-group input,
            .form-group textarea,
            .form-group select {
                width: 100%;
                padding: 12px 15px;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                font-size: 1em;
                transition: border-color 0.3s;
                background: white;
            }

            .form-group input:focus,
            .form-group textarea:focus,
            .form-group select:focus {
                border-color: #3498db;
                outline: none;
            }

            .form-hint {
                display: block;
                margin-top: 5px;
                color: #7f8c8d;
                font-size: 0.8em;
            }

            /* Таблицы */
            .data-table {
                width: 100%;
                border-collapse: collapse;
            }

            .data-table th,
            .data-table td {
                padding: 12px 15px;
                text-align: left;
                border-bottom: 1px solid #e0e0e0;
            }

            .data-table th {
                background: #f8f9fa;
                font-weight: 600;
                color: #2c3e50;
            }

            .data-table tr:hover {
                background: #f8f9fa;
            }

            /* Кнопки */
            .btn {
                padding: 10px 20px;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                font-size: 0.95em;
                font-weight: 500;
                transition: all 0.3s;
            }

            .btn-primary {
                background: #3498db;
                color: white;
            }

            .btn-primary:hover {
                background: #2980b9;
            }

            .btn-success {
                background: #27ae60;
                color: white;
            }

            .btn-success:hover {
                background: #219653;
            }

            .btn-danger {
                background: #e74c3c;
                color: white;
            }

            .btn-danger:hover {
                background: #c0392b;
            }

            .btn-secondary {
                background: #95a5a6;
                color: white;
            }

            .btn-secondary:hover {
                background: #7f8c8d;
            }

            .file-actions {
                display: flex;
                gap: 8px;
            }

            .btn-action {
                padding: 6px 12px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.9em;
                text-decoration: none;
                display: inline-block;
            }

            /* Сообщения */
            .alert {
                padding: 15px 20px;
                border-radius: 8px;
                margin-bottom: 20px;
                border-left: 4px solid;
            }

            .alert-success {
                background: #d4edda;
                color: #155724;
                border-left-color: #28a745;
            }

            .alert-error {
                background: #f8d7da;
                color: #721c24;
                border-left-color: #dc3545;
            }

            .empty-state {
                text-align: center;
                padding: 40px 20px;
                color: #7f8c8d;
            }

            .empty-state .icon {
                font-size: 3em;
                margin-bottom: 15px;
                opacity: 0.5;
            }

            /* Адаптивность */
            @media (max-width: 768px) {
                .sidebar {
                    width: 100%;
                    position: relative;
                    height: auto;
                }

                .main-content {
                    margin-left: 0;
                }

                .stats-grid {
                    grid-template-columns: 1fr;
                }

                .section-header {
                    flex-direction: column;
                    gap: 15px;
                    align-items: flex-start;
                }

                .file-actions {
                    flex-direction: column;
                }
            }

            .info-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }

            .info-card {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                border-left: 4px solid #3498db;
            }

            .info-card h3 {
                margin-bottom: 10px;
                color: #2c3e50;
            }

            .info-list {
                list-style: none;
                padding: 0;
            }

            .info-list li {
                padding: 5px 0;
                border-bottom: 1px solid #e9ecef;
            }

            .info-list li:last-child {
                border-bottom: none;
            }
        </style>
        <link rel="stylesheet" href="../css/teacher.css">
    </head>
    <body>
    <div class="dashboard-container">
        <?php $teacherActivePage = 'reports'; require __DIR__ . '/../includes/teacher_sidebar.php'; ?>

        <main class="main-content">
            <header class="content-header">
                <div class="header-title">
                    <h1>Отчёты и документы</h1>
                    <p>Управление отчетами и учебными материалами</p>
                </div>
            </header>

            <div class="content-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success"><?= consumeFlashMessage('success_message'); ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-error"><?= consumeFlashMessage('error_message'); ?></div>
                <?php endif; ?>

                <form method="GET" class="section" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <label for="period_id">Учебный период:</label>
                    <select id="period_id" name="period_id" onchange="this.form.submit()">
                        <option value="">Все периоды</option>
                        <?php foreach ($periods as $period): ?>
                            <option value="<?= (int)$period['id'] ?>" <?= $selected_period_id === (int)$period['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8') ?><?= $period['is_locked'] ? ' 🔒' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <!-- Статистика учителя -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">🏫</div>
                        <div class="stat-number"><?= $stats['total_classes'] ?></div>
                        <div class="stat-label">Классов</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">👨‍🎓</div>
                        <div class="stat-number"><?= $stats['total_students'] ?></div>
                        <div class="stat-label">Учеников</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📅</div>
                        <div class="stat-number"><?= $stats['today_lessons'] ?></div>
                        <div class="stat-label">Уроков сегодня</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">📝</div>
                        <div class="stat-number"><?= $stats['total_grades'] ?></div>
                        <div class="stat-label">Выставлено оценок</div>
                    </div>
                </div>

                <!-- Информация о классах и предметах -->
                <div class="info-grid">
                    <div class="info-card">
                        <h3>👨‍🏫 Мои классы</h3>
                        <ul class="info-list">
                            <?php if (!empty($my_classes)): ?>
                                <?php foreach ($my_classes as $class): ?>
                                    <li><?= htmlspecialchars($class['name']) ?> (<?= $class['grade_level'] ?> класс)</li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li style="color: #7f8c8d;">Нет назначенных классов</li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="info-card">
                        <h3>📚 Мои предметы</h3>
                        <ul class="info-list">
                            <?php if (!empty($my_subjects)): ?>
                                <?php foreach ($my_subjects as $subject): ?>
                                    <li><?= htmlspecialchars($subject['name']) ?></li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li style="color: #7f8c8d;">Нет назначенных предметов</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>

                <!-- Загрузка файлов -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">📎 Прикрепление отчетов и материалов</h2>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="upload-form">
                        <?php echo csrf_field(); ?>
                        <div class="form-group">
                            <label for="report_file">Выберите файл</label>
                            <input type="file" name="report_file" id="report_file"
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.gif" required>
                            <small class="form-hint">
                                Поддерживаемые форматы: PDF, DOC, DOCX, XLS, XLSX, CSV, TXT, JPG, PNG, GIF (макс. 10MB)
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="description">Описание файла</label>
                            <textarea name="description" id="description"
                                      placeholder="Опишите содержание файла (отчет, учебный материал, план урока и т.д.)..."
                                      rows="3"></textarea>
                        </div>

                        <button type="submit" class="btn btn-success" style="justify-self: start;">
                            📤 Загрузить файл
                        </button>
                    </form>
                </div>

                <!-- Мои файлы -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">📁 Мои прикрепленные файлы</h2>
                        <span class="btn btn-secondary" style="cursor: default;">
                            Всего: <?= count($files) ?>
                        </span>
                    </div>

                    <?php if (!empty($files)): ?>
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Имя файла</th>
                                <th>Размер</th>
                                <th>Тип</th>
                                <th>Описание</th>
                                <th>Дата загрузки</th>
                                <th>Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($files as $file): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($file['original_name']) ?></strong>
                                    </td>
                                    <td><?= formatFileSize($file['file_size']) ?></td>
                                    <td>
                                            <span style="font-size: 0.8em; background: #e9ecef; padding: 2px 6px; border-radius: 3px;">
                                                <?= htmlspecialchars($file['file_type']) ?>
                                            </span>
                                    </td>
                                    <td>
                                        <?= $file['description'] ? htmlspecialchars($file['description']) : '<span style="color: #7f8c8d;">—</span>' ?>
                                    </td>
                                    <td><?= date('d.m.Y H:i', strtotime($file['created_at'])) ?></td>
                                    <td>
                                        <div class="file-actions">
                                            <a href="../reports/download.php?id=<?= (int)$file['id'] ?>"
                                               class="btn-action btn-primary" title="Скачать">
                                                📥 Скачать
                                            </a>
                                            <form method="POST" class="inline-delete" onsubmit="return confirm('Вы уверены, что хотите удалить этот файл? Это действие нельзя отменить.');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="delete_file" value="<?= (int)$file['id'] ?>">
                                                <button type="submit" class="btn-action btn-danger" title="Удалить">🗑️ Удалить</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="icon">📁</div>
                            <h3>Нет прикрепленных файлов</h3>
                            <p>Загрузите свой первый файл используя форму выше</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Быстрые действия -->
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">⚡ Быстрые действия</h2>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <a href="grades.php" class="btn btn-primary" style="text-align: center; padding: 20px;">
                            <div style="font-size: 2em; margin-bottom: 10px;">📝</div>
                            Журнал оценок
                        </a>

                        <a href="homework.php" class="btn btn-primary" style="text-align: center; padding: 20px;">
                            <div style="font-size: 2em; margin-bottom: 10px;">📚</div>
                            Домашние задания
                        </a>

                        <a href="schedule.php" class="btn btn-primary" style="text-align: center; padding: 20px;">
                            <div style="font-size: 2em; margin-bottom: 10px;">📅</div>
                            Расписание
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Показываем имя выбранного файла
        document.getElementById('report_file').addEventListener('change', function(e) {
            const fileName = this.files[0] ? this.files[0].name : 'Файл не выбран';
            const hint = this.nextElementSibling;
            hint.textContent = 'Выбран файл: ' + fileName + ' | ' + hint.textContent;
        });
    </script>
    </body>
    </html>

<?php
// Функция для форматирования размера файла
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';

    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));

    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>