-- Associate homework with the lesson it was assigned for.
SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'homework' AND COLUMN_NAME = 'lesson_date') = 0,
  'ALTER TABLE `homework` ADD COLUMN `lesson_date` DATE DEFAULT NULL AFTER `due_date`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'homework' AND INDEX_NAME = 'idx_homework_class_subject_lesson_date') = 0,
  'ALTER TABLE `homework` ADD KEY `idx_homework_class_subject_lesson_date` (`class_id`, `subject_id`, `lesson_date`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
