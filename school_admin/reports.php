<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/report_files.php';
require_once __DIR__ . '/../includes/grades_helper.php';
require_once __DIR__ . '/../includes/periods_helper.php';

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

// Получение статистики для школы
$stats = [];

// Статистика по пользователям школы
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE school_id = ?");
$stmt->execute([$school_id]);
$stats['total_users'] = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE u.school_id = ? AND r.name IN ('teacher', 'class_teacher')");
$stmt->execute([$school_id]);
$stats['teachers'] = $stmt->fetch()['count'];

$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE u.school_id = ? AND r.name = 'student'");
$stmt->execute([$school_id]);
$stats['students'] = $stmt->fetch()['count'];

// Статистика по классам
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM classes WHERE school_id = ?");
$stmt->execute([$school_id]);
$stats['total_classes'] = $stmt->fetch()['count'];

// Статистика по предметам
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM subjects WHERE school_id = ?");
$stmt->execute([$school_id]);
$stats['total_subjects'] = $stmt->fetch()['count'];

$period_grade_count = 0;
$period_average = null;
if ($selected_period) {
    $grades_stmt = $pdo->prepare("
        SELECT g.student_id, g.subject_id, g.grade_value, g.grade_type_id, g.grade_weight_id,
               gt.default_weight_id, gw.weight AS grade_weight_value,
               default_gw.weight AS default_weight_value,
               default_gw.is_active AS default_weight_active
        FROM grades g
        JOIN users u ON u.id = g.student_id AND u.school_id = ?
        JOIN subjects s ON s.id = g.subject_id AND s.school_id = u.school_id
        LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = u.school_id
        LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = u.school_id
        LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = u.school_id
        WHERE u.school_id = ? AND g.lesson_date BETWEEN ? AND ?
    ");
    $grades_stmt->execute([$school_id, $school_id, $selected_period['start_date'], $selected_period['end_date']]);
    $grades_by_student = [];
    foreach ($grades_stmt->fetchAll() as $grade) {
        if (isNumericGrade($grade['grade_value'])) {
            $period_grade_count++;
            $grades_by_student[$grade['student_id']][$grade['subject_id']][] = $grade;
        }
    }
    $student_averages = [];
    foreach ($grades_by_student as $student_subjects) {
        $subject_averages = [];
        foreach ($student_subjects as $subject_grades) {
            $subject_averages[] = calculateWeightedAverage($subject_grades);
        }
        $student_averages[] = calculateOverallAverage($subject_averages);
    }
    $period_average = calculateOverallAverage($student_averages);
}

handleReportFileActions($pdo, (int)$_SESSION['user_id'], basename(__FILE__));

// Получение отчётов своей школы и системных отчётов супер-администратора
$stmt = $pdo->prepare("
    SELECT rf.*, u.full_name as uploaded_by_name, u.login as uploaded_by_login,
           r.name as uploaded_by_role
    FROM report_files rf
    JOIN users u ON rf.uploaded_by = u.id
    JOIN roles r ON u.role_id = r.id
    WHERE u.school_id = ? OR r.name = 'super_admin'
    ORDER BY rf.created_at DESC
");
$stmt->execute([$school_id]);
$files = $stmt->fetchAll();

// Получение списка пользователей школы
$stmt = $pdo->prepare("
    SELECT u.*, r.name as role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_id = r.id 
    WHERE u.school_id = ?
    ORDER BY u.created_at DESC
");
$stmt->execute([$school_id]);
$school_users = $stmt->fetchAll();
?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <link rel="shortcut icon" href="../logo.png" />
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Отчёты школы - Знание Севера</title>
        <link rel="stylesheet" href="../css/dashboard.css">
        <style>
            .stats-section {
                margin-bottom: 30px;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin-bottom: 20px;
            }

            .stat-card {
                background: white;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .stat-icon {
                font-size: 2em;
            }

            .stat-info h3 {
                margin: 0 0 5px 0;
                font-size: 0.9em;
                color: #666;
            }

            .stat-number {
                font-size: 1.8em;
                font-weight: bold;
                color: #2c3e50;
                display: block;
            }

            .stat-detail {
                font-size: 0.8em;
                color: #7f8c8d;
            }

            .upload-section {
                background: white;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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

            .form-hint {
                display: block;
                margin-top: 5px;
                color: #7f8c8d;
                font-size: 0.8em;
            }

            .files-section, .users-section {
                background: white;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .files-table, .users-table {
                width: 100%;
                border-collapse: collapse;
            }

            .files-table th, .users-table th {
                background: #f8f9fa;
                padding: 12px;
                text-align: left;
                font-weight: 600;
                border-bottom: 2px solid #e9ecef;
            }

            .files-table td, .users-table td {
                padding: 12px;
                border-bottom: 1px solid #e9ecef;
            }

            .file-actions {
                display: flex;
                gap: 5px;
            }

            .btn-action {
                padding: 4px 8px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 0.9em;
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
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
            }

            .btn-primary {
                background: #3498db;
                color: white;
            }
        </style>
    </head>
    <body>
    <div class="dashboard-container">
        <?php $schoolAdminActivePage = 'reports'; require __DIR__ . '/../includes/school_admin_sidebar.php'; ?>

        <main class="main-content">
            <header class="content-header">
                <div class="header-title">
                    <h1>Отчёты школы</h1>
                    <p><?php echo htmlspecialchars($school['full_name']); ?></p>
                </div>
            </header>

            <div class="content-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success"><?php echo consumeFlashMessage('success_message'); ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-error"><?php echo consumeFlashMessage('error_message'); ?></div>
                <?php endif; ?>

                <form method="GET" class="upload-section" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                    <label for="period_id">Учебный период:</label>
                    <select id="period_id" name="period_id" onchange="this.form.submit()">
                        <option value="">Выберите период</option>
                        <?php foreach ($periods as $period): ?>
                            <option value="<?php echo (int)$period['id']; ?>" <?php echo $selected_period_id === (int)$period['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8'); ?><?php echo $period['is_locked'] ? ' 🔒' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <!-- Статистика школы -->
                <div class="stats-section">
                    <h2>Статистика школы</h2>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">👥</div>
                            <div class="stat-info">
                                <h3>Всего пользователей</h3>
                                <span class="stat-number"><?php echo $stats['total_users']; ?></span>
                            </div>
                        </div>
                        <?php if ($selected_period): ?>
                            <div class="stats-section">
                                <h2>Успеваемость за период: <?php echo htmlspecialchars($selected_period['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                <div class="stats-grid">
                                    <div class="stat-card">
                                        <div class="stat-icon">📝</div>
                                        <div class="stat-info"><h3>Числовых оценок</h3><span class="stat-number"><?php echo $period_grade_count; ?></span></div>
                                    </div>
                                    <div class="stat-card">
                                        <div class="stat-icon">📊</div>
                                        <div class="stat-info"><h3>Средний балл</h3><span class="stat-number"><?php echo $period_average === null ? 'н/д' : number_format($period_average, 2, '.', ''); ?></span></div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="stat-card">
                            <div class="stat-icon">👨‍🏫</div>
                            <div class="stat-info">
                                <h3>Учителей</h3>
                                <span class="stat-number"><?php echo $stats['teachers']; ?></span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">🎓</div>
                            <div class="stat-info">
                                <h3>Учеников</h3>
                                <span class="stat-number"><?php echo $stats['students']; ?></span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">🏫</div>
                            <div class="stat-info">
                                <h3>Классов</h3>
                                <span class="stat-number"><?php echo $stats['total_classes']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Загрузка файлов -->
                <div class="upload-section">
                    <h2>Прикрепление отчетов</h2>
                    <form method="POST" enctype="multipart/form-data">
                        <?php echo csrf_field(); ?>
                        <div class="form-group">
                            <label for="report_file">Выберите файл отчета</label>
                            <input type="file" name="report_file" id="report_file" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" required>
                            <small class="form-hint">Поддерживаемые форматы: PDF, DOC, DOCX, XLS, XLSX, CSV, TXT (макс. 10MB)</small>
                        </div>
                        <div class="form-group">
                            <label for="description">Описание файла</label>
                            <textarea name="description" id="description" placeholder="Введите описание файла..." rows="3"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary">📎 Прикрепить файл</button>
                    </form>
                </div>

                <!-- Таблица прикрепленных файлов -->
                <div class="files-section">
                    <h2>Мои прикрепленные файлы</h2>
                    <table class="files-table">
                        <thead>
                        <tr>
                            <th>Имя файла</th>
                            <th>Размер</th>
                            <th>Тип</th>
                            <th>Описание</th>
                            <th>Загрузил</th>
                            <th>Дата загрузки</th>
                            <th>Действия</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($files)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 20px; color: #666;">
                                    Нет прикрепленных файлов
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($files as $file): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($file['original_name']); ?></td>
                                    <td><?php echo formatFileSize($file['file_size']); ?></td>
                                    <td><?php echo htmlspecialchars($file['file_type']); ?></td>
                                    <td><?php echo $file['description'] ? htmlspecialchars($file['description']) : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($file['uploaded_by_name']); ?><?php echo $file['uploaded_by_role'] === 'super_admin' ? ' (система)' : ''; ?></td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($file['created_at'])); ?></td>
                                    <td>
                                        <div class="file-actions">
                                            <a href="../reports/download.php?id=<?php echo (int)$file['id']; ?>"
                                               class="btn-action">📥</a>
                                                <?php if ((int)$file['uploaded_by'] === (int)$_SESSION['user_id']): ?>
                                                <form method="POST" class="inline-delete" onsubmit="return confirm('Вы уверены, что хотите удалить этот файл?');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="delete_file" value="<?php echo (int)$file['id']; ?>">
                                                    <button type="submit" class="btn-action">🗑️</button>
                                                </form>
                                                <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Таблица пользователей школы -->
                <div class="users-section">
                    <h2>Пользователи школы</h2>
                    <table class="users-table">
                        <thead>
                        <tr>
                            <th>ФИО</th>
                            <th>Логин</th>
                            <th>Роль</th>
                            <th>Email</th>
                            <th>Телефон</th>
                            <th>Статус</th>
                            <th>Дата регистрации</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($school_users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['login']); ?></td>
                                <td><?php echo htmlspecialchars($user['role_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                <td><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : '—'; ?></td>
                                <td>
                                <span style="padding: 2px 6px; border-radius: 3px; font-size: 0.8em; background: <?php echo $user['is_active'] ? '#d4edda' : '#f8d7da'; ?>; color: <?php echo $user['is_active'] ? '#155724' : '#721c24'; ?>;">
                                    <?php echo $user['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                </span>
                                </td>
                                <td><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script>
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