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
/**
 * Why: Approved / changed overtime lives in extra_hours_approved_amount, not in
 * hours_today, so totals must add it. Legacy rows may already include OT in
 * hours_today (e.g. 10h) — GREATEST(worked, LEAST(worked, 8) + approved) avoids
 * double counting. Mirrors creditedHours() in frontend/src/lib/workPeriodUtils.ts.
 */
function br_credited_hours_sql(PDO $conn, string $alias = ''): string
{
    static $hasColumns = null;
    $p = $alias !== '' ? $alias . '.' : '';
    if ($hasColumns === null) {
        try {
            $cols = $conn->query("SHOW COLUMNS FROM work_submissions")->fetchAll(PDO::FETCH_COLUMN);
            $hasColumns = in_array('extra_hours_approval_status', $cols, true)
                && in_array('extra_hours_approved_amount', $cols, true);
        } catch (Throwable $e) {
            $hasColumns = false;
        }
    }
    if (!$hasColumns) {
        return "COALESCE({$p}hours_today, 0)";
    }
    return "CASE WHEN {$p}extra_hours_approval_status IN ('approved', 'changed')"
        . " AND COALESCE({$p}extra_hours_approved_amount, {$p}overtime_hours, 0) > 0"
        . " THEN GREATEST(COALESCE({$p}hours_today, 0), LEAST(COALESCE({$p}hours_today, 0), 8)"
        . " + COALESCE({$p}extra_hours_approved_amount, {$p}overtime_hours, 0))"
        . " ELSE COALESCE({$p}hours_today, 0) END";
}

/**
 * Row-level twin of br_credited_hours_sql() for data already in memory.
 *
 * @param array<string, mixed> $row
 */
function br_credited_hours_row(array $row): float
{
    $worked = (float)($row['hours_today'] ?? 0);
    $status = strtolower(trim((string)($row['extra_hours_approval_status'] ?? '')));
    if ($status !== 'approved' && $status !== 'changed') {
        return $worked;
    }
    $approved = (float)($row['extra_hours_approved_amount'] ?? $row['overtime_hours'] ?? 0);
    if ($approved <= 0) {
        return $worked;
    }
    return round(max($worked, min($worked, 8) + $approved), 2);
}

function br_compute_calendar_month_totals(PDO $conn, $userId, string $submissionDate): array
{
    $monthStart = br_calendar_month_start($submissionDate);
    $credited = br_credited_hours_sql($conn);
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS days, COALESCE(SUM($credited), 0) AS hours
         FROM work_submissions
         WHERE user_id = ? AND submission_date >= ? AND submission_date <= ?"
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

function br_server_today(): string
{
    return (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
}

function br_work_submission_row_complete(array $row): bool
{
    $hours = (float)($row['hours_today'] ?? 0);
    if ($hours < 1) {
        return false;
    }

    $taskCount = 0;
    foreach (['completed_tasks', 'pending_tasks', 'ongoing_tasks', 'notes'] as $field) {
        $text = trim((string)($row[$field] ?? ''));
        if ($text === '') {
            continue;
        }
        $lines = array_filter(array_map('trim', explode("\n", $text)), static function ($line) {
            return $line !== '';
        });
        $taskCount += count($lines);
    }

    return $taskCount > 0;
}

/**
 * Prevent attendance backdating via manipulated device clocks.
 *
 * @return array{ok:bool,message?:string,date:string}
 */
function br_validate_attendance_date(PDO $conn, int $userId, string $requestedDate, string $context, bool $isAdmin = false): array
{
    $requestedDate = substr(trim($requestedDate), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
        return ['ok' => false, 'message' => 'Invalid submission date format.', 'date' => $requestedDate];
    }

    $serverToday = br_server_today();
    if ($requestedDate > $serverToday) {
        return ['ok' => false, 'message' => 'Future dates are not allowed.', 'date' => $serverToday];
    }

    if ($isAdmin) {
        return ['ok' => true, 'date' => $requestedDate];
    }

    if ($context === 'check_in') {
        if ($requestedDate !== $serverToday) {
            return [
                'ok' => false,
                'message' => 'Check-in is only allowed for today. Please correct your device date and time.',
                'date' => $serverToday,
            ];
        }
        return ['ok' => true, 'date' => $serverToday];
    }

    if ($requestedDate === $serverToday) {
        return ['ok' => true, 'date' => $serverToday];
    }

    $stmt = $conn->prepare(
        'SELECT submission_date, check_in_time, hours_today, completed_tasks, pending_tasks, ongoing_tasks, notes
         FROM work_submissions
         WHERE user_id = ? AND submission_date = ?
         LIMIT 1'
    );
    $stmt->execute([$userId, $requestedDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row && !empty($row['check_in_time']) && !br_work_submission_row_complete($row)) {
        return ['ok' => true, 'date' => $requestedDate];
    }

    if ($row && br_work_submission_row_complete($row)) {
        return [
            'ok' => false,
            'message' => 'Past attendance records cannot be changed.',
            'date' => $serverToday,
        ];
    }

    return [
        'ok' => false,
        'message' => 'You cannot submit attendance for past dates. Work date must match your check-in day.',
        'date' => $serverToday,
    ];
}
