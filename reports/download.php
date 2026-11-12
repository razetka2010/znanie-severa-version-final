<?php
require_once __DIR__ . '/../config/session.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/report_files.php';

requireAuth();

$role = $_SESSION['user_role'];
$userId = (int)$_SESSION['user_id'];
$schoolId = $_SESSION['user_school_id'] ?? null;
if (!in_array($role, ['super_admin', 'school_admin', 'teacher', 'class_teacher'], true)) {
    http_response_code(403);
    exit('Доступ запрещен');
}

$fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$fileId || $fileId < 1) {
    http_response_code(404);
    exit('Файл не найден');
}

$pdo = getDatabaseConnection();
$stmt = $pdo->prepare("
    SELECT rf.filename, rf.original_name, rf.uploaded_by, u.school_id AS uploader_school_id,
           r.name AS uploader_role
    FROM report_files rf
    JOIN users u ON u.id = rf.uploaded_by
    JOIN roles r ON r.id = u.role_id
    WHERE rf.id = ?
");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

$canDownload = false;
if ($file) {
    if ($role === 'super_admin') {
        $canDownload = true;
    } elseif ($role === 'school_admin') {
        $canDownload = $schoolId !== null
            && ($file['uploader_role'] === 'super_admin' || (string)$file['uploader_school_id'] === (string)$schoolId);
    } else {
        $canDownload = (int)$file['uploaded_by'] === $userId;
    }
}

if (!$canDownload) {
    http_response_code(404);
    exit('Файл не найден');
}

$filename = (string)($file['filename'] ?? '');
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$contentType = reportMimeTypeForExtension($extension);
$path = $contentType === null ? null : reportFilePath($filename);
if ($path === null || !is_readable($path)) {
    error_log('Файл отчета отсутствует или недоступен для чтения: ' . $fileId);
    http_response_code(404);
    exit('Файл не найден');
}

$originalName = basename(str_replace('\\', '/', (string)$file['original_name']));
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
if ($fallbackName === '' || $fallbackName === null) {
    $fallbackName = 'report.' . $extension;
}

header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($originalName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
session_write_close();
readfile($path);
