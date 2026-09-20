<?php
session_start();

$role = $_SESSION['user_role'] ?? null;
$redirectTarget = 'login.php';

if ($role === 'super_admin') {
    $redirectTarget = 'admin/super_dashboard.php';
} elseif ($role === 'school_admin') {
    $redirectTarget = 'school_admin/dashboard.php';
} elseif (in_array($role, ['teacher', 'class_teacher'], true)) {
    $redirectTarget = 'teacher/dashboard.php';
} elseif ($role === 'student') {
    $redirectTarget = 'student/dashboard.php';
} elseif ($role === 'parent') {
    $redirectTarget = 'parent/dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ запрещён</title>
    <style>
        body { margin:0; font-family: Arial, sans-serif; background: linear-gradient(135deg, #1f2937, #4f46e5); display:flex; align-items:center; justify-content:center; min-height:100vh; }
        .box { background:#fff; border-radius:16px; padding:32px; text-align:center; max-width:450px; width:90%; box-shadow:0 18px 35px rgba(0,0,0,0.2); }
        .icon { font-size:62px; margin-bottom:16px; }
        .btn { display:inline-block; margin-top:18px; padding:12px 20px; border-radius:10px; text-decoration:none; background:#2563eb; color:#fff; }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon">🚫</div>
        <h1>Доступ запрещён</h1>
        <p>У вас недостаточно прав для просмотра этой страницы.</p>
        <a class="btn" href="<?php echo htmlspecialchars($redirectTarget); ?>">Вернуться в систему</a>
    </div>
</body>
</html>
