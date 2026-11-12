-- Apply once to an existing installation after taking a database backup.
-- New installations should import config/schema.sql instead.

CREATE TABLE IF NOT EXISTS `teachers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `subjects` TEXT DEFAULT NULL,
  `qualification` VARCHAR(255) DEFAULT NULL,
  `experience_years` INT DEFAULT NULL,
  `education` TEXT DEFAULT NULL,
  `specialization` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teachers_school_user` (`user_id`, `school_id`),
  KEY `idx_teachers_school_id` (`school_id`),
  CONSTRAINT `fk_teachers_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_teachers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classes' AND COLUMN_NAME = 'is_active') = 0,
  'ALTER TABLE `classes` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `class_teacher_id`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'classes' AND COLUMN_NAME = 'updated_at') = 0,
  'ALTER TABLE `classes` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedule' AND COLUMN_NAME = 'lesson_date') = 0,
  'ALTER TABLE `schedule` ADD COLUMN `lesson_date` DATE NULL AFTER `teacher_id`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grades' AND COLUMN_NAME = 'grade_type_id') = 0,
  'ALTER TABLE `grades` ADD COLUMN `grade_type_id` INT NULL AFTER `grade_value`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grades' AND COLUMN_NAME = 'grade_weight_id') = 0,
  'ALTER TABLE `grades` ADD COLUMN `grade_weight_id` INT NULL AFTER `grade_type_id`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'homework' AND COLUMN_NAME = 'title') = 0,
  'ALTER TABLE `homework` ADD COLUMN `title` VARCHAR(255) NOT NULL DEFAULT ''Домашнее задание'' AFTER `subject_id`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS `homework_completion` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `homework_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `status` ENUM('not_done','done','late') NOT NULL DEFAULT 'not_done',
  `submitted_at` TIMESTAMP NULL DEFAULT NULL,
  `student_comment` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_homework_student` (`homework_id`, `student_id`),
  CONSTRAINT `fk_homework_completion_homework` FOREIGN KEY (`homework_id`) REFERENCES `homework` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_homework_completion_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'homework_completion' AND COLUMN_NAME = 'status') = 0,
  'ALTER TABLE `homework_completion` ADD COLUMN `status` ENUM(''not_done'',''done'',''late'') NOT NULL DEFAULT ''not_done'' AFTER `student_id`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'homework_completion' AND COLUMN_NAME = 'submitted_at') = 0,
  'ALTER TABLE `homework_completion` ADD COLUMN `submitted_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'homework_completion' AND COLUMN_NAME = 'student_comment') = 0,
  'ALTER TABLE `homework_completion` ADD COLUMN `student_comment` TEXT NULL AFTER `submitted_at`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS `report_files` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `uploaded_by` INT NOT NULL,
  `filename` VARCHAR(255) DEFAULT NULL,
  `original_name` VARCHAR(255) DEFAULT NULL,
  `file_size` INT DEFAULT NULL,
  `file_type` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_report_files_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_files' AND COLUMN_NAME = 'filename') = 0,
  'ALTER TABLE `report_files` ADD COLUMN `filename` VARCHAR(255) NULL AFTER `id`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_files' AND COLUMN_NAME = 'original_name') = 0,
  'ALTER TABLE `report_files` ADD COLUMN `original_name` VARCHAR(255) NULL AFTER `filename`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_files' AND COLUMN_NAME = 'file_size') = 0,
  'ALTER TABLE `report_files` ADD COLUMN `file_size` INT NULL AFTER `original_name`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_files' AND COLUMN_NAME = 'description') = 0,
  'ALTER TABLE `report_files` ADD COLUMN `description` TEXT NULL AFTER `file_type`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_files' AND COLUMN_NAME = 'file_name' AND IS_NULLABLE = 'NO') > 0,
  'ALTER TABLE `report_files` MODIFY COLUMN `file_name` VARCHAR(500) NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report_files' AND COLUMN_NAME = 'file_path' AND IS_NULLABLE = 'NO') > 0,
  'ALTER TABLE `report_files` MODIFY COLUMN `file_path` VARCHAR(500) NULL',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

INSERT IGNORE INTO `teachers` (`school_id`, `user_id`)
SELECT u.`school_id`, u.`id`
FROM `users` u
JOIN `roles` r ON r.`id` = u.`role_id`
WHERE u.`school_id` IS NOT NULL
  AND u.`is_active` = 1
  AND r.`name` IN ('teacher', 'class_teacher');

CREATE TABLE IF NOT EXISTS `student_parent_links` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `parent_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `relationship` VARCHAR(50) NOT NULL DEFAULT 'parent',
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_parent` (`parent_id`, `student_id`),
  KEY `idx_student_parent_student` (`student_id`),
  KEY `idx_student_parent_parent` (`parent_id`),
  CONSTRAINT `fk_student_parent_links_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_student_parent_links_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_parents') > 0,
  'INSERT IGNORE INTO `student_parent_links` (`parent_id`,`student_id`,`relationship`,`is_primary`,`created_at`) SELECT `parent_id`,`student_id`,`relationship`,COALESCE(`is_primary`,0),`created_at` FROM `student_parents`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'parent_students') > 0,
  'INSERT IGNORE INTO `student_parent_links` (`parent_id`,`student_id`,`relationship`,`is_primary`,`created_at`) SELECT `parent_id`,`student_id`,`relationship`,COALESCE(`is_primary`,0),`created_at` FROM `parent_students`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;