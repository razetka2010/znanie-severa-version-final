-- Apply once to an existing installation after taking a database backup.
-- Existing grade types remain unlinked and therefore use weight 1.00.

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_types' AND COLUMN_NAME = 'default_weight_id') = 0,
  'ALTER TABLE `grade_types` ADD COLUMN `default_weight_id` INT NULL DEFAULT NULL AFTER `max_score`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_weights' AND INDEX_NAME = 'uq_grade_weights_school_id_id') = 0,
  'ALTER TABLE `grade_weights` ADD UNIQUE KEY `uq_grade_weights_school_id_id` (`school_id`, `id`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_types' AND INDEX_NAME = 'idx_grade_types_default_weight') = 0,
  'ALTER TABLE `grade_types` ADD KEY `idx_grade_types_default_weight` (`school_id`, `default_weight_id`)',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_types' AND CONSTRAINT_NAME = 'fk_grade_types_default_weight') = 0,
  'ALTER TABLE `grade_types` ADD CONSTRAINT `fk_grade_types_default_weight` FOREIGN KEY (`school_id`, `default_weight_id`) REFERENCES `grade_weights` (`school_id`, `id`) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;
