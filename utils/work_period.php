<?php

function br_calendar_month_start(string $date): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = new DateTime($date, $tz);
    return $dt->format('Y-m-01');
}

function br_calendar_month_end(string $date): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = new DateTime($date, $tz);
    $dt->modify('last day of this month');
    return $dt->format('Y-m-d');
}

function br_calendar_month_period_label(string $date): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = new DateTime($date, $tz);
    return $dt->format('F Y');
}

function br_calendar_month_range_label(string $date): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $start = new DateTime(br_calendar_month_start($date), $tz);
    $end = new DateTime(br_calendar_month_end($date), $tz);
    return $start->format('M d') . ' – ' . $end->format('M d');
}

/**
 * Count working days and hours from the 1st of the submission month through $submissionDate (inclusive).
 *
 * @return array{days:int,hours:float,month_start:string,period_label:string,period_range:string}
 */
function br_compute_calendar_month_totals(PDO $conn, $userId, string $submissionDate): array
{
    $monthStart = br_calendar_month_start($submissionDate);
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS days, COALESCE(SUM(hours_today), 0) AS hours
         FROM work_submissions
         WHERE user_id = ? AND submission_date >= ? AND submission_date <= ?'
    );
    $stmt->execute([$userId, $monthStart, $submissionDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['days' => 0, 'hours' => 0];

    return [
        'days' => (int)($row['days'] ?? 0),
        'hours' => (float)($row['hours'] ?? 0),
        'month_start' => $monthStart,
        'period_label' => br_calendar_month_period_label($submissionDate),
        'period_range' => br_calendar_month_range_label($submissionDate),
    ];
}
