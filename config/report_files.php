<?php
function reportFileMimeTypes(): array
{
    return [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed'
        ],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/x-zip',
            'application/x-zip-compressed'
        ],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif']
    ];
}

function reportMimeTypeForExtension(string $extension): ?string
{
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif'
    ];

    return $mimeTypes[strtolower($extension)] ?? null;
}

function reportStorageDirectory(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'school_diary_uploads' . DIRECTORY_SEPARATOR . 'reports';
}

function reportLegacyStorageDirectory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'reports';
}

function reportFilePath(string $filename): ?string
{
    if ($filename === '' || basename($filename) !== $filename || strpos($filename, "\0") !== false) {
        return null;
    }

    foreach ([reportStorageDirectory(), reportLegacyStorageDirectory()] as $directory) {
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            continue;
        }

        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        if ($realDirectory !== false && $realPath !== false
            && strpos($realPath, $realDirectory . DIRECTORY_SEPARATOR) === 0) {
            return $realPath;
        }
    }

    return null;
}

function saveReportUpload(PDO $pdo, array $file, string $description, int $uploadedBy, bool $allowImages = false): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер, разрешенный сервером',
        UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер, указанный в форме',
        UPLOAD_ERR_PARTIAL => 'Файл был загружен только частично',
        UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
        UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
        UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
        UPLOAD_ERR_EXTENSION => 'Расширение PHP остановило загрузку файла'
    ];
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
        $message = is_int($error) ? ($messages[$error] ?? 'Неизвестная ошибка загрузки') : 'Некорректные данные файла';
        throw new RuntimeException('Ошибка загрузки файла: ' . $message);
    }

    $temporaryPath = $file['tmp_name'] ?? '';
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Не удалось проверить загруженный файл');
    }

    $clientName = $file['name'] ?? '';
    if (!is_string($clientName)) {
        throw new RuntimeException('Некорректное имя файла');
    }
    $originalName = basename(str_replace('\\', '/', $clientName));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedTypes = reportFileMimeTypes();
    $allowedExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
    if ($allowImages) {
        $allowedExtensions = array_merge($allowedExtensions, ['jpg', 'jpeg', 'png', 'gif']);
    }
    if ($originalName === '' || strlen($originalName) > 255
        || !in_array($extension, $allowedExtensions, true) || !isset($allowedTypes[$extension])) {
        $extensionList = $allowImages
            ? 'PDF, DOC, DOCX, XLS, XLSX, CSV, TXT, JPEG, PNG, GIF'
            : 'PDF, DOC, DOCX, XLS, XLSX, CSV, TXT';
        throw new RuntimeException('Недопустимое расширение файла. Разрешены: ' . $extensionList);
    }

    $fileSize = filesize($temporaryPath);
    if ($fileSize === false || $fileSize === 0 || $fileSize > 10 * 1024 * 1024) {
        throw new RuntimeException('Файл пустой или слишком большой. Максимальный размер: 10MB');
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('На сервере недоступна проверка MIME-типа файлов');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($temporaryPath);
    if ($mimeType === false || !in_array($mimeType, $allowedTypes[$extension], true)) {
        throw new RuntimeException('Тип содержимого файла не соответствует его расширению');
    }

    $directory = reportStorageDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать защищенную папку для файлов');
    }
    if (DIRECTORY_SEPARATOR !== '\\' && !chmod($directory, 0755)) {
        throw new RuntimeException('Не удалось установить права доступа к папке файлов');
    }
    if (!is_writable($directory)) {
        throw new RuntimeException('Папка для файлов недоступна для записи');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('Не удалось сохранить файл на сервере');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO report_files (filename, original_name, file_size, file_type, description, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$filename, $originalName, $fileSize, $mimeType, $description, $uploadedBy]);
    } catch (PDOException $e) {
        if (!unlink($destination)) {
            error_log('Не удалось удалить файл отчета после ошибки базы данных: ' . $destination);
        }
        error_log('Ошибка сохранения метаданных отчета: ' . $e->getMessage());
        throw new RuntimeException('Не удалось сохранить информацию о файле');
    }

    return $originalName;
}

function removeReportFile(string $filename): void
{
    if ($filename === '' || basename($filename) !== $filename || strpos($filename, "\0") !== false) {
        throw new RuntimeException('Некорректное имя файла');
    }

    foreach ([reportStorageDirectory(), reportLegacyStorageDirectory()] as $directory) {
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Не удалось удалить файл с сервера');
        }
    }
}

function handleReportFileActions(
    PDO $pdo,
    int $userId,
    string $context,
    bool $allowImages = false,
    bool $canDeleteAny = false
): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $file = $_FILES['report_file'] ?? null;
    if ($file !== null && (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)) {
        try {
            if (!is_array($file)) {
                throw new RuntimeException('Некорректные данные файла');
            }
            $description = isset($_POST['description']) && is_string($_POST['description'])
                ? trim($_POST['description'])
                : '';
            $originalName = saveReportUpload($pdo, $file, $description, $userId, $allowImages);
            $_SESSION['success_message'] = "Файл '{$originalName}' успешно загружен!";
        } catch (RuntimeException $e) {
            $_SESSION['error_message'] = publicErrorMessage($e, $context);
        }

        header('Location: reports.php');
        exit;
    }

    if (!isset($_POST['delete_file'])) {
        return;
    }

    $fileId = is_scalar($_POST['delete_file'])
        ? filter_var((string)$_POST['delete_file'], FILTER_VALIDATE_INT)
        : false;
    $notFoundMessage = $canDeleteAny
        ? 'Файл не найден'
        : 'Файл не найден или у вас нет прав для его удаления';

    if ($fileId === false || $fileId < 1) {
        $_SESSION['error_message'] = $notFoundMessage;
        header('Location: reports.php');
        exit;
    }

    try {
        $sql = 'SELECT filename FROM report_files WHERE id = ?';
        $params = [$fileId];
        if (!$canDeleteAny) {
            $sql .= ' AND uploaded_by = ?';
            $params[] = $userId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $file = $stmt->fetch();

        if ($file) {
            removeReportFile((string)$file['filename']);
            $deleteStmt = $pdo->prepare('DELETE FROM report_files WHERE id = ?');
            $deleteStmt->execute([$fileId]);
            $_SESSION['success_message'] = 'Файл успешно удален!';
        } else {
            $_SESSION['error_message'] = $notFoundMessage;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = publicErrorMessage($e, $context);
    }

    header('Location: reports.php');
    exit;
}
