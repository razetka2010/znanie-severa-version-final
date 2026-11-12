<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
requireSuperAdmin();

$pdo = getDatabaseConnection();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$period_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

function validateAcademicPeriodDates(string $start_date, string $end_date): bool
{
    $start = DateTime::createFromFormat('Y-m-d', $start_date);
    $end = DateTime::createFromFormat('Y-m-d', $end_date);

    return $start && $start->format('Y-m-d') === $start_date
        && $end && $end->format('Y-m-d') === $end_date
        && $start_date < $end_date;
}

function validateAcademicPeriodSchoolAndOverlap(
    PDO $pdo,
    int $school_id,
    string $academic_year,
    string $start_date,
    string $end_date,
    int $exclude_period_id = 0
): void {
    $school_stmt = $pdo->prepare('SELECT id FROM schools WHERE id = ?');
    $school_stmt->execute([$school_id]);
    if (!$school_stmt->fetchColumn()) {
        throw new InvalidArgumentException('Выберите существующую школу.');
    }

    $sql = "
        SELECT id FROM academic_periods
        WHERE school_id = ? AND (academic_year = ? OR academic_year IS NULL)
            AND start_date <= ? AND end_date >= ?
    ";
    $params = [$school_id, $academic_year, $end_date, $start_date];
    if ($exclude_period_id > 0) {
        $sql .= ' AND id != ?';
        $params[] = $exclude_period_id;
    }
    $sql .= ' LIMIT 1 FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException('Даты пересекаются с другим периодом этого учебного года.');
    }
}

// Обработка добавления учебного периода
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $school_id = isset($_POST['school_id']) ? intval($_POST['school_id']) : 0;
        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $academic_year = trim((string)($_POST['academic_year'] ?? ''));

        try {
            $pdo->beginTransaction();
            if ($name === '' || !validateAcademicPeriodDates($start_date, $end_date)) {
                throw new InvalidArgumentException('Укажите название и корректный диапазон дат: начало должно быть раньше окончания.');
            }
            if ($academic_year === '') {
                $academic_year = substr($start_date, 0, 4) . '-' . substr($end_date, 0, 4);
            }
            validateAcademicPeriodSchoolAndOverlap($pdo, $school_id, $academic_year, $start_date, $end_date);
            if ($is_current && !$is_active) {
                throw new InvalidArgumentException('Текущий период должен быть активным.');
            }

            if ($is_current) {
                $reset_stmt = $pdo->prepare("UPDATE academic_periods SET is_current = 0 WHERE school_id = ?");
                $reset_stmt->execute([$school_id]);
            }

            $stmt = $pdo->prepare("INSERT INTO academic_periods (name, school_id, start_date, end_date, academic_year, is_active, is_current)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name, $school_id, $start_date, $end_date, $academic_year, $is_active, $is_current
            ]);
            $created_period_id = (int)$pdo->lastInsertId();
            $pdo->commit();
            logUserAction($pdo, 'academic_period_created', json_encode([
                'school_id' => $school_id,
                'period_id' => $created_period_id
            ], JSON_UNESCAPED_UNICODE));

            $_SESSION['success_message'] = "Учебный период успешно создан!";
            header('Location: academic_periods.php');
            exit;
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = publicErrorMessage($e, basename(__FILE__));
        }
    }
    // Обработка редактирования учебного периода
    elseif ($action === 'edit' && $period_id > 0) {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $school_id = isset($_POST['school_id']) ? intval($_POST['school_id']) : 0;
        $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
        $end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $academic_year = trim((string)($_POST['academic_year'] ?? ''));

        try {
            $pdo->beginTransaction();
            $owned_stmt = $pdo->prepare('SELECT id FROM academic_periods WHERE id = ? FOR UPDATE');
            $owned_stmt->execute([$period_id]);
            if (!$owned_stmt->fetchColumn()) {
                throw new InvalidArgumentException('Учебный период не найден.');
            }
            if ($name === '' || !validateAcademicPeriodDates($start_date, $end_date)) {
                throw new InvalidArgumentException('Укажите название и корректный диапазон дат: начало должно быть раньше окончания.');
            }
            if ($academic_year === '') {
                $academic_year = substr($start_date, 0, 4) . '-' . substr($end_date, 0, 4);
            }
            validateAcademicPeriodSchoolAndOverlap($pdo, $school_id, $academic_year, $start_date, $end_date, $period_id);
            if ($is_current && !$is_active) {
                throw new InvalidArgumentException('Текущий период должен быть активным.');
            }

            if ($is_current) {
                $reset_stmt = $pdo->prepare("UPDATE academic_periods SET is_current = 0 WHERE school_id = ? AND id != ?");
                $reset_stmt->execute([$school_id, $period_id]);
            }

            $stmt = $pdo->prepare("UPDATE academic_periods SET name = ?, school_id = ?, start_date = ?, end_date = ?, academic_year = ?, is_active = ?, is_current = ? WHERE id = ?");

            $stmt->execute([
                $name, $school_id, $start_date, $end_date, $academic_year, $is_active, $is_current, $period_id
            ]);
            $pdo->commit();
            logUserAction($pdo, 'academic_period_updated', json_encode([
                'school_id' => $school_id,
                'period_id' => $period_id
            ], JSON_UNESCAPED_UNICODE));

            $_SESSION['success_message'] = "Учебный период успешно обновлен!";
            header('Location: academic_periods.php');
            exit;
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = publicErrorMessage($e, basename(__FILE__));
        }
    }
}

// Обработка удаления учебного периода
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete' && $period_id > 0) {
    try {
        $stmt = $pdo->prepare("DELETE FROM academic_periods WHERE id = ?");
        $stmt->execute([$period_id]);
        logUserAction($pdo, 'academic_period_deleted', json_encode(['period_id' => $period_id], JSON_UNESCAPED_UNICODE));

        $_SESSION['success_message'] = "Учебный период успешно удален!";
        header('Location: academic_periods.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: academic_periods.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['lock', 'unlock'], true) && $period_id > 0) {
    try {
        $stmt = $pdo->prepare('UPDATE academic_periods SET is_locked = ? WHERE id = ?');
        $stmt->execute([$action === 'lock' ? 1 : 0, $period_id]);
        if ($stmt->rowCount() < 1) {
            throw new InvalidArgumentException('Учебный период не найден.');
        }
        logUserAction($pdo, 'academic_period_' . $action, json_encode(['period_id' => $period_id], JSON_UNESCAPED_UNICODE));
        $_SESSION['success_message'] = $action === 'lock' ? 'Период закрыт.' : 'Период открыт.';
        header('Location: academic_periods.php');
        exit;
    } catch (InvalidArgumentException $e) {
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: academic_periods.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: academic_periods.php');
        exit;
    }
}

// Обработка установки текущего периода
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_current' && $period_id > 0) {
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT school_id, is_active FROM academic_periods WHERE id = ? FOR UPDATE");
        $stmt->execute([$period_id]);
        $period = $stmt->fetch();

        if ($period) {
            if (!(int)$period['is_active']) {
                throw new InvalidArgumentException('Нельзя сделать текущим неактивный период.');
            }
            // Сбрасываем текущий статус у всех периодов этой школы
            $reset_stmt = $pdo->prepare("UPDATE academic_periods SET is_current = 0 WHERE school_id = ?");
            $reset_stmt->execute([$period['school_id']]);

            // Устанавливаем текущий период
            $set_stmt = $pdo->prepare("UPDATE academic_periods SET is_current = 1 WHERE id = ?");
            $set_stmt->execute([$period_id]);
            $pdo->commit();
            logUserAction($pdo, 'academic_period_set_current', json_encode([
                'school_id' => $period['school_id'],
                'period_id' => $period_id
            ], JSON_UNESCAPED_UNICODE));

            $_SESSION['success_message'] = "Текущий учебный период установлен!";
        } else {
            throw new InvalidArgumentException('Учебный период не найден.');
        }

        header('Location: academic_periods.php');
        exit;
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
        header('Location: academic_periods.php');
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: academic_periods.php');
        exit;
    }
}

// Получение данных учебного периода для редактирования/просмотра
$period_data = null;
if (($action === 'edit' || $action === 'view') && $period_id > 0) {
    $stmt = $pdo->prepare("SELECT ap.*, s.full_name as school_name 
                          FROM academic_periods ap 
                          LEFT JOIN schools s ON ap.school_id = s.id 
                          WHERE ap.id = ?");
    $stmt->execute([$period_id]);
    $period_data = $stmt->fetch();

    if (!$period_data) {
        $_SESSION['error_message'] = "Учебный период не найден!";
        header('Location: academic_periods.php');
        exit;
    }
}

// Получение списка школ для выпадающего списка
$schools_stmt = $pdo->prepare("SELECT id, full_name FROM schools WHERE status = ? ORDER BY full_name");
$schools_stmt->execute(['active']);
$schools = $schools_stmt->fetchAll();

// Получение списка учебных периодов из БД
$sql = "SELECT ap.*, s.full_name as school_name 
        FROM academic_periods ap 
        LEFT JOIN schools s ON ap.school_id = s.id 
        ORDER BY ap.start_date DESC, ap.created_at DESC";
$periods_stmt = $pdo->prepare($sql);
$periods_stmt->execute();
$periods = $periods_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учебные периоды - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/academic_periods.css">
</head>
<body>
<div class="dashboard-container">
    <?php $adminActivePage = 'academic_periods'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Учебные периоды</h1>
                <p>Главный Администратор • <?php echo htmlspecialchars($_SESSION['user_login']); ?></p>
            </div>
            <div class="header-actions">
                <?php if ($action === 'add' || $action === 'edit' || $action === 'view'): ?>
                    <a href="academic_periods.php" class="btn btn-secondary">← Назад к списку</a>
                <?php else: ?>
                    <a href="academic_periods.php?action=add" class="btn btn-primary">➕ Создать период</a>
                <?php endif; ?>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo consumeFlashMessage('success_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?php echo consumeFlashMessage('error_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-error"><?php echo nl2br(htmlspecialchars(str_replace('<br>', "\n", $error), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- Форма добавления/редактирования учебного периода -->
                <div class="period-container">
                    <div class="period-form">
                        <h2><?php echo $action === 'add' ? 'Создание учебного периода' : 'Редактирование учебного периода'; ?></h2>
                        <form method="POST" id="period-form" onsubmit="return validatePeriodForm()">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="id" value="<?php echo (int)$period_id; ?>">
                            <div class="form-section">
                                <h3>Основная информация</h3>
                                <div class="form-grid">
                                    <div class="form-group required">
                                        <label>Название периода</label>
                                        <input type="text" name="name" value="<?php echo $period_data ? htmlspecialchars($period_data['name']) : ''; ?>"
                                               placeholder="Например: 2024-2025 учебный год" required>
                                    </div>
                                    <div class="form-group required">
                                        <label>Школа</label>
                                        <select name="school_id" required>
                                            <option value="">Выберите школу</option>
                                            <?php foreach ($schools as $school): ?>
                                                <option value="<?php echo $school['id']; ?>"
                                                    <?php echo ($period_data && $period_data['school_id'] == $school['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($school['full_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Учебный год</label>
                                        <input type="text" name="academic_year" maxlength="20"
                                               value="<?php echo htmlspecialchars((string)($_POST['academic_year'] ?? $period_data['academic_year'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                               placeholder="Например: 2026-2027">
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3>Даты периода</h3>
                                <div class="form-grid">
                                    <div class="form-group required">
                                        <label>Дата начала</label>
                                        <input type="date" name="start_date" value="<?php echo $period_data ? $period_data['start_date'] : ''; ?>" required>
                                    </div>
                                    <div class="form-group required">
                                        <label>Дата окончания</label>
                                        <input type="date" name="end_date" value="<?php echo $period_data ? $period_data['end_date'] : ''; ?>" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h3>Настройки</h3>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_current" value="1"
                                            <?php echo ($period_data && $period_data['is_current']) ? 'checked' : ''; ?>>
                                        Сделать текущим учебным периодом
                                    </label>
                                    <small class="form-hint">
                                        Текущий период будет использоваться по умолчанию для всех операций в выбранной школе
                                    </small>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-label">
                                        <input type="checkbox" name="is_active" value="1"
                                            <?php echo isset($_POST['action']) ? (isset($_POST['is_active']) ? 'checked' : '') : ((!$period_data || $period_data['is_active']) ? 'checked' : ''); ?>>
                                        Активный период
                                    </label>
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <?php echo $action === 'add' ? 'Создать период' : 'Сохранить изменения'; ?>
                                </button>
                                <a href="academic_periods.php" class="btn btn-secondary">Отмена</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($action === 'view' && $period_data): ?>
                <!-- Просмотр учебного периода -->
                <div class="period-container">
                    <div class="period-header">
                        <h2><?php echo htmlspecialchars($period_data['name']); ?></h2>
                        <div class="period-actions">
                            <a href="academic_periods.php?action=edit&id=<?php echo $period_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                            <?php if (!$period_data['is_current']): ?>
                                <?php echo csrf_post_button('set_current', (int)$period_data['id'], '⭐ Сделать текущим', 'btn btn-success'); ?>
                            <?php endif; ?>
                            <?php echo csrf_post_button('delete', (int)$period_data['id'], '🗑️ Удалить', 'btn btn-danger', 'Вы уверены, что хотите удалить этот учебный период?'); ?>
                        </div>
                    </div>

                    <div class="view-sections">
                        <div class="view-section">
                            <h3>Основная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Название:</label>
                                    <span><?php echo htmlspecialchars($period_data['name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Школа:</label>
                                    <span><?php echo htmlspecialchars($period_data['school_name']); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Статус:</label>
                                    <span class="status-badge <?php echo $period_data['is_current'] ? 'status-current' : 'status-archived'; ?>">
                                            <?php echo $period_data['is_current'] ? 'Текущий' : 'Архивный'; ?>
                                        </span>
                                </div>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Даты периода</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Дата начала:</label>
                                    <span><?php echo date('d.m.Y', strtotime($period_data['start_date'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Дата окончания:</label>
                                    <span><?php echo date('d.m.Y', strtotime($period_data['end_date'])); ?></span>
                                </div>
                                <div class="info-item">
                                    <label>Продолжительность:</label>
                                    <span>
                                            <?php
                                            $start = new DateTime($period_data['start_date']);
                                            $end = new DateTime($period_data['end_date']);
                                            $interval = $start->diff($end);
                                            echo $interval->days . ' дней';
                                            ?>
                                        </span>
                                </div>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Системная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Дата создания:</label>
                                    <span><?php echo date('d.m.Y H:i', strtotime($period_data['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список учебных периодов -->
                <div class="period-container">
                    <div class="period-filters">
                        <form id="period-filters">
                            <div class="filter-grid">
                                <div class="filter-group">
                                    <label>Школа</label>
                                    <select name="school">
                                        <option value="all">Все школы</option>
                                        <?php foreach ($schools as $school): ?>
                                            <option value="<?php echo $school['id']; ?>"><?php echo htmlspecialchars($school['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Статус</label>
                                    <select name="status">
                                        <option value="all">Все статусы</option>
                                        <option value="current">Текущие</option>
                                        <option value="archived">Архивные</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Год</label>
                                    <select name="year">
                                        <option value="all">Все годы</option>
                                        <?php
                                        $current_year = date('Y');
                                        for ($year = $current_year - 5; $year <= $current_year + 2; $year++):
                                            ?>
                                            <option value="<?php echo $year; ?>"><?php echo $year; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="filter-actions">
                                    <button type="submit" class="btn btn-primary">Применить</button>
                                    <button type="button" class="btn btn-secondary" onclick="resetFilters()">Сбросить</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="period-table">
                        <div class="table-responsive">
                            <table class="period-data-table" id="period-table">
                                <thead>
                                <tr>
                                    <th>Название</th>
                                    <th>Школа</th>
                                    <th>Дата начала</th>
                                    <th>Дата окончания</th>
                                    <th>Статус</th>
                                    <th>Дней</th>
                                    <th>Действия</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($periods)): ?>
                                    <tr>
                                        <td colspan="7" class="empty-state">
                                            <div class="empty-state-icon">📅</div>
                                            <h3>Учебные периоды не найдены</h3>
                                            <p>Создайте первый учебный период для вашей школы</p>
                                            <a href="academic_periods.php?action=add" class="btn btn-primary">Создать период</a>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($periods as $period):
                                        $start = new DateTime($period['start_date']);
                                        $end = new DateTime($period['end_date']);
                                        $days = $start->diff($end)->days;
                                        ?>
                                        <tr data-id="<?php echo $period['id']; ?>" data-school-id="<?php echo $period['school_id']; ?>" data-year="<?php echo $start->format('Y'); ?>">
                                            <td>
                                                <div class="period-name"><?php echo htmlspecialchars($period['name']); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($period['school_name']); ?></td>
                                            <td>
                                                <div class="date-cell"><?php echo date('d.m.Y', strtotime($period['start_date'])); ?></div>
                                            </td>
                                            <td>
                                                <div class="date-cell"><?php echo date('d.m.Y', strtotime($period['end_date'])); ?></div>
                                            </td>
                                            <td>
                                                        <span class="status-badge <?php echo $period['is_current'] ? 'status-current' : 'status-archived'; ?>">
                                                            <?php echo $period['is_current'] ? 'Текущий' : 'Архивный'; ?>
                                                        </span>
                                            </td>
                                            <td>
                                                <span class="days-count"><?php echo $days; ?></span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-action btn-edit" title="Редактировать" onclick="editPeriod(<?php echo $period['id']; ?>)">
                                                        ✏️
                                                    </button>
                                                    <button class="btn-action btn-view" title="Просмотреть" onclick="viewPeriod(<?php echo $period['id']; ?>)">
                                                        👁️
                                                    </button>
                                                    <?php if (!$period['is_current']): ?>
                                                        <?php echo csrf_post_button('set_current', (int)$period['id'], '⭐', 'btn-action btn-current'); ?>
                                                    <?php endif; ?>
                                                    <?php echo csrf_post_button(!empty($period['is_locked']) ? 'unlock' : 'lock', (int)$period['id'], !empty($period['is_locked']) ? '🔓' : '🔒', 'btn-action'); ?>
                                                    <?php echo csrf_post_button('delete', (int)$period['id'], '🗑️', 'btn-action btn-delete', 'Вы уверены, что хотите удалить этот учебный период?'); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../js/academic_periods.js"></script>
</body>
</html>
