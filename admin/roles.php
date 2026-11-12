<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
requireSuperAdmin();

// Функция для безопасного получения массива permissions
function getSafePermissions($permissions_data) {
    if (!$permissions_data || $permissions_data === 'null') {
        return [];
    }

    $decoded = json_decode($permissions_data, true);
    return is_array($decoded) ? $decoded : [];
}

$pdo = getDatabaseConnection();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$role_id = isset($_POST['id']) ? intval($_POST['id']) : (isset($_GET['id']) ? intval($_GET['id']) : 0);

// Базовые разрешения системы
$available_permissions = [
        'system' => [
                'name' => 'Системные разрешения',
                'permissions' => [
                        'view_dashboard' => 'Просмотр панели управления',
                        'manage_profile' => 'Управление своим профилем',
                        'view_audit_logs' => 'Просмотр журнала действий'
                ]
        ],
        'schools' => [
                'name' => 'Учебные заведения',
                'permissions' => [
                        'view_schools' => 'Просмотр учебных заведений',
                        'manage_schools' => 'Управление учебными заведениями'
                ]
        ],
        'users' => [
                'name' => 'Пользователи',
                'permissions' => [
                        'view_users' => 'Просмотр пользователей',
                        'manage_users' => 'Управление пользователями',
                        'reset_passwords' => 'Сброс паролей'
                ]
        ],
        'roles' => [
                'name' => 'Роли и права',
                'permissions' => [
                        'view_roles' => 'Просмотр ролей',
                        'manage_roles' => 'Управление ролями и правами'
                ]
        ],
        'curriculum' => [
                'name' => 'Учебные планы',
                'permissions' => [
                        'view_curriculum' => 'Просмотр учебных планов',
                        'manage_curriculum' => 'Управление учебными планами'
                ]
        ],
        'academic' => [
                'name' => 'Учебный процесс',
                'permissions' => [
                        'view_academic_periods' => 'Просмотр учебных периодов',
                        'manage_academic_periods' => 'Управление учебными периодами',
                        'view_classes' => 'Просмотр классов',
                        'manage_classes' => 'Управление классами',
                        'view_schedule' => 'Просмотр расписания',
                        'manage_schedule' => 'Управление расписанием',
                        'view_subjects' => 'Просмотр предметов',
                        'manage_subjects' => 'Управление предметами',
                        'view_grade_types' => 'Просмотр типов оценок',
                        'manage_grade_types' => 'Управление типами оценок',
                        'view_grade_weights' => 'Просмотр весов оценок',
                        'manage_grade_weights' => 'Управление весами оценок'
                ]
        ],
        'teaching' => [
                'name' => 'Преподавание',
                'permissions' => [
                        'view_students' => 'Просмотр учеников',
                        'view_teachers' => 'Просмотр учителей',
                        'manage_teachers' => 'Управление учителями',
                        'view_parents' => 'Просмотр родителей',
                        'manage_parents' => 'Управление родителями',
                        'view_grades' => 'Просмотр оценок',
                        'view_homework' => 'Просмотр домашних заданий',
                        'view_class_journal' => 'Просмотр классного журнала',
                        'manage_grades' => 'Выставление оценок',
                        'manage_homework' => 'Управление домашними заданиями',
                        'view_attendance' => 'Просмотр посещаемости',
                        'manage_attendance' => 'Управление посещаемостью'
                ]
        ],
                'families' => [
                    'name' => 'Взаимодействие с родителями',
                    'permissions' => [
                        'view_child_grades' => 'Просмотр оценок ребенка',
                        'view_child_homework' => 'Просмотр домашних заданий ребенка',
                        'contact_teachers' => 'Связь с учителями'
                    ]
                ],
        'reports' => [
                'name' => 'Отчетность',
                'permissions' => [
                        'view_reports' => 'Просмотр отчетов',
                        'generate_reports' => 'Генерация отчетов',
                        'export_data' => 'Экспорт данных'
                ]
        ]
];

// Обработка добавления роли
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];

        if (empty($name)) {
            $error = "Название роли обязательно для заполнения!";
        } else {
            try {
                $permissions_json = json_encode($permissions);

                $stmt = $pdo->prepare("INSERT INTO roles (name, description, permissions) VALUES (?, ?, ?)");
                $stmt->execute([$name, $description, $permissions_json]);

                $_SESSION['success_message'] = "Роль успешно создана!";
                header('Location: roles.php');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Роль с таким названием уже существует!";
                } else {
                    $error = publicErrorMessage($e, basename(__FILE__));
                }
            }
        }
    }
    // Обработка редактирования роли
    elseif ($action === 'edit' && $role_id > 0) {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        $permissions = isset($_POST['permissions']) ? $_POST['permissions'] : [];

        if (empty($name)) {
            $error = "Название роли обязательно для заполнения!";
        } else {
            try {
                $permissions_json = json_encode($permissions);

                $stmt = $pdo->prepare("UPDATE roles SET name = ?, description = ?, permissions = ? WHERE id = ?");
                $stmt->execute([$name, $description, $permissions_json, $role_id]);

                $_SESSION['success_message'] = "Роль успешно обновлена!";
                header('Location: roles.php');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $error = "Роль с таким названием уже существует!";
                } else {
                    $error = publicErrorMessage($e, basename(__FILE__));
                }
            }
        }
    }
}

// Обработка удаления роли
if ($action === 'delete' && $role_id > 0) {
    try {
        // Проверяем есть ли пользователи с этой ролью
        $check_stmt = $pdo->prepare("SELECT COUNT(*) as user_count FROM users WHERE role_id = ?");
        $check_stmt->execute([$role_id]);
        $result = $check_stmt->fetch();

        if ($result['user_count'] > 0) {
            $_SESSION['error_message'] = "Невозможно удалить роль: есть пользователи с этой ролью";
        } else {
            $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ?");
            $stmt->execute([$role_id]);
            $_SESSION['success_message'] = "Роль успешно удалена!";
        }

        header('Location: roles.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, basename(__FILE__));
        header('Location: roles.php');
        exit;
    }
}

// Получение данных роли для редактирования/просмотра
$role_data = null;
$role_permissions = [];
if (($action === 'edit' || $action === 'view') && $role_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
    $stmt->execute([$role_id]);
    $role_data = $stmt->fetch();

    if (!$role_data) {
        $_SESSION['error_message'] = "Роль не найдена!";
        header('Location: roles.php');
        exit;
    }

    // Декодируем разрешения с использованием безопасной функции
    $role_permissions = getSafePermissions($role_data['permissions']);
}

// Получение списка ролей
$roles = $pdo->query("SELECT r.*, COUNT(u.id) as user_count 
                     FROM roles r 
                     LEFT JOIN users u ON r.id = u.role_id 
                     GROUP BY r.id 
                     ORDER BY r.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Роли и права - Знание Севера</title>
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/roles.css">
</head>
<body>
<div class="dashboard-container">
    <?php $adminActivePage = 'roles'; require __DIR__ . '/../includes/admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Управление ролями и правами</h1>
                <p>Главный Администратор • <?php echo htmlspecialchars($_SESSION['user_login']); ?></p>
            </div>
            <div class="header-actions">
                <?php if ($action === 'add' || $action === 'edit' || $action === 'view'): ?>
                    <a href="roles.php" class="btn btn-secondary">← Назад к списку</a>
                <?php else: ?>
                    <a href="roles.php?action=add" class="btn btn-primary">➕ Создать роль</a>
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
                <!-- Форма добавления/редактирования роли -->
                <div class="form-container">
                    <h2><?php echo $action === 'add' ? 'Создание новой роли' : 'Редактирование роли'; ?></h2>
                    <form method="POST" class="role-form">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$role_id; ?>">
                        <div class="form-section">
                            <h3>Основная информация</h3>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Название роли *</label>
                                    <input type="text" name="name" value="<?php echo $role_data ? htmlspecialchars($role_data['name']) : ''; ?>" required>
                                </div>
                                <div class="form-group full-width">
                                    <label>Описание роли</label>
                                    <textarea name="description" rows="3" placeholder="Опишите назначение этой роли..."><?php echo $role_data ? htmlspecialchars($role_data['description']) : ''; ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h3>Настройка прав доступа</h3>
                            <div class="permissions-section">
                                <div class="permissions-header">
                                    <div class="select-all-container">
                                        <label class="checkbox-label">
                                            <input type="checkbox" id="select-all-permissions">
                                            <span class="checkmark"></span>
                                            Выбрать все разрешения
                                        </label>
                                    </div>
                                </div>

                                <div class="permissions-grid">
                                    <?php foreach ($available_permissions as $category => $category_data): ?>
                                        <div class="permission-category">
                                            <div class="category-header">
                                                <h4><?php echo htmlspecialchars($category_data['name']); ?></h4>
                                                <label class="checkbox-label">
                                                    <input type="checkbox" class="category-select" data-category="<?php echo $category; ?>">
                                                    <span class="checkmark"></span>
                                                    Выбрать все
                                                </label>
                                            </div>
                                            <div class="permissions-list">
                                                <?php foreach ($category_data['permissions'] as $permission => $description): ?>
                                                    <div class="permission-item">
                                                        <label class="checkbox-label">
                                                            <input type="checkbox" name="permissions[]" value="<?php echo $permission; ?>"
                                                                    <?php echo (in_array($permission, $role_permissions)) ? 'checked' : ''; ?>
                                                                   data-category="<?php echo $category; ?>">
                                                            <span class="checkmark"></span>
                                                            <?php echo htmlspecialchars($description); ?>
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action === 'add' ? 'Создать роль' : 'Сохранить изменения'; ?>
                            </button>
                            <a href="roles.php" class="btn btn-secondary">Отмена</a>
                        </div>
                    </form>
                </div>

            <?php elseif ($action === 'view' && $role_data): ?>
                <!-- Просмотр роли -->
                <div class="view-container">
                    <div class="view-header">
                        <h2><?php echo htmlspecialchars($role_data['name']); ?></h2>
                        <div class="view-actions">
                            <a href="roles.php?action=edit&id=<?php echo $role_data['id']; ?>" class="btn btn-primary">✏️ Редактировать</a>
                            <?php echo csrf_post_button('delete', (int)$role_data['id'], '🗑️ Удалить', 'btn btn-danger', 'Вы уверены, что хотите удалить эту роль?'); ?>
                        </div>
                    </div>

                    <div class="view-sections">
                        <div class="view-section">
                            <h3>Основная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Название роли:</label>
                                    <span><?php echo htmlspecialchars($role_data['name']); ?></span>
                                </div>
                                <?php if ($role_data['description']): ?>
                                    <div class="info-item full-width">
                                        <label>Описание:</label>
                                        <span><?php echo nl2br(htmlspecialchars($role_data['description'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <label>Пользователей с ролью:</label>
                                    <span class="user-count"><?php
                                        $user_count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE role_id = ?");
                                        $user_count_stmt->execute([$role_data['id']]);
                                        $user_count = $user_count_stmt->fetch()['count'];
                                        echo $user_count;
                                        ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Права доступа</h3>
                            <div class="permissions-view">
                                <?php
                                $has_permissions = false;

                                foreach ($available_permissions as $category => $category_data):
                                    $category_permissions = array_intersect($role_permissions, array_keys($category_data['permissions']));
                                    if (!empty($category_permissions)):
                                        $has_permissions = true;
                                        ?>
                                        <div class="permission-category-view">
                                            <h4><?php echo htmlspecialchars($category_data['name']); ?></h4>
                                            <div class="permissions-list-view">
                                                <?php foreach ($category_permissions as $permission): ?>
                                                    <div class="permission-item-view">
                                                        <span class="permission-check">✓</span>
                                                        <span><?php echo htmlspecialchars($category_data['permissions'][$permission]); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php
                                    endif;
                                endforeach;

                                if (!$has_permissions):
                                    ?>
                                    <div class="no-permissions">
                                        📝 У этой роли нет назначенных прав доступа
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="view-section">
                            <h3>Системная информация</h3>
                            <div class="info-grid">
                                <div class="info-item">
                                    <label>Дата создания:</label>
                                    <span><?php echo date('d.m.Y H:i', strtotime($role_data['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Список ролей -->
                <div class="table-section">
                    <div class="table-header">
                        <h3>Список ролей системы</h3>
                        <div class="table-actions">
                            <button class="btn btn-sm btn-secondary" onclick="refreshTable()">🔄 Обновить</button>
                        </div>
                    </div>

                    <div class="table-container">
                        <table class="data-table" id="roles-table">
                            <thead>
                            <tr>
                                <th>Название роли</th>
                                <th>Описание</th>
                                <th>Пользователей</th>
                                <th>Прав доступа</th>
                                <th>Дата создания</th>
                                <th>Действия</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($roles)): ?>
                                <tr>
                                    <td colspan="6" class="no-data">
                                        📝 Нет созданных ролей
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($roles as $role):
                                    // Безопасное получение количества прав
                                    $permissions_count = count(getSafePermissions($role['permissions']));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="role-name">
                                                <strong><?php echo htmlspecialchars($role['name']); ?></strong>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo $role['description'] ? htmlspecialchars($role['description']) : '<span class="text-muted">—</span>'; ?>
                                        </td>
                                        <td>
                                            <span class="user-count-badge"><?php echo $role['user_count']; ?></span>
                                        </td>
                                        <td>
                                            <span class="permissions-count"><?php echo $permissions_count; ?> прав</span>
                                        </td>
                                        <td><?php echo date('d.m.Y', strtotime($role['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action btn-edit" title="Редактировать" onclick="editRole(<?php echo $role['id']; ?>)">
                                                    ✏️
                                                </button>
                                                <button class="btn-action btn-view" title="Просмотреть" onclick="viewRole(<?php echo $role['id']; ?>)">
                                                    👁️
                                                </button>
                                                <?php if ($role['user_count'] == 0): ?>
                                                    <?php echo csrf_post_button('delete', (int)$role['id'], '🗑️', 'btn-action btn-delete', 'Вы уверены, что хотите удалить эту роль?'); ?>
                                                <?php else: ?>
                                                    <button class="btn-action btn-disabled" title="Нельзя удалить (есть пользователи)" disabled>
                                                        🚫
                                                    </button>
                                                <?php endif; ?>
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
                            Показано <strong><?php echo count($roles); ?></strong> ролей
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../js/roles.js"></script>
</body>
</html>