<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';

requireSchoolAdmin();

// Проверяем что school_id установлен
if (empty($_SESSION['user_school_id'])) {
    header('Location: ../login.php?error=no_school');
    exit;
}

$school_id = $_SESSION['user_school_id'];

$pdo = getDatabaseConnection();

// Получаем информацию о школе
$school_stmt = $pdo->prepare("SELECT full_name, short_name FROM schools WHERE id = ?");
$school_stmt->execute([$school_id]);
$school = $school_stmt->fetch();

// Статистика для школьного админа
$stats = [];
try {
    // Учителя
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE school_id = ? AND role_id IN (SELECT id FROM roles WHERE name IN ('teacher', 'class_teacher'))");
    $stmt->execute([$school_id]);
    $stats['total_teachers'] = $stmt->fetch()['count'];

    // Ученики
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE school_id = ? AND role_id IN (SELECT id FROM roles WHERE name = 'student')");
    $stmt->execute([$school_id]);
    $stats['total_students'] = $stmt->fetch()['count'];

    // Классы
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM classes WHERE school_id = ?");
    $stmt->execute([$school_id]);
    $stats['total_classes'] = $stmt->fetch()['count'];

    // Активные учителя
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE school_id = ? AND role_id IN (SELECT id FROM roles WHERE name IN ('teacher', 'class_teacher')) AND is_active = 1");
    $stmt->execute([$school_id]);
    $stats['active_teachers'] = $stmt->fetch()['count'];

    // Родители
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE school_id = ? AND role_id IN (SELECT id FROM roles WHERE name = 'parent')");
    $stmt->execute([$school_id]);
    $stats['total_parents'] = $stmt->fetch()['count'];

} catch (PDOException $e) {
    $stats = ['total_teachers' => 0, 'total_students' => 0, 'total_classes' => 0, 'active_teachers' => 0, 'total_parents' => 0];
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Панель администратора школы - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>
<div class="dashboard-container">
    <?php $schoolAdminActivePage = 'dashboard'; require __DIR__ . '/../includes/school_admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Панель администратора школы</h1>
                <p><?php echo htmlspecialchars($school['full_name']); ?></p>
            </div>
            <div class="header-actions">
                <a href="teachers.php?action=add" class="btn btn-primary">👥 Добавить учителя</a>
                <a href="students.php?action=add" class="btn btn-secondary">🎓 Добавить ученика</a>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo consumeFlashMessage('success_message'); ?></div>
            <?php endif; ?>

            <!-- Статистика школы -->
            <div class="stats-section">
                <h2>Статистика школы</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">👨‍🏫</div>
                        <div class="stat-info">
                            <h3>Преподаватели</h3>
                            <span class="stat-number"><?php echo $stats['total_teachers']; ?></span>
                            <span class="stat-detail"><?php echo $stats['active_teachers']; ?> активных</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🎓</div>
                        <div class="stat-info">
                            <h3>Ученики</h3>
                            <span class="stat-number"><?php echo $stats['total_students']; ?></span>
                            <span class="stat-detail">в школе</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👨‍🏫</div>
                        <div class="stat-info">
                            <h3>Классы</h3>
                            <span class="stat-number"><?php echo $stats['total_classes']; ?></span>
                            <span class="stat-detail">сформировано</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👨‍👩‍👧‍👦</div>
                        <div class="stat-info">
                            <h3>Родители</h3>
                            <span class="stat-number"><?php echo $stats['total_parents']; ?></span>
                            <span class="stat-detail">в системе</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Быстрые действия -->
            <div class="quick-actions-section">
                <h2>Быстрые действия</h2>
                <div class="actions-grid">
                    <a href="classes.php" class="action-card">
                        <div class="action-icon">👨‍🏫</div>
                        <div class="action-content">
                            <h3>Классы</h3>
                            <p>Создание и управление классами</p>
                        </div>
                    </a>
                    <a href="teachers.php" class="action-card">
                        <div class="action-icon">👥</div>
                        <div class="action-content">
                            <h3>Учителя</h3>
                            <p>Добавление и редактирование учителей</p>
                        </div>
                    </a>
                    <a href="schedule.php" class="action-card">
                        <div class="action-icon">📅</div>
                        <div class="action-content">
                            <h3>Расписание</h3>
                            <p>Составление расписания уроков</p>
                        </div>
                    </a>
                    <a href="reports.php" class="action-card">
                        <div class="action-icon">📈</div>
                        <div class="action-content">
                            <h3>Отчеты</h3>
                            <p>Анализ успеваемости и посещаемости</p>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Последние активности -->
            <div class="dashboard-grid">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h3>Последние действия</h3>
                        <a href="reports.php" class="btn-link">Все отчеты →</a>
                    </div>
                    <div class="card-content">
                        <div class="empty-state">
                            <p>Здесь будут отображаться последние действия в системе</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>