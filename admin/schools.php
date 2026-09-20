<?php
session_start();
require_once '../config/database.php';

// Проверка авторизации
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'super_admin') {
    header('Location: ../login.php');
    exit;
}

$pdo = getDatabaseConnection();
$action = isset($_GET['action']) ? $_GET['action'] : '';
$school_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Обработка добавления школы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $short_name = isset($_POST['short_name']) ? trim($_POST['short_name']) : '';
        $inn = isset($_POST['inn']) ? trim($_POST['inn']) : '';
        $type = isset($_POST['type']) ? $_POST['type'] : 'общеобразовательная';
        $status = 'active';
        $legal_address = isset($_POST['legal_address']) ? trim($_POST['legal_address']) : '';
        $physical_address = isset($_POST['physical_address']) ? trim($_POST['physical_address']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $website = isset($_POST['website']) ? trim($_POST['website']) : '';
        $director_name = isset($_POST['director_name']) ? trim($_POST['director_name']) : '';
        $license_number = isset($_POST['license_number']) ? trim($_POST['license_number']) : '';
        $license_date = isset($_POST['license_date']) ? $_POST['license_date'] : null;
        $license_issued_by = isset($_POST['license_issued_by']) ? trim($_POST['license_issued_by']) : '';
        $accreditation_number = isset($_POST['accreditation_number']) ? trim($_POST['accreditation_number']) : '';
        $accreditation_date = isset($_POST['accreditation_date']) ? $_POST['accreditation_date'] : null;
        $accreditation_until = isset($_POST['accreditation_until']) ? $_POST['accreditation_until'] : null;

        try {
            $stmt = $pdo->prepare("INSERT INTO schools (full_name, short_name, inn, type, status, legal_address, physical_address, phone, email, website, director_name, license_number, license_date, license_issued_by, accreditation_number, accreditation_date, accreditation_until) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $full_name, $short_name, $inn, $type, $status, $legal_address, $physical_address,
                $phone, $email, $website, $director_name, $license_number, $license_date,
                $license_issued_by, $accreditation_number, $accreditation_date, $accreditation_until
            ]);

            $_SESSION['success_message'] = "Школа успешно добавлена!";
            header('Location: schools.php');
            exit;
        } catch (PDOException $e) {
            $error = "Ошибка при добавлении школы: " . $e->getMessage();
        }
    }
    // Обработка редактирования школы
    elseif ($action === 'edit' && $school_id > 0) {
        $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
        $short_name = isset($_POST['short_name']) ? trim($_POST['short_name']) : '';
        $inn = isset($_POST['inn']) ? trim($_POST['inn']) : '';
        $type = isset($_POST['type']) ? $_POST['type'] : 'общеобразовательная';
        $status = isset($_POST['status']) ? $_POST['status'] : 'active';
        $legal_address = isset($_POST['legal_address']) ? trim($_POST['legal_address']) : '';
        $physical_address = isset($_POST['physical_address']) ? trim($_POST['physical_address']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $website = isset($_POST['website']) ? trim($_POST['website']) : '';
        $director_name = isset($_POST['director_name']) ? trim($_POST['director_name']) : '';
        $license_number = isset($_POST['license_number']) ? trim($_POST['license_number']) : '';
        $license_date = isset($_POST['license_date']) ? $_POST['license_date'] : null;
        $license_issued_by = isset($_POST['license_issued_by']) ? trim($_POST['license_issued_by']) : '';
        $accreditation_number = isset($_POST['accreditation_number']) ? trim($_POST['accreditation_number']) : '';
        $accreditation_date = isset($_POST['accreditation_date']) ? $_POST['accreditation_date'] : null;
        $accreditation_until = isset($_POST['accreditation_until']) ? $_POST['accreditation_until'] : null;

        try {
            $stmt = $pdo->prepare("UPDATE schools SET full_name = ?, short_name = ?, inn = ?, type = ?, status = ?, legal_address = ?, physical_address = ?, phone = ?, email = ?, website = ?, director_name = ?, license_number = ?, license_date = ?, license_issued_by = ?, accreditation_number = ?, accreditation_date = ?, accreditation_until = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");

            $stmt->execute([
                $full_name, $short_name, $inn, $type, $status, $legal_address, $physical_address,
                $phone, $email, $website, $director_name, $license_number, $license_date,
                $license_issued_by, $accreditation_number, $accreditation_date, $accreditation_until, $school_id
            ]);

            $_SESSION['success_message'] = "Данные школы успешно обновлены!";
            header('Location: schools.php');
            exit;
        } catch (PDOException $e) {
            $error = "Ошибка при обновлении школы: " . $e->getMessage();
        }
    }
}

// Обработка удаления школы
if ($action === 'delete' && $school_id > 0) {
    try {
        // Проверяем есть ли пользователи связанные со школой
        $check_stmt = $pdo->prepare("SELECT COUNT(*) as user_count FROM users WHERE school_id = ?");
        $check_stmt->execute([$school_id]);
        $result = $check_stmt->fetch();

        if ($result['user_count'] > 0) {
            $_SESSION['error_message'] = "Невозможно удалить школу: есть связанные пользователи";
        } else {
            $stmt = $pdo->prepare("DELETE FROM schools WHERE id = ?");
            $stmt->execute([$school_id]);
            $_SESSION['success_message'] = "Школа успешно удалена!";
        }

        header('Location: schools.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Ошибка при удалении школы: " . $e->getMessage();
        header('Location: schools.php');
        exit;
    }
}

// Получение данных школы для редактирования/просмотра
$school_data = null;
if (($action === 'edit' || $action === 'view') && $school_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
    $stmt->execute([$school_id]);
    $school_data = $stmt->fetch();

    if (!$school_data) {
        $_SESSION['error_message'] = "Школа не найдена!";
        header('Location: schools.php');
        exit;
    }
}

// Получение списка школ из БД
$schools = $pdo->query("SELECT * FROM schools ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учебные заведения - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/schools.css">
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
                <li><a href="schools.php" class="nav-link active">🏫 Учебные заведения</a></li>
                <li><a href="users.php" class="nav-link">👥 Пользователи</a></li>
                <li><a href="roles.php" class="nav-link">🔐 Роли и права</a></li>
                <li><a href="curriculum.php" class="nav-link">📚 Учебные планы</a></li>
                <li><a href="academic_periods.php" class="nav-link">📅 Учебные периоды</a></li>
                <li><a href="reports.php" class="nav-link">📈 Отчеты</a></li>
                <li class="nav-section">Общее</li>
                <li><a href="../profile.php" class="nav-link">👤 Профиль</a></li>
                <li><a href="../logout.php" class="nav-link">🚪 Выход</a></li>
            </ul>
        </nav>
    </aside>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Учебные заведения</h1>
                <p>Главный Администратор • <?php echo htmlspecialchars($_SESSION['user_login']); ?></p>
            </div>
            <div class="header-actions">
                <?php if ($action === 'add' || $action === 'edit' || $action === 'view'): ?>
                    <a href="schools.php" class="btn btn-secondary">← Назад к списку</a>
                <?php else: ?>
                    <a href="schools.php?action=add" class="btn btn-primary">➕ Добавить школу</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- Форма добавления/редактирования школы -->
                <div class="form-container">
                    <h2><?php echo $action === 'add' ? 'Добавление учебного заведения' : 'Редактирование учебного заведения'; ?></h2>
                    <form method="POST" class="school-form">
                        <div class="form-section">
                            <h3>Основные данные</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Полное название школы *</label>
                                    <input type="text" name="full_name" value="<?php echo $school_data ? htmlspecialchars($school_data['full_name']) : ''; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Краткое название</label>
                                    <input type="text" name="short_name" value="<?php echo $school_data ? htmlspecialchars($school_data['short_name']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>ИНН *</label>
                                    <input type="text" name="inn" value="<?php echo $school_data ? htmlspecialchars($school_data['inn']) : ''; ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Тип учреждения</label>
                                    <select name="type">
                                        <option value="общеобразовательная" <?php echo ($school_data && $school_data['type'] == 'общеобразовательная') ? 'selected' : ''; ?>>Общеобразовательная</option>
                                        <option value="гимназия" <?php echo ($school_data && $school_data['type'] == 'гимназия') ? 'selected' : ''; ?>>Гимназия</option>
                                        <option value="лицей" <?php echo ($school_data && $school_data['type'] == 'лицей') ? 'selected' : ''; ?>>Лицей</option>
                                        <option value="интернат" <?php echo ($school_data && $school_data['type'] == 'интернат') ? 'selected' : ''; ?>>Интернат</option>
                                    </select>
                                </div>
                                <?php if ($action === 'edit'): ?>
                                    <div class="form-group">
                                        <label>Статус</label>
                                        <select name="status">
                                            <option value="active" <?php echo (!$school_data || $school_data['status'] == 'active') ? 'selected' : ''; ?>>Активная</option>
                                            <option value="inactive" <?php echo ($school_data && $school_data['status'] == 'inactive') ? 'selected' : ''; ?>>Неактивная</option>
                                            <option value="archive" <?php echo ($school_data && $school_data['status'] == 'archive') ? 'selected' : ''; ?>>Архив</option>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>Адреса</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Юридический адрес</label>
                                    <textarea name="legal_address" rows="3"><?php echo $school_data ? htmlspecialchars($school_data['legal_address']) : ''; ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Физический адрес</label>
                                    <textarea name="physical_address" rows="3"><?php echo $school_data ? htmlspecialchars($school_data['physical_address']) : ''; ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>Контакты</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Телефон</label>
                                    <input type="tel" name="phone" value="<?php echo $school_data ? htmlspecialchars($school_data['phone']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Электронная почта</label>
                                    <input type="email" name="email" value="<?php echo $school_data ? htmlspecialchars($school_data['email']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Сайт</label>
                                    <input type="url" name="website" value="<?php echo $school_data ? htmlspecialchars($school_data['website']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>ФИО директора</label>
                                    <input type="text" name="director_name" value="<?php echo $school_data ? htmlspecialchars($school_data['director_name']) : ''; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>Документы</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Лицензия №</label>
                                    <input type="text" name="license_number" value="<?php echo $school_data ? htmlspecialchars($school_data['license_number']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Дата лицензии</label>
                                    <input type="date" name="license_date" value="<?php echo $school_data ? $school_data['license_date'] : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Кем выдана</label>
                                    <input type="text" name="license_issued_by" value="<?php echo $school_data ? htmlspecialchars($school_data['license_issued_by']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Аккредитация №</label>
                                    <input type="text" name="accreditation_number" value="<?php echo $school_data ? htmlspecialchars($school_data['accreditation_number']) : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Дата аккредитации</label>
                                    <input type="date" name="accreditation_date" value="<?php echo $school_data ? $school_data['accreditation_date'] : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Действует до</label>
                                    <input type="date" name="accreditation_until" value="<?php echo $school_data ? $school_data['accreditation_until'] : ''; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? 'Сохранить школу' : 'Обновить данные'; ?>
                            </button>
                            <a href="schools.php" class="btn btn-secondary">Отмена</a>
                        </div>
                    </form>
                </div>

            <?php elseif ($action === 'view' && $school_data): ?>
                <!-- Просмотр школы -->
                <div class="view-container">
                    <div class="view-header">
                        <h2><?php echo htmlspecialchars($school_data['full_name']); ?></h2>
                        <div class="view-actions">
                            <a href="schools.php?action=edit&id=<?php echo $school_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                            <button onclick="confirmDelete(<?php echo $school_data['id']; ?>)" class="btn btn-danger">🗑️ Удалить</button>
                        </div>
                    </div>

                    <div class="view-sections">
                        <div class="view-section">
                            <h3>Основная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Полное название:</label>
                                    <span><?php echo htmlspecialchars($school_data['full_name']); ?></span>
                                </div>
                                <?php if ($school_data['short_name']): ?>
                                    <div class="info-item">
                                        <label>Краткое название:</label>
                                        <span><?php echo htmlspecialchars($school_data['short_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <label>ИНН:</label>
                                    <span><?php echo htmlspecialchars($school_data['inn']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Тип:</label>
                                    <span class="type-badge type-<?php echo $school_data['type']; ?>">
                                            <?php echo htmlspecialchars($school_data['type']); ?>
                                        </span>
                                </div>
                                <div class="info-item">
                                    <label>Статус:</label>
                                    <span class="status-badge status-<?php echo $school_data['status']; ?>">
                                            <?php echo htmlspecialchars($school_data['status']); ?>
                                        </span>
                                </div>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Адреса</h3>
                            <div class="info-grid">
                                <?php if ($school_data['legal_address']): ?>
                                    <div class="info-item">
                                        <label>Юридический адрес:</label>
                                        <span><?php echo nl2br(htmlspecialchars($school_data['legal_address'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($school_data['physical_address']): ?>
                                    <div class="info-item">
                                        <label>Физический адрес:</label>
                                        <span><?php echo nl2br(htmlspecialchars($school_data['physical_address'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Контакты</h3>
                            <div class="info-grid">
                                <?php if ($school_data['phone']): ?>
                                    <div class="info-item">
                                        <label>Телефон:</label>
                                        <span><?php echo htmlspecialchars($school_data['phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($school_data['email']): ?>
                                    <div class="info-item">
                                        <label>Email:</label>
                                        <span><?php echo htmlspecialchars($school_data['email']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($school_data['website']): ?>
                                    <div class="info-item">
                                        <label>Сайт:</label>
                                        <span><?php echo htmlspecialchars($school_data['website']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($school_data['director_name']): ?>
                                    <div class="info-item">
                                        <label>Директор:</label>
                                        <span><?php echo htmlspecialchars($school_data['director_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($school_data['license_number'] || $school_data['accreditation_number']): ?>
                            <div class="view-section">
                                <h3>Документы</h3>
                                <div class="info-grid">
                                    <?php if ($school_data['license_number']): ?>
                                        <div class="info-item">
                                            <label>Лицензия №:</label>
                                            <span><?php echo htmlspecialchars($school_data['license_number']); ?></span>
                                        </div>
                                        <?php if ($school_data['license_date']): ?>
                                            <div class="info-item">
                                                <label>Дата лицензии:</label>
                                                <span><?php echo date('d.m.Y', strtotime($school_data['license_date'])); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($school_data['license_issued_by']): ?>
                                            <div class="info-item">
                                                <label>Кем выдана:</label>
                                                <span><?php echo htmlspecialchars($school_data['license_issued_by']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if ($school_data['accreditation_number']): ?>
                                        <div class="info-item">
                                            <label>Аккредитация №:</label>
                                            <span><?php echo htmlspecialchars($school_data['accreditation_number']); ?></span>
                                        </div>
                                        <?php if ($school_data['accreditation_date']): ?>
                                            <div class="info-item">
                                                <label>Дата аккредитации:</label>
                                                <span><?php echo date('d.m.Y', strtotime($school_data['accreditation_date'])); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($school_data['accreditation_until']): ?>
                                            <div class="info-item">
                                                <label>Действует до:</label>
                                                <span><?php echo date('d.m.Y', strtotime($school_data['accreditation_until'])); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="view-section">
                            <h3>Системная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Дата создания:</label>
                                    <span><?php echo date('d.m.Y H:i', strtotime($school_data['created_at'])); ?></span>
                                </div>
                                <?php if ($school_data['updated_at'] != $school_data['created_at']): ?>
                                    <div class="info-item">
                                        <label>Последнее обновление:</label>
                                        <span><?php echo date('d.m.Y H:i', strtotime($school_data['updated_at'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список школ -->
                <div class="table-section">
                    <div class="table-header">
                        <h3>Список учебных заведений</h3>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-secondary" onclick="refreshTable()">🔄 Обновить</button>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="data-table" id="schools-table">
                            <thead>
                            <tr>
                                <th>Название</th>
                                <th>ИНН</th>
                                <th>Тип</th>
                                <th>Статус</th>
                                <th>Директор</th>
                                <th>Дата создания</th>
                                <th>Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($schools)): ?>
                                <tr>
                                    <td colspan="7" class="no-data">
                                        📝 Нет данных о учебных заведениях
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($schools as $school): ?>
                                    <tr>
                                        <td>
                                            <div class="school-name">
                                                <strong><?php echo htmlspecialchars($school['full_name']); ?></strong>
                                                <?php if (!empty($school['short_name'])): ?>
                                                    <br><small><?php echo htmlspecialchars($school['short_name']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($school['inn']); ?></td>
                                        <td>
                                                <span class="type-badge type-<?php echo $school['type']; ?>">
                                                    <?php echo htmlspecialchars($school['type']); ?>
                                                </span>
                                        </td>
                                        <td>
                                                <span class="status-badge status-<?php echo $school['status']; ?>">
                                                    <?php echo htmlspecialchars($school['status']); ?>
                                                </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($school['director_name']); ?></td>
                                        <td><?php echo date('d.m.Y', strtotime($school['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action btn-edit" title="Редактировать" onclick="editSchool(<?php echo $school['id']; ?>)">
                                                    ✏️
                                                </button>
                                                <button class="btn-action btn-view" title="Просмотреть" onclick="viewSchool(<?php echo $school['id']; ?>)">
                                                    👁️
                                                </button>
                                                <button class="btn-action btn-delete" title="Удалить" onclick="confirmDelete(<?php echo $school['id']; ?>)">
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

                    <div class="table-footer">
                        <div class="table-info">
                            Показано <strong><?php echo count($schools); ?></strong> учебных заведений
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../js/schools.js"></script>
</body>
</html>