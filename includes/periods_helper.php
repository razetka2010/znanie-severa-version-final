<?php

function getCurrentPeriod(PDO $pdo, int $school_id): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, school_id, name, start_date, end_date, is_active, is_current, is_locked, academic_year
        FROM academic_periods
        WHERE school_id = ? AND is_active = 1 AND is_current = 1
        ORDER BY start_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$school_id]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($period) {
        return $period;
    }

    $stmt = $pdo->prepare("
        SELECT id, school_id, name, start_date, end_date, is_active, is_current, is_locked, academic_year
        FROM academic_periods
        WHERE school_id = ? AND is_active = 1 AND CURRENT_DATE BETWEEN start_date AND end_date
        ORDER BY start_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$school_id]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getPeriodForDate(PDO $pdo, int $school_id, string $date): ?array
{
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Некорректная дата периода.');
    }

    $stmt = $pdo->prepare("
        SELECT id, school_id, name, start_date, end_date, is_active, is_current, is_locked, academic_year
        FROM academic_periods
        WHERE school_id = ? AND is_active = 1 AND ? BETWEEN start_date AND end_date
        ORDER BY start_date DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$school_id, $date]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getSchoolPeriods(PDO $pdo, int $school_id, ?string $academic_year = null): array
{
    $sql = "
        SELECT id, school_id, name, start_date, end_date, is_active, is_current, is_locked, academic_year
        FROM academic_periods
        WHERE school_id = ? AND is_active = 1
    ";
    $params = [$school_id];
    if ($academic_year !== null) {
        $sql .= ' AND academic_year = ?';
        $params[] = $academic_year;
    }
    $sql .= ' ORDER BY start_date DESC, id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function isDateLocked(PDO $pdo, int $school_id, string $date): bool
{
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Некорректная дата периода.');
    }

    $stmt = $pdo->prepare("
        SELECT 1
        FROM academic_periods
        WHERE school_id = ? AND is_locked = 1
            AND ? BETWEEN start_date AND end_date
        LIMIT 1
    ");
    $stmt->execute([$school_id, $date]);

    return (bool)$stmt->fetchColumn();
}
