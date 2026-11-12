-- Apply once to add period locking and date-range lookup support.
SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_periods' AND COLUMN_NAME = 'is_locked') = 0,
  'ALTER TABLE `academic_periods` ADD COLUMN `is_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_current`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'academic_periods' AND INDEX_NAME = 'idx_academic_periods_school_dates') = 0,
  'ALTER TABLE `academic_periods` ADD KEY `idx_academic_periods_school_dates` (`school_id`, `start_date`, `end_date`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
