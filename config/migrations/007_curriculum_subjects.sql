-- Add normalized curriculum data and class subject assignments.
SET @migration_ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'curriculum' AND COLUMN_NAME = 'academic_year') = 0,
  'ALTER TABLE `curriculum` ADD COLUMN `academic_year` VARCHAR(20) DEFAULT NULL AFTER `school_id`',
  'SELECT 1'
);
PREPARE migration_statement FROM @migration_ddl;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

CREATE TABLE IF NOT EXISTS `curriculum_subjects` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `curriculum_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `grade_level` INT NOT NULL,
  `hours_per_week` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_curriculum_subjects_plan_subject_grade` (`curriculum_id`, `subject_id`, `grade_level`),
  KEY `idx_curriculum_subjects_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `class_subjects` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `teacher_id` INT DEFAULT NULL,
  `hours_per_week` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_class_subjects_school_class_subject` (`school_id`, `class_id`, `subject_id`),
  KEY `idx_class_subjects_subject` (`school_id`, `subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `roles`
SET `permissions` = JSON_ARRAY_APPEND(COALESCE(`permissions`, JSON_ARRAY()), '$', 'view_academic_periods')
WHERE `name` = 'school_admin'
  AND JSON_CONTAINS(COALESCE(`permissions`, JSON_ARRAY()), JSON_QUOTE('view_academic_periods')) = 0;

UPDATE `roles`
SET `permissions` = JSON_ARRAY_APPEND(COALESCE(`permissions`, JSON_ARRAY()), '$', 'manage_academic_periods')
WHERE `name` = 'school_admin'
  AND JSON_CONTAINS(COALESCE(`permissions`, JSON_ARRAY()), JSON_QUOTE('manage_academic_periods')) = 0;

UPDATE `roles`
SET `permissions` = JSON_ARRAY_APPEND(COALESCE(`permissions`, JSON_ARRAY()), '$', 'view_curriculum')
WHERE `name` = 'school_admin'
  AND JSON_CONTAINS(COALESCE(`permissions`, JSON_ARRAY()), JSON_QUOTE('view_curriculum')) = 0;

UPDATE `roles`
SET `permissions` = JSON_ARRAY_APPEND(COALESCE(`permissions`, JSON_ARRAY()), '$', 'manage_curriculum')
WHERE `name` = 'school_admin'
  AND JSON_CONTAINS(COALESCE(`permissions`, JSON_ARRAY()), JSON_QUOTE('manage_curriculum')) = 0;

DROP PROCEDURE IF EXISTS `migrate_curriculum_subjects_007`;
DELIMITER //
CREATE PROCEDURE `migrate_curriculum_subjects_007`()
BEGIN
  DECLARE v_done TINYINT DEFAULT 0;
  DECLARE v_curriculum_id INT;
  DECLARE v_school_id INT;
  DECLARE v_grades LONGTEXT;
  DECLARE v_subjects LONGTEXT;
  DECLARE v_grade_index INT;
  DECLARE v_subject_index INT;
  DECLARE v_grade_count INT;
  DECLARE v_subject_count INT;
  DECLARE v_grade_level INT;
  DECLARE v_subject_id INT;
  DECLARE v_hours INT;
  DECLARE v_subject_name VARCHAR(255);
  DECLARE v_subject_value LONGTEXT;

  DECLARE curriculum_cursor CURSOR FOR
    SELECT `id`, `school_id`, `grades`, `subjects` FROM `curriculum`;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

  OPEN curriculum_cursor;
  curriculum_loop: LOOP
    FETCH curriculum_cursor INTO v_curriculum_id, v_school_id, v_grades, v_subjects;
    IF v_done = 1 THEN
      LEAVE curriculum_loop;
    END IF;

    IF JSON_VALID(v_grades) AND JSON_TYPE(v_grades) = 'ARRAY'
      AND JSON_VALID(v_subjects) AND JSON_TYPE(v_subjects) = 'ARRAY' THEN
      SET v_grade_count = JSON_LENGTH(v_grades);
      SET v_subject_count = JSON_LENGTH(v_subjects);
      SET v_grade_index = 0;

      WHILE v_grade_index < v_grade_count DO
        SET v_grade_level = CAST(JSON_UNQUOTE(JSON_EXTRACT(v_grades, CONCAT('$[', v_grade_index, ']'))) AS UNSIGNED);
        IF v_grade_level BETWEEN 1 AND 11 THEN
          SET v_subject_index = 0;
          WHILE v_subject_index < v_subject_count DO
            SET v_subject_value = JSON_EXTRACT(v_subjects, CONCAT('$[', v_subject_index, ']'));
            IF JSON_TYPE(v_subject_value) = 'OBJECT' THEN
              SET v_subject_name = TRIM(JSON_UNQUOTE(JSON_EXTRACT(v_subject_value, '$.name')));
            ELSE
              SET v_subject_name = TRIM(JSON_UNQUOTE(v_subject_value));
            END IF;
            SET v_hours = 1;
            SET v_subject_id = (
              SELECT MIN(`id`) FROM `subjects`
              WHERE `school_id` = v_school_id AND LOWER(`name`) = LOWER(v_subject_name)
            );
            IF v_subject_name <> '' AND v_subject_id IS NOT NULL THEN
              INSERT IGNORE INTO `curriculum_subjects`
                (`curriculum_id`, `subject_id`, `grade_level`, `hours_per_week`)
              VALUES (v_curriculum_id, v_subject_id, v_grade_level, v_hours);
            END IF;
            SET v_subject_index = v_subject_index + 1;
          END WHILE;
        END IF;
        SET v_grade_index = v_grade_index + 1;
      END WHILE;
    END IF;
  END LOOP;
  CLOSE curriculum_cursor;
END//
DELIMITER ;

CALL `migrate_curriculum_subjects_007`();
DROP PROCEDURE `migrate_curriculum_subjects_007`;
