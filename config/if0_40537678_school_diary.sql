DROP DATABASE IF EXISTS `if0_40537678_school_diary`;
CREATE DATABASE `if0_40537678_school_diary` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `if0_40537678_school_diary`;

CREATE TABLE `schools` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(255) NOT NULL,
  `short_name` VARCHAR(100) DEFAULT NULL,
  `inn` VARCHAR(20) DEFAULT NULL,
  `type` ENUM('obshcheobrazovatelnaya','gymnasium','lyceum','internat') DEFAULT 'obshcheobrazovatelnaya',
  `status` ENUM('active','inactive','archive') DEFAULT 'active',
  `legal_address` TEXT DEFAULT NULL,
  `physical_address` TEXT DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `website` VARCHAR(100) DEFAULT NULL,
  `director_name` VARCHAR(100) DEFAULT NULL,
  `license_number` VARCHAR(50) DEFAULT NULL,
  `license_date` DATE DEFAULT NULL,
  `license_issued_by` VARCHAR(255) DEFAULT NULL,
  `accreditation_number` VARCHAR(50) DEFAULT NULL,
  `accreditation_date` DATE DEFAULT NULL,
  `accreditation_until` DATE DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `roles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL,
  `permissions` JSON DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT DEFAULT NULL,
  `class_id` INT DEFAULT NULL,
  `login` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `position` VARCHAR(100) DEFAULT NULL,
  `birth_date` DATE DEFAULT NULL,
  `gender` ENUM('male','female') DEFAULT NULL,
  `work_place` VARCHAR(255) DEFAULT NULL,
  `passport_series` VARCHAR(4) DEFAULT NULL,
  `passport_number` VARCHAR(6) DEFAULT NULL,
  `snils` VARCHAR(14) DEFAULT NULL,
  `iin` VARCHAR(12) DEFAULT NULL,
  `parent_name` VARCHAR(255) DEFAULT NULL,
  `parent_phone` VARCHAR(20) DEFAULT NULL,
  `parent_email` VARCHAR(255) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `qualification` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `role_id` INT NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_users_school_id` (`school_id`),
  KEY `idx_users_role_id` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `classes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `grade_level` INT NOT NULL,
  `class_teacher_id` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_classes_school_name` (`school_id`, `name`),
  KEY `idx_classes_teacher` (`class_teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subjects` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `short_name` VARCHAR(20) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_school_name` (`school_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `academic_periods` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `is_active` TINYINT(1) DEFAULT 0,
  `is_current` TINYINT(1) DEFAULT 0,
  `academic_year` VARCHAR(20) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `curriculum` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `school_id` INT NOT NULL,
  `description` TEXT DEFAULT NULL,
  `grades` TEXT DEFAULT '[]',
  `subjects` TEXT DEFAULT '[]',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_curriculum_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grade_types` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `min_score` INT NOT NULL DEFAULT 0,
  `max_score` INT NOT NULL DEFAULT 5,
  `description` TEXT DEFAULT NULL,
  `color` VARCHAR(7) DEFAULT '#3498db',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_types_school_name` (`school_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grade_weights` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `weight` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  `description` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade_weights_school_name` (`school_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedule` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `teacher_id` INT NOT NULL,
  `lesson_date` DATE NOT NULL,
  `lesson_number` INT DEFAULT NULL,
  `room` VARCHAR(20) DEFAULT NULL,
  `is_completed` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_schedule_class_date` (`class_id`, `lesson_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `grades` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `student_id` INT NOT NULL,
  `teacher_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `grade_value` VARCHAR(10) NOT NULL,
  `grade_type_id` INT DEFAULT NULL,
  `grade_weight_id` INT DEFAULT NULL,
  `lesson_date` DATE NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_grades_student_date` (`student_id`, `lesson_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `homework` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `teacher_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `due_date` DATE DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `homework_completion` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `homework_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `status` ENUM('not_done','done','late') DEFAULT 'not_done',
  `submitted_at` TIMESTAMP NULL DEFAULT NULL,
  `student_comment` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_homework_student` (`homework_id`, `student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `attendance` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `student_id` INT NOT NULL,
  `teacher_id` INT NOT NULL,
  `lesson_date` DATE NOT NULL,
  `status` ENUM('present','absent','late') NOT NULL DEFAULT 'present',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_student_date` (`student_id`, `lesson_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_info` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `birth_date` DATE DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_info_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `student_parent_links` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `parent_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `relationship` VARCHAR(50) NOT NULL DEFAULT 'parent',
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_parent` (`parent_id`, `student_id`),
  KEY `idx_student_parent_student` (`student_id`),
  KEY `idx_student_parent_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teacher_events` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `teacher_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `event_date` DATE NOT NULL,
  `event_time` TIME DEFAULT NULL,
  `event_type` ENUM('lesson','meeting','event','reminder','exam') DEFAULT 'event',
  `class_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `teaching_materials` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `teacher_id` INT NOT NULL,
  `class_id` INT DEFAULT NULL,
  `subject_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `file_type` VARCHAR(50) DEFAULT NULL,
  `topic` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `report_files` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `uploaded_by` INT NOT NULL,
  `filename` VARCHAR(255) DEFAULT NULL,
  `original_name` VARCHAR(255) DEFAULT NULL,
  `file_size` INT DEFAULT NULL,
  `file_type` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `action` VARCHAR(255) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `session_id` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT;

ALTER TABLE `classes`
  ADD CONSTRAINT `fk_classes_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_classes_teacher` FOREIGN KEY (`class_teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `subjects`
  ADD CONSTRAINT `fk_subjects_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `academic_periods`
  ADD CONSTRAINT `fk_academic_periods_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `curriculum`
  ADD CONSTRAINT `fk_curriculum_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_curriculum_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `grade_types`
  ADD CONSTRAINT `fk_grade_types_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `grade_weights`
  ADD CONSTRAINT `fk_grade_weights_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `schedule`
  ADD CONSTRAINT `fk_schedule_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_schedule_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_schedule_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_schedule_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `grades`
  ADD CONSTRAINT `fk_grades_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_grades_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_grades_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

ALTER TABLE `homework`
  ADD CONSTRAINT `fk_homework_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_homework_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_homework_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

ALTER TABLE `homework_completion`
  ADD CONSTRAINT `fk_homework_completion_homework` FOREIGN KEY (`homework_id`) REFERENCES `homework` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_homework_completion_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attendance_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `student_info`
  ADD CONSTRAINT `fk_student_info_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `student_parent_links`
  ADD CONSTRAINT `fk_student_parent_links_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_student_parent_links_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `teacher_events`
  ADD CONSTRAINT `fk_teacher_events_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_teacher_events_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL;

ALTER TABLE `teaching_materials`
  ADD CONSTRAINT `fk_teaching_materials_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_teaching_materials_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_teaching_materials_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE;

ALTER TABLE `report_files`
  ADD CONSTRAINT `fk_report_files_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_logs`
  ADD CONSTRAINT `fk_user_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_sessions`
  ADD CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

INSERT INTO `schools` (`id`, `full_name`, `short_name`, `inn`, `type`, `status`, `legal_address`, `physical_address`, `phone`, `email`, `website`, `director_name`, `license_number`, `license_date`, `license_issued_by`, `accreditation_number`, `accreditation_date`, `accreditation_until`) VALUES
(1, 'School No. 1', 'School 1', '7701000001', 'obshcheobrazovatelnaya', 'active', 'Moscow, Lenina st. 12', 'Moscow, Lenina st. 12', '+7 (495) 111-22-33', 'school1@example.com', 'https://school1.example', 'Anna Petrova', 'LIC-001', '2024-09-01', 'Education Department', 'A-1001', '2023-05-15', '2028-05-15');

INSERT INTO `roles` (`id`, `name`, `description`, `permissions`) VALUES
(1, 'super_admin', 'Main system administrator', '["manage_users","manage_schools","manage_roles","view_reports","system_config"]'),
(2, 'school_admin', 'School administrator', '["manage_classes","manage_teachers","manage_students","manage_parents","view_reports"]'),
(3, 'teacher', 'Teacher', '["grade_students","create_homework","view_students","manage_attendance"]'),
(4, 'class_teacher', 'Class teacher', '["grade_students","create_homework","view_class_journal","contact_parents"]'),
(5, 'student', 'Student', '["view_grades","view_homework","view_schedule"]'),
(6, 'parent', 'Parent', '["view_child_grades","view_child_homework","contact_teachers"]');

INSERT INTO `users` (`id`, `school_id`, `class_id`, `login`, `email`, `password_hash`, `full_name`, `position`, `birth_date`, `gender`, `phone`, `role_id`, `is_active`) VALUES
(1, NULL, NULL, 'superadmin', 'superadmin@school.ru', '$2y$10$WrrONmo.trGLNWoa07jcUuFlfXjkX9n2.qFxHZ6Qryc19VaiObDnC', 'Main Administrator', 'System administrator', NULL, NULL, '+7 (900) 000-00-01', 1, 1),
(2, 1, NULL, 'schooladmin', 'schooladmin@school.ru', '$2y$10$D1nlwUzPsuTVZBxIdo.AiupzYVBZzl49TfRl7vDeEuToXrwhYkMwm', 'School Administrator', 'Director', NULL, NULL, '+7 (900) 000-00-02', 2, 1),
(3, 1, NULL, 'teacher1', 'teacher1@school.ru', '$2y$10$wcm59Teztv2.f88TvH8A9OeucaSJ323aE18hIxubWRHgm.pEXML4e', 'Maria Ivanova', 'Math teacher', '1990-05-12', 'female', '+7 (900) 000-00-03', 3, 1),
(4, 1, NULL, 'classteacher', 'classteacher@school.ru', '$2y$10$36L3NokXz74vq/FyFd2fLeTFpmwGLFyP/4fsmkKJIZ5gBC/GgBENu', 'Olga Petrova', 'Class teacher', '1987-08-20', 'female', '+7 (900) 000-00-04', 4, 1),
(5, 1, NULL, 'student1', 'student1@school.ru', '$2y$10$p9Ql4WQhhjKHxWnbL3.gXOomfFL0P/20BMWib/256o6sLIZyyAItq', 'Artem Smirnov', 'Student', '2011-02-14', 'male', '+7 (900) 000-00-05', 5, 1),
(6, 1, NULL, 'parent1', 'parent1@school.ru', '$2y$10$7ZIrf5uQx/KXaxt6DE7gMOmJAohNn26AwlA75n471N.E1DKPhklr.', 'Elena Smirnova', 'Parent', NULL, 'female', '+7 (900) 000-00-06', 6, 1);

INSERT INTO `classes` (`id`, `school_id`, `name`, `grade_level`, `class_teacher_id`) VALUES
(1, 1, '8A', 8, 4);

UPDATE `users` SET `class_id` = 1 WHERE `id` = 5;

INSERT INTO `subjects` (`id`, `school_id`, `name`, `short_name`, `description`, `is_active`) VALUES
(1, 1, 'Mathematics', 'Math', 'Core subject', 1),
(2, 1, 'Russian Language', 'Rus', 'Language and literature', 1),
(3, 1, 'Literature', 'Lit', 'Reading and literature', 1),
(4, 1, 'History', 'Hist', 'History of Russia and world', 1),
(5, 1, 'Physics', 'Phys', 'Science subject', 1),
(6, 1, 'English', 'Eng', 'Foreign language', 1);

INSERT INTO `academic_periods` (`id`, `school_id`, `name`, `start_date`, `end_date`, `is_active`, `is_current`, `academic_year`) VALUES
(1, 1, '1 quarter', '2025-09-01', '2025-10-31', 0, 0, '2025-2026'),
(2, 1, '2 quarter', '2025-11-01', '2025-12-31', 1, 1, '2025-2026'),
(3, 1, '3 quarter', '2026-01-09', '2026-03-22', 0, 0, '2025-2026');

INSERT INTO `curriculum` (`id`, `name`, `school_id`, `description`, `grades`, `subjects`, `is_active`, `created_by`) VALUES
(1, 'Main curriculum 8A', 1, 'Base curriculum for grade 8', '["5","4","3","2"]', '[{"name":"Mathematics","hours":"4"},{"name":"Russian language","hours":"4"},{"name":"Literature","hours":"2"},{"name":"History","hours":"2"},{"name":"Physics","hours":"2"},{"name":"English language","hours":"3"}]', 1, 2);

INSERT INTO `grade_types` (`id`, `school_id`, `name`, `min_score`, `max_score`, `description`, `color`, `is_active`) VALUES
(1, 1, 'Excellent', 5, 5, 'Excellent work', '#2ecc71', 1),
(2, 1, 'Good', 4, 4, 'Good work', '#f1c40f', 1),
(3, 1, 'Satisfactory', 3, 3, 'Passable result', '#e67e22', 1),
(4, 1, 'Unsatisfactory', 2, 2, 'Failed result', '#e74c3c', 1);

INSERT INTO `grade_weights` (`id`, `school_id`, `name`, `weight`, `description`, `is_active`) VALUES
(1, 1, 'Standard', 1.00, 'Regular mark', 1),
(2, 1, 'Quiz', 1.50, 'Quiz', 1),
(3, 1, 'Control', 2.00, 'Control work', 1);

INSERT INTO `schedule` (`id`, `school_id`, `class_id`, `subject_id`, `teacher_id`, `lesson_date`, `lesson_number`, `room`, `is_completed`) VALUES
(1, 1, 1, 1, 3, '2026-09-16', 1, '205', 1),
(2, 1, 1, 2, 3, '2026-09-16', 2, '204', 1),
(3, 1, 1, 3, 3, '2026-09-17', 3, '201', 0),
(4, 1, 1, 4, 4, '2026-09-17', 4, '202', 0);

INSERT INTO `grades` (`id`, `student_id`, `teacher_id`, `subject_id`, `grade_value`, `grade_type_id`, `grade_weight_id`, `lesson_date`) VALUES
(1, 5, 3, 1, '5', 1, 1, '2026-09-16'),
(2, 5, 3, 2, '4', 2, 1, '2026-09-16'),
(3, 5, 4, 3, '3', 3, 1, '2026-09-17');

INSERT INTO `homework` (`id`, `teacher_id`, `class_id`, `subject_id`, `title`, `description`, `due_date`) VALUES
(1, 3, 1, 1, 'Solve exercises on equations', 'Solve tasks 12, 15, 18 on page 45', '2026-09-20'),
(2, 3, 1, 2, 'Prepare a summary', 'Write a summary on the theme Autumn in the city', '2026-09-21');

INSERT INTO `homework_completion` (`id`, `homework_id`, `student_id`, `status`, `submitted_at`) VALUES
(1, 1, 5, 'done', '2026-09-17 08:00:00'),
(2, 2, 5, 'not_done', NULL);

INSERT INTO `attendance` (`id`, `student_id`, `teacher_id`, `lesson_date`, `status`, `notes`) VALUES
(1, 5, 3, '2026-09-16', 'present', 'Present at lesson'),
(2, 5, 4, '2026-09-17', 'late', 'Late by 5 minutes');

INSERT INTO `student_info` (`id`, `user_id`, `birth_date`, `address`) VALUES
(1, 5, '2011-02-14', 'Moscow, Lesnaya st. 15, apt. 10');

INSERT INTO `student_parent_links` (`id`, `parent_id`, `student_id`, `relationship`, `is_primary`) VALUES
(1, 6, 5, 'parent', 1);

INSERT INTO `teacher_events` (`id`, `teacher_id`, `title`, `description`, `event_date`, `event_time`, `event_type`, `class_id`) VALUES
(1, 3, 'Pedagogical meeting', 'Weekly planning meeting', '2026-09-18', '15:30:00', 'meeting', 1),
(2, 4, 'Parent meeting', 'Meeting with parents of 8A class', '2026-09-20', '18:00:00', 'event', 1);

INSERT INTO `user_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `user_agent`) VALUES
(1, 1, 'system_setup', 'Database rebuilt successfully', '127.0.0.1', 'mysql-import');

INSERT INTO `user_sessions` (`id`, `user_id`, `session_id`, `ip_address`, `user_agent`) VALUES
(1, 1, 'seed-session-superadmin', '127.0.0.1', 'seed');

SET @db_status = 'DATABASE_REBUILT_OK';
SELECT @db_status AS status;