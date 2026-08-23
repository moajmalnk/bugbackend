<?php
/**
 * Why: Saturday checkout must collect one Mon–Sat weekly report per user
 * before hours can be saved, then notify admins once with that checkout.
 */

require_once __DIR__ . '/work_period.php';
if (!class_exists('Utils')) {
    require_once __DIR__ . '/../config/utils.php';
}
require_once __DIR__ . '/leave_attendance.php';
require_once __DIR__ . '/work_submission_ot.php';

const BR_WEEKLY_REPORT_FIELD_MAX = 20000;

/**
 * Why: Recycle bin soft-deletes set deleted_at; list/read paths must ignore archived rows.
 */
function br_weekly_report_deleted_at_supported(PDO $conn): bool
{
    static $cache = [];
    $key = spl_object_id($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute(['weekly_reports', 'deleted_at']);
        $cache[$key] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * SQL AND fragment excluding soft-deleted weekly reports (empty when column missing).
 */
function br_weekly_report_live_and(PDO $conn, string $alias = ''): string
{
    if (!br_weekly_report_deleted_at_supported($conn)) {
        return '';
    }
    $p = $alias !== '' ? $alias . '.' : '';
    return " AND {$p}deleted_at IS NULL";
}

/**
 * @return array{week_start:string,week_end:string}
 */
function br_monday_saturday_week_bounds(string $date): array
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = new DateTime(substr(trim($date), 0, 10) . ' 12:00:00', $tz);
    $n = (int)$dt->format('N');
    $mondayOffset = $n === 7 ? -6 : 1 - $n;
    $monday = clone $dt;
    $monday->modify(($mondayOffset >= 0 ? '+' : '') . $mondayOffset . ' days');
    $saturday = clone $monday;
    $saturday->modify('+5 days');

    return [
        'week_start' => $monday->format('Y-m-d'),
        'week_end' => $saturday->format('Y-m-d'),
    ];
}

function br_is_saturday_date(string $date): bool
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = new DateTime(substr(trim($date), 0, 10) . ' 12:00:00', $tz);
    return (int)$dt->format('N') === 6;
}

function br_weekly_report_date_label(string $date): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = new DateTime(substr(trim($date), 0, 10) . ' 12:00:00', $tz);
    return $dt->format('F j, Y');
}

function br_weekly_report_week_label(string $weekStart, string $weekEnd): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $start = new DateTime(substr(trim($weekStart), 0, 10) . ' 12:00:00', $tz);
    $end = new DateTime(substr(trim($weekEnd), 0, 10) . ' 12:00:00', $tz);
    return $start->format('F j') . ' – ' . $end->format('F j, Y');
}

function br_ensure_weekly_reports_schema(PDO $conn): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    try {
        $stmt = $conn->query("SHOW TABLES LIKE 'weekly_reports'");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_NUM) : false;
        if ($row && !empty($row[0])) {
            $ready = true;
            return;
        }
    } catch (Throwable $e) {
        error_log('br_ensure_weekly_reports_schema show tables: ' . $e->getMessage());
    }

    $migration = realpath(__DIR__ . '/../migrations/080_weekly_reports.sql');
    if (!$migration || !is_readable($migration)) {
        error_log('br_ensure_weekly_reports_schema: migration file missing');
        return;
    }

    try {
        $sql = file_get_contents($migration);
        if ($sql === false || trim($sql) === '') {
            return;
        }
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $statement) {
            if ($statement === '') {
                continue;
            }
            $conn->exec($statement);
        }
        $ready = true;
    } catch (Throwable $e) {
        error_log('br_ensure_weekly_reports_schema apply: ' . $e->getMessage());
    }
}

function br_sanitize_weekly_report_field($value, bool $required = false): string
{
    $text = trim(strip_tags((string)$value));
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, BR_WEEKLY_REPORT_FIELD_MAX);
    } else {
        $text = substr($text, 0, BR_WEEKLY_REPORT_FIELD_MAX);
    }
    if ($required && $text === '') {
        return '';
    }
    return $text;
}

/**
 * @return string[]
 */
function br_weekly_report_split_lines(?string $text): array
{
    $raw = trim((string)$text);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $lines = [];
    foreach ($parts as $part) {
        $line = trim((string)$part);
        $line = preg_replace('/^[-*•]\s*/u', '', $line) ?? $line;
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $lines[] = $line;
    }
    return $lines;
}

function br_weekly_report_join_bullets(array $lines): string
{
    $unique = [];
    $seen = [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        $key = function_exists('mb_strtolower') ? mb_strtolower($line) : strtolower($line);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = '- ' . $line;
    }
    return implode("\n", $unique);
}

function br_weekly_report_exists(PDO $conn, string $userId, string $weekStart): bool
{
    $live = br_weekly_report_live_and($conn);
    $stmt = $conn->prepare(
        "SELECT id FROM weekly_reports WHERE user_id = ? AND week_start = ?{$live} LIMIT 1"
    );
    $stmt->execute([$userId, $weekStart]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * @return array<string,mixed>|null
 */
function br_get_weekly_report(PDO $conn, string $userId, string $weekStart): ?array
{
    $live = br_weekly_report_live_and($conn);
    $stmt = $conn->prepare(
        "SELECT id, user_id, week_start, week_end, report_date,
                work_completed, work_in_progress, issues_blockers, plan_next_week,
                notified_at, created_at, updated_at
         FROM weekly_reports
         WHERE user_id = ? AND week_start = ?{$live}
         LIMIT 1"
    );
    $stmt->execute([$userId, $weekStart]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return string[]
 */
function br_weekly_report_week_dates(string $weekStart, string $weekEnd): array
{
    $dates = [];
    $tz = new DateTimeZone('Asia/Kolkata');
    $cursor = DateTime::createFromFormat('Y-m-d', substr(trim($weekStart), 0, 10), $tz);
    $end = DateTime::createFromFormat('Y-m-d', substr(trim($weekEnd), 0, 10), $tz);
    if (!$cursor || !$end) {
        return $dates;
    }
    while ($cursor <= $end) {
        $dates[] = $cursor->format('Y-m-d');
        $cursor->modify('+1 day');
    }
    return $dates;
}

function br_weekly_attendance_day_label(string $date): string
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = DateTime::createFromFormat('Y-m-d', substr(trim($date), 0, 10), $tz);
    return $dt ? $dt->format('D, M j') : $date;
}

function br_weekly_attendance_format_check_in(?string $checkIn): ?string
{
    $raw = trim((string)$checkIn);
    if ($raw === '') {
        return null;
    }
    try {
        $tz = new DateTimeZone('Asia/Kolkata');
        $dt = new DateTime($raw, $tz);
        return $dt->format('g:i A');
    } catch (Throwable $e) {
        return $raw;
    }
}

function br_weekly_attendance_break_minutes(array $submission): int
{
    $minutes = (int)($submission['total_break_minutes'] ?? 0);
    if ($minutes > 0) {
        return $minutes;
    }
    $entries = $submission['break_entries'] ?? null;
    if ($entries === null || $entries === '') {
        return 0;
    }
    if (is_string($entries)) {
        $decoded = json_decode($entries, true);
        if (!is_array($decoded)) {
            return 0;
        }
        $entries = $decoded;
    }
    if (!is_array($entries)) {
        return 0;
    }
    $sum = 0;
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $sum += (int)($entry['minutes'] ?? $entry['duration_minutes'] ?? 0);
    }
    return max(0, $sum);
}

/**
 * Why: Weekly report copy/export must list Mon–Sat hours, breaks, leave, check-in, late, office/WFH.
 *
 * @return array{summary:array<string,mixed>,days:array<int,array<string,mixed>>}
 */
function br_weekly_attendance_summary(PDO $conn, string $userId, string $weekStart, string $weekEnd): array
{
    $weekDates = br_weekly_report_week_dates($weekStart, $weekEnd);
    $leaveMap = br_leave_day_map($conn, $userId, $weekStart, $weekEnd);
    $submissionsByDate = [];

    try {
        $stmt = $conn->prepare(
            'SELECT submission_date, check_in_time, hours_today, total_break_minutes, break_entries,
                    work_mode, is_late, overtime_hours, requested_extra_hours,
                    extra_hours_approval_status, extra_hours_approved_amount, approval_reason
             FROM work_submissions
             WHERE user_id = ? AND submission_date >= ? AND submission_date <= ?
             ORDER BY submission_date ASC'
        );
        $stmt->execute([$userId, $weekStart, $weekEnd]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $submissionsByDate[(string)($row['submission_date'] ?? '')] = $row;
        }
    } catch (Throwable $e) {
        error_log('br_weekly_attendance_summary: ' . $e->getMessage());
    }

    $days = [];
    $summary = [
        'days_worked' => 0,
        'total_hours' => 0.0,
        'break_minutes' => 0,
        'leave_days' => 0,
        'check_ins' => 0,
        'office_days' => 0,
        'wfh_days' => 0,
        'late_days' => 0,
        'overtime_hours' => 0.0,
    ];

    foreach ($weekDates as $date) {
        $submission = $submissionsByDate[$date] ?? null;
        $leave = $leaveMap[$date] ?? null;
        $checkInRaw = $submission['check_in_time'] ?? null;
        $checkIn = br_weekly_attendance_format_check_in(is_string($checkInRaw) ? $checkInRaw : null);
        $hours = $submission ? (float)($submission['hours_today'] ?? 0) : 0.0;
        $breakMinutes = $submission ? br_weekly_attendance_break_minutes($submission) : 0;
        $workMode = strtolower(trim((string)($submission['work_mode'] ?? '')));
        if ($workMode !== 'office' && $workMode !== 'wfh') {
            $workMode = $checkIn ? 'office' : '';
        }
        $isLate = $submission ? ((int)($submission['is_late'] ?? 0) === 1) : false;
        $overtimeHours = $submission ? br_effective_overtime_hours_for_stats($submission) : 0.0;

        if ($leave) {
            $credited = br_leave_credited_hours($leave['leave_type_code'] ?? null);
            if ($credited > $hours) {
                $hours = $credited;
            }
            $dayStatus = 'leave';
            $summary['leave_days'] += 1;
        } elseif ($submission && ($checkIn || $hours > 0 || $breakMinutes > 0)) {
            $dayStatus = 'worked';
        } else {
            $dayStatus = 'off';
        }

        if ($dayStatus === 'worked') {
            if ($checkIn) {
                $summary['check_ins'] += 1;
            }
            if ($hours > 0 || $checkIn) {
                $summary['days_worked'] += 1;
            }
            $summary['total_hours'] += $hours;
            $summary['break_minutes'] += $breakMinutes;
            $summary['overtime_hours'] += $overtimeHours;
            if ($workMode === 'wfh') {
                $summary['wfh_days'] += 1;
            } elseif ($workMode === 'office') {
                $summary['office_days'] += 1;
            }
            if ($isLate) {
                $summary['late_days'] += 1;
            }
        } elseif ($dayStatus === 'leave' && $hours > 0) {
            $summary['total_hours'] += $hours;
            $summary['days_worked'] += 1;
        }

        $days[] = [
            'date' => $date,
            'date_label' => br_weekly_attendance_day_label($date),
            'day_status' => $dayStatus,
            'check_in' => $checkIn,
            'check_in_raw' => $checkInRaw,
            'hours' => round($hours, 2),
            'break_minutes' => $breakMinutes,
            'work_mode' => $workMode !== '' ? $workMode : null,
            'is_late' => $isLate,
            'leave_type_name' => $leave['leave_type_name'] ?? null,
            'leave_type_code' => $leave['leave_type_code'] ?? null,
            'overtime_hours' => round($overtimeHours, 2),
        ];
    }

    $summary['total_hours'] = round((float)$summary['total_hours'], 2);
    $summary['overtime_hours'] = round((float)$summary['overtime_hours'], 2);

    return [
        'summary' => $summary,
        'days' => $days,
    ];
}

function br_weekly_attendance_document_block(array $attendance): string
{
    $summary = is_array($attendance['summary'] ?? null) ? $attendance['summary'] : [];
    $days = is_array($attendance['days'] ?? null) ? $attendance['days'] : [];

    $lines = [
        'Weekly Attendance Summary',
        sprintf('Worked days: %d', (int)($summary['days_worked'] ?? 0)),
        sprintf('Total hours: %.2f h', (float)($summary['total_hours'] ?? 0)),
        sprintf('Break: %d min', (int)($summary['break_minutes'] ?? 0)),
        sprintf('Leave days: %d', (int)($summary['leave_days'] ?? 0)),
        sprintf('Check-ins: %d', (int)($summary['check_ins'] ?? 0)),
        sprintf('Office days: %d', (int)($summary['office_days'] ?? 0)),
        sprintf('WFH days: %d', (int)($summary['wfh_days'] ?? 0)),
        sprintf('Late days: %d', (int)($summary['late_days'] ?? 0)),
        sprintf('Overtime: %.2f h', (float)($summary['overtime_hours'] ?? 0)),
        '',
        'Daily Attendance',
    ];

    foreach ($days as $day) {
        if (!is_array($day)) {
            continue;
        }
        $label = (string)($day['date_label'] ?? $day['date'] ?? '');
        $status = (string)($day['day_status'] ?? 'off');
        $parts = [$label];

        if ($status === 'leave') {
            $leaveName = trim((string)($day['leave_type_name'] ?? ''));
            $parts[] = $leaveName !== '' ? 'Leave (' . $leaveName . ')' : 'Leave';
            if ((float)($day['hours'] ?? 0) > 0) {
                $parts[] = sprintf('%.2f h credited', (float)$day['hours']);
            }
        } elseif ($status === 'worked') {
            if (!empty($day['check_in'])) {
                $parts[] = 'Check-in ' . $day['check_in'];
            }
            if ((float)($day['hours'] ?? 0) > 0) {
                $parts[] = sprintf('%.2f h worked', (float)$day['hours']);
            }
            if ((int)($day['break_minutes'] ?? 0) > 0) {
                $parts[] = (int)$day['break_minutes'] . ' min break';
            }
            $mode = strtolower(trim((string)($day['work_mode'] ?? '')));
            if ($mode === 'wfh') {
                $parts[] = 'WFH';
            } elseif ($mode === 'office') {
                $parts[] = 'Office';
            }
            if (!empty($day['is_late'])) {
                $parts[] = 'Late';
            }
            if ((float)($day['overtime_hours'] ?? 0) > 0) {
                $parts[] = sprintf('%.2f h OT', (float)$day['overtime_hours']);
            }
        } else {
            $parts[] = 'No record';
        }

        $lines[] = '- ' . implode(' · ', $parts);
    }

    return implode("\n", $lines);
}

/**
 * @param array<string,mixed> $report
 * @return array<string,mixed>
 */
function br_weekly_report_attach_attendance(PDO $conn, array $report): array
{
    $userId = trim((string)($report['user_id'] ?? ''));
    $weekStart = trim((string)($report['week_start'] ?? ''));
    $weekEnd = trim((string)($report['week_end'] ?? ''));
    if ($userId === '' || $weekStart === '' || $weekEnd === '') {
        return $report;
    }

    $attendance = br_weekly_attendance_summary($conn, $userId, $weekStart, $weekEnd);
    $report['attendance'] = $attendance;
    $report['attendance_text'] = br_weekly_attendance_document_block($attendance);
    return $report;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function br_present_weekly_report_row(array $row, ?PDO $conn = null): array
{
    $weekStart = (string)($row['week_start'] ?? '');
    $weekEnd = (string)($row['week_end'] ?? '');
    $reportDate = (string)($row['report_date'] ?? $weekEnd);
    $completed = (string)($row['work_completed'] ?? '');
    $wip = (string)($row['work_in_progress'] ?? '');
    $blockers = trim((string)($row['issues_blockers'] ?? ''));
    $plan = (string)($row['plan_next_week'] ?? '');

    $report = [
        'id' => (string)($row['id'] ?? ''),
        'user_id' => (string)($row['user_id'] ?? ''),
        'user_name' => trim((string)($row['username'] ?? $row['user_name'] ?? 'User')) ?: 'User',
        'user_role' => $row['role'] ?? null,
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'week_label' => $weekStart !== '' && $weekEnd !== ''
            ? br_weekly_report_week_label($weekStart, $weekEnd)
            : '',
        'report_date' => $reportDate,
        'date_label' => $reportDate !== '' ? br_weekly_report_date_label($reportDate) : '',
        'work_completed' => $completed,
        'work_in_progress' => $wip,
        'issues_blockers' => $blockers,
        'plan_next_week' => $plan,
        'notified_at' => $row['notified_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'counts' => [
            'completed' => count(br_weekly_report_split_lines($completed)),
            'wip' => count(br_weekly_report_split_lines($wip)),
            'blockers' => count(br_weekly_report_split_lines($blockers)),
            'plan' => count(br_weekly_report_split_lines($plan)),
        ],
    ];

    return $conn ? br_weekly_report_attach_attendance($conn, $report) : $report;
}

/**
 * Why: Admins review the team; developers only read their own filed weeks.
 *
 * @param array{scope?:string,user_id?:string,week_start?:string,q?:string,page?:int,limit?:int} $opts
 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,limit:int,week_start:?string,week_end:?string,week_label:?string}
 */
function br_list_weekly_reports(PDO $conn, array $opts): array
{
    $page = max(1, (int)($opts['page'] ?? 1));
    $limit = (int)($opts['limit'] ?? 20);
    if ($limit < 1) {
        $limit = 20;
    }
    if ($limit > 100) {
        $limit = 100;
    }
    $offset = ($page - 1) * $limit;

    $where = ['1=1'];
    $params = [];

    if (br_weekly_report_deleted_at_supported($conn)) {
        $where[] = 'wr.deleted_at IS NULL';
    }

    $scopeUserId = trim((string)($opts['user_id'] ?? ''));
    if ($scopeUserId !== '') {
        $where[] = 'wr.user_id = ?';
        $params[] = $scopeUserId;
    }

    $weekStart = substr(trim((string)($opts['week_start'] ?? '')), 0, 10);
    $weekEnd = null;
    $weekLabel = null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
        $bounds = br_monday_saturday_week_bounds($weekStart);
        $weekStart = $bounds['week_start'];
        $weekEnd = $bounds['week_end'];
        $weekLabel = br_weekly_report_week_label($weekStart, $weekEnd);
        $where[] = 'wr.week_start = ?';
        $params[] = $weekStart;
    } else {
        $weekStart = null;
    }

    $q = trim((string)($opts['q'] ?? ''));
    if ($q !== '') {
        $where[] = 'u.username LIKE ?';
        $params[] = '%' . $q . '%';
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM weekly_reports wr
         INNER JOIN users u ON u.id = wr.user_id
         WHERE {$whereSql}"
    );
    $countStmt->execute($params);
    $total = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

    $stmt = $conn->prepare(
        "SELECT wr.id, wr.user_id, wr.week_start, wr.week_end, wr.report_date,
                wr.work_completed, wr.work_in_progress, wr.issues_blockers, wr.plan_next_week,
                wr.notified_at, wr.created_at, wr.updated_at,
                u.username, u.role
         FROM weekly_reports wr
         INNER JOIN users u ON u.id = wr.user_id
         WHERE {$whereSql}
         ORDER BY wr.week_start DESC, wr.updated_at DESC, u.username ASC
         LIMIT {$limit} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $items[] = br_present_weekly_report_row($row, $conn);
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'week_label' => $weekLabel,
    ];
}

/**
 * Why: Prefill from this week's daily checkout notes so Saturday is edit-not-rewrite.
 *
 * @return array{work_completed:string,work_in_progress:string,plan_next_week:string}
 */
function br_weekly_report_suggestions(PDO $conn, string $userId, string $weekStart, string $weekEnd): array
{
    $completed = [];
    $wip = [];
    $plan = [];

    try {
        $stmt = $conn->prepare(
            'SELECT completed_tasks, pending_tasks, ongoing_tasks, notes
             FROM work_submissions
             WHERE user_id = ? AND submission_date >= ? AND submission_date <= ?
             ORDER BY submission_date ASC'
        );
        $stmt->execute([$userId, $weekStart, $weekEnd]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            foreach (br_weekly_report_split_lines($row['completed_tasks'] ?? '') as $line) {
                $completed[] = $line;
            }
            foreach (br_weekly_report_split_lines($row['ongoing_tasks'] ?? '') as $line) {
                $wip[] = $line;
            }
            foreach (br_weekly_report_split_lines($row['pending_tasks'] ?? '') as $line) {
                $wip[] = $line;
            }
            foreach (br_weekly_report_split_lines($row['notes'] ?? '') as $line) {
                $plan[] = $line;
            }
        }
    } catch (Throwable $e) {
        error_log('br_weekly_report_suggestions: ' . $e->getMessage());
    }

    return [
        'work_completed' => br_weekly_report_join_bullets($completed),
        'work_in_progress' => br_weekly_report_join_bullets($wip),
        'plan_next_week' => br_weekly_report_join_bullets($plan),
    ];
}

/**
 * Block first Saturday checkout until a weekly report exists for that week.
 * Revising an already-complete Saturday row does not re-block.
 *
 * @return array{ok:bool,message?:string}
 */
function br_assert_saturday_weekly_report_for_checkout(PDO $conn, string $userId, string $date, float $hours): array
{
    if ($hours < 1) {
        return ['ok' => true];
    }
    if (!br_is_saturday_date($date)) {
        return ['ok' => true];
    }

    br_ensure_weekly_reports_schema($conn);

    try {
        $stmt = $conn->prepare(
            'SELECT hours_today FROM work_submissions WHERE user_id = ? AND submission_date = ? LIMIT 1'
        );
        $stmt->execute([$userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && (float)($row['hours_today'] ?? 0) >= 1) {
            return ['ok' => true];
        }
    } catch (Throwable $e) {
        error_log('br_assert_saturday_weekly_report_for_checkout existing: ' . $e->getMessage());
    }

    $bounds = br_monday_saturday_week_bounds($date);
    if (!br_weekly_report_exists($conn, $userId, $bounds['week_start'])) {
        return [
            'ok' => false,
            'message' => 'Submit your weekly report before Saturday checkout.',
        ];
    }

    return ['ok' => true];
}

function br_display_user_name(PDO $conn, string $userId, string $fallback = 'User'): string
{
    try {
        $stmt = $conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $name = trim((string)($row['username'] ?? ''));
        return $name !== '' ? $name : $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * @return array<string,mixed>
 */
function br_save_weekly_report(PDO $conn, string $userId, array $payload): array
{
    br_ensure_weekly_reports_schema($conn);

    $reportDate = substr(trim((string)($payload['report_date'] ?? br_server_today())), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        throw new InvalidArgumentException('Invalid report date.');
    }
    if (!br_is_saturday_date($reportDate) && !br_is_saturday_date(br_server_today())) {
        throw new InvalidArgumentException('Weekly reports can only be submitted on Saturday.');
    }
    if (!br_is_saturday_date($reportDate)) {
        $reportDate = br_server_today();
        if (!br_is_saturday_date($reportDate)) {
            throw new InvalidArgumentException('Weekly reports can only be submitted on Saturday.');
        }
    }

    $workCompleted = br_sanitize_weekly_report_field($payload['work_completed'] ?? '', true);
    $workInProgress = br_sanitize_weekly_report_field($payload['work_in_progress'] ?? '', true);
    $issues = br_sanitize_weekly_report_field($payload['issues_blockers'] ?? '', false);
    $planNext = br_sanitize_weekly_report_field($payload['plan_next_week'] ?? '', true);

    if ($workCompleted === '' || $workInProgress === '' || $planNext === '') {
        throw new InvalidArgumentException('Work completed, work in progress, and plan for next week are required.');
    }

    $bounds = br_monday_saturday_week_bounds($reportDate);
    $existing = br_get_weekly_report($conn, $userId, $bounds['week_start']);

    if ($existing) {
        $stmt = $conn->prepare(
            'UPDATE weekly_reports
             SET week_end = ?, report_date = ?, work_completed = ?, work_in_progress = ?,
                 issues_blockers = ?, plan_next_week = ?
             WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([
            $bounds['week_end'],
            $reportDate,
            $workCompleted,
            $workInProgress,
            $issues !== '' ? $issues : null,
            $planNext,
            $existing['id'],
            $userId,
        ]);
        $saved = br_get_weekly_report($conn, $userId, $bounds['week_start']);
        return $saved ?: $existing;
    }

    if (br_weekly_report_deleted_at_supported($conn)) {
        $archived = $conn->prepare(
            'SELECT id FROM weekly_reports
             WHERE user_id = ? AND week_start = ? AND deleted_at IS NOT NULL
             LIMIT 1'
        );
        $archived->execute([$userId, $bounds['week_start']]);
        $archivedRow = $archived->fetch(PDO::FETCH_ASSOC);
        if ($archivedRow) {
            $stmt = $conn->prepare(
                'UPDATE weekly_reports
                 SET week_end = ?, report_date = ?, work_completed = ?, work_in_progress = ?,
                     issues_blockers = ?, plan_next_week = ?, deleted_at = NULL, deleted_by = NULL
                 WHERE id = ? AND user_id = ?'
            );
            $stmt->execute([
                $bounds['week_end'],
                $reportDate,
                $workCompleted,
                $workInProgress,
                $issues !== '' ? $issues : null,
                $planNext,
                $archivedRow['id'],
                $userId,
            ]);
            $saved = br_get_weekly_report($conn, $userId, $bounds['week_start']);
            if ($saved) {
                return $saved;
            }
        }
    }

    $id = class_exists('Utils') ? Utils::generateUUID() : sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );

    $stmt = $conn->prepare(
        'INSERT INTO weekly_reports
            (id, user_id, week_start, week_end, report_date,
             work_completed, work_in_progress, issues_blockers, plan_next_week)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $userId,
        $bounds['week_start'],
        $bounds['week_end'],
        $reportDate,
        $workCompleted,
        $workInProgress,
        $issues !== '' ? $issues : null,
        $planNext,
    ]);

    $saved = br_get_weekly_report($conn, $userId, $bounds['week_start']);
    if (!$saved) {
        throw new RuntimeException('Failed to save weekly report.');
    }
    return $saved;
}

/**
 * @return array<string,mixed>|null
 */
function br_get_weekly_report_by_id(PDO $conn, string $id): ?array
{
    $id = trim($id);
    if ($id === '') {
        return null;
    }
    $live = br_weekly_report_live_and($conn, 'wr');
    $stmt = $conn->prepare(
        "SELECT wr.id, wr.user_id, wr.week_start, wr.week_end, wr.report_date,
                wr.work_completed, wr.work_in_progress, wr.issues_blockers, wr.plan_next_week,
                wr.notified_at, wr.created_at, wr.updated_at,
                u.username, u.role
         FROM weekly_reports wr
         INNER JOIN users u ON u.id = wr.user_id
         WHERE wr.id = ?{$live}
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? br_present_weekly_report_row($row, $conn) : null;
}

/**
 * Why: Admins may correct typos or merge team reports without waiting for Saturday checkout.
 *
 * @return array<string,mixed>
 */
function br_admin_update_weekly_report(PDO $conn, string $id, array $payload): array
{
    br_ensure_weekly_reports_schema($conn);
    $id = trim($id);
    if ($id === '') {
        throw new InvalidArgumentException('Report id is required.');
    }

    $existing = br_get_weekly_report_by_id($conn, $id);
    if (!$existing) {
        throw new InvalidArgumentException('Weekly report not found.');
    }

    $workCompleted = br_sanitize_weekly_report_field($payload['work_completed'] ?? '', true);
    $workInProgress = br_sanitize_weekly_report_field($payload['work_in_progress'] ?? '', true);
    $issues = br_sanitize_weekly_report_field($payload['issues_blockers'] ?? '', false);
    $planNext = br_sanitize_weekly_report_field($payload['plan_next_week'] ?? '', true);

    if ($workCompleted === '' || $workInProgress === '' || $planNext === '') {
        throw new InvalidArgumentException('Work completed, work in progress, and plan for next week are required.');
    }

    $stmt = $conn->prepare(
        'UPDATE weekly_reports
         SET work_completed = ?, work_in_progress = ?, issues_blockers = ?, plan_next_week = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $workCompleted,
        $workInProgress,
        $issues !== '' ? $issues : null,
        $planNext,
        $id,
    ]);

    $saved = br_get_weekly_report_by_id($conn, $id);
    if (!$saved) {
        throw new RuntimeException('Failed to update weekly report.');
    }
    return $saved;
}

function br_admin_delete_weekly_report(PDO $conn, string $id): bool
{
    br_ensure_weekly_reports_schema($conn);
    $id = trim($id);
    if ($id === '') {
        return false;
    }
    $stmt = $conn->prepare('DELETE FROM weekly_reports WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/**
 * @return string[]
 */
function br_weekly_report_admin_emails(PDO $conn): array
{
    $stmt = $conn->prepare(
        "SELECT email FROM users WHERE account_active = 1 AND (role = 'admin' OR role_id = 1)"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $emails = [];
    foreach ($rows as $row) {
        $email = trim((string)($row['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    return $emails;
}

/**
 * Send weekly report WhatsApp + email with Saturday checkout, once per week.
 */
function br_send_weekly_report_with_checkout(
    PDO $conn,
    string $userId,
    string $date,
    string $userName,
    string $userEmail
): void {
    if (!br_is_saturday_date($date)) {
        return;
    }

    br_ensure_weekly_reports_schema($conn);
    $bounds = br_monday_saturday_week_bounds($date);
    $report = br_get_weekly_report($conn, $userId, $bounds['week_start']);
    if (!$report) {
        return;
    }
    if (!empty($report['notified_at'])) {
        return;
    }

    $payload = [
        'user_id' => $userId,
        'user_name' => $userName,
        'user_email' => $userEmail,
        'week_start' => $report['week_start'],
        'week_end' => $report['week_end'],
        'report_date' => $report['report_date'],
        'date_label' => br_weekly_report_date_label((string)$report['report_date']),
        'week_label' => br_weekly_report_week_label((string)$report['week_start'], (string)$report['week_end']),
        'work_completed' => (string)$report['work_completed'],
        'work_in_progress' => (string)$report['work_in_progress'],
        'issues_blockers' => trim((string)($report['issues_blockers'] ?? '')) !== ''
            ? (string)$report['issues_blockers']
            : 'No major blockers.',
        'plan_next_week' => (string)$report['plan_next_week'],
    ];
    $payload = br_weekly_report_attach_attendance($conn, $payload);

    $sent = false;

    try {
        require_once __DIR__ . '/email.php';
        $adminEmails = br_weekly_report_admin_emails($conn);
        if (!empty($adminEmails)) {
            $results = sendWeeklyReportEmailToAdmins($adminEmails, $payload);
            foreach ($results as $ok) {
                if ($ok) {
                    $sent = true;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('br_send_weekly_report_with_checkout email: ' . $e->getMessage());
    }

    try {
        require_once __DIR__ . '/whatsapp.php';
        $wa = sendWeeklyReportWhatsAppToAdmins($payload);
        if ($wa) {
            $sent = true;
        }
    } catch (Throwable $e) {
        error_log('br_send_weekly_report_with_checkout whatsapp: ' . $e->getMessage());
    }

    if (!$sent) {
        return;
    }

    try {
        $stmt = $conn->prepare(
            'UPDATE weekly_reports SET notified_at = CURRENT_TIMESTAMP WHERE id = ? AND notified_at IS NULL'
        );
        $stmt->execute([(string)$report['id']]);
    } catch (Throwable $e) {
        error_log('br_send_weekly_report_with_checkout notified_at: ' . $e->getMessage());
    }
}
