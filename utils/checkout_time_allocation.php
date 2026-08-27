<?php
/**
 * Why: Checkout hours must tally — lunch/breaks/Growth Glimpse (when attended)
 * plus per-project hours. Skipped slots count as 0 so users can reallocate.
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
 * Coerce client attendance flag; missing/legacy → true (attended).
 */
function br_coerce_attended($value, bool $fallback = true): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if ($value === 0 || $value === '0' || $value === 'false' || $value === 'no') {
        return false;
    }
    if ($value === 1 || $value === '1' || $value === 'true' || $value === 'yes') {
        return true;
    }
    return $fallback;
}

/**
 * @param array{lunch_attended?:bool,breaks_attended?:bool,growth_glimpse_attended?:bool}|null $attendance
 * @return array{
 *   lunch_hours:float,
 *   break_hours:float,
 *   growth_glimpse_hours:float,
 *   other_hours:float,
 *   lunch_attended:bool,
 *   breaks_attended:bool,
 *   growth_glimpse_attended:bool
 * }
 */
function br_build_time_allocation(string $dateStr, $otherHours = 0, $attendance = null): array
{
    $lunchAttended = true;
    $breaksAttended = true;
    $glimpseAttended = true;
    if (is_array($attendance)) {
        if (array_key_exists('lunch_attended', $attendance)) {
            $lunchAttended = br_coerce_attended($attendance['lunch_attended'], true);
        }
        if (array_key_exists('breaks_attended', $attendance)) {
            $breaksAttended = br_coerce_attended($attendance['breaks_attended'], true);
        }
        if (array_key_exists('growth_glimpse_attended', $attendance)) {
            $glimpseAttended = br_coerce_attended($attendance['growth_glimpse_attended'], true);
        }
    }

    $glimpseDay = br_is_growth_glimpse_day($dateStr);

    return [
        'lunch_attended' => $lunchAttended,
        'breaks_attended' => $breaksAttended,
        'growth_glimpse_attended' => $glimpseDay ? $glimpseAttended : false,
        'lunch_hours' => $lunchAttended ? BR_LUNCH_HOURS : 0.0,
        'break_hours' => $breaksAttended ? BR_BREAK_HOURS : 0.0,
        'growth_glimpse_hours' => ($glimpseDay && $glimpseAttended) ? BR_GROWTH_GLIMPSE_HOURS : 0.0,
        'other_hours' => br_clamp_hours($otherHours),
    ];
}

/**
 * Normalize client payload. Hours for lunch/breaks/glimpse are derived from
 * attendance flags (default attended); client cannot forge arbitrary fixed hours.
 *
 * @return array{
 *   lunch_hours:float,
 *   break_hours:float,
 *   growth_glimpse_hours:float,
 *   other_hours:float,
 *   lunch_attended:bool,
 *   breaks_attended:bool,
 *   growth_glimpse_attended:bool
 * }
 */
function br_normalize_time_allocation($raw, string $dateStr): array
{
    $other = 0.0;
    $attendance = null;
    if (is_array($raw)) {
        if (array_key_exists('other_hours', $raw)) {
            $other = br_clamp_hours($raw['other_hours']);
        }
        $attendance = [
            'lunch_attended' => br_coerce_attended($raw['lunch_attended'] ?? true, true),
            'breaks_attended' => br_coerce_attended($raw['breaks_attended'] ?? true, true),
            'growth_glimpse_attended' => br_coerce_attended($raw['growth_glimpse_attended'] ?? true, true),
        ];
    }
    return br_build_time_allocation($dateStr, $other, $attendance);
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
