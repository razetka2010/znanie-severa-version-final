<?php
function ensureHomeworkCompletionSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS homework_completion (
        id INT NOT NULL AUTO_INCREMENT,
        homework_id INT NOT NULL,
        student_id INT NOT NULL,
        status ENUM('not_done','done','late') NOT NULL DEFAULT 'not_done',
        submitted_at TIMESTAMP NULL DEFAULT NULL,
        student_comment TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_homework_student (homework_id, student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM homework_completion')->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $columns[$column] = true;
    }

    if (!isset($columns['status'])) {
        $pdo->exec("ALTER TABLE homework_completion ADD COLUMN status ENUM('not_done','done','late') NOT NULL DEFAULT 'not_done' AFTER student_id");
    }
    if (!isset($columns['submitted_at'])) {
        $pdo->exec("ALTER TABLE homework_completion ADD COLUMN submitted_at TIMESTAMP NULL DEFAULT NULL AFTER status");
    }
    if (!isset($columns['student_comment'])) {
        $pdo->exec("ALTER TABLE homework_completion ADD COLUMN student_comment TEXT NULL AFTER submitted_at");
    }
}
