<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/report_files.php';

// Проверка прав - только school_admin
requireSchoolAdmin();

$pdo = getDatabaseConnection();

try {
    ensureReportFilesSchema($pdo);
} catch (PDOException $e) {
    error_log('Ошибка подготовки таблицы report_files: ' . $e->getMessage());
}

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

// Обработка загрузки файлов (используем ту же таблицу что и super_admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['report_file']) && $_FILES['report_file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload_dir = '../uploads/reports/';

    // Создаем директорию если не существует
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file = $_FILES['report_file'];
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    // Проверяем ошибки загрузки
    if ($file['error'] === UPLOAD_ERR_OK) {
        $original_name = basename($file['name']);
        $file_size = $file['size'];
        $file_type = $file['type'];

        // Проверяем размер файла (максимум 10MB)
        $max_size = 10 * 1024 * 1024;
        if ($file_size > $max_size) {
            $_SESSION['error_message'] = "Файл слишком большой. Максимальный размер: 10MB";
            header('Location: reports.php');
            exit;
        }

        // Проверяем тип файла
        $allowed_types = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'text/plain'
        ];

        if (!in_array($file_type, $allowed_types)) {
            $_SESSION['error_message'] = "Недопустимый тип файла. Разрешены: PDF, DOC, DOCX, XLS, XLSX, CSV, TXT";
            header('Location: reports.php');
            exit;
        }

        // Генерируем уникальное имя файла
        $file_extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $filename = uniqid() . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $filename;

        // Перемещаем файл
        if (move_uploaded_file($file['tmp_name'], $file_path)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO report_files (filename, original_name, file_size, file_type, description, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$filename, $original_name, $file_size, $file_type, $description, $_SESSION['user_id']]);

                $_SESSION['success_message'] = "Файл '{$original_name}' успешно загружен!";
            } catch (PDOException $e) {
                $_SESSION['error_message'] = "Ошибка при сохранении информации о файле: " . $e->getMessage();
                // Удаляем файл если не удалось сохранить в БД
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        } else {
            $_SESSION['error_message'] = "Ошибка при загрузке файла на сервер";
        }
    } else {
        $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, разрешенный сервером',
                UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер, указанный в форме',
                UPLOAD_ERR_PARTIAL => 'Файл был загружен только частично',
                UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
                UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
                UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
                UPLOAD_ERR_EXTENSION => 'Расширение PHP остановило загрузку файла'
        ];

        $error_message = isset($error_messages[$file['error']]) ? $error_messages[$file['error']] : 'Неизвестная ошибка загрузки';
        $_SESSION['error_message'] = "Ошибка загрузки файла: " . $error_message;
    }

    header('Location: reports.php');
    exit;
}

// Обработка удаления файла (только свои файлы)
if (isset($_GET['delete_file'])) {
    $file_id = intval($_GET['delete_file']);

    try {
        // Проверяем, что файл принадлежит текущему пользователю
        $stmt = $pdo->prepare("SELECT filename FROM report_files WHERE id = ? AND uploaded_by = ?");
        $stmt->execute([$file_id, $_SESSION['user_id']]);
        $file = $stmt->fetch();

        if ($file) {
            // Удаляем физический файл
            $file_path = '../uploads/reports/' . $file['filename'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }

            // Удаляем запись из БД
            $delete_stmt = $pdo->prepare("DELETE FROM report_files WHERE id = ?");
            $delete_stmt->execute([$file_id]);

            $_SESSION['success_message'] = "Файл успешно удален!";
        } else {
            $_SESSION['error_message'] = "Файл не найден или у вас нет прав для его удаления";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Ошибка при удалении файла: " . $e->getMessage();
    }

    header('Location: reports.php');
    exit;
}

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
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>Знание Севера</h1>
                <p>Электронный дневник</p>
                <?php if ($school): ?>
                    <div class="school-info">
                        <strong><?php echo htmlspecialchars($school['short_name'] ?: $school['full_name']); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
            <nav class="sidebar-nav">
                <div class="user-info">
                    <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
                    <span class="role-badge school-admin">Администратор школы</span>
                </div>
                <ul class="nav-menu">
                    <li><a href="dashboard.php" class="nav-link">🏠 Главная</a></li>
                    <li class="nav-section">Управление школой</li>
                    <li><a href="classes.php" class="nav-link">👨‍🏫 Классы</a></li>
                    <li><a href="teachers.php" class="nav-link">👥 Учителя</a></li>
                    <li><a href="students.php" class="nav-link">🎓 Ученики</a></li>
                    <li><a href="parents.php" class="nav-link">👨‍👩‍👧‍👦 Родители</a></li>
                    <li><a href="schedule.php" class="nav-link">📅 Расписание</a></li>
                    <li><a href="grade_types.php" class="nav-link">📊 Типы оценок</a></li>
                    <li><a href="grade_weights.php" class="nav-link">⚖️ Веса оценок</a></li>
                    <li><a href="reports.php" class="nav-link active">📈 Отчеты</a></li>
                    <li class="nav-section">Общее</li>
                    <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                    <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <header class="content-header">
                <div class="header-title">
                    <h1>Отчеты школы</h1>
                    <p><?php echo htmlspecialchars($school['full_name']); ?></p>
                </div>
            </header>

            <div class="content-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-error"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
                <?php endif; ?>

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
                                            <a href="../uploads/reports/<?php echo htmlspecialchars($file['filename']); ?>"
                                               download="<?php echo htmlspecialchars($file['original_name']); ?>"
                                               class="btn-action">📥</a>
                                                <?php if ((int)$file['uploaded_by'] === (int)$_SESSION['user_id']): ?>
                                                <button onclick="confirmDeleteFile(<?php echo $file['id']; ?>)"
                                                    class="btn-action">🗑️</button>
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
        function confirmDeleteFile(fileId) {
            if (confirm('Вы уверены, что хотите удалить этот файл?')) {
                window.location.href = 'reports.php?delete_file=' + fileId;
            }
        }
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