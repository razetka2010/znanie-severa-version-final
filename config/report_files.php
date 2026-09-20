<?php
function ensureReportFilesSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS report_files (
        id INT NOT NULL AUTO_INCREMENT,
        filename VARCHAR(255) NULL,
        original_name VARCHAR(255) NULL,
        file_size INT NULL,
        file_type VARCHAR(100) NULL,
        description TEXT NULL,
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM report_files')->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[$column] = true;
    }

    $missing_columns = [
        'filename' => "ALTER TABLE report_files ADD COLUMN filename VARCHAR(255) NULL AFTER id",
        'original_name' => "ALTER TABLE report_files ADD COLUMN original_name VARCHAR(255) NULL AFTER filename",
        'file_size' => "ALTER TABLE report_files ADD COLUMN file_size INT NULL AFTER original_name",
        'description' => "ALTER TABLE report_files ADD COLUMN description TEXT NULL AFTER file_type"
    ];

    foreach ($missing_columns as $column => $sql) {
        if (!isset($columns[$column])) {
            $pdo->exec($sql);
        }
    }

    // Legacy schema used mandatory file_name/file_path columns that the application does not need.
    foreach (['file_name', 'file_path'] as $legacy_column) {
        if (isset($columns[$legacy_column])) {
            $pdo->exec("ALTER TABLE report_files MODIFY COLUMN {$legacy_column} VARCHAR(500) NULL");
        }
    }
}
