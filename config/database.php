<?php
function getDatabaseConnection(bool $throwOnError = false) {
    $localConfigPath = __DIR__ . '/database.local.php';
    $config = [];
    if (is_file($localConfigPath)) {
        $localConfig = require $localConfigPath;
        if (!is_array($localConfig)) {
            error_log('Local database configuration must return an array.');
            http_response_code(500);
            die('Не удалось подключиться к базе данных. Проверьте конфигурацию сервера.');
        }
        $config = $localConfig;
    }

    $environmentVariables = [
        'host' => 'DB_HOST',
        'port' => 'DB_PORT',
        'dbname' => 'DB_NAME',
        'username' => 'DB_USER',
        'password' => 'DB_PASSWORD',
    ];
    foreach ($environmentVariables as $key => $variable) {
        $value = getenv($variable);
        if ($value !== false) {
            $config[$key] = $value;
        }
    }

    $host = $config['host'] ?? null;
    $port = $config['port'] ?? '3306';
    $dbname = $config['dbname'] ?? null;
    $username = $config['username'] ?? null;
    $password = $config['password'] ?? null;

    if (!is_string($host) || $host === '' || !is_string($dbname) || $dbname === ''
        || !is_string($username) || $username === ''
        || (!is_string($password) && !is_int($password))) {
        $message = 'Database configuration is incomplete. Set DB_HOST, DB_NAME, DB_USER, and DB_PASSWORD or create config/database.local.php.';
        error_log($message);
        if ($throwOnError) {
            throw new RuntimeException($message);
        }
        http_response_code(500);
        die('Не удалось подключиться к базе данных. Проверьте конфигурацию сервера.');
    }

    if ((!is_string($port) && !is_int($port)) || !ctype_digit((string)$port)
        || (int)$port < 1 || (int)$port > 65535) {
        $message = 'Database configuration contains an invalid DB_PORT.';
        error_log($message);
        if ($throwOnError) {
            throw new RuntimeException($message);
        }
        http_response_code(500);
        die('Не удалось подключиться к базе данных. Проверьте конфигурацию сервера.');
    }

    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log('Ошибка подключения к базе данных: ' . $e->getMessage());
        if ($throwOnError) {
            throw $e;
        }
        http_response_code(500);
        die('Не удалось подключиться к базе данных. Попробуйте позже.');
    }
}
?>
