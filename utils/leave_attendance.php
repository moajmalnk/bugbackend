<?php

/**
 * Leave + joining-date helpers for attendance gating and balances.
 */

function br_leave_tables_ready(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $t1 = $conn->query("SHOW TABLES LIKE 'leave_requests'");
        $t2 = $conn->query("SHOW TABLES LIKE 'leave_types'");
        $ready = ($t1 && $t1->fetch(PDO::FETCH_NUM)) && ($t2 && $t2->fetch(PDO::FETCH_NUM));
    } catch (Exception $e) {
        $ready = false;
    }
    return $ready;
}

function br_users_has_joining_date(PDO $conn): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'joining_date'");
        $has = (bool)($res && $res->fetch(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

/**
 * Effective joining date (YYYY-MM-DD). Falls back to DATE(created_at).
 */
function br_user_joining_date(PDO $conn, string $userId): ?string
{
    $hasJoining = br_users_has_joining_date($conn);
    $sql = $hasJoining
        ? 'SELECT joining_date, DATE(created_at) AS created_day FROM users WHERE id = ? LIMIT 1'
        : 'SELECT NULL AS joining_date, DATE(created_at) AS created_day FROM users WHERE id = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $joining = trim((string)($row['joining_date'] ?? ''));
    if ($joining !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $joining)) {
        return $joining;
    }
    $created = trim((string)($row['created_day'] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $created) ? $created : null;
}

/**
 * Inclusive calendar-day count between two YYYY-MM-DD dates.
 */
function br_leave_calendar_days(string $startDate, string $endDate): float
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $start = DateTime::createFromFormat('Y-m-d', $startDate, $tz);
    $end = DateTime::createFromFormat('Y-m-d', $endDate, $tz);
    if (!$start || !$end) {
        return 0.0;
    }
    if ($end < $start) {
        return 0.0;
    }
    return (float)($start->diff($end)->days + 1);
}

/**
 * Why: Leave balances should skip Sundays and company office-closed holidays.
 */
function br_leave_working_days(string $startDate, string $endDate, ?PDO $conn = null): float
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $start = DateTime::createFromFormat('Y-m-d', $startDate, $tz);
    $end = DateTime::createFromFormat('Y-m-d', $endDate, $tz);
    if (!$start || !$end || $end < $start) {
        return 0.0;
    }

    if (!function_exists('br_is_office_closed')) {
        require_once __DIR__ . '/bug_dates_recurrence.php';
    }

    $days = 0.0;
    $cursor = clone $start;
    while ($cursor <= $end) {
        $ymd = $cursor->format('Y-m-d');
        if (!br_is_office_closed($ymd, $conn)) {
            $days += 1.0;
        }
        $cursor->modify('+1 day');
    }
    return $days;
}

/**
 * Days of a leave span that fall inside a calendar month (YYYY-MM).
 * Skips Sundays / office-closed holidays when $conn is provided.
 */
function br_leave_days_in_month(
    string $startDate,
    string $endDate,
    string $yearMonth,
    ?PDO $conn = null
): float {
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        return 0.0;
    }
    $monthStart = $yearMonth . '-01';
    $tz = new DateTimeZone('Asia/Kolkata');
    $ms = new DateTime($monthStart, $tz);
    $me = clone $ms;
    $me->modify('last day of this month');
    $monthEnd = $me->format('Y-m-d');

    $overlapStart = max($startDate, $monthStart);
    $overlapEnd = min($endDate, $monthEnd);
    if ($overlapStart > $overlapEnd) {
        return 0.0;
    }
    if ($conn) {
        return br_leave_working_days($overlapStart, $overlapEnd, $conn);
    }
    return br_leave_calendar_days($overlapStart, $overlapEnd);
}

/**
 * Approved leave covering a specific date, or null.
 *
 * @return array{id:int,leave_type_id:int,leave_type_code:?string,leave_type_name:?string,start_date:string,end_date:string}|null
 */
function br_approved_leave_on_date(PDO $conn, string $userId, string $date): ?array
{
    if (!br_leave_tables_ready($conn)) {
        return null;
    }
    $date = substr(trim($date), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT lr.id, lr.leave_type_id, lr.start_date, lr.end_date,
                lt.code AS leave_type_code, lt.name AS leave_type_name
         FROM leave_requests lr
         LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.user_id = ?
           AND lr.status = 'approved'
           AND lr.start_date <= ?
           AND lr.end_date >= ?
         ORDER BY lr.id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId, $date, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Approved leave rows overlapping [from, to] inclusive.
 *
 * @return list<array>
 */
function br_approved_leaves_in_range(PDO $conn, string $userId, string $from, string $to): array
{
    if (!br_leave_tables_ready($conn)) {
        return [];
    }
    $stmt = $conn->prepare(
        "SELECT lr.id, lr.user_id, lr.leave_type_id, lr.start_date, lr.end_date, lr.days_count,
                lr.reason, lr.status, lt.code AS leave_type_code, lt.name AS leave_type_name
         FROM leave_requests lr
         LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.user_id = ?
           AND lr.status = 'approved'
           AND lr.start_date <= ?
           AND lr.end_date >= ?
         ORDER BY lr.start_date ASC"
    );
    $stmt->execute([$userId, $to, $from]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Expand approved leave rows into per-day map within [from, to].
 *
 * @return array<string, array{day_status:string,leave_type_code:?string,leave_type_name:?string,leave_request_id:int,leave_reason:?string}>
 */
function br_leave_day_map(PDO $conn, string $userId, string $from, string $to): array
{
    $map = [];
    $leaves = br_approved_leaves_in_range($conn, $userId, $from, $to);
    $tz = new DateTimeZone('Asia/Kolkata');
    foreach ($leaves as $leave) {
        $start = max($from, (string)$leave['start_date']);
        $end = min($to, (string)$leave['end_date']);
        if ($start > $end) {
            continue;
        }
        $cursor = DateTime::createFromFormat('Y-m-d', $start, $tz);
        $endDt = DateTime::createFromFormat('Y-m-d', $end, $tz);
        if (!$cursor || !$endDt) {
            continue;
        }
        while ($cursor <= $endDt) {
            $key = $cursor->format('Y-m-d');
            if (!isset($map[$key])) {
                $map[$key] = [
                    'day_status' => 'leave',
                    'leave_type_code' => $leave['leave_type_code'] ?? null,
                    'leave_type_name' => $leave['leave_type_name'] ?? null,
                    'leave_request_id' => (int)$leave['id'],
                    'leave_reason' => isset($leave['reason']) ? (string)$leave['reason'] : null,
                ];
            }
            $cursor->modify('+1 day');
        }
    }
    return $map;
}

/**
 * Hours credited toward work stats for an approved leave day.
 * Why: Casual/Paid (paid), legacy Personal, and admin Official Leave (corporate)
 * each count as a full workday (8h). Sick, unpaid, on-duty, and others credit 0.
 */
function br_leave_credited_hours(?string $leaveTypeCode): float
{
    $code = strtolower(trim((string)$leaveTypeCode));
    return in_array($code, ['paid', 'personal', 'corporate'], true) ? 8.0 : 0.0;
}

/**
 * Merge approved leave into a work-submissions list (Daily Update / my submissions).
 * Why: Admin work-stats already injects leave-only days; the developer Daily Update
 * feed must show the same leave days with credited hours so dashboards stay aligned.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function br_merge_leave_into_submission_rows(PDO $conn, string $userId, string $from, string $to, array $rows): array
{
    $leaveMap = br_leave_day_map($conn, $userId, $from, $to);
    if ($leaveMap === []) {
        return $rows;
    }

    $seen = [];
    foreach ($rows as &$row) {
        $d = (string)($row['submission_date'] ?? '');
        if ($d === '') {
            continue;
        }
        $seen[$d] = true;
        if (!isset($leaveMap[$d])) {
            continue;
        }
        $info = $leaveMap[$d];
        $row['day_status'] = 'leave';
        $row['leave_type_code'] = $info['leave_type_code'];
        $row['leave_type_name'] = $info['leave_type_name'];
        $row['leave_request_id'] = $info['leave_request_id'];
        $row['leave_reason'] = $info['leave_reason'] ?? null;
        $credited = br_leave_credited_hours($info['leave_type_code'] ?? null);
        if ($credited > (float)($row['hours_today'] ?? 0)) {
            $row['hours_today'] = $credited;
        }
    }
    unset($row);

    foreach ($leaveMap as $leaveDate => $info) {
        if (isset($seen[$leaveDate])) {
            continue;
        }
        $credited = br_leave_credited_hours($info['leave_type_code'] ?? null);
        $rows[] = [
            'id' => null,
            'user_id' => $userId,
            'submission_date' => $leaveDate,
            'start_time' => null,
            'check_in_time' => null,
            'hours_today' => $credited,
            'overtime_hours' => 0,
            'requested_extra_hours' => 0,
            'total_break_minutes' => 0,
            'completed_tasks' => '',
            'pending_tasks' => '',
            'ongoing_tasks' => '',
            'notes' => '',
            'day_status' => 'leave',
            'leave_type_code' => $info['leave_type_code'],
            'leave_type_name' => $info['leave_type_name'],
            'leave_request_id' => $info['leave_request_id'],
            'leave_reason' => $info['leave_reason'] ?? null,
        ];
    }

    usort($rows, static function ($a, $b) {
        return strcmp((string)($b['submission_date'] ?? ''), (string)($a['submission_date'] ?? ''));
    });

    return $rows;
}

/**
 * Add leave-only days and paid-leave hour credits into month totals.
 *
 * @param array<string, float> $submissionHoursByDate date => hours_today
 * @return array{days:int,hours:float}
 */
function br_apply_leave_to_work_totals(array $submissionHoursByDate, array $leaveMap): array
{
    $days = count($submissionHoursByDate);
    $hours = array_sum($submissionHoursByDate);
    foreach ($leaveMap as $date => $info) {
        $credited = br_leave_credited_hours($info['leave_type_code'] ?? null);
        if (!isset($submissionHoursByDate[$date])) {
            $days += 1;
            $hours += $credited;
            continue;
        }
        $existing = (float)$submissionHoursByDate[$date];
        if ($credited > $existing) {
            $hours += ($credited - $existing);
        }
    }
    return ['days' => (int)$days, 'hours' => (float)$hours];
}

/**
 * Whether pending/approved leave overlaps the given range (excluding optional request id).
 */
function br_leave_has_overlap(PDO $conn, string $userId, string $startDate, string $endDate, ?int $excludeId = null): bool
{
    if (!br_leave_tables_ready($conn)) {
        return false;
    }
    $sql = "SELECT id FROM leave_requests
            WHERE user_id = ?
              AND status IN ('pending', 'approved')
              AND start_date <= ?
              AND end_date >= ?";
    $params = [$userId, $endDate, $startDate];
    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Used approved days for a leave type in a calendar month (YYYY-MM).
 */
function br_leave_used_days_in_month(PDO $conn, string $userId, int $leaveTypeId, string $yearMonth): float
{
    if (!br_leave_tables_ready($conn) || !preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        return 0.0;
    }
    $monthStart = $yearMonth . '-01';
    $tz = new DateTimeZone('Asia/Kolkata');
    $me = new DateTime($monthStart, $tz);
    $me->modify('last day of this month');
    $monthEnd = $me->format('Y-m-d');

    $stmt = $conn->prepare(
        "SELECT start_date, end_date FROM leave_requests
         WHERE user_id = ?
           AND leave_type_id = ?
           AND status = 'approved'
           AND start_date <= ?
           AND end_date >= ?"
    );
    $stmt->execute([$userId, $leaveTypeId, $monthEnd, $monthStart]);
    $used = 0.0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $used += br_leave_days_in_month((string)$row['start_date'], (string)$row['end_date'], $yearMonth);
    }
    return $used;
}

/**
 * @return list<array{id:int,code:string,name:string,monthly_quota:float,used:float,remaining:float}>
 */
function br_leave_balances_for_month(PDO $conn, string $userId, string $yearMonth): array
{
    if (!br_leave_tables_ready($conn)) {
        return [];
    }
    $stmt = $conn->query(
        "SELECT id, code, name, monthly_quota FROM leave_types WHERE is_active = 1 ORDER BY id ASC"
    );
    $types = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach ($types as $type) {
        $quota = (float)$type['monthly_quota'];
        $used = br_leave_used_days_in_month($conn, $userId, (int)$type['id'], $yearMonth);
        $out[] = [
            'id' => (int)$type['id'],
            'code' => (string)$type['code'],
            'name' => (string)$type['name'],
            'monthly_quota' => $quota,
            'used' => $used,
            'remaining' => max(0.0, $quota - $used),
        ];
    }
    return $out;
}

/**
 * Gate check-in / submit / admin hours for joining date + approved leave.
 *
 * @return array{ok:bool,message?:string,reason?:string,joining_date?:string,leave?:array}
 */
function br_assert_attendance_allowed(PDO $conn, string $userId, string $date, string $context = 'attendance'): array
{
    $date = substr(trim($date), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'message' => 'Invalid date format.', 'reason' => 'invalid_date'];
    }

    $joining = br_user_joining_date($conn, $userId);
    if ($joining !== null && $date < $joining) {
        $verb = $context === 'admin_hours'
            ? 'Cannot record hours'
            : ($context === 'check_in' ? 'Cannot check in' : 'Cannot submit attendance');
        return [
            'ok' => false,
            'message' => "{$verb} before joining date ({$joining}).",
            'reason' => 'before_joining',
            'joining_date' => $joining,
        ];
    }

    $leave = br_approved_leave_on_date($conn, $userId, $date);
    if ($leave) {
        $typeName = trim((string)($leave['leave_type_name'] ?? 'leave'));
        $verb = $context === 'admin_hours'
            ? 'Cannot record hours'
            : ($context === 'check_in' ? 'Cannot check in' : 'Cannot submit attendance');
        return [
            'ok' => false,
            'message' => "{$verb}: user is on approved {$typeName} that day.",
            'reason' => 'on_leave',
            'leave' => $leave,
        ];
    }

    return ['ok' => true];
}
