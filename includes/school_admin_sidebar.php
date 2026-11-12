<?php
$schoolAdminActivePage = $schoolAdminActivePage ?? '';
$schoolAdminBasePath = $schoolAdminBasePath ?? '';
$schoolAdminProfilePath = $schoolAdminProfilePath ?? '../profile.php';
$schoolAdminLogoutPath = $schoolAdminLogoutPath ?? '../logout.php';
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <h1>Знание Севера</h1>
        <p>Электронный дневник</p>
        <?php if (!empty($school)): ?>
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
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>dashboard.php" class="nav-link<?= $schoolAdminActivePage === 'dashboard' ? ' active' : '' ?>">🏠 Главная</a></li>
            <li class="nav-section">Управление школой</li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>classes.php" class="nav-link<?= $schoolAdminActivePage === 'classes' ? ' active' : '' ?>">👨‍🏫 Классы</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>teachers.php" class="nav-link<?= $schoolAdminActivePage === 'teachers' ? ' active' : '' ?>">👥 Учителя</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>students.php" class="nav-link<?= $schoolAdminActivePage === 'students' ? ' active' : '' ?>">🎓 Ученики</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>parents.php" class="nav-link<?= $schoolAdminActivePage === 'parents' ? ' active' : '' ?>">👨‍👩‍👧‍👦 Родители</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>schedule.php" class="nav-link<?= $schoolAdminActivePage === 'schedule' ? ' active' : '' ?>">📅 Расписание</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>academic_periods.php" class="nav-link<?= $schoolAdminActivePage === 'academic_periods' ? ' active' : '' ?>">📅 Учебные периоды</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>curriculum.php" class="nav-link<?= $schoolAdminActivePage === 'curriculum' ? ' active' : '' ?>">📋 Учебные планы</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>class_subjects.php" class="nav-link<?= $schoolAdminActivePage === 'class_subjects' ? ' active' : '' ?>">📚 Предметы и нагрузка классов</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>grade_types.php" class="nav-link<?= $schoolAdminActivePage === 'grade_types' ? ' active' : '' ?>">📊 Система оценок</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>grade_weights.php" class="nav-link<?= $schoolAdminActivePage === 'grade_weights' ? ' active' : '' ?>">⚖️ Веса оценок</a></li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminBasePath); ?>reports.php" class="nav-link<?= $schoolAdminActivePage === 'reports' ? ' active' : '' ?>">📈 Отчёты школы</a></li>
            <li class="nav-section">Общее</li>
            <li><a href="<?php echo htmlspecialchars($schoolAdminProfilePath); ?>" class="nav-link<?= $schoolAdminActivePage === 'profile' ? ' active' : '' ?>">👤 Профиль</a></li>
            <li><?= logoutForm($schoolAdminLogoutPath) ?></li>
        </ul>
    </nav>
</aside>