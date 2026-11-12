<?php
// config/auth.php

function appUrl(string $path): string
{
    $scriptName = parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '';
    $directory = dirname(str_replace('\\', '/', $scriptName));
    $directory = str_replace('\\', '/', $directory);
    $topLevelDirectories = ['admin', 'school_admin', 'teacher', 'student', 'parent', 'reports'];
    if (in_array(basename($directory), $topLevelDirectories, true)) {
        $directory = dirname($directory);
    }

    $basePath = $directory === '.' || $directory === '/' ? '' : rtrim($directory, '/');
    return $basePath . '/' . ltrim($path, '/');
}

function redirectToLogin(string $query = ''): void
{
    header('Location: ' . appUrl('login.php' . ($query !== '' ? '?' . $query : '')));
    exit;
}

/**
 * Проверка авторизации пользователя
 */
function requireAuth() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        redirectToLogin();
    }

    if (!isset($_SESSION['last_activity']) || !is_numeric($_SESSION['last_activity'])
        || time() - (int)$_SESSION['last_activity'] > 28800) {
        destroyAppSession();
        redirectToLogin('error=session_expired');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $actionParam = $_GET['action'] ?? '';
        $getAction = is_string($actionParam) ? strtolower($actionParam) : '';
        // Белый список: через GET разрешены только действия, открывающие формы/просмотр.
        // Любое другое значение action (lock, unlock, apply и т.д.) требует POST + CSRF-токен.
        $allowedGetActions = ['', 'add', 'edit', 'view', 'list', 'add_month'];
        if (isset($_GET['action']) && (!is_string($_GET['action']) || !in_array($getAction, $allowedGetActions, true))
            || isset($_GET['delete_file']) || isset($_GET['delete_event'])) {
            http_response_code(405);
            header('Allow: POST');
            exit('Изменяющие действия требуют POST-запроса.');
        }
    }

    try {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->prepare("\n            SELECT u.*, r.name AS role_name, r.permissions AS role_permissions, s.full_name AS school_name\n            FROM users u\n            JOIN roles r ON r.id = u.role_id\n            LEFT JOIN schools s ON s.id = u.school_id\n            WHERE u.id = ? AND u.is_active = 1\n            LIMIT 1\n        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Auth check failed: ' . $e->getMessage());
        destroyAppSession();
        redirectToLogin('error=auth_unavailable');
    }

    if (!$user || $user['role_name'] !== $_SESSION['user_role']) {
        destroyAppSession();
        redirectToLogin('error=session_invalid');
    }

    $_SESSION['user_role'] = $user['role_name'];
    $_SESSION['user_school_id'] = $user['school_id'];
    $_SESSION['school_name'] = $user['school_name'] ?? '';
    $_SESSION['last_activity'] = time();
    $rolePermissions = json_decode((string)($user['role_permissions'] ?? '[]'), true);
    $_SESSION['role_permissions'] = is_array($rolePermissions)
        ? array_values(array_filter($rolePermissions, 'is_string'))
        : [];

    enforcePagePermission();
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])
        || !preg_match('/\A[a-f0-9]{64}\z/', $_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function logoutForm(string $action, string $class = 'nav-link logout-button'): string
{
    return '<form method="POST" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" class="logout-form">'
        . csrf_field()
        . '<button type="submit" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">🚪 Выход</button>'
        . '</form>';
}

function consumeFlashMessage(string $key): string
{
    if (!in_array($key, ['success_message', 'error_message', 'warning_message'], true) || !isset($_SESSION[$key])) {
        return '';
    }

    $message = $_SESSION[$key];
    unset($_SESSION[$key]);

    return htmlspecialchars(is_scalar($message) ? (string)$message : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function publicErrorMessage(Throwable $exception, string $context): string
{
    error_log($context . ': ' . $exception->getMessage());

    return 'Не удалось выполнить операцию. Попробуйте позже.';
}

function csrf_post_button(string $action, int $id, string $label, string $class, string $confirmation = ''): string
{
    $confirm = $confirmation === ''
        ? ''
        : ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirmation, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') . ')"';

    return '<form method="POST" class="csrf-action-form" style="display:inline"' . $confirm . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button type="submit" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
        . '</form>';
}

function verify_csrf_token(): void
{
    $token = $_POST['csrf_token'] ?? '';
    $expectedToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($expectedToken)
        || $expectedToken === '' || !hash_equals($expectedToken, $token)) {
        http_response_code(419);
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if (is_string($accept) && strpos($accept, 'application/json') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            exit(json_encode([
                'ok' => false,
                'message' => 'Недействительный CSRF-токен. Обновите страницу и повторите действие.',
                'new_value' => null,
                'attendance_status' => null,
                'average' => null
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        exit('Недействительный CSRF-токен. Обновите страницу и повторите действие.');
    }
}

/**
 * Проверка прав супер администратора
 */
function requireSuperAdmin() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'super_admin') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права супер администратора.";
        redirectToLogin();
    }
}

/**
 * Проверка прав администратора школы
 */
function requireSchoolAdmin() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'school_admin') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права администратора школы.";
        redirectToLogin();
    }
}

function getSchoolAdminContext(PDO $pdo): array
{
    $schoolId = $_SESSION['user_school_id'] ?? null;
    if (!$schoolId) {
        $_SESSION['error_message'] = 'Школа не определена. Обратитесь к администратору.';
        header('Location: ' . appUrl('school_admin/dashboard.php'));
        exit;
    }

    $stmt = $pdo->prepare('SELECT full_name, short_name FROM schools WHERE id = ?');
    $stmt->execute([$schoolId]);

    return [
        'school_id' => $schoolId,
        'school' => $stmt->fetch(),
    ];
}

function deactivateSchoolUser(PDO $pdo, int $userId, int $schoolId): bool
{
    $stmt = $pdo->prepare('UPDATE users SET is_active = FALSE WHERE id = ? AND school_id = ?');
    $stmt->execute([$userId, $schoolId]);

    return $stmt->rowCount() > 0;
}

/**
 * Проверка прав учителя
 */
function requireTeacher() {
    requireAuth();
    if (!in_array($_SESSION['user_role'], ['teacher', 'class_teacher'])) {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права учителя.";
        redirectToLogin();
    }
}

/**
 * Проверка прав классного руководителя
 */
function requireClassTeacher() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'class_teacher') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права классного руководителя.";
        redirectToLogin();
    }
}

/**
 * Проверка прав ученика
 */
function requireStudent() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'student') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права ученика.";
        redirectToLogin();
    }
}

/**
 * Проверка прав родителя
 */
function requireParent() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'parent') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права родителя.";
        redirectToLogin();
    }
}

/**
 * Проверка что пользователь является учителем или классным руководителем
 */
function requireTeacherOrClassTeacher() {
    requireAuth();
    if (!in_array($_SESSION['user_role'], ['teacher', 'class_teacher'])) {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права учителя или классного руководителя.";
        redirectToLogin();
    }
}

/**
 * Проверка что пользователь является администратором (любого уровня)
 */
function requireAnyAdmin() {
    requireAuth();
    if (!in_array($_SESSION['user_role'], ['super_admin', 'school_admin'])) {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права администратора.";
        redirectToLogin();
    }
}

/**
 * Проверка что пользователь является сотрудником школы (админ или учитель)
 */
function requireSchoolStaff() {
    requireAuth();
    if (!in_array($_SESSION['user_role'], ['super_admin', 'school_admin', 'teacher', 'class_teacher'])) {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права сотрудника школы.";
        redirectToLogin();
    }
}

/**
 * Получение текущей роли пользователя
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

function getRoleDisplayName(string $role): string
{
    $roleNames = [
        'super_admin' => 'Главный администратор',
        'school_admin' => 'Администратор школы',
        'teacher' => 'Учитель',
        'class_teacher' => 'Классный руководитель',
        'student' => 'Ученик',
        'parent' => 'Родитель',
    ];

    return $roleNames[$role] ?? $role;
}

/**
 * Получение ID текущего пользователя
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Получение school_id текущего пользователя
 */
function getCurrentUserSchoolId() {
    return $_SESSION['user_school_id'] ?? null;
}

/**
 * Проверка принадлежности пользователя к школе
 */
function requireSameSchool($school_id) {
    requireAuth();
    $user_school_id = getCurrentUserSchoolId();

    if ($user_school_id != $school_id && getCurrentUserRole() !== 'super_admin') {
        $_SESSION['error_message'] = "Доступ запрещен. Несоответствие школы.";
        redirectToLogin();
    }
}

/**
 * Проверка что пользователь может управлять указанным классом
 */
function canManageClass($class_id, $pdo) {
    $user_id = getCurrentUserId();
    $user_role = getCurrentUserRole();

    // Супер администратор может управлять всеми классами
    if ($user_role === 'super_admin') {
        return true;
    }

    // Администратор школы может управлять классами своей школы
    if ($user_role === 'school_admin') {
        $stmt = $pdo->prepare("SELECT school_id FROM classes WHERE id = ?");
        $stmt->execute([$class_id]);
        $class = $stmt->fetch();

        return $class && $class['school_id'] == getCurrentUserSchoolId();
    }

    // Учитель может управлять классами где он преподает
    if ($user_role === 'class_teacher') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE id = ? AND school_id = ? AND class_teacher_id = ?");
        $stmt->execute([$class_id, getCurrentUserSchoolId(), $user_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return true;
        }
    }

    if (in_array($user_role, ['teacher', 'class_teacher'])) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM classes
            WHERE id = ? AND school_id = ? AND is_active = 1
        ");
        $stmt->execute([$class_id, getCurrentUserSchoolId()]);
        $result = $stmt->fetch();

        return $result && $result['count'] > 0;
    }

    return false;
}

/**
 * Проверка что пользователь может управлять указанным учеником
 */
function canManageStudent($student_id, $pdo) {
    $user_id = getCurrentUserId();
    $user_role = getCurrentUserRole();

    // Супер администратор может управлять всеми учениками
    if ($user_role === 'super_admin') {
        return true;
    }

    // Администратор школы может управлять учениками своей школы
    if ($user_role === 'school_admin') {
        $stmt = $pdo->prepare("SELECT school_id FROM users WHERE id = ?");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch();

        return $student && $student['school_id'] == getCurrentUserSchoolId();
    }

    // Учитель может управлять учениками из своих классов
    if (in_array($user_role, ['teacher', 'class_teacher'])) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM users u
            JOIN schedule s ON u.class_id = s.class_id
            WHERE u.id = ? AND s.teacher_id = ?
        ");
        $stmt->execute([$student_id, $user_id]);
        $result = $stmt->fetch();

        return $result && $result['count'] > 0;
    }

    // Родитель может управлять только своими детьми
    if ($user_role === 'parent') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM student_parent_links 
            WHERE student_id = ? AND parent_id = ?
        ");
        $stmt->execute([$student_id, $user_id]);
        $result = $stmt->fetch();

        return $result && $result['count'] > 0;
    }

    // Ученик может управлять только собой
    if ($user_role === 'student') {
        return $student_id == $user_id;
    }

    return false;
}

/**
 * Проверка что пользователь может выставлять оценки по указанному предмету
 */
function canGradeSubject($subject_id, $pdo) {
    $user_id = getCurrentUserId();
    $user_role = getCurrentUserRole();

    if (!in_array($user_role, ['teacher', 'class_teacher'])) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM schedule 
        WHERE subject_id = ? AND teacher_id = ?
    ");
    $stmt->execute([$subject_id, $user_id]);
    $result = $stmt->fetch();

    return $result && $result['count'] > 0;
}

/**
 * Проверка что пользователь может просматривать журнал класса
 */
function canViewClassJournal($class_id, $pdo) {
    $user_id = getCurrentUserId();
    $user_role = getCurrentUserRole();

    // Супер администратор может просматривать все журналы
    if ($user_role === 'super_admin') {
        return true;
    }

    // Администратор школы может просматривать журналы своей школы
    if ($user_role === 'school_admin') {
        $stmt = $pdo->prepare("SELECT school_id FROM classes WHERE id = ?");
        $stmt->execute([$class_id]);
        $class = $stmt->fetch();

        return $class && $class['school_id'] == getCurrentUserSchoolId();
    }

    // Учитель может просматривать журналы своих классов
    if (in_array($user_role, ['teacher', 'class_teacher'])) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM schedule 
            WHERE class_id = ? AND teacher_id = ?
        ");
        $stmt->execute([$class_id, $user_id]);
        $result = $stmt->fetch();

        return $result && $result['count'] > 0;
    }

    // Классный руководитель может просматривать журнал своего класса
    if ($user_role === 'class_teacher') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM classes 
            WHERE id = ? AND class_teacher_id = ?
        ");
        $stmt->execute([$class_id, $user_id]);
        $result = $stmt->fetch();

        return $result && $result['count'] > 0;
    }

    return false;
}

/**
 * Логирование действий пользователя
 */
function logUserAction($pdo, $action, $details = null) {
    $user_id = getCurrentUserId();

    try {
        $stmt = $pdo->prepare("
            INSERT INTO user_logs (user_id, action, details, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $user_id,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (PDOException $e) {
        // Логируем ошибку логирования, но не прерываем выполнение
        error_log("Ошибка при логировании действия пользователя: " . $e->getMessage());
    }
}

/**
 * Проверка CSRF токена
 */
function verifyCsrfToken() {
    verify_csrf_token();
}

/**
 * Генерация CSRF токена
 */
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Получение списка разрешенных действий для текущей роли
 */
function getAllowedActions($role) {
    if ($role === getCurrentUserRole() && isset($_SESSION['role_permissions'])) {
        return $_SESSION['role_permissions'];
    }

    try {
        $stmt = getDatabaseConnection()->prepare('SELECT permissions FROM roles WHERE name = ? LIMIT 1');
        $stmt->execute([$role]);
        $permissions = json_decode((string)$stmt->fetchColumn(), true);
        return is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [];
    } catch (Throwable $e) {
        error_log('Role permissions lookup failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Проверка разрешения на действие
 */
function hasPermission($action) {
    $role = getCurrentUserRole();
    $allowed_actions = getAllowedActions($role);

    if (in_array($action, $allowed_actions, true)) {
        return true;
    }

    $viewManageAliases = [
        'view_schools' => 'manage_schools',
        'view_users' => 'manage_users',
        'view_roles' => 'manage_roles',
        'view_curriculum' => 'manage_curriculum',
        'view_classes' => 'manage_classes',
        'view_teachers' => 'manage_teachers',
        'view_students' => 'manage_students',
        'view_parents' => 'manage_parents',
        'view_schedule' => 'manage_schedule',
        'view_academic_periods' => 'manage_academic_periods',
        'view_grade_types' => 'manage_grade_types',
        'view_grade_weights' => 'manage_grade_weights',
    ];
    $legacyAliases = [
        'manage_grades' => ['grade_students'],
        'manage_homework' => ['create_homework'],
        'view_reports' => ['view_school_reports', 'view_subject_reports', 'view_all_reports'],
    ];

    if (isset($viewManageAliases[$action]) && in_array($viewManageAliases[$action], $allowed_actions, true)) {
        return true;
    }
    foreach ($legacyAliases[$action] ?? [] as $alias) {
        if (in_array($alias, $allowed_actions, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Проверка и редирект если нет разрешения
 */
function requirePermission($action) {
    if (!hasPermission($action)) {
        $_SESSION['error_message'] = "У вас нет прав для выполнения этого действия.";
        http_response_code(403);
        exit;
    }
}

function enforcePagePermission(): void
{
    $pagePermissions = [
        'admin/super_dashboard.php' => ['view_dashboard', 'view_dashboard'],
        'admin/academic_periods.php' => ['view_academic_periods', 'manage_academic_periods'],
        'admin/audit_logs.php' => ['view_audit_logs', 'view_audit_logs'],
        'admin/curriculum.php' => ['view_curriculum', 'manage_curriculum'],
        'admin/profile.php' => ['manage_profile', 'manage_profile'],
        'admin/reports.php' => ['view_reports', 'generate_reports'],
        'admin/roles.php' => ['view_roles', 'manage_roles'],
        'admin/schools.php' => ['view_schools', 'manage_schools'],
        'admin/users.php' => ['view_users', 'manage_users'],
        'school_admin/dashboard.php' => ['view_dashboard', 'view_dashboard'],
        'school_admin/academic_periods.php' => ['view_academic_periods', 'manage_academic_periods'],
        'school_admin/classes.php' => ['view_classes', 'manage_classes'],
        'school_admin/teachers.php' => ['view_teachers', 'manage_teachers'],
        'school_admin/students.php' => ['view_students', 'manage_students'],
        'school_admin/parents.php' => ['view_parents', 'manage_parents'],
        'school_admin/schedule.php' => ['view_schedule', 'manage_schedule'],
        'school_admin/grade_types.php' => ['view_grade_types', 'manage_grade_types'],
        'school_admin/grade_weights.php' => ['view_grade_weights', 'manage_grade_weights'],
        'school_admin/reports.php' => ['view_reports', 'generate_reports'],
        'profile.php' => ['manage_profile', 'manage_profile'],
        'teacher/dashboard.php' => ['view_dashboard', 'view_dashboard'],
        'teacher/calendar.php' => ['view_dashboard', 'view_dashboard'],
        'teacher/class_journal.php' => ['view_class_journal', 'manage_attendance'],
        'teacher/grades.php' => ['view_grades', 'manage_grades'],
        'teacher/homework.php' => ['view_homework', 'manage_homework'],
        'teacher/reports.php' => ['view_reports', 'generate_reports'],
        'teacher/reports_advanced.php' => ['view_reports', 'generate_reports'],
        'teacher/schedule.php' => ['view_schedule', 'view_schedule'],
        'student/dashboard.php' => ['view_dashboard', 'view_dashboard'],
        'student/grades.php' => ['view_grades', 'view_grades'],
        'student/homework.php' => ['view_homework', 'view_homework'],
        'student/schedule.php' => ['view_schedule', 'view_schedule'],
        'student/subjects.php' => ['view_subjects', 'view_subjects'],
        'parent/dashboard.php' => ['view_child_grades', 'view_child_grades'],
    ];

    $scriptName = parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH) ?: '';
    $scriptName = str_replace('\\', '/', $scriptName);
    $parentDirectory = basename(dirname($scriptName));
    $topLevelDirectories = ['admin', 'school_admin', 'teacher', 'student', 'parent', 'reports'];
    $route = in_array($parentDirectory, $topLevelDirectories, true)
        ? $parentDirectory . '/' . basename($scriptName)
        : basename($scriptName);
    if (!isset($pagePermissions[$route])) {
        return;
    }

    $permissionIndex = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 1 : 0;
    requirePermission($pagePermissions[$route][$permissionIndex]);
}
?>
