<?php
session_start();
require_once '../config/database.php';
require_once '../config/report_files.php';

// Системные отчёты доступны только супер-администратору.
// Школьный администратор использует отдельный отчётный раздел своей школы.
if (($_SESSION['user_role'] ?? null) === 'school_admin') {
    header('Location: ../school_admin/reports.php');
    exit;
}

// Проверка авторизации
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'super_admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = getDatabaseConnection();

try {
    ensureReportFilesSchema($pdo);
} catch (PDOException $e) {
    error_log('Ошибка подготовки таблицы report_files: ' . $e->getMessage());
}

// Получение статистики для дашборда
$stats = [];

// Общая статистика по школам
$stats['total_schools'] = $pdo->query("SELECT COUNT(*) as count FROM schools")->fetch()['count'];
$stats['active_schools'] = $pdo->query("SELECT COUNT(*) as count FROM schools WHERE status = 'активная'")->fetch()['count'];

// Статистика по пользователям
$stats['total_users'] = $pdo->query("SELECT COUNT(*) as count FROM users")->fetch()['count'];
$stats['teachers'] = $pdo->query("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name IN ('teacher', 'class_teacher')")->fetch()['count'];
$stats['students'] = $pdo->query("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'student'")->fetch()['count'];
$stats['admins'] = $pdo->query("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name IN ('super_admin', 'school_admin')")->fetch()['count'];

// Статистика по учебным планам
$stats['total_curriculum'] = $pdo->query("SELECT COUNT(*) as count FROM curriculum")->fetch()['count'];
$stats['active_curriculum'] = $pdo->query("SELECT COUNT(*) as count FROM curriculum WHERE is_active = 1")->fetch()['count'];

// Статистика по учебным периодам
$stats['total_periods'] = $pdo->query("SELECT COUNT(*) as count FROM academic_periods")->fetch()['count'];
$stats['current_periods'] = $pdo->query("SELECT COUNT(*) as count FROM academic_periods WHERE is_current = 1")->fetch()['count'];

// Создание таблицы для прикрепленных файлов, если её нет
$pdo->exec("
    CREATE TABLE IF NOT EXISTS report_files (
        id INT PRIMARY KEY AUTO_INCREMENT,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_size INT NOT NULL,
        file_type VARCHAR(100) NOT NULL,
        description TEXT,
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
    )
");

// Обработка загрузки файлов
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

// Обработка удаления файла
if (isset($_GET['delete_file'])) {
    $file_id = intval($_GET['delete_file']);

    try {
        // Получаем информацию о файле
        $stmt = $pdo->prepare("SELECT filename FROM report_files WHERE id = ?");
        $stmt->execute([$file_id]);
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
            $_SESSION['error_message'] = "Файл не найден";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Ошибка при удалении файла: " . $e->getMessage();
    }

    header('Location: reports.php');
    exit;
}

// Получение списка прикрепленных файлов
$files = $pdo->query("
    SELECT rf.*, u.full_name as uploaded_by_name, u.login as uploaded_by_login 
    FROM report_files rf 
    JOIN users u ON rf.uploaded_by = u.id 
    ORDER BY rf.created_at DESC
")->fetchAll();

// Получение списка пользователей для просмотра данных
$all_users = $pdo->query("
    SELECT u.*, s.full_name as school_name, r.name as role_name 
    FROM users u 
    LEFT JOIN schools s ON u.school_id = s.id 
    LEFT JOIN roles r ON u.role_id = r.id 
    ORDER BY u.created_at DESC
")->fetchAll();
?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <link rel="shortcut icon" href="../logo.png" />
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Отчеты - Знание Севера</title>
        <link rel="stylesheet" href="../css/dashboard.css">
        <link rel="stylesheet" href="../css/reports.css">
    </head>
    <body>
    <div class="dashboard-container">
        <!-- Боковая панель -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1>Знание Севера</h1>
                <p>Электронный дневник</p>
            </div>

            <nav class="sidebar-nav">
                <div class="user-info">
                    <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
                    <span class="role-badge"><?php echo htmlspecialchars($_SESSION['user_role']); ?></span>
                </div>

                <ul class="nav-menu">
                    <li><a href="super_dashboard.php" class="nav-link">📊 Обзор</a></li>
                    <li class="nav-section">Администрирование</li>
                    <li><a href="schools.php" class="nav-link">🏫 Учебные заведения</a></li>
                    <li><a href="users.php" class="nav-link">👥 Пользователи</a></li>
                    <li><a href="roles.php" class="nav-link">🔐 Роли и права</a></li>
                    <li><a href="curriculum.php" class="nav-link">📚 Учебные планы</a></li>
                    <li><a href="academic_periods.php" class="nav-link">📅 Учебные периоды</a></li>
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
                    <h1>Системные отчеты</h1>
                    <p>Главный Администратор • <?php echo htmlspecialchars($_SESSION['user_login']); ?></p>
                </div>
            </header>

            <div class="content-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-error"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
                <?php endif; ?>

                <!-- Статистика в реальном времени -->
                <div class="stats-section">
                    <h2>Общая статистика системы</h2>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">🏫</div>
                            <div class="stat-info">
                                <h3>Учебные заведения</h3>
                                <span class="stat-number"><?php echo $stats['total_schools']; ?></span>
                                <span class="stat-detail"><?php echo $stats['active_schools']; ?> активных</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">👥</div>
                            <div class="stat-info">
                                <h3>Пользователи</h3>
                                <span class="stat-number"><?php echo $stats['total_users']; ?></span>
                                <span class="stat-detail"><?php echo $stats['teachers']; ?> учителей, <?php echo $stats['students']; ?> учеников</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">📚</div>
                            <div class="stat-info">
                                <h3>Учебные планы</h3>
                                <span class="stat-number"><?php echo $stats['total_curriculum']; ?></span>
                                <span class="stat-detail"><?php echo $stats['active_curriculum']; ?> активных</span>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">📅</div>
                            <div class="stat-info">
                                <h3>Учебные периоды</h3>
                                <span class="stat-number"><?php echo $stats['total_periods']; ?></span>
                                <span class="stat-detail"><?php echo $stats['current_periods']; ?> текущих</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Загрузка файлов -->
                <div class="upload-section">
                    <h2>Прикрепление отчетов</h2>
                    <div class="upload-card">
                        <form method="POST" enctype="multipart/form-data" class="upload-form">
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
                </div>

                <!-- Таблица прикрепленных файлов -->
                <div class="files-section">
                    <h2>Прикрепленные файлы отчетов</h2>
                    <div class="files-table-container">
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
                                    <td colspan="7" class="no-files">
                                        <div class="no-files-icon">📁</div>
                                        <p>Нет прикрепленных файлов</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($files as $file): ?>
                                    <tr>
                                        <td>
                                            <div class="file-name">
                                                <span class="file-icon">📄</span>
                                                <?php echo htmlspecialchars($file['original_name']); ?>
                                            </div>
                                        </td>
                                        <td><?php echo formatFileSize($file['file_size']); ?></td>
                                        <td>
                                            <span class="file-type"><?php echo htmlspecialchars($file['file_type']); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($file['description']): ?>
                                                <span class="file-description"><?php echo htmlspecialchars($file['description']); ?></span>
                                            <?php else: ?>
                                                <span class="no-description">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="uploader-info">
                                                <strong><?php echo htmlspecialchars($file['uploaded_by_name']); ?></strong>
                                                <small><?php echo htmlspecialchars($file['uploaded_by_login']); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($file['created_at'])); ?></td>
                                        <td>
                                            <div class="file-actions">
                                                <a href="../uploads/reports/<?php echo htmlspecialchars($file['filename']); ?>"
                                                   download="<?php echo htmlspecialchars($file['original_name']); ?>"
                                                   class="btn-action btn-download" title="Скачать">
                                                    📥
                                                </a>
                                                <button onclick="confirmDeleteFile(<?php echo $file['id']; ?>)"
                                                        class="btn-action btn-delete" title="Удалить">
                                                    🗑️
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Таблица пользователей -->
                <div class="users-section">
                    <h2>Все пользователи системы</h2>
                    <div class="users-table-container">
                        <table class="users-table">
                            <thead>
                            <tr>
                                <th>ФИО</th>
                                <th>Логин</th>
                                <th>Роль</th>
                                <th>Школа</th>
                                <th>Email</th>
                                <th>Телефон</th>
                                <th>Статус</th>
                                <th>Последний вход</th>
                                <th>Дата регистрации</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($all_users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-name">
                                            <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                            <?php if ($user['position']): ?>
                                                <br><small><?php echo htmlspecialchars($user['position']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['login']); ?></td>
                                    <td>
                                        <span class="user-role role-<?php echo htmlspecialchars($user['role_name']); ?>">
                                            <?php echo htmlspecialchars($user['role_name']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $user['school_name'] ? htmlspecialchars($user['school_name']) : '—'; ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : '—'; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $user['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['last_login']): ?>
                                            <?php echo date('d.m.Y H:i', strtotime($user['last_login'])); ?>
                                        <?php else: ?>
                                            <span class="never-logged">Никогда</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        function confirmDeleteFile(fileId) {
            if (confirm('Вы уверены, что хотите удалить этот файл? Это действие нельзя отменить.')) {
                window.location.href = 'reports.php?delete_file=' + fileId;
            }
        }

        // Показ размера файла при выборе
        document.getElementById('report_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const fileSize = file.size;
                const maxSize = 10 * 1024 * 1024; // 10MB

                if (fileSize > maxSize) {
                    alert('Файл слишком большой. Максимальный размер: 10MB');
                    e.target.value = '';
                }
            }
        });

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                color: white;
                font-weight: 600;
                z-index: 10000;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            `;
            notification.textContent = message;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
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