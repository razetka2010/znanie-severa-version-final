<?php
require_once __DIR__ . '/../config/session.php';
require_once '../config/database.php';
require_once '../config/auth.php';
require_once __DIR__ . '/../includes/grades_helper.php';
require_once __DIR__ . '/../includes/periods_helper.php';

requireTeacher();

$pdo = getDatabaseConnection();
$teacher_id = $_SESSION['user_id'];
$school_id = $_SESSION['user_school_id'];
$periods = getSchoolPeriods($pdo, (int)$school_id);
$current_period = getCurrentPeriod($pdo, (int)$school_id);
$selected_period_id = isset($_GET['period_id']) ? (int)$_GET['period_id'] : (int)($current_period['id'] ?? 0);
$selected_period = null;
foreach ($periods as $period) {
    if ((int)$period['id'] === $selected_period_id) {
        $selected_period = $period;
        break;
    }
}

// Добавляем стартовые предметы при первой загрузке школьного кабинета.
try {
    // Добавляем базовые предметы если их нет
    $check_subjects = $pdo->prepare("SELECT COUNT(*) as count FROM subjects WHERE school_id = ?");
    $check_subjects->execute([$school_id]);
    $subjects_count = $check_subjects->fetch()['count'];

    if ($subjects_count == 0) {
        $base_subjects = [
            ['name' => 'Математика', 'short_name' => 'Матем'],
            ['name' => 'Русский язык', 'short_name' => 'Рус яз'],
            ['name' => 'Литература', 'short_name' => 'Лит-ра'],
            ['name' => 'История', 'short_name' => 'Ист'],
            ['name' => 'Обществознание', 'short_name' => 'Общ'],
            ['name' => 'География', 'short_name' => 'Геогр'],
            ['name' => 'Биология', 'short_name' => 'Биол'],
            ['name' => 'Физика', 'short_name' => 'Физ'],
            ['name' => 'Химия', 'short_name' => 'Хим'],
            ['name' => 'Английский язык', 'short_name' => 'Англ'],
            ['name' => 'Информатика', 'short_name' => 'Инф'],
            ['name' => 'Физкультура', 'short_name' => 'Физ-ра'],
            ['name' => 'Музыка', 'short_name' => 'Муз'],
            ['name' => 'ИЗО', 'short_name' => 'ИЗО'],
            ['name' => 'Технология', 'short_name' => 'Техн']
        ];

        $stmt = $pdo->prepare("INSERT INTO subjects (school_id, name, short_name) VALUES (?, ?, ?)");
        foreach ($base_subjects as $subject) {
            $stmt->execute([$school_id, $subject['name'], $subject['short_name']]);
        }
    }

} catch (PDOException $e) {
    error_log("Ошибка при создании таблиц: " . $e->getMessage());
}

// Получаем информацию о учителе
$teacher_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$teacher_stmt->execute([$teacher_id]);
$teacher = $teacher_stmt->fetch();

$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$selected_subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

$grade_types = [];
$grade_weights = [];
try {
    $stmt = $pdo->prepare("
        SELECT gt.id, gt.name, gt.min_score, gt.max_score, gt.color,
               gt.default_weight_id, gw.weight AS default_weight_value,
               gw.is_active AS default_weight_active
        FROM grade_types gt
        LEFT JOIN grade_weights gw ON gw.id = gt.default_weight_id AND gw.school_id = gt.school_id
        WHERE gt.school_id = ? AND gt.is_active = 1
        ORDER BY gt.min_score DESC, gt.name
    ");
    $stmt->execute([$school_id]);
    $grade_types = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name, weight FROM grade_weights WHERE school_id = ? AND is_active = 1 ORDER BY weight, name");
    $stmt->execute([$school_id]);
    $grade_weights = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Ошибка получения настроек оценок: ' . $e->getMessage());
}

$default_grade_type_id = $grade_types[0]['id'] ?? null;
$default_grade_weight_id = $grade_types[0]['default_weight_id'] ?? null;

// Получаем предметы
$subjects = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM subjects WHERE school_id = ? ORDER BY name");
    $stmt->execute([$school_id]);
    $subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении предметов: " . $e->getMessage());
}

// Получаем классы учителя
$classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, c.grade_level 
        FROM classes c 
        WHERE c.school_id = ? AND c.is_active = 1
        ORDER BY c.grade_level, c.name
    ");
    $stmt->execute([$school_id]);
    $classes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении классов: " . $e->getMessage());
}

// Получаем даты уроков
$lesson_dates = [];
$homework_by_date = [];
$homework_load_error = false;
if ($selected_class_id > 0 && $selected_subject_id > 0) {
    try {
        $dates = [];
        if ($selected_period) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT lesson_date
                FROM schedule
                WHERE class_id = ? AND subject_id = ? AND teacher_id = ? AND school_id = ?
                    AND lesson_date BETWEEN ? AND ?
                ORDER BY lesson_date ASC
            ");
            $stmt->execute([
                $selected_class_id,
                $selected_subject_id,
                $teacher_id,
                $school_id,
                $selected_period['start_date'],
                $selected_period['end_date']
            ]);
            $dates = $stmt->fetchAll();
        }

        foreach ($dates as $date_row) {
            $lesson_dates[] = [
                'full_date' => $date_row['lesson_date'],
                'display_date' => date('j', strtotime($date_row['lesson_date'])),
                'month_name' => date('F', strtotime($date_row['lesson_date']))
            ];
        }

        if ($lesson_dates) {
            $lesson_date_values = array_column($lesson_dates, 'full_date');
            $date_placeholders = implode(',', array_fill(0, count($lesson_date_values), '?'));
            $homework_stmt = $pdo->prepare("
                SELECT lesson_date, title, description
                FROM homework
                WHERE teacher_id = ? AND class_id = ? AND subject_id = ?
                    AND lesson_date IN ($date_placeholders)
                ORDER BY created_at, id
            ");
            $homework_stmt->execute(array_merge(
                [$teacher_id, $selected_class_id, $selected_subject_id],
                $lesson_date_values
            ));
            foreach ($homework_stmt->fetchAll() as $homework_row) {
                $homework_by_date[$homework_row['lesson_date']][] = $homework_row;
            }
        }

    } catch (PDOException $e) {
        error_log("Ошибка при получении уроков и домашних заданий: " . $e->getMessage());
        $homework_load_error = true;
    }
}

// Получаем учеников
$class_students = [];
if ($selected_class_id > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name
            FROM users u 
            JOIN classes c ON c.id = u.class_id AND c.school_id = ?
            WHERE u.class_id = ? AND u.school_id = ?
                AND u.role_id IN (SELECT id FROM roles WHERE name = 'student') AND u.is_active = 1
            ORDER BY u.full_name
        ");
        $stmt->execute([$school_id, $selected_class_id, $school_id]);
        $class_students = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Ошибка при получении учеников класса: " . $e->getMessage());
    }
}

// Получаем существующие оценки
$existing_grades = [];
if ($selected_class_id > 0 && $selected_subject_id > 0) {
    try {
        $grade_query = "
            SELECT g.*, u.full_name as student_name,
                   gt.name AS grade_type_name, gt.is_active AS grade_type_active, gt.default_weight_id,
                   gw.name AS grade_weight_name, gw.weight AS grade_weight_value,
                   default_gw.weight AS default_weight_value,
                   default_gw.is_active AS default_weight_active
            FROM grades g
            JOIN users u ON g.student_id = u.id AND u.school_id = ?
            LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = u.school_id
            LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = u.school_id
            LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = u.school_id
            WHERE u.class_id = ? AND g.subject_id = ? AND g.teacher_id = ?
        ";
        $grade_params = [$school_id, $selected_class_id, $selected_subject_id, $teacher_id];
        if ($selected_period) {
            $grade_query .= ' AND g.lesson_date BETWEEN ? AND ?';
            $grade_params[] = $selected_period['start_date'];
            $grade_params[] = $selected_period['end_date'];
        }
        $grade_query .= ' ORDER BY g.lesson_date, g.updated_at DESC, g.id DESC';
        $stmt = $pdo->prepare($grade_query);
        $stmt->execute($grade_params);
        $existing_grades = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Ошибка при получении оценок: " . $e->getMessage());
    }
}

$lesson_grade_type_by_date = [];
$lesson_grade_type_load_error = false;
foreach ($existing_grades as $grade) {
    if (!empty($grade['grade_type_id'])) {
        $lesson_grade_type_by_date[$grade['lesson_date']] ??= (int)$grade['grade_type_id'];
    }
}
if ($selected_class_id > 0 && $selected_subject_id > 0 && $selected_period) {
    try {
        $lesson_type_stmt = $pdo->prepare("
            SELECT lesson_date, grade_type_id
            FROM lesson_grade_types
            WHERE school_id = ? AND class_id = ? AND subject_id = ? AND teacher_id = ?
                AND lesson_date BETWEEN ? AND ?
        ");
        $lesson_type_stmt->execute([
            $school_id,
            $selected_class_id,
            $selected_subject_id,
            $teacher_id,
            $selected_period['start_date'],
            $selected_period['end_date']
        ]);
        foreach ($lesson_type_stmt->fetchAll() as $lesson_type) {
            $lesson_grade_type_by_date[$lesson_type['lesson_date']] = (int)$lesson_type['grade_type_id'];
        }
    } catch (PDOException $e) {
        $lesson_grade_type_load_error = true;
        error_log('Ошибка при получении типов работ по датам журнала: ' . $e->getMessage());
    }
}

// Получаем посещаемость одним запросом для всех учеников и дат выбранного журнала.
$attendance_by_cell = [];
if ($selected_class_id > 0 && $selected_subject_id > 0 && !empty($lesson_dates) && !empty($class_students)) {
    try {
        $attendance_dates = array_column($lesson_dates, 'full_date');
        $date_placeholders = implode(',', array_fill(0, count($attendance_dates), '?'));
        $attendance_stmt = $pdo->prepare("
            SELECT a.student_id, a.lesson_date, a.status
            FROM attendance a
            JOIN users u ON u.id = a.student_id
            JOIN classes c ON c.id = u.class_id AND c.school_id = ?
            WHERE u.class_id = ? AND u.school_id = ? AND a.lesson_date IN ($date_placeholders)
        ");
        $attendance_stmt->execute(array_merge([$school_id, $selected_class_id, $school_id], $attendance_dates));
        foreach ($attendance_stmt->fetchAll() as $attendance_row) {
            $attendance_by_cell[$attendance_row['student_id'] . ':' . $attendance_row['lesson_date']] = $attendance_row['status'];
        }
    } catch (PDOException $e) {
        error_log('Ошибка при получении посещаемости журнала оценок: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    header('Content-Type: application/json; charset=utf-8');

    $action = (string)($_POST['action'] ?? '');
    $selected_class_id = (int)($_POST['class_id'] ?? 0);
    $selected_subject_id = (int)($_POST['subject_id'] ?? 0);
    $student_id = (int)($_POST['student_id'] ?? 0);
    $lesson_date = (string)($_POST['lesson_date'] ?? '');
    $grade_value = trim((string)($_POST['grade_value'] ?? ''));
    $grade_type_id = (int)($_POST['grade_type_id'] ?? $default_grade_type_id ?? 0);
    if ($grade_type_id < 1) {
        $grade_type_id = (int)($default_grade_type_id ?? 0);
    }

    $respond = static function (int $status, array $payload): void {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    };

    try {
        if (!in_array($action, ['set_grade', 'set_attendance', 'clear_grade', 'set_lesson_type'], true)) {
            throw new InvalidArgumentException('Неизвестное действие.');
        }
        if ($selected_class_id < 1 || $selected_subject_id < 1
            || ($action !== 'set_lesson_type' && $student_id < 1)) {
            throw new InvalidArgumentException('Некорректно указаны класс, предмет или ученик.');
        }

        $date_object = DateTime::createFromFormat('Y-m-d', $lesson_date);
        if (!$date_object || $date_object->format('Y-m-d') !== $lesson_date) {
            throw new InvalidArgumentException('Некорректная дата урока.');
        }

        if ($action === 'set_lesson_type') {
            $access_stmt = $pdo->prepare("
                SELECT 1
                FROM schedule sch
                JOIN classes c ON c.id = sch.class_id AND c.school_id = sch.school_id
                JOIN subjects subj ON subj.id = sch.subject_id AND subj.school_id = sch.school_id
                WHERE sch.class_id = ? AND sch.subject_id = ? AND sch.teacher_id = ?
                    AND sch.school_id = ? AND sch.lesson_date = ? AND c.is_active = 1
                LIMIT 1
            ");
            $access_stmt->execute([
                $selected_class_id,
                $selected_subject_id,
                $teacher_id,
                $school_id,
                $lesson_date
            ]);
        } else {
            $access_stmt = $pdo->prepare("
                SELECT 1
                FROM users student
                JOIN classes c ON c.id = student.class_id AND c.school_id = student.school_id
                JOIN schedule sch ON sch.class_id = student.class_id
                    AND sch.subject_id = ?
                    AND sch.teacher_id = ?
                    AND sch.school_id = student.school_id
                    AND sch.lesson_date = ?
                JOIN subjects subj ON subj.id = sch.subject_id AND subj.school_id = student.school_id
                WHERE student.id = ? AND student.class_id = ? AND student.school_id = ?
                    AND student.is_active = 1 AND c.is_active = 1
                LIMIT 1
            ");
            $access_stmt->execute([
                $selected_subject_id,
                $teacher_id,
                $lesson_date,
                $student_id,
                $selected_class_id,
                $school_id
            ]);
        }
        if (!$access_stmt->fetchColumn()) {
            throw new InvalidArgumentException($action === 'set_lesson_type'
                ? 'У вас нет доступа к этому уроку.'
                : 'У вас нет доступа к этому ученику и уроку.');
        }

        $period = getPeriodForDate($pdo, (int)$school_id, $lesson_date);
        $request_period_id = (int)($_POST['period_id'] ?? 0);
        if (!$period || (int)$period['id'] !== $request_period_id) {
            throw new InvalidArgumentException('Дата урока не входит в выбранный активный учебный период.');
        }
        if ((int)$period['is_locked'] === 1 && !hasPermission('edit_locked_periods')) {
            throw new InvalidArgumentException('Учебный период закрыт. Изменение оценок, типов работ и посещаемости запрещено.');
        }

        if ($action === 'set_lesson_type') {
            $grade_type_id = (int)($_POST['grade_type_id'] ?? 0);
            $type_stmt = $pdo->prepare("
                SELECT id
                FROM grade_types
                WHERE id = ? AND school_id = ? AND is_active = 1
            ");
            $type_stmt->execute([$grade_type_id, $school_id]);
            if (!$type_stmt->fetchColumn()) {
                throw new InvalidArgumentException('Выберите активный тип работы.');
            }

            $save_lesson_type_stmt = $pdo->prepare("
                INSERT INTO lesson_grade_types
                    (school_id, class_id, subject_id, teacher_id, lesson_date, grade_type_id)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE grade_type_id = VALUES(grade_type_id), updated_at = NOW()
            ");
            $save_lesson_type_stmt->execute([
                $school_id,
                $selected_class_id,
                $selected_subject_id,
                $teacher_id,
                $lesson_date,
                $grade_type_id
            ]);
            logUserAction($pdo, 'lesson_grade_type_changed', json_encode([
                'class_id' => $selected_class_id,
                'subject_id' => $selected_subject_id,
                'lesson_date' => $lesson_date,
                'grade_type_id' => $grade_type_id
            ], JSON_UNESCAPED_UNICODE));
            $respond(200, ['ok' => true, 'message' => 'Тип работы сохранён.']);
        }

        $existing_stmt = $pdo->prepare("
            SELECT id, grade_value, grade_type_id, grade_weight_id
            FROM grades
            WHERE student_id = ? AND teacher_id = ? AND subject_id = ? AND lesson_date = ?
            LIMIT 1
        ");
        $existing_stmt->execute([$student_id, $teacher_id, $selected_subject_id, $lesson_date]);
        $existing_grade = $existing_stmt->fetch() ?: null;

        if ($action === 'set_attendance') {
            $attendance_status = (string)($_POST['attendance_status'] ?? '');
            if (!in_array($attendance_status, ['present', 'absent', 'late'], true)) {
                throw new InvalidArgumentException('Недопустимый статус посещаемости.');
            }

            $old_status_stmt = $pdo->prepare('SELECT status FROM attendance WHERE student_id = ? AND lesson_date = ?');
            $old_status_stmt->execute([$student_id, $lesson_date]);
            $old_status = $old_status_stmt->fetchColumn();
            $old_status = $old_status === false ? null : $old_status;

            $upsert_stmt = $pdo->prepare("
                INSERT INTO attendance (student_id, teacher_id, lesson_date, status, notes)
                VALUES (?, ?, ?, ?, NULL)
                ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), status = VALUES(status), notes = VALUES(notes)
            ");
            $upsert_stmt->execute([$student_id, $teacher_id, $lesson_date, $attendance_status]);

            logUserAction($pdo, 'attendance_changed', json_encode([
                'student_id' => $student_id,
                'class_id' => $selected_class_id,
                'subject_id' => $selected_subject_id,
                'lesson_date' => $lesson_date,
                'old_status' => $old_status,
                'new_status' => $attendance_status
            ], JSON_UNESCAPED_UNICODE));
        } elseif ($action === 'set_grade') {
            $allowed_grade_values = ['2', '3', '4', '5', 'Б', 'От', 'УП'];
            if (!in_array($grade_value, $allowed_grade_values, true)) {
                throw new InvalidArgumentException('Недопустимое значение оценки.');
            }

            if (empty($grade_types) || empty($grade_weights)) {
                throw new InvalidArgumentException('Сначала настройте типы и веса оценок: school_admin/grade_types.php.');
            }

            $type_stmt = $pdo->prepare("
                SELECT gt.min_score, gt.max_score, gt.default_weight_id,
                       default_gw.weight AS default_weight_value,
                       default_gw.is_active AS default_weight_active
                FROM grade_types gt
                LEFT JOIN grade_weights default_gw
                    ON default_gw.id = gt.default_weight_id AND default_gw.school_id = gt.school_id
                WHERE gt.id = ? AND gt.school_id = ?
                    AND (gt.is_active = 1 OR gt.id = ?)
            ");
            $type_stmt->execute([$grade_type_id, $school_id, $existing_grade['grade_type_id'] ?? 0]);
            $grade_type = $type_stmt->fetch();
            if (!$grade_type) {
                throw new InvalidArgumentException('Выберите активный тип оценки.');
            }

            $requested_weight = $_POST['grade_weight_id'] ?? '';
            if ($requested_weight !== '') {
                $grade_weight_id = (int)$requested_weight;
                $weight_stmt = $pdo->prepare("
                    SELECT id
                    FROM grade_weights
                    WHERE id = ? AND school_id = ?
                        AND (is_active = 1 OR id = ?)
                ");
                $weight_stmt->execute([$grade_weight_id, $school_id, $existing_grade['grade_weight_id'] ?? 0]);
                if (!$weight_stmt->fetchColumn()) {
                    throw new InvalidArgumentException('Выберите активный вес этой школы.');
                }
            } else {
                $grade_weight_id = $grade_type['default_weight_id'] !== null
                    && (int)($grade_type['default_weight_active'] ?? 0) === 1
                    ? (int)$grade_type['default_weight_id']
                    : null;
            }
            if (isNumericGrade($grade_value)
                && ((int)$grade_value < $grade_type['min_score'] || (int)$grade_value > $grade_type['max_score'])) {
                throw new InvalidArgumentException('Оценка не входит в диапазон выбранного типа.');
            }

            if ($existing_grade) {
                $update_stmt = $pdo->prepare("
                    UPDATE grades
                    SET grade_value = ?, grade_type_id = ?, grade_weight_id = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $update_stmt->execute([$grade_value, $grade_type_id, $grade_weight_id, $existing_grade['id']]);
            } else {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO grades (student_id, teacher_id, subject_id, grade_value, grade_type_id, grade_weight_id, lesson_date, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert_stmt->execute([
                    $student_id,
                    $teacher_id,
                    $selected_subject_id,
                    $grade_value,
                    $grade_type_id,
                    $grade_weight_id,
                    $lesson_date
                ]);
            }

            logUserAction($pdo, 'grade_changed', json_encode([
                'student_id' => $student_id,
                'subject_id' => $selected_subject_id,
                'lesson_date' => $lesson_date,
                'value' => $grade_value,
                'grade_type_id' => $grade_type_id,
                'grade_weight_id' => $grade_weight_id
            ], JSON_UNESCAPED_UNICODE));
        } else {
            if ($existing_grade) {
                $delete_stmt = $pdo->prepare("
                    DELETE FROM grades
                    WHERE id = ? AND student_id = ? AND teacher_id = ? AND subject_id = ?
                ");
                $delete_stmt->execute([
                    $existing_grade['id'],
                    $student_id,
                    $teacher_id,
                    $selected_subject_id
                ]);
                logUserAction($pdo, 'grade_changed', json_encode([
                    'student_id' => $student_id,
                    'subject_id' => $selected_subject_id,
                    'lesson_date' => $lesson_date,
                    'old_value' => $existing_grade['grade_value'],
                    'value' => null
                ], JSON_UNESCAPED_UNICODE));
            }
        }

        $attendance_stmt = $pdo->prepare('SELECT status FROM attendance WHERE student_id = ? AND lesson_date = ?');
        $attendance_stmt->execute([$student_id, $lesson_date]);
        $current_attendance = $attendance_stmt->fetchColumn();
        $current_attendance = $current_attendance === false ? 'present' : $current_attendance;

        $average_stmt = $pdo->prepare("
            SELECT g.grade_value, g.grade_weight_id, g.grade_type_id,
                   gw.weight AS grade_weight_value, gt.default_weight_id,
                   default_gw.weight AS default_weight_value,
                   default_gw.is_active AS default_weight_active
            FROM grades g
            JOIN users student ON student.id = g.student_id AND student.school_id = ?
            LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = student.school_id
            LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = student.school_id
            LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = student.school_id
            WHERE g.student_id = ? AND g.teacher_id = ? AND g.subject_id = ?
                AND g.lesson_date BETWEEN ? AND ?
        ");
        $average_stmt->execute([
            $school_id,
            $student_id,
            $teacher_id,
            $selected_subject_id,
            $period['start_date'],
            $period['end_date']
        ]);
        $average = calculateWeightedAverage($average_stmt->fetchAll());

        $new_grade_stmt = $pdo->prepare("
            SELECT grade_value, grade_type_id, grade_weight_id
            FROM grades
            WHERE student_id = ? AND teacher_id = ? AND subject_id = ? AND lesson_date = ?
            LIMIT 1
        ");
        $new_grade_stmt->execute([$student_id, $teacher_id, $selected_subject_id, $lesson_date]);
        $new_grade = $new_grade_stmt->fetch() ?: null;

        $respond(200, [
            'ok' => true,
            'message' => $action === 'set_attendance' ? 'Посещаемость сохранена.' : 'Изменения сохранены.',
            'new_value' => $new_grade['grade_value'] ?? null,
            'attendance_status' => $current_attendance,
            'average' => $average,
            'grade_type_id' => $new_grade['grade_type_id'] ?? null,
            'grade_weight_id' => $new_grade['grade_weight_id'] ?? null
        ]);
    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
        error_log(basename(__FILE__) . ': ' . $message);
        $respond(422, [
            'ok' => false,
            'message' => $message,
            'new_value' => $existing_grade['grade_value'] ?? null,
            'attendance_status' => null,
            'average' => null
        ]);
    } catch (PDOException $e) {
        $message = publicErrorMessage($e, basename(__FILE__));
        $respond(500, [
            'ok' => false,
            'message' => $message,
            'new_value' => $existing_grade['grade_value'] ?? null,
            'attendance_status' => null,
            'average' => null
        ]);
    }
}

// Рассчитываем средние оценки
$student_averages = [];
foreach ($class_students as $student) {
    $student_grades = array_values(array_filter($existing_grades, function($grade) use ($student) {
        return (int)$grade['student_id'] === (int)$student['id'] && isNumericGrade($grade['grade_value'] ?? '');
    }));

    $student_averages[$student['id']] = calculateWeightedAverage($student_grades);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Журнал оценок - Учитель</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            color: #2c3e50;
            line-height: 1.6;
        }

        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }

        /* Сайдбар */
        .sidebar {
            width: 280px;
            background: #2c3e50;
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px;
            background: #34495e;
            border-bottom: 1px solid #4a6278;
        }

        .sidebar-header h1 {
            font-size: 1.2em;
            margin-bottom: 5px;
        }

        .sidebar-header p {
            font-size: 0.9em;
            opacity: 0.8;
        }

        .sidebar-nav {
            padding: 0;
        }

        .user-info {
            padding: 15px 20px;
            background: #34495e;
            border-bottom: 1px solid #4a6278;
        }

        .role-badge {
            display: inline-block;
            background: #e74c3c;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            margin-left: 8px;
        }

        .nav-menu {
            list-style: none;
            padding: 0;
        }

        .nav-section {
            padding: 12px 20px;
            font-size: 0.8em;
            text-transform: uppercase;
            opacity: 0.7;
            border-bottom: 1px solid #4a6278;
        }

        .nav-link {
            display: block;
            padding: 12px 20px;
            color: white;
            text-decoration: none;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }

        .nav-link:hover {
            background: #34495e;
            border-left-color: #3498db;
        }

        .nav-link.active {
            background: #34495e;
            border-left-color: #3498db;
            font-weight: bold;
        }

        /* Основной контент */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
        }

        .content-header {
            background: white;
            padding: 20px 30px;
            border-bottom: 1px solid #e0e0e0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .header-title h1 {
            font-size: 1.8em;
            margin-bottom: 5px;
            color: #2c3e50;
        }

        .header-title p {
            color: #7f8c8d;
        }

        .content-body {
            padding: 30px;
        }

        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5em;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.9em;
        }

        /* Секции */
        .section {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 25px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }

        .section-title {
            font-size: 1.3em;
            color: #2c3e50;
            margin: 0;
        }

        /* Таблицы */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 0.9em;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #3498db;
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-success {
            background: #27ae60;
            color: white;
        }

        .btn-success:hover {
            background: #219653;
        }

        .btn-secondary {
            background: #95a5a6;
            color: white;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }

        .empty-state .icon {
            font-size: 3em;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                position: relative;
                height: auto;
            }

            .main-content {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .quick-action-btn {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 25px 20px;
            text-align: center;
            text-decoration: none;
            color: #2c3e50;
            transition: all 0.3s;
            display: block;
        }

        .quick-action-btn:hover {
            border-color: #3498db;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .quick-action-icon {
            font-size: 2.5em;
            margin-bottom: 10px;
            display: block;
        }

        .journal-container {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow-x: auto;
            margin-top: 20px;
        }

        .journal-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .journal-table th, .journal-table td {
            padding: 12px 8px;
            border: 1px solid #e0e0e0;
            text-align: center;
            vertical-align: middle;
        }

        .journal-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }

        .student-name-cell {
            text-align: left;
            font-weight: 500;
            min-width: 200px;
            background: #f8f9fa;
            position: sticky;
            left: 0;
            z-index: 10;
        }

        .date-header {
            background: #e3f2fd;
            font-weight: 500;
            min-width: 60px;
            cursor: pointer;
            color: #1976d2;
        }

        .date-header-link {
            color: inherit;
            text-decoration: none;
        }

        .date-header-link:hover,
        .date-header-link:focus {
            text-decoration: underline;
        }

        .column-grade-type-status {
            margin-left: 4px;
            font-size: 11px;
            color: #2e7d32;
        }

        .column-grade-type-status.is-error {
            color: #c62828;
        }

        .month-header {
            background: #1976d2;
            color: white;
            font-weight: bold;
            text-align: center;
            font-size: 0.9em;
        }

        .average-cell {
            background: #e8f5e8;
            font-weight: bold;
            color: #2e7d32;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 260px)) auto;
            gap: 12px;
            align-items: end;
        }

        @media (max-width: 1200px) {
            .filter-form {
                grid-template-columns: repeat(2, minmax(180px, 260px));
            }
        }

        @media (max-width: 600px) {
            .filter-form {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
        }

        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1em;
            transition: border-color 0.3s;
            background: white;
        }

        .form-group select:focus {
            border-color: #3498db;
            outline: none;
        }

        .journal-table {
            min-width: max(800px, 100%);
        }

    </style>
    <link rel="stylesheet" href="../css/teacher.css?v=<?= (int)filemtime(__DIR__ . '/../css/teacher.css') ?>">
</head>
<body>
<div class="dashboard-container">
    <?php $teacherActivePage = 'grades'; require __DIR__ . '/../includes/teacher_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Журнал оценок</h1>
                <p>Выставление и просмотр оценок</p>
            </div>
        </header>

        <div class="content-body">
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= consumeFlashMessage('success_message'); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?= consumeFlashMessage('error_message'); ?></div>
            <?php endif; ?>

            <!-- Фильтры -->
            <div class="section">
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label>Учебный период:</label>
                        <select name="period_id" onchange="this.form.submit()">
                            <option value="">Выберите период</option>
                            <?php foreach ($periods as $period): ?>
                                <option value="<?= (int)$period['id'] ?>" <?= $selected_period_id === (int)$period['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8') ?><?= $period['is_locked'] ? ' 🔒' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Класс:</label>
                        <select name="class_id" required onchange="this.form.submit()">
                            <option value="">Выберите класс</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $selected_class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['name']) ?> (<?= $class['grade_level'] ?> класс)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Предмет:</label>
                        <select name="subject_id" required onchange="this.form.submit()">
                            <option value="">Выберите предмет</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $selected_subject_id == $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <a href="grades.php" class="btn btn-secondary">Сбросить</a>
                    </div>
                </form>
            </div>
            <?php if (!$periods): ?>
                <div class="alert alert-warning">В школе нет активных учебных периодов. <a href="../school_admin/academic_periods.php">Создайте учебный период</a>.</div>
            <?php elseif ($selected_period && (int)$selected_period['is_locked'] === 1 && !hasPermission('edit_locked_periods')): ?>
                <div class="alert alert-warning">🔒 Учебный период закрыт: оценки, типы работ и посещаемость доступны только для просмотра.</div>
            <?php endif; ?>

            <!-- Таблица оценок -->
            <?php if ($selected_class_id && $selected_subject_id && $selected_period): ?>
                <div class="journal-container journal-app"
                     data-class-id="<?= (int)$selected_class_id ?>"
                     data-subject-id="<?= (int)$selected_subject_id ?>"
                     data-period-id="<?= (int)$selected_period_id ?>"
                     data-default-grade-weight-id="<?= (int)$default_grade_weight_id ?>">
                    <span class="journal-csrf-token"><?= csrf_field() ?></span>
                    <?php if ($homework_load_error): ?>
                        <div class="alert alert-error">Не удалось загрузить домашние задания для дат журнала.</div>
                    <?php endif; ?>
                    <?php if ($lesson_grade_type_load_error): ?>
                        <div class="alert alert-warning">Не удалось загрузить сохранённые типы работ. Попробуйте обновить страницу или обратитесь к администратору.</div>
                    <?php endif; ?>
                    <div style="text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;">
                        <h2 style="color: #2c3e50; margin-bottom: 5px;">Класс: <?= htmlspecialchars($classes[array_search($selected_class_id, array_column($classes, 'id'))]['name'] ?? 'Неизвестный класс') ?></h2>
                        <h3 style="color: #7f8c8d; font-weight: normal;">Предмет: <?= htmlspecialchars($subjects[array_search($selected_subject_id, array_column($subjects, 'id'))]['name'] ?? 'Неизвестный предмет') ?></h3>
                    </div>

                        <?php if (empty($grade_types) || empty($grade_weights)): ?>
                            <div class="alert alert-warning">
                                Для выставления оценок настройте типы и веса в
                                <a href="../school_admin/grade_types.php">Системе оценок</a>.
                            </div>
                        <?php endif; ?>

                    <?php if (empty($class_students)): ?>
                        <div class="empty-state">
                            <div class="icon">👨‍🎓</div>
                            <h3>В классе нет учеников</h3>
                            <p>Обратитесь к администратору для добавления учеников</p>
                        </div>
                    <?php elseif (empty($lesson_dates)): ?>
                        <div class="empty-state">
                            <div class="icon">📅</div>
                            <h3>Нет уроков по расписанию</h3>
                            <p>Для выбранного класса и предмета нет запланированных уроков</p>
                        </div>
                    <?php else: ?>
                        <table class="journal-table">
                            <thead>
                            <tr>
                                <th class="student-name-cell">Ученики</th>
                                <?php
                                // Группируем даты по месяцам
                                $months = [];
                                foreach ($lesson_dates as $date) {
                                    $month = $date['month_name'];
                                    if (!isset($months[$month])) {
                                        $months[$month] = [];
                                    }
                                    $months[$month][] = $date;
                                }
                                ?>

                                <?php foreach ($months as $month_name => $month_dates): ?>
                                    <th colspan="<?= count($month_dates) ?>" class="month-header">
                                        <?= $month_name ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="average-cell">Средний балл</th>
                            </tr>
                            <tr>
                                <th class="student-name-cell">Даты уроков</th>
                                <?php foreach ($lesson_dates as $date):
                                    $homework_title = date('d.m.Y', strtotime($date['full_date'])) . "\n";
                                    if ($homework_load_error) {
                                        $homework_title .= 'Не удалось загрузить домашнее задание';
                                    } elseif (empty($homework_by_date[$date['full_date']])) {
                                        $homework_title .= 'Домашнее задание не задано';
                                    } else {
                                        $homework_title .= 'Домашнее задание задано:';
                                        foreach ($homework_by_date[$date['full_date']] as $date_homework) {
                                            $homework_title .= "\n" . $date_homework['title'];
                                            if (!empty($date_homework['description'])) {
                                                $homework_title .= "\n" . $date_homework['description'];
                                            }
                                        }
                                    }
                                    $homework_url = 'homework.php?' . http_build_query([
                                        'class_id' => $selected_class_id,
                                        'subject_id' => $selected_subject_id,
                                        'lesson_date' => $date['full_date'],
                                        'period_id' => $selected_period_id
                                    ]);
                                ?>
                                    <th class="date-header" data-lesson-date="<?= htmlspecialchars($date['full_date'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($homework_title, ENT_QUOTES, 'UTF-8') ?>">
                                        <a class="date-header-link" href="<?= htmlspecialchars($homework_url, ENT_QUOTES, 'UTF-8') ?>" aria-label="Домашнее задание на <?= htmlspecialchars(date('d.m.Y', strtotime($date['full_date'])), ENT_QUOTES, 'UTF-8') ?>">
                                            <span><?= htmlspecialchars((string)$date['display_date'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </a>
                                        <select class="column-grade-type" aria-label="Тип работы на <?= htmlspecialchars(date('d.m.Y', strtotime($date['full_date'])), ENT_QUOTES, 'UTF-8') ?>">
                                            <?php foreach ($grade_types as $grade_type): ?>
                                                <option value="<?= (int)$grade_type['id'] ?>" <?= (int)($lesson_grade_type_by_date[$date['full_date']] ?? $default_grade_type_id) === (int)$grade_type['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($grade_type['name'], ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span class="column-grade-type-status" role="status" aria-live="polite" hidden></span>
                                    </th>
                                <?php endforeach; ?>
                                <th class="average-cell">Средняя</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($class_students as $index => $student): ?>
                                <tr>
                                    <td class="student-name-cell">
                                        <strong>
                                            <a href="student.php?id=<?= (int)$student['id'] ?>&amp;class_id=<?= (int)$selected_class_id ?>&amp;back=grades&amp;subject_id=<?= (int)$selected_subject_id ?>&amp;period_id=<?= (int)$selected_period_id ?>">
                                                <?= ($index + 1) ?>. <?= htmlspecialchars($student['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <?php foreach ($lesson_dates as $date):
                                        $grade_value = null;
                                        $current_grade = null;
                                        foreach ($existing_grades as $grade) {
                                            if ($grade['student_id'] == $student['id'] && $grade['lesson_date'] == $date['full_date']) {
                                                $grade_value = $grade['grade_value'];
                                                $current_grade = $grade;
                                                break;
                                            }
                                        }
                                        $attendance_status = $attendance_by_cell[$student['id'] . ':' . $date['full_date']] ?? 'present';
                                        ?>
                                        <td class="journal-cell-container">
                                            <div class="journal-cell" data-student-id="<?= (int)$student['id'] ?>"
                                                 data-lesson-date="<?= htmlspecialchars($date['full_date'], ENT_QUOTES, 'UTF-8') ?>"
                                                 data-grade-value="<?= htmlspecialchars((string)($grade_value ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                 data-attendance-status="<?= htmlspecialchars($attendance_status, ENT_QUOTES, 'UTF-8') ?>"
                                                 data-grade-type-id="<?= (int)($current_grade['grade_type_id'] ?? $default_grade_type_id) ?>"
                                                 data-grade-weight-id="<?= (int)($current_grade['grade_weight_id'] ?? 0) ?>">
                                                <button type="button" class="journal-cell-trigger" aria-expanded="false" aria-label="Поставить оценку или изменить посещаемость" title="Нажмите, чтобы поставить оценку или изменить посещаемость">
                                                    <span class="journal-cell-value <?= $grade_value === null && $attendance_status === 'absent' ? 'is-absent' : ($grade_value === null && $attendance_status === 'late' ? 'is-late' : ($grade_value === null ? 'is-empty' : '')) ?>"><?= htmlspecialchars((string)($grade_value ?? ($attendance_status === 'absent' ? 'Н' : ($attendance_status === 'late' ? 'О' : '+'))), ENT_QUOTES, 'UTF-8') ?></span>
                                                    <span class="journal-cell-attendance <?= $grade_value !== null && $attendance_status === 'absent' ? 'is-absent' : ($grade_value !== null && $attendance_status === 'late' ? 'is-late' : '') ?>" aria-hidden="true"><?= $grade_value !== null && $attendance_status === 'absent' ? 'Н' : ($grade_value !== null && $attendance_status === 'late' ? 'О' : '') ?></span>
                                                </button>
                                                <div class="journal-popover" role="dialog" aria-label="Изменить оценку и посещаемость" hidden>
                                                    <div class="journal-action-group" aria-label="Оценка">
                                                        <?php foreach (['5', '4', '3', '2'] as $value): ?>
                                                            <button type="button" class="journal-action grade-action" data-grade-value="<?= $value ?>"><?= $value ?></button>
                                                        <?php endforeach; ?>
                                                    </div>
                                                    <div class="journal-action-group" aria-label="Посещаемость и отметки">
                                                        <button type="button" class="journal-action attendance-action absent-action" data-attendance-status="absent">Н</button>
                                                        <button type="button" class="journal-action attendance-action late-action" data-attendance-status="late">О</button>
                                                        <button type="button" class="journal-action grade-action" data-grade-value="Б">Б</button>
                                                        <button type="button" class="journal-action grade-action" data-grade-value="От">От</button>
                                                        <button type="button" class="journal-action grade-action" data-grade-value="УП">УП</button>
                                                    </div>
                                                    <div class="journal-action-group">
                                                        <button type="button" class="journal-action attendance-action present-action" data-attendance-status="present">Присутствует</button>
                                                        <button type="button" class="journal-action clear-action">Очистить</button>
                                                    </div>
                                                    <p class="journal-warning" role="status" hidden>Ученик отмечен отсутствующим.</p>
                                                    <details class="journal-extra" <?= $current_grade ? '' : 'hidden' ?>>
                                                            <summary>Ещё</summary>
                                                            <label>Тип
                                                                <select class="grade-meta-type">
                                                                    <?php if ($current_grade && !empty($current_grade['grade_type_id']) && empty($current_grade['grade_type_active'])): ?>
                                                                        <option value="<?= (int)$current_grade['grade_type_id'] ?>" selected>
                                                                            <?= htmlspecialchars($current_grade['grade_type_name'] ?? 'Отключённый тип', ENT_QUOTES, 'UTF-8') ?> (отключён)
                                                                        </option>
                                                                    <?php endif; ?>
                                                                    <?php foreach ($grade_types as $grade_type): ?>
                                                                        <option value="<?= (int)$grade_type['id'] ?>" <?= (int)($current_grade['grade_type_id'] ?? $default_grade_type_id) === (int)$grade_type['id'] ? 'selected' : '' ?>>
                                                                            <?= htmlspecialchars($grade_type['name'], ENT_QUOTES, 'UTF-8') ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </label>
                                                            <label>Вес
                                                                <select class="grade-meta-weight">
                                                                    <option value="" <?= empty($current_grade['grade_weight_id']) ? 'selected' : '' ?>>Вес типа (или 1.00)</option>
                                                                    <?php if ($current_grade && !empty($current_grade['grade_weight_id']) && !in_array((int)$current_grade['grade_weight_id'], array_map('intval', array_column($grade_weights, 'id')), true)): ?>
                                                                        <option value="<?= (int)$current_grade['grade_weight_id'] ?>" selected>
                                                                            <?= htmlspecialchars($current_grade['grade_weight_name'] ?? 'Отключённый вес', ENT_QUOTES, 'UTF-8') ?>
                                                                            (<?= htmlspecialchars((string)($current_grade['grade_weight_value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>, отключён)
                                                                        </option>
                                                                    <?php endif; ?>
                                                                    <?php foreach ($grade_weights as $grade_weight): ?>
                                                                        <option value="<?= (int)$grade_weight['id'] ?>" <?= (int)($current_grade['grade_weight_id'] ?? 0) === (int)$grade_weight['id'] ? 'selected' : '' ?>>
                                                                            <?= htmlspecialchars($grade_weight['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string)$grade_weight['weight'], ENT_QUOTES, 'UTF-8') ?>)
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </label>
                                                            <button type="button" class="journal-action save-meta-action">Сохранить параметры</button>
                                                    </details>
                                                    <p class="journal-cell-message" role="status" hidden></p>
                                                </div>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="average-cell">
                                        <strong class="student-average" data-student-id="<?= (int)$student['id'] ?>"><?= $student_averages[$student['id']] ?? 'н/д' ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 6px; border-left: 4px solid #ffc107;">
                            <p style="margin: 0; font-size: 0.9em;"><strong>Примечание:</strong> Б - Неудовлетворительно, От - Отсутствовал, УП - Удовлетворительно</p>
                            <p style="margin: 5px 0 0 0; font-size: 0.8em; color: #666;">Нажмите на ячейку, чтобы поставить оценку или отметить посещаемость. Стрелки перемещают выбор, цифры 2–5 ставят оценку, клавиша «Н» отмечает отсутствие, Delete очищает оценку.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="icon">📝</div>
                    <h3>Выберите класс и предмет</h3>
                    <p>Для просмотра журнала оценок выберите класс и предмет из фильтров выше</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../js/teacher_journal.js?v=<?= (int)filemtime(__DIR__ . '/../js/teacher_journal.js') ?>" defer></script>
</body>
</html>