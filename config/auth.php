<?php
// config/auth.php

/**
 * Проверка авторизации пользователя
 */
function requireAuth() {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
        header('Location: ../login.php');
        exit;
    }

    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 28800) {
        session_unset();
        session_destroy();
        header('Location: ../login.php?error=session_expired');
        exit;
    }

    try {
        $pdo = getDatabaseConnection();
        $stmt = $pdo->prepare("\n            SELECT u.*, r.name AS role_name, s.full_name AS school_name\n            FROM users u\n            JOIN roles r ON r.id = u.role_id\n            LEFT JOIN schools s ON s.id = u.school_id\n            WHERE u.id = ? AND u.is_active = 1\n            LIMIT 1\n        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Auth check failed: ' . $e->getMessage());
        session_unset();
        session_destroy();
        header('Location: ../login.php?error=auth_unavailable');
        exit;
    }

    if (!$user || $user['role_name'] !== $_SESSION['user_role']) {
        session_unset();
        session_destroy();
        header('Location: ../login.php?error=session_invalid');
        exit;
    }

    $_SESSION['user_role'] = $user['role_name'];
    $_SESSION['user_school_id'] = $user['school_id'];
    $_SESSION['school_name'] = $user['school_name'] ?? '';
    $_SESSION['last_activity'] = time();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
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
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Проверка прав администратора школы
 */
function requireSchoolAdmin() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'school_admin') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права администратора школы.";
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Проверка прав учителя
 */
function requireTeacher() {
    requireAuth();
    if (!in_array($_SESSION['user_role'], ['teacher', 'class_teacher'])) {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права учителя.";
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Проверка прав классного руководителя
 */
function requireClassTeacher() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'class_teacher') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права классного руководителя.";
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Проверка прав ученика
 */
function requireStudent() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'student') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права ученика.";
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Проверка прав родителя
 */
function requireParent() {
    requireAuth();
    if ($_SESSION['user_role'] !== 'parent') {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права родителя.";
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Проверка что пользователь является учителем или классным руководителем
 */
function requireTeacherOrClassTeacher() {
    requireAuth();
    if (!in_array($_SESSION['user_role'], ['teacher', 'class_teacher'])) {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права учителя или классного руководителя.";
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Проверка что пользователь является администратором (любого уровня)
 */
function requireAnyAdmin() {
    requireAuth();
    if (!in_array($_SESSION['user_role'], ['super_admin', 'school_admin'])) {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права администратора.";
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Проверка что пользователь является сотрудником школы (админ или учитель)
 */
function requireSchoolStaff() {
    requireAuth();
    if (!in_array($_SESSION['user_role'], ['super_admin', 'school_admin', 'teacher', 'class_teacher'])) {
        $_SESSION['error_message'] = "Доступ запрещен. Требуются права сотрудника школы.";
        header('Location: ../login.php');
        exit;
    }
}

/**
 * Получение текущей роли пользователя
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
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
        header('Location: ../login.php');
        exit;
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!$token || $token !== ($_SESSION['csrf_token'] ?? '')) {
            $_SESSION['error_message'] = "Ошибка безопасности. Недействительный токен.";
            header('Location: ' . $_SERVER['HTTP_REFERER'] ?? '../login.php');
            exit;
        }
    }
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
    $actions = [
        'super_admin' => [
            'manage_schools', 'manage_users', 'manage_roles', 'view_all_reports',
            'system_config', 'backup_restore', 'audit_logs'
        ],
        'school_admin' => [
            'manage_classes', 'manage_teachers', 'manage_students', 'manage_parents',
            'manage_schedule', 'manage_curriculum', 'view_school_reports'
        ],
        'class_teacher' => [
            'view_class_journal', 'manage_class_attendance', 'contact_parents',
            'create_class_events', 'view_student_profiles'
        ],
        'teacher' => [
            'grade_students', 'create_homework', 'manage_attendance',
            'upload_materials', 'view_subject_reports'
        ],
        'student' => [
            'view_grades', 'view_homework', 'view_schedule', 'view_materials'
        ],
        'parent' => [
            'view_child_grades', 'view_child_attendance', 'contact_teachers'
        ]
    ];

    return $actions[$role] ?? [];
}

/**
 * Проверка разрешения на действие
 */
function hasPermission($action) {
    $role = getCurrentUserRole();
    $allowed_actions = getAllowedActions($role);

    return in_array($action, $allowed_actions);
}

/**
 * Проверка и редирект если нет разрешения
 */
function requirePermission($action) {
    if (!hasPermission($action)) {
        $_SESSION['error_message'] = "У вас нет прав для выполнения этого действия.";
        header('Location: ../login.php');
        exit;
    }
}
?>
