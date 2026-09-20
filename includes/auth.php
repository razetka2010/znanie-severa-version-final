<?php
require_once __DIR__ . '/../config/auth.php';

function checkAuth(): bool
{
    if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
        return false;
    }

    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 28800) {
        session_unset();
        session_destroy();
        return false;
    }

    return true;
}

function getCurrentUser(): ?array
{
    if (!checkAuth()) {
        return null;
    }

    return [
        'id' => $_SESSION['user_id'],
        'login' => $_SESSION['user_login'] ?? '',
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['user_role'],
        'school_id' => $_SESSION['user_school_id'] ?? null,
        'school_name' => $_SESSION['school_name'] ?? ''
    ];
}

function hasAccessToSchool($school_id): bool
{
    $user = getCurrentUser();
    return $user && ($user['role'] === 'super_admin' || $user['school_id'] == $school_id);
}

function logout(): void
{
    session_unset();
    session_destroy();
    header('Location: ../login.php');
    exit;
}
