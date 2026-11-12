<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';

// Проверка прав - только super_admin
requireSuperAdmin();

$pdo = getDatabaseConnection();

// Статистика для главного админа
$stats = [];
try {
    // Учебные заведения
    $stats['total_schools'] = $pdo->query("SELECT COUNT(*) as count FROM schools")->fetch()['count'];
    $stats['active_schools'] = $pdo->query("SELECT COUNT(*) as count FROM schools WHERE status = 'активная'")->fetch()['count'];
    $stats['inactive_schools'] = $pdo->query("SELECT COUNT(*) as count FROM schools WHERE status = 'неактивная'")->fetch()['count'];

    // Пользователи по ролям
    $stats['total_admins'] = $pdo->query("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'school_admin'")->fetch()['count'];
    $stats['total_teachers'] = $pdo->query("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name IN ('teacher', 'class_teacher')")->fetch()['count'];
    $stats['total_students'] = $pdo->query("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'student'")->fetch()['count'];
    $stats['total_parents'] = $pdo->query("SELECT COUNT(*) as count FROM users u JOIN roles r ON u.role_id = r.id WHERE r.name = 'parent'")->fetch()['count'];
    $stats['total_users'] = $stats['total_admins'] + $stats['total_teachers'] + $stats['total_students'] + $stats['total_parents'];

    // Активность
    $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
    $stats['recent_logins'] = $pdo->query("SELECT COUNT(*) as count FROM users WHERE last_login >= '$thirty_days_ago'")->fetch()['count'];

    // Учебные планы
    $stats['total_curriculum'] = $pdo->query("SELECT COUNT(*) as count FROM curriculum")->fetch()['count'];
    $stats['active_curriculum'] = $pdo->query("SELECT COUNT(*) as count FROM curriculum WHERE is_active = 1")->fetch()['count'];

} catch (PDOException $e) {
    // Обработка ошибок
    $stats = [
        'total_schools' => 0, 'active_schools' => 0, 'inactive_schools' => 0,
        'total_admins' => 0, 'total_teachers' => 0, 'total_students' => 0,
        'total_parents' => 0, 'total_users' => 0, 'recent_logins' => 0,
        'total_curriculum' => 0, 'active_curriculum' => 0
    ];
}

// Последние добавленные школы
$recent_schools = $pdo->query("SELECT * FROM schools ORDER BY created_at DESC LIMIT 3")->fetchAll();

// Последние активности пользователей
$recent_activities = $pdo->query("
    SELECT us.*, u.full_name, u.login, r.name as role_name 
    FROM user_sessions us 
    JOIN users u ON us.user_id = u.id 
    JOIN roles r ON u.role_id = r.id 
    ORDER BY us.created_at DESC 
    LIMIT 3
")->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Главная панель - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        /* Дополнительные стили для главного админа */
        :root {
            --bg: #f3f7ff;
            --panel: #ffffff;
            --panel-soft: #f8fafc;
            --sidebar: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
            --primary: #2563eb;
            --primary-strong: #1d4ed8;
            --line: #e2e8f0;
            --text: #1f2937;
            --muted: #64748b;
            --shadow: 0 12px 28px rgba(15, 23, 42, 0.10);
        }

        body {
            background: linear-gradient(180deg, #eef4ff 0%, #f8fafc 100%);
            color: var(--text);
        }

        .sidebar {
            background: var(--sidebar);
            box-shadow: 12px 0 30px rgba(15, 23, 42, 0.12);
        }

        .nav-link {
            color: rgba(255,255,255,0.82);
            border-radius: 0 12px 12px 0;
            margin-right: 12px;
            transition: all 0.25s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.08);
            border-left-color: #7dd3fc;
            color: white;
        }

        .role-badge.school_admin {
            background: linear-gradient(135deg, #60a5fa 0%, #2563eb 100%);
        }

        .role-badge.teacher {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .role-badge.class_teacher {
            background: linear-gradient(135deg, #a78bfa 0%, #7c3aed 100%);
        }

        .role-badge.student {
            background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
        }

        .role-badge.parent {
            background: linear-gradient(135deg, #475569 0%, #334155 100%);
        }

        .dashboard-login-item .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #38bdf8, #2563eb);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 40px;
            font-weight: 700;
            font-size: 16px;
            text-transform: uppercase;
        }

        .dashboard-activity-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .dashboard-activity-item {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
            padding: 14px;
            border: 0;
            border-left: 4px solid #667eea;
            border-radius: 9px;
            background: #f8fafc;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .dashboard-activity-item:hover {
            background: #f1f5f9;
            border-left-color: #2563eb;
        }

        .dashboard-activity-item .activity-info {
            flex: 1;
            min-width: 0;
        }

        .dashboard-activity-item .activity-user {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 6px;
            overflow-wrap: anywhere;
        }

        .dashboard-activity-item .activity-details {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            color: #64748b;
            font-size: 12px;
        }

        .dashboard-login-item .user-role {
            padding: 4px 8px;
            border-radius: 5px;
            background: #e0f2fe;
            color: #0369a1;
            font-size: 11px;
            font-weight: 500;
        }

        .dashboard-login-item .activity-ip {
            font-family: 'Courier New', monospace;
            font-size: 11px;
        }

        .dashboard-activity-item .activity-time {
            flex-shrink: 0;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
        }

        .stats-section {
            margin-bottom: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 22px 20px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 16px;
            min-height: 128px;
            transition: transform 0.22s ease, box-shadow 0.22s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 28px rgba(37, 99, 235, 0.12);
        }

        .stat-icon {
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #60a5fa, #2563eb);
            border-radius: 14px;
            color: white;
            font-size: 2em;
            margin-right: 20px;
            opacity: 1;
        }

        .stat-info h3 {
            margin: 0 0 8px 0;
            color: #7f8c8d;
            font-size: 0.9em;
            font-weight: 600;
        }

        .stat-number {
            font-size: 2.2em;
            font-weight: bold;
            color: #2c3e50;
            display: block;
            line-height: 1;
        }

        .stat-detail {
            font-size: 0.85em;
            color: #95a5a6;
            margin-top: 5px;
            display: block;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        .dashboard-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-header {
            padding: 18px 22px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.1em;
        }

        .btn-link {
            color: #3498db;
            text-decoration: none;
            font-size: 0.9em;
            font-weight: 500;
        }

        .btn-link:hover {
            text-decoration: underline;
        }

        .card-content {
            padding: 20px 22px;
        }

        .empty-state {
            text-align: center;
            color: #95a5a6;
            padding: 20px;
        }

        .quick-actions-section {
            margin-top: 30px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .action-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 22px 20px;
            box-shadow: 0 12px 22px rgba(15, 23, 42, 0.06);
            text-decoration: none;
            color: inherit;
            transition: all 0.22s ease;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            min-height: 110px;
        }

        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 16px 28px rgba(37, 99, 235, 0.12);
            border-color: rgba(37, 99, 235, 0.32);
            color: inherit;
        }

        .action-icon {
            font-size: 2.5em;
            margin-right: 20px;
            opacity: 0.8;
        }

        .action-content h3 {
            margin: 0 0 8px 0;
            color: #2c3e50;
            font-size: 1.1em;
        }

        .action-content p {
            margin: 0;
            color: #7f8c8d;
            font-size: 0.9em;
            line-height: 1.4;
        }

        @media (max-width: 1024px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .actions-grid {
                grid-template-columns: 1fr;
            }

            .stat-card {
                padding: 20px;
            }

            .action-card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php $adminActivePage = 'super_dashboard'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Панель главного администратора</h1>
                <p>Управление всей системой "Знание Севера"</p>
            </div>
            <div class="header-actions">
                <a href="schools.php?action=add" class="btn btn-primary">🏫 Добавить школу</a>
                <a href="users.php?action=add" class="btn btn-secondary">👥 Добавить администратора</a>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo consumeFlashMessage('success_message'); ?></div>
            <?php endif; ?>

            <!-- Статистика системы -->
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
                            <span class="stat-detail">в системе</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👨‍💼</div>
                        <div class="stat-info">
                            <h3>Администраторы</h3>
                            <span class="stat-number"><?php echo $stats['total_admins']; ?></span>
                            <span class="stat-detail">школьных администраторов</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👨‍🏫</div>
                        <div class="stat-info">
                            <h3>Преподаватели</h3>
                            <span class="stat-number"><?php echo $stats['total_teachers']; ?></span>
                            <span class="stat-detail">в системе</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🎓</div>
                        <div class="stat-info">
                            <h3>Ученики</h3>
                            <span class="stat-number"><?php echo $stats['total_students']; ?></span>
                            <span class="stat-detail">в системе</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🔐</div>
                        <div class="stat-info">
                            <h3>Активность</h3>
                            <span class="stat-number"><?php echo $stats['recent_logins']; ?></span>
                            <span class="stat-detail">входов за 30 дней</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <!-- Последние школы -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>Недавно добавленные школы</h3>
                        <a href="schools.php" class="btn-link">Все школы →</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($recent_schools)): ?>
                            <div class="empty-state">
                                <p>Нет добавленных школ</p>
                            </div>
                        <?php else: ?>
                            <div class="activities-list dashboard-activity-list">
                                <?php foreach ($recent_schools as $school): ?>
                                    <div class="activity-item dashboard-activity-item">
                                        <div class="activity-info">
                                            <div class="activity-user"><?php echo htmlspecialchars($school['full_name']); ?></div>
                                            <div class="activity-details">
                                                <span class="activity-ip"><?php echo htmlspecialchars($school['type']); ?></span>
                                                <span class="activity-ip">• <?php echo htmlspecialchars($school['status']); ?></span>
                                            </div>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo date('d.m.Y', strtotime($school['created_at'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Последние активности -->
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>Последние входы в систему</h3>
                        <a href="reports.php" class="btn-link">Все отчеты →</a>
                    </div>
                    <div class="card-content">
                        <?php if (empty($recent_activities)): ?>
                            <div class="empty-state">
                                <p>Нет данных о входах</p>
                            </div>
                        <?php else: ?>
                            <div class="activities-list dashboard-activity-list">
                                <?php foreach ($recent_activities as $activity): ?>
                                    <div class="activity-item dashboard-activity-item dashboard-login-item">
                                        <div class="user-avatar">
                                            <?php
                                            preg_match('/^./us', (string)$activity['full_name'], $initial);
                                            echo htmlspecialchars($initial[0] ?? '?');
                                            ?>
                                        </div>
                                        <div class="activity-info">
                                            <div class="activity-user"><?php echo htmlspecialchars($activity['full_name']); ?></div>
                                            <div class="activity-details">
                                                <span class="user-role role-<?php echo htmlspecialchars((string)$activity['role_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo htmlspecialchars(getRoleDisplayName((string)$activity['role_name'])); ?>
                                                </span>
                                                <span class="activity-ip">• IP: <?php echo htmlspecialchars($activity['ip_address']); ?></span>
                                            </div>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo date('H:i', strtotime($activity['created_at'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Быстрые действия -->
            <div class="quick-actions-section">
                <h2>Быстрые действия</h2>
                <div class="actions-grid">
                    <a href="schools.php" class="action-card">
                        <div class="action-icon">🏫</div>
                        <div class="action-content">
                            <h3>Учебные заведения</h3>
                            <p>Управление школами в системе</p>
                        </div>
                    </a>
                    <a href="users.php" class="action-card">
                        <div class="action-icon">👥</div>
                        <div class="action-content">
                            <h3>Пользователи</h3>
                            <p>Управление администраторами школ</p>
                        </div>
                    </a>
                    <a href="reports.php" class="action-card">
                        <div class="action-icon">📈</div>
                        <div class="action-content">
                            <h3>Системные отчеты</h3>
                            <p>Анализ работы всей системы</p>
                        </div>
                    </a>
                    <a href="curriculum.php" class="action-card">
                        <div class="action-icon">📚</div>
                        <div class="action-content">
                            <h3>Учебные планы</h3>
                            <p>Настройка учебных программ</p>
                        </div>
                    </a>
                    <a href="roles.php" class="action-card">
                        <div class="action-icon">🔐</div>
                        <div class="action-content">
                            <h3>Права доступа</h3>
                            <p>Настройка ролей и разрешений</p>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
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