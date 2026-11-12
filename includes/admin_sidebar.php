<?php
$adminActivePage = $adminActivePage ?? '';
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h1>Знание Севера</h1>
        <p>Электронный дневник</p>
    </div>
    <nav class="sidebar-nav">
        <div class="user-info">
            <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
            <span class="role-badge super-admin"><?php echo htmlspecialchars(getRoleDisplayName((string)$_SESSION['user_role'])); ?></span>
        </div>
        <ul class="nav-menu">
            <li><a href="super_dashboard.php" class="nav-link<?= $adminActivePage === 'super_dashboard' ? ' active' : '' ?>">🏠 Главная</a></li>
            <li class="nav-section">Системное управление</li>
            <li><a href="schools.php" class="nav-link<?= $adminActivePage === 'schools' ? ' active' : '' ?>">🏫 Учебные заведения</a></li>
            <li><a href="users.php" class="nav-link<?= $adminActivePage === 'users' ? ' active' : '' ?>">👥 Пользователи системы</a></li>
            <li><a href="roles.php" class="nav-link<?= $adminActivePage === 'roles' ? ' active' : '' ?>">🔐 Роли и права</a></li>
            <li><a href="curriculum.php" class="nav-link<?= $adminActivePage === 'curriculum' ? ' active' : '' ?>">📚 Учебные планы</a></li>
            <li><a href="academic_periods.php" class="nav-link<?= $adminActivePage === 'academic_periods' ? ' active' : '' ?>">📅 Учебные периоды</a></li>
            <li><a href="reports.php" class="nav-link<?= $adminActivePage === 'reports' ? ' active' : '' ?>">📈 Системные отчёты</a></li>
            <li><a href="audit_logs.php" class="nav-link<?= $adminActivePage === 'audit_logs' ? ' active' : '' ?>">🔎 Аудит действий</a></li>
            <li class="nav-section">Общее</li>
            <li><a href="../profile.php" class="nav-link<?= $adminActivePage === 'profile' ? ' active' : '' ?>">👤 Профиль</a></li>
            <li><?= logoutForm('../logout.php') ?></li>
        </ul>
    </nav>
</aside>
