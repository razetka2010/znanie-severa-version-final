<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/report_files.php';

// Системные отчёты доступны только супер-администратору.
// Школьный администратор использует отдельный отчётный раздел своей школы.
if (($_SESSION['user_role'] ?? null) === 'school_admin') {
    header('Location: ../school_admin/reports.php');
    exit;
}
requireSuperAdmin();

$pdo = getDatabaseConnection();

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

handleReportFileActions($pdo, (int)$_SESSION['user_id'], basename(__FILE__), false, true);

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
        <?php $adminActivePage = 'reports'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>

        <main class="main-content">
            <header class="content-header">
                <div class="header-title">
                    <h1>Системные отчеты</h1>
                    <p>Главный Администратор • <?php echo htmlspecialchars($_SESSION['user_login']); ?></p>
                </div>
            </header>

            <div class="content-body">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success"><?php echo consumeFlashMessage('success_message'); ?></div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-error"><?php echo consumeFlashMessage('error_message'); ?></div>
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
                                                <a href="../reports/download.php?id=<?php echo (int)$file['id']; ?>"
                                                   class="btn-action btn-download" title="Скачать">
                                                    📥
                                                </a>
                                                <form method="POST" class="inline-delete" onsubmit="return confirm('Вы уверены, что хотите удалить этот файл? Это действие нельзя отменить.');">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="delete_file" value="<?php echo (int)$file['id']; ?>">
                                                    <button type="submit" class="btn-action btn-delete" title="Удалить">🗑️</button>
                                                </form>
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
                                        <span class="user-role role-<?php echo htmlspecialchars($user['role_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars(getRoleDisplayName((string)$user['role_name'])); ?>
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