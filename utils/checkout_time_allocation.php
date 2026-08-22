<?php
/**
 * Why: Checkout hours must tally — fixed lunch/breaks/Growth Glimpse plus per-project hours.
 */

if (!defined('BR_LUNCH_HOURS')) {
    define('BR_LUNCH_HOURS', 0.5);
}
if (!defined('BR_BREAK_HOURS')) {
    define('BR_BREAK_HOURS', 0.5);
}
if (!defined('BR_GROWTH_GLIMPSE_HOURS')) {
    define('BR_GROWTH_GLIMPSE_HOURS', 0.5);
}
if (!defined('BR_HOURS_TALLY_TOLERANCE')) {
    define('BR_HOURS_TALLY_TOLERANCE', 0.05);
}

/**
 * Growth Glimpse runs Tuesday, Thursday, Saturday (Asia/Kolkata weekday).
 */
function br_is_growth_glimpse_day(string $dateStr): bool
{
    $raw = trim($dateStr);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return false;
    }
    try {
        $tz = new DateTimeZone('Asia/Kolkata');
        $d = new DateTimeImmutable($raw . ' 12:00:00', $tz);
        $weekday = (int) $d->format('N'); // 1=Mon … 7=Sun
        return $weekday === 2 || $weekday === 4 || $weekday === 6;
    } catch (Exception $e) {
        return false;
    }
}

function br_clamp_hours($value): float
{
    $n = (float) $value;
    if (!is_finite($n) || $n < 0) {
        return 0.0;
    }
    return min(24.0, round($n, 1));
}

/**
 * @return array{lunch_hours:float,break_hours:float,growth_glimpse_hours:float,other_hours:float}
 */
function br_build_time_allocation(string $dateStr, $otherHours = 0): array
{
    return [
        'lunch_hours' => BR_LUNCH_HOURS,
        'break_hours' => BR_BREAK_HOURS,
        'growth_glimpse_hours' => br_is_growth_glimpse_day($dateStr) ? BR_GROWTH_GLIMPSE_HOURS : 0.0,
        'other_hours' => br_clamp_hours($otherHours),
    ];
}

/**
 * Normalize client payload; fixed slots always overwrite client values.
 *
 * @return array{lunch_hours:float,break_hours:float,growth_glimpse_hours:float,other_hours:float}
 */
function br_normalize_time_allocation($raw, string $dateStr): array
{
    $other = 0.0;
    if (is_array($raw) && array_key_exists('other_hours', $raw)) {
        $other = br_clamp_hours($raw['other_hours']);
    }
    return br_build_time_allocation($dateStr, $other);
}

/**
 * @param array $allocation
 * @param array $projectUpdates normalized project_updates rows
 */
function br_allocation_total(array $allocation, array $projectUpdates): float
{
    $sum = br_clamp_hours($allocation['lunch_hours'] ?? 0)
        + br_clamp_hours($allocation['break_hours'] ?? 0)
        + br_clamp_hours($allocation['growth_glimpse_hours'] ?? 0)
        + br_clamp_hours($allocation['other_hours'] ?? 0);
    foreach ($projectUpdates as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sum += br_clamp_hours($row['hours'] ?? 0);
    }
    return round($sum, 1);
}

function br_hours_tally_matches(float $hoursToday, array $allocation, array $projectUpdates): bool
{
    $target = br_clamp_hours($hoursToday);
    $allocated = br_allocation_total($allocation, $projectUpdates);
    return abs($target - $allocated) <= BR_HOURS_TALLY_TOLERANCE;
}
