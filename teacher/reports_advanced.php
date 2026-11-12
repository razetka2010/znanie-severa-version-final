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
$selected_period_id = isset($_POST['period_id']) ? (int)$_POST['period_id']
    : (isset($_GET['period_id']) ? (int)$_GET['period_id'] : (int)($current_period['id'] ?? 0));
$selected_period = null;
foreach ($periods as $period) {
    if ((int)$period['id'] === $selected_period_id) {
        $selected_period = $period;
        break;
    }
}

// Получаем информацию о учителе
$teacher_stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$teacher_stmt->execute([$teacher_id]);
$teacher = $teacher_stmt->fetch();

// Получаем классы учителя
$classes = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.name, c.grade_level
        FROM classes c 
        JOIN schedule sch ON sch.class_id = c.id AND sch.school_id = c.school_id
        WHERE c.school_id = ? AND c.is_active = 1 AND sch.teacher_id = ?
        ORDER BY c.grade_level, c.name
    ");
    $stmt->execute([$school_id, $teacher_id]);
    $classes = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Ошибка при получении классов: " . $e->getMessage());
}

// Обработка генерации отчета
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_report') {
    $report_type = is_string($_POST['report_type'] ?? null) ? $_POST['report_type'] : '';
    $class_id = intval($_POST['class_id']);
    $date_from = is_string($_POST['date_from'] ?? null) ? $_POST['date_from'] : '';
    $date_to = is_string($_POST['date_to'] ?? null) ? $_POST['date_to'] : '';
    $period_id = (int)($_POST['period_id'] ?? 0);
    if ($period_id > 0) {
        $selected_report_period = null;
        foreach (getSchoolPeriods($pdo, (int)$school_id) as $period) {
            if ((int)$period['id'] === $period_id) {
                $selected_report_period = $period;
                break;
            }
        }
        if (!$selected_report_period) {
            http_response_code(400);
            exit('Выбранный учебный период недоступен.');
        }
        $date_from = $selected_report_period['start_date'];
        $date_to = $selected_report_period['end_date'];
    }
    $format = is_string($_POST['format'] ?? null) ? $_POST['format'] : '';

    $validDateFrom = DateTime::createFromFormat('Y-m-d', $date_from);
    $validDateTo = DateTime::createFromFormat('Y-m-d', $date_to);
    if (!in_array($report_type, ['quarter_grades', 'class_statistics', 'parent_meeting', 'head_teacher'], true)
        || !in_array($format, ['pdf', 'excel'], true)
        || !$validDateFrom || $validDateFrom->format('Y-m-d') !== $date_from
        || !$validDateTo || $validDateTo->format('Y-m-d') !== $date_to
        || $date_from > $date_to) {
        http_response_code(400);
        exit('Некорректно указаны параметры отчета.');
    }

    $class_access_stmt = $pdo->prepare("
        SELECT 1 FROM classes c
        JOIN schedule sch ON sch.class_id = c.id AND sch.school_id = c.school_id
        WHERE c.id = ? AND c.school_id = ? AND c.is_active = 1 AND sch.teacher_id = ?
        LIMIT 1
    ");
    $class_access_stmt->execute([$class_id, $school_id, $teacher_id]);
    if (!$class_access_stmt->fetchColumn()) {
        http_response_code(403);
        exit('Нет доступа к выбранному классу.');
    }

    // Генерация отчета в зависимости от типа
    switch ($report_type) {
        case 'quarter_grades':
            generateQuarterGradesReport($pdo, $teacher_id, $school_id, $class_id, $date_from, $date_to, $format);
            break;
        case 'class_statistics':
            generateClassStatisticsReport($pdo, $teacher_id, $school_id, $class_id, $date_from, $date_to, $format);
            break;
        case 'parent_meeting':
            generateParentMeetingReport($pdo, $teacher_id, $school_id, $class_id, $date_from, $date_to, $format);
            break;
        case 'head_teacher':
            generateHeadTeacherReport($pdo, $teacher_id, $school_id, $class_id, $date_from, $date_to, $format);
            break;
    }
    exit;
}

// Функция генерации отчета за четверть
function generateQuarterGradesReport($pdo, $teacher_id, $school_id, $class_id, $date_from, $date_to, $format) {
    $stmt = $pdo->prepare("
        SELECT u.id AS student_id, u.full_name AS student_name,
               g.subject_id, g.grade_value, g.grade_type_id, g.grade_weight_id,
               gt.default_weight_id, gw.weight AS grade_weight_value,
               default_gw.weight AS default_weight_value,
               default_gw.is_active AS default_weight_active
        FROM users u
        JOIN classes c ON c.id = u.class_id AND c.school_id = u.school_id
        JOIN grades g ON g.student_id = u.id AND g.teacher_id = ?
            AND g.lesson_date BETWEEN ? AND ?
        JOIN subjects subject ON subject.id = g.subject_id AND subject.school_id = u.school_id
        LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = u.school_id
        LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = u.school_id
        LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = u.school_id
        WHERE u.class_id = ? AND u.school_id = ?
        ORDER BY u.full_name, g.subject_id
    ");
    $stmt->execute([$teacher_id, $date_from, $date_to, $class_id, $school_id]);
    $student_subject_grades = [];
    foreach ($stmt->fetchAll() as $grade) {
        $student_subject_grades[$grade['student_id']]['student_name'] = $grade['student_name'];
        $student_subject_grades[$grade['student_id']]['subjects'][$grade['subject_id']][] = $grade;
    }
    $grades_data = [];
    foreach ($student_subject_grades as $student) {
        $subject_averages = [];
        $total_grades = 0;
        $good_grades = 0;
        $bad_grades = 0;
        foreach ($student['subjects'] as $subject_grades) {
            $numeric_grades = array_values(array_filter($subject_grades, static function ($grade) {
                return isNumericGrade($grade['grade_value']);
            }));
            $total_grades += count($numeric_grades);
            $subject_average = calculateWeightedAverage($numeric_grades);
            if ($subject_average !== null) {
                $subject_averages[] = $subject_average;
            }
            foreach ($numeric_grades as $grade) {
                if (in_array($grade['grade_value'], ['4', '5'], true)) {
                    $good_grades++;
                } elseif ($grade['grade_value'] === '2') {
                    $bad_grades++;
                }
            }
        }
        $grades_data[] = [
            'student_name' => $student['student_name'],
            'total_grades' => $total_grades,
            'average_grade' => calculateOverallAverage($subject_averages) ?? 0,
            'good_grades' => $good_grades,
            'bad_grades' => $bad_grades
        ];
    }
    usort($grades_data, static function ($left, $right) {
        return $right['average_grade'] <=> $left['average_grade'];
    });

    // Получаем информацию о классе
    $class_stmt = $pdo->prepare("SELECT name, grade_level FROM classes WHERE id = ? AND school_id = ?");
    $class_stmt->execute([$class_id, $school_id]);
    $class_info = $class_stmt->fetch();

    if ($format === 'pdf') {
        generatePDFReport($grades_data, $class_info, 'quarter_grades', $date_from, $date_to);
    } else {
        generateExcelReport($grades_data, $class_info, 'quarter_grades', $date_from, $date_to);
    }
}

// Функция генерации статистики по классу
function generateClassStatisticsReport($pdo, $teacher_id, $school_id, $class_id, $date_from, $date_to, $format) {
    $stmt = $pdo->prepare("
        SELECT u.id AS student_id, g.subject_id, g.grade_value,
               g.grade_type_id, g.grade_weight_id, gt.default_weight_id,
               gw.weight AS grade_weight_value, default_gw.weight AS default_weight_value,
               default_gw.is_active AS default_weight_active
        FROM users u
        JOIN classes c ON c.id = u.class_id AND c.school_id = u.school_id
        JOIN roles r ON r.id = u.role_id AND r.name = 'student'
        LEFT JOIN grades g ON g.student_id = u.id AND g.teacher_id = ?
            AND g.lesson_date BETWEEN ? AND ?
            AND EXISTS (SELECT 1 FROM subjects s WHERE s.id = g.subject_id AND s.school_id = u.school_id)
        LEFT JOIN subjects subject ON subject.id = g.subject_id AND subject.school_id = u.school_id
        LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = u.school_id
        LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = u.school_id
        LEFT JOIN grade_weights default_gw ON default_gw.id = gt.default_weight_id AND default_gw.school_id = u.school_id
        WHERE u.class_id = ? AND u.school_id = ?
        ORDER BY u.id, g.subject_id
    ");
    $stmt->execute([$teacher_id, $date_from, $date_to, $class_id, $school_id]);
    $student_subject_grades = [];
    foreach ($stmt->fetchAll() as $grade) {
        $student_subject_grades[$grade['student_id']]['subjects'][$grade['subject_id']][] = $grade;
    }
    $student_averages = [];
    $excellent_students = 0;
    $good_students = 0;
    $satisfactory_students = 0;
    foreach ($student_subject_grades as $student) {
        $subject_averages = [];
        foreach ($student['subjects'] as $subject_grades) {
            $subject_average = calculateWeightedAverage($subject_grades);
            if ($subject_average !== null) {
                $subject_averages[] = $subject_average;
            }
        }
        $student_average = calculateOverallAverage($subject_averages);
        if ($student_average === null) {
            continue;
        }
        $student_averages[] = $student_average;
        if ($student_average >= 4.5) {
            $excellent_students++;
        } elseif ($student_average >= 3.5) {
            $good_students++;
        } else {
            $satisfactory_students++;
        }
    }
    $statistics = [
        'total_students' => count($student_subject_grades),
        'class_average' => calculateOverallAverage($student_averages) ?? 0,
        'excellent_students' => $excellent_students,
        'good_students' => $good_students,
        'satisfactory_students' => $satisfactory_students
    ];

    // Получаем информацию о классе
    $class_stmt = $pdo->prepare("SELECT name, grade_level FROM classes WHERE id = ? AND school_id = ?");
    $class_stmt->execute([$class_id, $school_id]);
    $class_info = $class_stmt->fetch();

    if ($format === 'pdf') {
        generatePDFReport([$statistics], $class_info, 'class_statistics', $date_from, $date_to);
    } else {
        generateExcelReport([$statistics], $class_info, 'class_statistics', $date_from, $date_to);
    }
}

function fetchAdvancedReportGrades(PDO $pdo, int $teacher_id, int $school_id, int $class_id, string $date_from, string $date_to): array
{
    $stmt = $pdo->prepare("
        SELECT g.student_id, u.full_name AS student_name, g.subject_id,
               subject.name AS subject_name, g.grade_value, g.grade_type_id,
               COALESCE(gt.name, 'Без типа') AS grade_type_name,
               g.grade_weight_id, gw.weight AS grade_weight_value,
               default_gw.weight AS default_weight_value,
               default_gw.is_active AS default_weight_active
        FROM grades g
        JOIN users u ON u.id = g.student_id AND u.school_id = ?
        JOIN classes c ON c.id = u.class_id AND c.school_id = u.school_id
        JOIN roles r ON r.id = u.role_id AND r.name = 'student'
        JOIN subjects subject ON subject.id = g.subject_id AND subject.school_id = u.school_id
        LEFT JOIN grade_types gt ON gt.id = g.grade_type_id AND gt.school_id = u.school_id
        LEFT JOIN grade_weights gw ON gw.id = g.grade_weight_id AND gw.school_id = u.school_id
        LEFT JOIN grade_weights default_gw
            ON default_gw.id = gt.default_weight_id AND default_gw.school_id = u.school_id
        WHERE g.teacher_id = ? AND u.class_id = ? AND u.is_active = 1
            AND g.lesson_date BETWEEN ? AND ?
        ORDER BY subject.name, u.full_name
    ");
    $stmt->execute([$school_id, $teacher_id, $class_id, $date_from, $date_to]);
    return $stmt->fetchAll();
}

function fetchAdvancedReportStudents(PDO $pdo, int $school_id, int $class_id): array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name
        FROM users u
        JOIN roles r ON r.id = u.role_id AND r.name = 'student'
        WHERE u.school_id = ? AND u.class_id = ? AND u.is_active = 1
        ORDER BY u.full_name
    ");
    $stmt->execute([$school_id, $class_id]);
    return $stmt->fetchAll();
}

function getAdvancedReportClassInfo(PDO $pdo, int $school_id, int $class_id): array
{
    $stmt = $pdo->prepare("SELECT name, grade_level FROM classes WHERE id = ? AND school_id = ?");
    $stmt->execute([$class_id, $school_id]);
    return $stmt->fetch() ?: [];
}

function calculateAdvancedReportSubjectStats(array $grades): array
{
    $subjects = [];
    foreach ($grades as $grade) {
        if (!isNumericGrade($grade['grade_value'])) {
            continue;
        }
        $subject_id = (int)$grade['subject_id'];
        if (!isset($subjects[$subject_id])) {
            $subjects[$subject_id] = [
                'subject_id' => $subject_id,
                'subject_name' => $grade['subject_name'],
                'grades' => [],
                'grades_count' => 0,
                'low_grades' => 0
            ];
        }
        $subjects[$subject_id]['grades'][] = $grade;
        $subjects[$subject_id]['grades_count']++;
        if ($grade['grade_value'] === '2') {
            $subjects[$subject_id]['low_grades']++;
        }
    }

    foreach ($subjects as &$subject) {
        $subject['average_grade'] = calculateWeightedAverage($subject['grades']);
        unset($subject['grades']);
    }
    unset($subject);

    return $subjects;
}

function generateParentMeetingReport(PDO $pdo, int $teacher_id, int $school_id, int $class_id, string $date_from, string $date_to, string $format): void
{
    $class_info = getAdvancedReportClassInfo($pdo, $school_id, $class_id);
    $students = fetchAdvancedReportStudents($pdo, $school_id, $class_id);
    $grades = fetchAdvancedReportGrades($pdo, $teacher_id, $school_id, $class_id, $date_from, $date_to);

    $grades_by_student = [];
    foreach ($students as $student) {
        $student_id = (int)$student['id'];
        $grades_by_student[$student_id] = [
            'student_name' => $student['full_name'],
            'subjects' => [],
            'grades_count' => 0,
            'low_grades' => 0
        ];
    }
    foreach ($grades as $grade) {
        if (!isNumericGrade($grade['grade_value'])) {
            continue;
        }
        $student_id = (int)$grade['student_id'];
        $grades_by_student[$student_id]['subjects'][(int)$grade['subject_id']][] = $grade;
        $grades_by_student[$student_id]['grades_count']++;
        if ($grade['grade_value'] === '2') {
            $grades_by_student[$student_id]['low_grades']++;
        }
    }

    $student_rows = [];
    $student_averages = [];
    $students_at_risk = 0;
    foreach ($grades_by_student as $student) {
        $subject_averages = [];
        foreach ($student['subjects'] as $subject_grades) {
            $subject_average = calculateWeightedAverage($subject_grades);
            if ($subject_average !== null) {
                $subject_averages[] = $subject_average;
            }
        }
        $average = calculateOverallAverage($subject_averages);
        if ($average !== null) {
            $student_averages[] = $average;
        }
        if ($student['low_grades'] > 0 || ($average !== null && $average < 3.5)) {
            $students_at_risk++;
        }
        $student_rows[] = [
            'student_name' => $student['student_name'],
            'grades_count' => $student['grades_count'],
            'average_grade' => $average,
            'low_grades' => $student['low_grades']
        ];
    }

    $attendance_stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(a.status = 'absent'), 0) AS absences,
            COALESCE(SUM(a.status = 'late'), 0) AS lates
        FROM attendance a
        JOIN users u ON u.id = a.student_id AND u.school_id = ?
        WHERE u.class_id = ? AND u.is_active = 1 AND a.teacher_id = ?
            AND a.lesson_date BETWEEN ? AND ?
    ");
    $attendance_stmt->execute([$school_id, $class_id, $teacher_id, $date_from, $date_to]);
    $attendance = $attendance_stmt->fetch() ?: ['absences' => 0, 'lates' => 0];

    $subject_rows = array_values(calculateAdvancedReportSubjectStats($grades));
    usort($subject_rows, static function (array $left, array $right): int {
        return ($left['average_grade'] ?? 0) <=> ($right['average_grade'] ?? 0);
    });
    $problem_subjects = array_values(array_filter($subject_rows, static function (array $subject): bool {
        return $subject['low_grades'] > 0
            || ($subject['average_grade'] !== null && $subject['average_grade'] < 3.5);
    }));

    $recommendations = [];
    if ($students_at_risk > 0) {
        $recommendations[] = 'Обсудить с семьями учеников, которым требуется поддержка, индивидуальный план занятий.';
    }
    if ($problem_subjects) {
        $recommendations[] = 'Согласовать с учителями проблемных предметов дополнительные упражнения и способы закрепления материала.';
    }
    if ((int)$attendance['absences'] > 0) {
        $recommendations[] = 'Уточнить причины пропусков и помочь ученикам восполнить пропущенный материал.';
    }
    if (!$recommendations) {
        $recommendations[] = 'Продолжать поддерживать текущий темп обучения и регулярно отслеживать прогресс учеников.';
    }

    $data = [
        'summary' => [
            'total_students' => count($students),
            'class_average' => calculateOverallAverage($student_averages),
            'students_at_risk' => $students_at_risk,
            'absences' => (int)$attendance['absences'],
            'lates' => (int)$attendance['lates']
        ],
        'students' => $student_rows,
        'subjects' => $subject_rows,
        'problem_subjects' => $problem_subjects,
        'recommendations' => $recommendations
    ];

    if ($format === 'pdf') {
        generatePDFReport($data, $class_info, 'parent_meeting', $date_from, $date_to);
    } else {
        generateExcelReport($data, $class_info, 'parent_meeting', $date_from, $date_to);
    }
}

function generateHeadTeacherReport(PDO $pdo, int $teacher_id, int $school_id, int $class_id, string $date_from, string $date_to, string $format): void
{
    $class_info = getAdvancedReportClassInfo($pdo, $school_id, $class_id);
    $grades = fetchAdvancedReportGrades($pdo, $teacher_id, $school_id, $class_id, $date_from, $date_to);
    $students = fetchAdvancedReportStudents($pdo, $school_id, $class_id);
    $subject_rows = calculateAdvancedReportSubjectStats($grades);

    $schedule_stmt = $pdo->prepare("
        SELECT subject.id AS subject_id, subject.name AS subject_name,
               COUNT(*) AS planned_lessons,
               SUM(sch.is_completed = 1) AS completed_lessons
        FROM schedule sch
        JOIN subjects subject ON subject.id = sch.subject_id AND subject.school_id = sch.school_id
        WHERE sch.teacher_id = ? AND sch.school_id = ? AND sch.class_id = ?
            AND sch.lesson_date BETWEEN ? AND ?
        GROUP BY subject.id
    ");
    $schedule_stmt->execute([$teacher_id, $school_id, $class_id, $date_from, $date_to]);
    $schedule_by_subject = [];
    foreach ($schedule_stmt->fetchAll() as $row) {
        $schedule_by_subject[(int)$row['subject_id']] = $row;
    }

    $assessment_types = [];
    $type_grades = [];
    foreach ($grades as $grade) {
        if (!isNumericGrade($grade['grade_value'])) {
            continue;
        }
        $type_name = $grade['grade_type_name'];
        $type_grades[$type_name][] = $grade;
    }
    foreach ($type_grades as $type_name => $type_grade_rows) {
        $assessment_types[] = [
            'type_name' => $type_name,
            'grades_count' => count($type_grade_rows),
            'average_grade' => calculateWeightedAverage($type_grade_rows)
        ];
    }
    usort($assessment_types, static function (array $left, array $right): int {
        return strcmp($left['type_name'], $right['type_name']);
    });

    $total_planned = 0;
    $total_completed = 0;
    $total_numeric_grades = 0;
    $student_subject_averages = [];
    foreach ($subject_rows as $subject_id => &$subject) {
        $schedule = $schedule_by_subject[$subject_id] ?? ['planned_lessons' => 0, 'completed_lessons' => 0];
        $subject['planned_lessons'] = (int)$schedule['planned_lessons'];
        $subject['completed_lessons'] = (int)$schedule['completed_lessons'];
        $subject['completion_rate'] = $subject['planned_lessons'] > 0
            ? round($subject['completed_lessons'] / $subject['planned_lessons'] * 100, 1)
            : null;
        $total_planned += $subject['planned_lessons'];
        $total_completed += $subject['completed_lessons'];
        $total_numeric_grades += $subject['grades_count'];
    }
    unset($subject);
    foreach ($schedule_by_subject as $subject_id => $schedule) {
        if (!isset($subject_rows[$subject_id])) {
            $subject_rows[$subject_id] = [
                'subject_id' => $subject_id,
                'subject_name' => $schedule['subject_name'],
                'planned_lessons' => (int)$schedule['planned_lessons'],
                'completed_lessons' => (int)$schedule['completed_lessons'],
                'completion_rate' => (int)$schedule['planned_lessons'] > 0
                    ? round((int)$schedule['completed_lessons'] / (int)$schedule['planned_lessons'] * 100, 1)
                    : null,
                'grades_count' => 0,
                'low_grades' => 0,
                'average_grade' => null
            ];
            $total_planned += (int)$schedule['planned_lessons'];
            $total_completed += (int)$schedule['completed_lessons'];
        }
    }

    $grades_by_student = [];
    foreach ($grades as $grade) {
        if (isNumericGrade($grade['grade_value'])) {
            $grades_by_student[(int)$grade['student_id']][(int)$grade['subject_id']][] = $grade;
        }
    }
    foreach ($grades_by_student as $subject_grades) {
        $averages = [];
        foreach ($subject_grades as $grades_for_subject) {
            $average = calculateWeightedAverage($grades_for_subject);
            if ($average !== null) {
                $averages[] = $average;
            }
        }
        $average = calculateOverallAverage($averages);
        if ($average !== null) {
            $student_subject_averages[] = $average;
        }
    }

    $data = [
        'summary' => [
            'total_students' => count($students),
            'total_subjects' => count($subject_rows),
            'planned_lessons' => $total_planned,
            'completed_lessons' => $total_completed,
            'completion_rate' => $total_planned > 0 ? round($total_completed / $total_planned * 100, 1) : null,
            'total_grades' => $total_numeric_grades,
            'class_average' => calculateOverallAverage($student_subject_averages)
        ],
        'subjects' => array_values($subject_rows),
        'assessment_types' => $assessment_types
    ];

    if ($format === 'pdf') {
        generatePDFReport($data, $class_info, 'head_teacher', $date_from, $date_to);
    } else {
        generateExcelReport($data, $class_info, 'head_teacher', $date_from, $date_to);
    }
}

function reportHtml($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Генерация PDF отчета
function generatePDFReport($data, $class_info, $report_type, $date_from, $date_to) {
    // Для простоты выводим HTML, который можно сохранить как PDF
    header('Content-Type: text/html; charset=utf-8');

    $report_titles = [
        'quarter_grades' => 'Отчет об успеваемости за четверть',
        'class_statistics' => 'Статистика успеваемости класса',
        'parent_meeting' => 'Материалы для родительского собрания',
        'head_teacher' => 'Отчет для завуча'
    ];
    $report_title = $report_titles[$report_type] ?? 'Отчет';
    $class_name = reportHtml($class_info['name'] ?? '');
    $class_grade = reportHtml($class_info['grade_level'] ?? '');

    echo "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>{$report_title}</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 30px; }
            .class-info { margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .footer { margin-top: 30px; text-align: right; font-size: 0.9em; color: #666; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>{$report_title}</h1>
            <div class='class-info'>
                <strong>Класс:</strong> {$class_name} ({$class_grade} класс)<br>
                <strong>Период:</strong> " . date('d.m.Y', strtotime($date_from)) . " - " . date('d.m.Y', strtotime($date_to)) . "
            </div>
        </div>
    ";

    // Вывод данных в зависимости от типа отчета
    if ($report_type === 'quarter_grades') {
        echo "<table>
            <tr>
                <th>Ученик</th>
                <th>Кол-во оценок</th>
                <th>Средний балл</th>
                <th>Хорошие оценки (4-5)</th>
                <th>Плохие оценки (2)</th>
            </tr>";
        foreach ($data as $row) {
                $student_name = reportHtml($row['student_name'] ?? '');
                echo "<tr>
                    <td>{$student_name}</td>
                    <td>" . (int)$row['total_grades'] . "</td>
                    <td>" . number_format((float)$row['average_grade'], 2, '.', '') . "</td>
                    <td>" . (int)$row['good_grades'] . "</td>
                    <td>" . (int)$row['bad_grades'] . "</td>
                </tr>";
        }
        echo "</table>";
    } elseif ($report_type === 'class_statistics') {
        $stats = $data[0];
        echo "<table>
            <tr><th>Показатель</th><th>Значение</th></tr>
            <tr><td>Всего учеников</td><td>" . (int)$stats['total_students'] . "</td></tr>
            <tr><td>Средний балл по классу</td><td>" . number_format((float)$stats['class_average'], 2, '.', '') . "</td></tr>
            <tr><td>Отличников</td><td>" . (int)$stats['excellent_students'] . "</td></tr>
            <tr><td>Хорошистов</td><td>" . (int)$stats['good_students'] . "</td></tr>
            <tr><td>Успевающих</td><td>" . (int)$stats['satisfactory_students'] . "</td></tr>
        </table>";
    } elseif ($report_type === 'parent_meeting') {
        $summary = $data['summary'];
        $classAverage = $summary['class_average'] === null
            ? 'Нет оценок'
            : number_format((float)$summary['class_average'], 2, '.', '');
        echo "<h2>Итоги класса</h2>
        <table>
            <tr><th>Показатель</th><th>Значение</th></tr>
            <tr><td>Учеников</td><td>" . (int)$summary['total_students'] . "</td></tr>
            <tr><td>Средний балл</td><td>{$classAverage}</td></tr>
            <tr><td>Ученикам требуется дополнительное внимание</td><td>" . (int)$summary['students_at_risk'] . "</td></tr>
            <tr><td>Пропуски</td><td>" . (int)$summary['absences'] . "</td></tr>
            <tr><td>Опоздания</td><td>" . (int)$summary['lates'] . "</td></tr>
        </table>
        <h2>Предметы, требующие внимания</h2>
        <table><tr><th>Предмет</th><th>Средний балл</th><th>Оценок «2»</th></tr>";
        foreach ($data['problem_subjects'] as $row) {
            $subjectName = reportHtml($row['subject_name']);
            $average = $row['average_grade'] === null
                ? '—'
                : number_format((float)$row['average_grade'], 2, '.', '');
            echo "<tr><td>{$subjectName}</td><td>{$average}</td><td>" . (int)$row['low_grades'] . "</td></tr>";
        }
        if (!$data['problem_subjects']) {
            echo "<tr><td colspan='3'>Проблемные предметы не выявлены.</td></tr>";
        }
        echo "</table><h2>Сводка по ученикам</h2>
        <table><tr><th>Ученик</th><th>Оценок</th><th>Средний балл</th><th>Оценок «2»</th></tr>";
        foreach ($data['students'] as $row) {
            $studentName = reportHtml($row['student_name']);
            $average = $row['average_grade'] === null
                ? '—'
                : number_format((float)$row['average_grade'], 2, '.', '');
            echo "<tr><td>{$studentName}</td><td>" . (int)$row['grades_count']
                . "</td><td>{$average}</td><td>" . (int)$row['low_grades'] . "</td></tr>";
        }
        echo "</table><h2>Рекомендации</h2><ul>";
        foreach ($data['recommendations'] as $recommendation) {
            echo '<li>' . reportHtml($recommendation) . '</li>';
        }
        echo "</ul>";
    } elseif ($report_type === 'head_teacher') {
        $summary = $data['summary'];
        $classAverage = $summary['class_average'] === null
            ? 'Нет оценок'
            : number_format((float)$summary['class_average'], 2, '.', '');
        $completionRate = $summary['completion_rate'] === null
            ? '—'
            : number_format((float)$summary['completion_rate'], 1, '.', '') . '%';
        echo "<h2>Общие показатели</h2>
        <table>
            <tr><th>Показатель</th><th>Значение</th></tr>
            <tr><td>Учеников</td><td>" . (int)$summary['total_students'] . "</td></tr>
            <tr><td>Предметов</td><td>" . (int)$summary['total_subjects'] . "</td></tr>
            <tr><td>Запланировано уроков</td><td>" . (int)$summary['planned_lessons'] . "</td></tr>
            <tr><td>Проведено уроков</td><td>" . (int)$summary['completed_lessons'] . "</td></tr>
            <tr><td>Выполнение расписания</td><td>{$completionRate}</td></tr>
            <tr><td>Выставлено числовых оценок</td><td>" . (int)$summary['total_grades'] . "</td></tr>
            <tr><td>Средний балл класса</td><td>{$classAverage}</td></tr>
        </table>
        <h2>Показатели по предметам</h2>
        <table><tr><th>Предмет</th><th>Уроков проведено / запланировано</th><th>Выполнение</th><th>Оценок</th><th>Средний балл</th><th>Оценок «2»</th></tr>";
        foreach ($data['subjects'] as $row) {
            $subjectName = reportHtml($row['subject_name']);
            $completion = $row['completion_rate'] === null
                ? '—'
                : number_format((float)$row['completion_rate'], 1, '.', '') . '%';
            $average = $row['average_grade'] === null
                ? '—'
                : number_format((float)$row['average_grade'], 2, '.', '');
            echo "<tr><td>{$subjectName}</td><td>" . (int)$row['completed_lessons'] . ' / '
                . (int)$row['planned_lessons'] . "</td><td>{$completion}</td><td>"
                . (int)$row['grades_count'] . "</td><td>{$average}</td><td>"
                . (int)$row['low_grades'] . "</td></tr>";
        }
        echo "</table><h2>Оценки по типам работ</h2>
        <table><tr><th>Тип работы</th><th>Оценок</th><th>Средний балл</th></tr>";
        foreach ($data['assessment_types'] as $row) {
            $typeName = reportHtml($row['type_name']);
            $average = $row['average_grade'] === null
                ? '—'
                : number_format((float)$row['average_grade'], 2, '.', '');
            echo "<tr><td>{$typeName}</td><td>" . (int)$row['grades_count'] . "</td><td>{$average}</td></tr>";
        }
        if (!$data['assessment_types']) {
            echo "<tr><td colspan='3'>За выбранный период оценок нет.</td></tr>";
        }
        echo "</table>";
    }

    echo "
        <div class='footer'>
            Сгенерировано: " . date('d.m.Y H:i') . "
        </div>
    </body>
    </html>
    ";
}

// Генерация Excel отчета
function generateExcelReport($data, $class_info, $report_type, $date_from, $date_to) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.xls"');

    $report_titles = [
        'quarter_grades' => 'Отчет об успеваемости за четверть',
        'class_statistics' => 'Статистика успеваемости класса',
        'parent_meeting' => 'Материалы для родительского собрания',
        'head_teacher' => 'Отчет для завуча'
    ];
    $report_title = reportHtml($report_titles[$report_type] ?? 'Отчет');
    $class_name = reportHtml($class_info['name'] ?? '');
    $class_grade = reportHtml($class_info['grade_level'] ?? '');

    echo "<table border='1'>
        <tr><th colspan='5' style='background: #ccc;'>{$report_title}</th></tr>
        <tr><td colspan='5'>Класс: {$class_name} ({$class_grade} класс)</td></tr>
        <tr><td colspan='5'>Период: " . date('d.m.Y', strtotime($date_from)) . " - " . date('d.m.Y', strtotime($date_to)) . "</td></tr>
    ";

    if ($report_type === 'quarter_grades') {
        echo "<tr>
            <th>Ученик</th>
            <th>Кол-во оценок</th>
            <th>Средний балл</th>
            <th>Хорошие оценки</th>
            <th>Плохие оценки</th>
        </tr>";
        foreach ($data as $row) {
            $student_name = reportHtml($row['student_name'] ?? '');
            echo "<tr>
                <td>{$student_name}</td>
                <td>" . (int)$row['total_grades'] . "</td>
                <td>" . number_format((float)$row['average_grade'], 2, '.', '') . "</td>
                <td>" . (int)$row['good_grades'] . "</td>
                <td>" . (int)$row['bad_grades'] . "</td>
            </tr>";
        }
    } elseif ($report_type === 'class_statistics') {
        $stats = $data[0];
        echo "<tr><th>Показатель</th><th colspan='4'>Значение</th></tr>
            <tr><td>Всего учеников</td><td colspan='4'>" . (int)$stats['total_students'] . "</td></tr>
            <tr><td>Средний балл по классу</td><td colspan='4'>" . number_format((float)$stats['class_average'], 2, '.', '') . "</td></tr>
            <tr><td>Отличников</td><td colspan='4'>" . (int)$stats['excellent_students'] . "</td></tr>
            <tr><td>Хорошистов</td><td colspan='4'>" . (int)$stats['good_students'] . "</td></tr>
            <tr><td>Успевающих</td><td colspan='4'>" . (int)$stats['satisfactory_students'] . "</td></tr>";
    } elseif ($report_type === 'parent_meeting') {
        $summary = $data['summary'];
        echo "<tr><th colspan='5'>Итоги класса</th></tr>
            <tr><td>Учеников</td><td colspan='4'>" . (int)$summary['total_students'] . "</td></tr>
            <tr><td>Средний балл</td><td colspan='4'>" . ($summary['class_average'] === null ? 'Нет оценок' : number_format((float)$summary['class_average'], 2, '.', '')) . "</td></tr>
            <tr><td>Ученикам требуется дополнительное внимание</td><td colspan='4'>" . (int)$summary['students_at_risk'] . "</td></tr>
            <tr><td>Пропуски</td><td colspan='4'>" . (int)$summary['absences'] . "</td></tr>
            <tr><td>Опоздания</td><td colspan='4'>" . (int)$summary['lates'] . "</td></tr>
            <tr><th>Ученик</th><th>Оценок</th><th>Средний балл</th><th>Оценок «2»</th><th></th></tr>";
        foreach ($data['students'] as $row) {
            echo '<tr><td>' . reportHtml($row['student_name']) . '</td><td>' . (int)$row['grades_count']
                . '</td><td>' . ($row['average_grade'] === null ? '—' : number_format((float)$row['average_grade'], 2, '.', ''))
                . '</td><td>' . (int)$row['low_grades'] . '</td><td></td></tr>';
        }
        echo "<tr><th>Предмет</th><th>Оценок</th><th>Средний балл</th><th>Оценок «2»</th><th></th></tr>";
        foreach ($data['subjects'] as $row) {
            echo '<tr><td>' . reportHtml($row['subject_name']) . '</td><td>' . (int)$row['grades_count']
                . '</td><td>' . ($row['average_grade'] === null ? '—' : number_format((float)$row['average_grade'], 2, '.', ''))
                . '</td><td>' . (int)$row['low_grades'] . '</td><td></td></tr>';
        }
        echo "<tr><th colspan='5'>Рекомендации</th></tr>";
        foreach ($data['recommendations'] as $recommendation) {
            echo '<tr><td colspan="5">' . reportHtml($recommendation) . '</td></tr>';
        }
    } elseif ($report_type === 'head_teacher') {
        $summary = $data['summary'];
        echo "<tr><th colspan='5'>Общие показатели</th></tr>
            <tr><td>Учеников</td><td colspan='4'>" . (int)$summary['total_students'] . "</td></tr>
            <tr><td>Предметов</td><td colspan='4'>" . (int)$summary['total_subjects'] . "</td></tr>
            <tr><td>Запланировано уроков</td><td colspan='4'>" . (int)$summary['planned_lessons'] . "</td></tr>
            <tr><td>Проведено уроков</td><td colspan='4'>" . (int)$summary['completed_lessons'] . "</td></tr>
            <tr><td>Выполнение расписания</td><td colspan='4'>" . ($summary['completion_rate'] === null ? '—' : number_format((float)$summary['completion_rate'], 1, '.', '') . '%') . "</td></tr>
            <tr><td>Выставлено числовых оценок</td><td colspan='4'>" . (int)$summary['total_grades'] . "</td></tr>
            <tr><td>Средний балл класса</td><td colspan='4'>" . ($summary['class_average'] === null ? 'Нет оценок' : number_format((float)$summary['class_average'], 2, '.', '')) . "</td></tr>
            <tr><th>Предмет</th><th>Уроков проведено / запланировано</th><th>Выполнение</th><th>Оценок</th><th>Средний балл / оценок «2»</th></tr>";
        foreach ($data['subjects'] as $row) {
            echo '<tr><td>' . reportHtml($row['subject_name']) . '</td><td>' . (int)$row['completed_lessons'] . ' / '
                . (int)$row['planned_lessons'] . '</td><td>'
                . ($row['completion_rate'] === null ? '—' : number_format((float)$row['completion_rate'], 1, '.', '') . '%')
                . '</td><td>' . (int)$row['grades_count'] . '</td><td>'
                . ($row['average_grade'] === null ? '—' : number_format((float)$row['average_grade'], 2, '.', ''))
                . ' / ' . (int)$row['low_grades'] . '</td></tr>';
        }
        echo "<tr><th>Тип работы</th><th colspan='2'>Оценок</th><th colspan='2'>Средний балл</th></tr>";
        foreach ($data['assessment_types'] as $row) {
            echo '<tr><td>' . reportHtml($row['type_name']) . '</td><td colspan="2">' . (int)$row['grades_count']
                . '</td><td colspan="2">' . ($row['average_grade'] === null ? '—' : number_format((float)$row['average_grade'], 2, '.', ''))
                . '</td></tr>';
        }
    }

    echo "</table>";
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <link rel="shortcut icon" href="../logo.png" />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Расширенные отчёты - Учитель</title>
    <style>
        .reports-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
        }

        .reports-sidebar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .reports-main {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .report-form {
            display: grid;
            gap: 15px;
        }

        .report-types {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }

        .report-type-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
            cursor: pointer;
            transition: all 0.3s;
        }

        .report-type-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .report-type-card.selected {
            background: #e3f2fd;
            border-left-color: #2196F3;
        }

        .report-preview {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
        }

        .preview-table {
            width: 100%;
            border-collapse: collapse;
        }

        .preview-table th,
        .preview-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .preview-table th {
            background: #e9ecef;
        }

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

    </style>
    <link rel="stylesheet" href="../css/teacher.css">
</head>
<body>
<div class="dashboard-container">
    <?php $teacherActivePage = 'reports_advanced'; require __DIR__ . '/../includes/teacher_sidebar.php'; ?>

    <main class="main-content">
        <header class="content-header">
            <div class="header-title">
                <h1>Расширенные отчёты</h1>
                <p>Генерация аналитических отчетов и статистики</p>
            </div>
        </header>

        <div class="content-body">
            <div class="reports-container">
                <!-- Боковая панель -->
                <div class="reports-sidebar">
                    <h3>⚙️ Настройки отчета</h3>
                    <form method="POST" class="report-form" id="reportForm">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="generate_report">

                        <div class="form-group">
                            <label>Тип отчета:</label>
                            <select name="report_type" id="reportType" required>
                                <option value="quarter_grades">📊 Успеваемость за четверть</option>
                                <option value="class_statistics">📈 Статистика класса</option>
                                <option value="parent_meeting">👥 Для родительского собрания</option>
                                <option value="head_teacher">🎓 Отчет для завуча</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Класс:</label>
                            <select name="class_id" required>
                                <option value="">Выберите класс</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>">
                                        <?= htmlspecialchars($class['name']) ?> (<?= $class['grade_level'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Учебный период:</label>
                            <select name="period_id" id="reportPeriod">
                                <option value="">Выберите период вручную</option>
                                <?php foreach ($periods as $period): ?>
                                    <option value="<?= (int)$period['id'] ?>"
                                        data-start="<?= htmlspecialchars($period['start_date'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-end="<?= htmlspecialchars($period['end_date'], ENT_QUOTES, 'UTF-8') ?>"
                                        <?= $selected_period_id === (int)$period['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($period['name'], ENT_QUOTES, 'UTF-8') ?><?= $period['is_locked'] ? ' 🔒' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Период с:</label>
                            <input id="reportDateFrom" type="date" name="date_from" required value="<?= htmlspecialchars($selected_period['start_date'] ?? date('Y-m-01'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="form-group">
                            <label>Период по:</label>
                            <input id="reportDateTo" type="date" name="date_to" required value="<?= htmlspecialchars($selected_period['end_date'] ?? date('Y-m-t'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="form-group">
                            <label>Формат:</label>
                            <select name="format" required>
                                <option value="excel">📗 Excel (.xls)</option>
                                <option value="pdf">📘 PDF</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-success">📥 Сгенерировать отчет</button>
                    </form>

                    <div class="report-types">
                        <div class="report-type-card" onclick="selectReportType('quarter_grades')">
                            <h4>📊 За четверть</h4>
                            <p>Детальная успеваемость по ученикам</p>
                        </div>
                        <div class="report-type-card" onclick="selectReportType('class_statistics')">
                            <h4>📈 Статистика</h4>
                            <p>Общая статистика по классу</p>
                        </div>
                        <div class="report-type-card" onclick="selectReportType('parent_meeting')">
                            <h4>👥 Родителям</h4>
                            <p>Материалы для собрания</p>
                        </div>
                        <div class="report-type-card" onclick="selectReportType('head_teacher')">
                            <h4>🎓 Завучу</h4>
                            <p>Формальный отчет</p>
                        </div>
                    </div>
                </div>

                <!-- Основная область -->
                <div class="reports-main">
                    <h3>👁️ Предпросмотр отчета</h3>
                    <div class="report-preview" id="reportPreview">
                        <p>Выберите тип отчета для просмотра примера</p>
                    </div>

                    <div style="margin-top: 30px;">
                        <h3>📋 История отчетов</h3>
                        <div class="reports-history">
                            <p style="text-align: center; color: #666; padding: 20px;">
                                Здесь будет отображаться история сгенерированных отчетов
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    function selectReportType(type) {
        // Убираем выделение со всех карточек
        document.querySelectorAll('.report-type-card').forEach(card => {
            card.classList.remove('selected');
        });

        // Добавляем выделение выбранной карточке
        event.currentTarget.classList.add('selected');

        // Устанавливаем значение в select
        document.getElementById('reportType').value = type;

        // Показываем предпросмотр
        showReportPreview(type);
    }

    function showReportPreview(type) {
        const preview = document.getElementById('reportPreview');
        const previews = {
            'quarter_grades': `
                    <h4>Пример отчета: Успеваемость за четверть</h4>
                    <table class="preview-table">
                        <tr>
                            <th>Ученик</th>
                            <th>Оценок</th>
                            <th>Средний балл</th>
                            <th>4 и 5</th>
                            <th>2</th>
                        </tr>
                        <tr><td>Иванов А.</td><td>15</td><td>4.2</td><td>12</td><td>0</td></tr>
                        <tr><td>Петрова Б.</td><td>14</td><td>3.8</td><td>8</td><td>1</td></tr>
                        <tr><td>Сидоров В.</td><td>16</td><td>4.5</td><td>14</td><td>0</td></tr>
                    </table>
                `,
            'class_statistics': `
                    <h4>Пример отчета: Статистика класса</h4>
                    <table class="preview-table">
                        <tr><th>Показатель</th><th>Значение</th></tr>
                        <tr><td>Всего учеников</td><td>25</td></tr>
                        <tr><td>Средний балл</td><td>4.1</td></tr>
                        <tr><td>Отличников</td><td>8</td></tr>
                        <tr><td>Хорошистов</td><td>12</td></tr>
                        <tr><td>Успевающих</td><td>5</td></tr>
                    </table>
                `,
            'parent_meeting': `
                    <h4>Пример отчета: Для родительского собрания</h4>
                    <p>Будут включены:</p>
                    <ul>
                        <li>Успеваемость класса</li>
                        <li>Проблемные темы</li>
                        <li>Рекомендации родителям</li>
                        <li>Графики прогресса</li>
                    </ul>
                `,
            'head_teacher': `
                    <h4>Пример отчета: Для завуча</h4>
                    <p>Будут включены:</p>
                    <ul>
                        <li>Формальная статистика</li>
                        <li>Выполнение учебного плана</li>
                        <li>Анализ контрольных работ</li>
                        <li>Планы на следующую четверть</li>
                    </ul>
                `
        };

        preview.innerHTML = previews[type] || '<p>Предпросмотр недоступен</p>';
    }

    // Инициализация при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        selectReportType('quarter_grades');
    });
</script>
<script>
    document.getElementById('reportPeriod')?.addEventListener('change', function () {
        const option = this.selectedOptions[0];
        if (option && option.dataset.start && option.dataset.end) {
            document.getElementById('reportDateFrom').value = option.dataset.start;
            document.getElementById('reportDateTo').value = option.dataset.end;
        }
    });
</script>
</body>
</html>