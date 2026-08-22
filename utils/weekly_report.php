<?php
/**
 * Why: Saturday checkout must collect one Mon–Sat weekly report per user
 * before hours can be saved, then notify admins once with that checkout.
 */

require_once __DIR__ . '/work_period.php';
if (!class_exists('Utils')) {
    require_once __DIR__ . '/../config/utils.php';
}

const BR_WEEKLY_REPORT_FIELD_MAX = 20000;

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
    $stmt = $conn->prepare(
        'SELECT id FROM weekly_reports WHERE user_id = ? AND week_start = ? LIMIT 1'
    );
    $stmt->execute([$userId, $weekStart]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * @return array<string,mixed>|null
 */
function br_get_weekly_report(PDO $conn, string $userId, string $weekStart): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, user_id, week_start, week_end, report_date,
                work_completed, work_in_progress, issues_blockers, plan_next_week,
                notified_at, created_at, updated_at
         FROM weekly_reports
         WHERE user_id = ? AND week_start = ?
         LIMIT 1'
    );
    $stmt->execute([$userId, $weekStart]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function br_present_weekly_report_row(array $row): array
{
    $weekStart = (string)($row['week_start'] ?? '');
    $weekEnd = (string)($row['week_end'] ?? '');
    $reportDate = (string)($row['report_date'] ?? $weekEnd);
    $completed = (string)($row['work_completed'] ?? '');
    $wip = (string)($row['work_in_progress'] ?? '');
    $blockers = trim((string)($row['issues_blockers'] ?? ''));
    $plan = (string)($row['plan_next_week'] ?? '');

    return [
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
        $items[] = br_present_weekly_report_row($row);
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
