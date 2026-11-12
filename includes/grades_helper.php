<?php

function isNumericGrade($value): bool
{
    return in_array((string)$value, ['2', '3', '4', '5'], true);
}

function effectiveGradeWeight(array $grade): float
{
    // A linked historical weight remains usable after deactivation; new defaults must be active.
    if (!empty($grade['grade_weight_id'])
        && isset($grade['grade_weight_value'])
        && is_numeric($grade['grade_weight_value'])
        && (float)$grade['grade_weight_value'] > 0) {
        return (float)$grade['grade_weight_value'];
    }

    if (isset($grade['default_weight_value'])
        && is_numeric($grade['default_weight_value'])
        && (float)$grade['default_weight_value'] > 0
        && (!array_key_exists('default_weight_active', $grade) || (int)$grade['default_weight_active'] === 1)) {
        return (float)$grade['default_weight_value'];
    }

    return 1.0;
}

function calculateWeightedAverage(array $grades): ?float
{
    $weightedTotal = 0.0;
    $weightTotal = 0.0;

    foreach ($grades as $grade) {
        if (!is_array($grade) || !isNumericGrade($grade['grade_value'] ?? '')) {
            continue;
        }

        $weight = effectiveGradeWeight($grade);
        $weightedTotal += (float)$grade['grade_value'] * $weight;
        $weightTotal += $weight;
    }

    return $weightTotal > 0 ? round($weightedTotal / $weightTotal, 2) : null;
}

function calculateOverallAverage(array $subjectAverages): ?float
{
    // The pupil-wide average is the arithmetic mean of the per-subject weighted averages.
    $validAverages = array_filter($subjectAverages, static function ($average): bool {
        return $average !== null && is_numeric($average);
    });

    return $validAverages
        ? round(array_sum($validAverages) / count($validAverages), 2)
        : null;
}
