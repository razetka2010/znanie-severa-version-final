<?php
$studentActivePage = $studentActivePage ?? '';
$studentBasePath = $studentBasePath ?? '';
$studentProfilePath = $studentProfilePath ?? '../profile.php';
$studentLogoutPath = $studentLogoutPath ?? '../logout.php';
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
            <?php if (!empty($student['class_name'])): ?>
                <div class="user-details"><?php echo htmlspecialchars($student['class_name']); ?> класс</div>
            <?php endif; ?>
        </div>
        <ul class="nav-menu">
            <li><a href="<?php echo htmlspecialchars($studentBasePath); ?>dashboard.php" class="nav-link<?= $studentActivePage === 'dashboard' ? ' active' : '' ?>">📊 Главная</a></li>
            <li class="nav-section">Учебный процесс</li>
            <li><a href="<?php echo htmlspecialchars($studentBasePath); ?>grades.php" class="nav-link<?= $studentActivePage === 'grades' ? ' active' : '' ?>">📝 Мои оценки</a></li>
            <li><a href="<?php echo htmlspecialchars($studentBasePath); ?>schedule.php" class="nav-link<?= $studentActivePage === 'schedule' ? ' active' : '' ?>">📅 Расписание</a></li>
            <li><a href="<?php echo htmlspecialchars($studentBasePath); ?>homework.php" class="nav-link<?= $studentActivePage === 'homework' ? ' active' : '' ?>">📚 Домашние задания</a></li>
            <li><a href="<?php echo htmlspecialchars($studentBasePath); ?>subjects.php" class="nav-link<?= $studentActivePage === 'subjects' ? ' active' : '' ?>">📖 Предметы</a></li>
            <li class="nav-section">Общее</li>
            <li><a href="<?php echo htmlspecialchars($studentProfilePath); ?>" class="nav-link<?= $studentActivePage === 'profile' ? ' active' : '' ?>">👤 Профиль</a></li>
            <li><?= logoutForm($studentLogoutPath) ?></li>
        </ul>
    </nav>
</aside>
