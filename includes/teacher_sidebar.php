<?php
$teacherActivePage = $teacherActivePage ?? '';
$teacherBasePath = $teacherBasePath ?? '';
$teacherProfilePath = $teacherProfilePath ?? '../profile.php';
$teacherLogoutPath = $teacherLogoutPath ?? '../logout.php';
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h1>Знание Севера</h1>
        <p>Электронный дневник</p>
    </div>
    <nav class="sidebar-nav">
        <div class="user-info">
            <strong><?php echo htmlspecialchars($_SESSION['user_name']); ?></strong>
            <span class="role-badge"><?php echo htmlspecialchars(getRoleDisplayName((string)$_SESSION['user_role'])); ?></span>
        </div>
        <ul class="nav-menu">
            <li><a href="<?php echo htmlspecialchars($teacherBasePath); ?>dashboard.php" class="nav-link<?= $teacherActivePage === 'dashboard' ? ' active' : '' ?>">📊 Главная</a></li>
            <li class="nav-section">Учебный процесс</li>
            <li><a href="<?php echo htmlspecialchars($teacherBasePath); ?>grades.php" class="nav-link<?= $teacherActivePage === 'grades' ? ' active' : '' ?>">📝 Журнал оценок</a></li>
            <li><a href="<?php echo htmlspecialchars($teacherBasePath); ?>class_journal.php" class="nav-link<?= $teacherActivePage === 'class_journal' ? ' active' : '' ?>">📋 Классный журнал</a></li>
            <li><a href="<?php echo htmlspecialchars($teacherBasePath); ?>homework.php" class="nav-link<?= $teacherActivePage === 'homework' ? ' active' : '' ?>">📚 Домашние задания</a></li>
            <li><a href="<?php echo htmlspecialchars($teacherBasePath); ?>schedule.php" class="nav-link<?= $teacherActivePage === 'schedule' ? ' active' : '' ?>">📅 Моё расписание</a></li>
            <li><a href="<?php echo htmlspecialchars($teacherBasePath); ?>calendar.php" class="nav-link<?= $teacherActivePage === 'calendar' ? ' active' : '' ?>">🗓️ Календарь</a></li>
            <li><a href="<?php echo htmlspecialchars($teacherBasePath); ?>reports.php" class="nav-link<?= $teacherActivePage === 'reports' ? ' active' : '' ?>">📈 Отчёты</a></li>
            <li><a href="<?php echo htmlspecialchars($teacherBasePath); ?>reports_advanced.php" class="nav-link<?= $teacherActivePage === 'reports_advanced' ? ' active' : '' ?>">📊 Расширенные отчёты</a></li>
            <li class="nav-section">Общее</li>
            <li><a href="<?php echo htmlspecialchars($teacherProfilePath); ?>" class="nav-link<?= $teacherActivePage === 'profile' ? ' active' : '' ?>">👤 Профиль</a></li>
            <li><?= logoutForm($teacherLogoutPath) ?></li>
        </ul>
    </nav>
</aside>
