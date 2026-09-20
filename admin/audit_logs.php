<?php
session_start();
require_once '../config/database.php';
require_once '../config/auth.php';

requireSuperAdmin();

$pdo = getDatabaseConnection();
$action_filter = trim($_GET['action_filter'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = [];
$params = [];
if ($action_filter !== '') {
    $where[] = 'l.action = ?';
    $params[] = $action_filter;
}
if ($date_from !== '') {
    $where[] = 'l.created_at >= ?';
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to !== '') {
    $where[] = 'l.created_at <= ?';
    $params[] = $date_to . ' 23:59:59';
}

$sql = "
    SELECT l.*, u.login, u.full_name, r.name AS role_name
    FROM user_logs l
    LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN roles r ON r.id = u.role_id
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY l.created_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
$actions = $pdo->query("SELECT DISTINCT action FROM user_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Аудит действий - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="dashboard-container">
    <aside class="sidebar">
        <div class="sidebar-header"><h1>Знание Севера</h1><p>Электронный дневник</p></div>
        <nav class="sidebar-nav">
            <div class="user-info"><strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong><span class="role-badge">Главный администратор</span></div>
            <ul class="nav-menu">
                <li><a href="super_dashboard.php" class="nav-link">🏠 Главная</a></li>
                <li><a href="schools.php" class="nav-link">🏫 Учебные заведения</a></li>
                <li><a href="users.php" class="nav-link">👥 Пользователи</a></li>
                <li><a href="reports.php" class="nav-link">📈 Системные отчёты</a></li>
                <li><a href="audit_logs.php" class="nav-link active">🔎 Аудит действий</a></li>
                <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
            </ul>
        </nav>
    </aside>
    <main class="main-content">
        <header class="content-header">
            <div class="header-title"><h1>Аудит действий</h1><p>Последние изменения пользователей в системе</p></div>
        </header>
        <div class="content-body">
            <form method="GET" class="dashboard-card" style="padding: 22px; display: flex; gap: 16px; align-items: end; flex-wrap: wrap;">
                <label>Действие
                    <select name="action_filter">
                        <option value="">Все действия</option>
                        <?php foreach ($actions as $action): ?><option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action_filter === $action ? 'selected' : ''; ?>><?php echo htmlspecialchars($action); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>С даты <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></label>
                <label>По дату <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></label>
                <button class="btn btn-primary" type="submit">Фильтровать</button>
            </form>
            <div class="dashboard-card">
                <div class="card-header"><h3>Журнал изменений</h3><span><?php echo count($logs); ?> записей</span></div>
                <div class="card-content" style="overflow-x: auto;">
                    <?php if (!$logs): ?><div class="empty-state"><p>Записей по выбранным условиям нет.</p></div><?php else: ?>
                    <table class="admin-table">
                        <thead><tr><th>Дата</th><th>Пользователь</th><th>Действие</th><th>Подробности</th><th>IP</th></tr></thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($log['created_at']))); ?></td>
                                <td><?php echo htmlspecialchars($log['full_name'] ?: ($log['login'] ?: 'Система')); ?></td>
                                <td><strong><?php echo htmlspecialchars($log['action']); ?></strong></td>
                                <td><?php echo htmlspecialchars($log['details'] ?: ''); ?></td>
                                <td><?php echo htmlspecialchars($log['ip_address'] ?: ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
