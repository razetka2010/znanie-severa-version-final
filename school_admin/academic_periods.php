<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireSchoolAdmin();

$pdo = getDatabaseConnection();
$context = getSchoolAdminContext($pdo);
$school_id = (int)$context['school_id'];
$school = $context['school'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$period_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)
    ?: (filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0);

function validatePeriodDates(string $start_date, string $end_date): bool
{
    $start = DateTime::createFromFormat('Y-m-d', $start_date);
    $end = DateTime::createFromFormat('Y-m-d', $end_date);

    return $start && $start->format('Y-m-d') === $start_date
        && $end && $end->format('Y-m-d') === $end_date
        && $start_date < $end_date;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array($action, ['add', 'edit'], true)) {
        $name = trim((string)($_POST['name'] ?? ''));
        $start_date = (string)($_POST['start_date'] ?? '');
        $end_date = (string)($_POST['end_date'] ?? '');
        $academic_year = trim((string)($_POST['academic_year'] ?? ''));
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        $errors = [];

        if ($name === '') {
            $errors[] = 'Введите название учебного периода.';
        }
        if (!validatePeriodDates($start_date, $end_date)) {
            $errors[] = 'Дата начала должна быть раньше даты окончания.';
        }
        if ($academic_year === '') {
            $start_year = (int)substr($start_date, 0, 4);
            $end_year = (int)substr($end_date, 0, 4);
            $academic_year = $start_year . '-' . $end_year;
        }
        if (strlen($academic_year) > 20) {
            $errors[] = 'Учебный год не должен превышать 20 символов.';
        }
        if ($action === 'edit' && $period_id < 1) {
            $errors[] = 'Учебный период не найден.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($action === 'edit') {
                    $owned_stmt = $pdo->prepare('SELECT id FROM academic_periods WHERE id = ? AND school_id = ? FOR UPDATE');
                    $owned_stmt->execute([$period_id, $school_id]);
                    if (!$owned_stmt->fetchColumn()) {
                        throw new InvalidArgumentException('Учебный период не найден в вашей школе.');
                    }
                }

                $overlap_sql = "
                    SELECT id FROM academic_periods
                    WHERE school_id = ? AND (academic_year = ? OR academic_year IS NULL)
                        AND start_date <= ? AND end_date >= ?
                ";
                $overlap_params = [$school_id, $academic_year, $end_date, $start_date];
                if ($action === 'edit') {
                    $overlap_sql .= ' AND id != ?';
                    $overlap_params[] = $period_id;
                }
                $overlap_sql .= ' LIMIT 1 FOR UPDATE';
                $overlap_stmt = $pdo->prepare($overlap_sql);
                $overlap_stmt->execute($overlap_params);
                if ($overlap_stmt->fetchColumn()) {
                    throw new InvalidArgumentException('Даты пересекаются с другим периодом этого учебного года.');
                }

                if ($is_current) {
                    $reset_stmt = $pdo->prepare('UPDATE academic_periods SET is_current = 0 WHERE school_id = ?');
                    $reset_stmt->execute([$school_id]);
                }

                if ($action === 'add') {
                    $stmt = $pdo->prepare("
                        INSERT INTO academic_periods
                            (school_id, name, start_date, end_date, academic_year, is_active, is_current)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$school_id, $name, $start_date, $end_date, $academic_year, $is_active, $is_current]);
                    $period_id = (int)$pdo->lastInsertId();
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE academic_periods
                        SET name = ?, start_date = ?, end_date = ?, academic_year = ?,
                            is_active = ?, is_current = ?
                        WHERE id = ? AND school_id = ?
                    ");
                    $stmt->execute([$name, $start_date, $end_date, $academic_year, $is_active, $is_current, $period_id, $school_id]);
                }

                $pdo->commit();
                logUserAction($pdo, $action === 'add' ? 'academic_period_created' : 'academic_period_updated', json_encode([
                    'school_id' => $school_id,
                    'period_id' => $period_id,
                    'academic_year' => $academic_year
                ], JSON_UNESCAPED_UNICODE));
                $_SESSION['success_message'] = $action === 'add'
                    ? 'Учебный период создан.'
                    : 'Учебный период обновлён.';
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
        } else {
            $error = implode("\n", $errors);
        }
    } elseif (in_array($action, ['set_current', 'lock', 'unlock', 'delete'], true) && $period_id > 0) {
        try {
            $pdo->beginTransaction();
            $period_stmt = $pdo->prepare('SELECT id, is_active FROM academic_periods WHERE id = ? AND school_id = ? FOR UPDATE');
            $period_stmt->execute([$period_id, $school_id]);
            $period = $period_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$period) {
                throw new InvalidArgumentException('Учебный период не найден в вашей школе.');
            }

            if ($action === 'set_current') {
                if (!(int)$period['is_active']) {
                    throw new InvalidArgumentException('Нельзя сделать текущим неактивный период.');
                }
                $stmt = $pdo->prepare('UPDATE academic_periods SET is_current = 0 WHERE school_id = ?');
                $stmt->execute([$school_id]);
                $stmt = $pdo->prepare('UPDATE academic_periods SET is_current = 1 WHERE id = ? AND school_id = ?');
                $stmt->execute([$period_id, $school_id]);
            } elseif ($action === 'lock' || $action === 'unlock') {
                $stmt = $pdo->prepare('UPDATE academic_periods SET is_locked = ? WHERE id = ? AND school_id = ?');
                $stmt->execute([$action === 'lock' ? 1 : 0, $period_id, $school_id]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM academic_periods WHERE id = ? AND school_id = ?');
                $stmt->execute([$period_id, $school_id]);
            }

            $pdo->commit();
            logUserAction($pdo, 'academic_period_' . $action, json_encode([
                'school_id' => $school_id,
                'period_id' => $period_id
            ], JSON_UNESCAPED_UNICODE));
            $_SESSION['success_message'] = $action === 'lock' ? 'Период закрыт.'
                : ($action === 'unlock' ? 'Период открыт.'
                : ($action === 'delete' ? 'Период удалён.' : 'Текущий период установлен.'));
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
}

$period_data = null;
if (($action === 'edit' || $action === 'view') && $period_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM academic_periods WHERE id = ? AND school_id = ?');
    $stmt->execute([$period_id, $school_id]);
    $period_data = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$periods_stmt = $pdo->prepare('SELECT * FROM academic_periods WHERE school_id = ? ORDER BY start_date DESC, id DESC');
$periods_stmt->execute([$school_id]);
$periods = $periods_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Учебные периоды - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/academic_periods.css?v=20261111">
</head>
<body>
<div class="dashboard-container">
    <?php $schoolAdminActivePage = 'academic_periods'; require __DIR__ . '/../includes/school_admin_sidebar.php'; ?>
    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Учебные периоды</h1>
                <p><?php echo htmlspecialchars($school['full_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <div class="header-actions">
                <a href="academic_periods.php?action=add" class="btn btn-primary">➕ Создать период</a>
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
                <div class="alert alert-error"><?php echo nl2br(htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); ?></div>
            <?php endif; ?>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <section class="period-container">
                    <h2><?php echo $action === 'add' ? 'Создание учебного периода' : 'Редактирование учебного периода'; ?></h2>
                    <form method="POST" class="period-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$period_id; ?>">
                        <label>Название периода
                            <input name="name" required maxlength="100" value="<?php echo htmlspecialchars((string)($_POST['name'] ?? $period_data['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <label>Учебный год
                            <input name="academic_year" maxlength="20" placeholder="Например, 2026-2027" value="<?php echo htmlspecialchars((string)($_POST['academic_year'] ?? $period_data['academic_year'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <label>Дата начала
                            <input type="date" name="start_date" required value="<?php echo htmlspecialchars((string)($_POST['start_date'] ?? $period_data['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <label>Дата окончания
                            <input type="date" name="end_date" required value="<?php echo htmlspecialchars((string)($_POST['end_date'] ?? $period_data['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        </label>
                        <label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?php echo isset($_POST['is_active']) || (!isset($_POST['action']) && (!$period_data || $period_data['is_active'])) || (isset($_POST['action']) && isset($_POST['is_active'])) ? 'checked' : ''; ?>> Активный период</label>
                        <label class="checkbox-label"><input type="checkbox" name="is_current" value="1" <?php echo (!isset($_POST['action']) && !empty($period_data['is_current'])) || (isset($_POST['action']) && isset($_POST['is_current'])) ? 'checked' : ''; ?>> Текущий период</label>
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><?php echo $action === 'add' ? 'Создать период' : 'Сохранить'; ?></button>
                            <a href="academic_periods.php" class="btn btn-secondary">Отмена</a>
                        </div>
                    </form>
                </section>
            <?php else: ?>
                <div class="period-container">
                    <div class="table-responsive">
                        <table class="period-data-table">
                            <thead><tr><th>Название</th><th>Учебный год</th><th>Даты</th><th>Состояние</th><th>Действия</th></tr></thead>
                            <tbody>
                            <?php foreach ($periods as $period): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$period['academic_year'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($period['start_date'] . ' — ' . $period['end_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $period['is_current'] ? 'Текущий' : 'Обычный'; ?><?php echo $period['is_locked'] ? ' 🔒 Закрыт' : ''; ?><?php echo !$period['is_active'] ? ' · Неактивен' : ''; ?></td>
                                    <td class="action-buttons">
                                        <a class="btn-action btn-edit" href="academic_periods.php?action=edit&amp;id=<?php echo (int)$period['id']; ?>" title="Редактировать">✏️</a>
                                        <?php if (!$period['is_current'] && $period['is_active']): ?>
                                            <?php echo csrf_post_button('set_current', (int)$period['id'], '⭐', 'btn-action btn-current'); ?>
                                        <?php endif; ?>
                                        <?php echo csrf_post_button($period['is_locked'] ? 'unlock' : 'lock', (int)$period['id'], $period['is_locked'] ? '🔓' : '🔒', 'btn-action'); ?>
                                        <?php echo csrf_post_button('delete', (int)$period['id'], '🗑️', 'btn-action btn-delete', 'Удалить учебный период?'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$periods): ?>
                                <tr><td colspan="5" class="empty-state">Периодов пока нет. Создайте первый учебный период.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
