<?php
/**
 * Finbro M2M integration helpers (auth + Team Analytics–aligned hours aggregation).
 *
 * Why: Finbro pulls work hours and account status from BugRicer. Hours must match
 * Team Analytics month totals (SUM hours_today + effective OT + break minutes),
 * not the leave-credited individual work-stats path.
 */

require_once __DIR__ . '/work_submission_ot.php';

/** @var float|null */
$GLOBALS['br_finbro_req_start'] = $GLOBALS['br_finbro_req_start'] ?? null;
/** @var string */
$GLOBALS['br_finbro_req_route'] = $GLOBALS['br_finbro_req_route'] ?? '';
/** @var bool */
$GLOBALS['br_finbro_req_logged'] = $GLOBALS['br_finbro_req_logged'] ?? false;

/** Soft ceiling: parallel payroll GETs + retries must stay under this. */
const BR_FINBRO_RATE_LIMIT_MAX = 120;
const BR_FINBRO_RATE_LIMIT_WINDOW_SEC = 60;

/**
 * Mark request start for latency logging (call once per HTTP request).
 */
function br_finbro_begin_request(string $route): void
{
    $GLOBALS['br_finbro_req_start'] = microtime(true);
    $GLOBALS['br_finbro_req_route'] = $route;
    $GLOBALS['br_finbro_req_logged'] = false;
}

/**
 * Structured access log: route, status, latency_ms (payroll spike visibility).
 */
function br_finbro_log_request(int $status): void
{
    if (!empty($GLOBALS['br_finbro_req_logged'])) {
        return;
    }
    $GLOBALS['br_finbro_req_logged'] = true;

    $start = $GLOBALS['br_finbro_req_start'] ?? null;
    $route = (string)($GLOBALS['br_finbro_req_route'] ?? 'unknown');
    $ms = ($start !== null) ? (int)round((microtime(true) - (float)$start) * 1000) : 0;
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '?');

    error_log(sprintf(
        'finbro_integration route=%s method=%s status=%d latency_ms=%d',
        $route !== '' ? $route : 'unknown',
        $method,
        $status,
        $ms
    ));
}

/**
 * Lightweight file-backed rate limit for /v1/integrations/finbro/*.
 * Why: When Finbro payroll opens, identical Bearer GETs spike; soft-cap avoids DB pile-up.
 * Exits with 429 JSON when exceeded.
 */
function br_finbro_rate_limit_check(): void
{
    $dir = sys_get_temp_dir();
    $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bugricer_finbro_rl.json';
    $now = time();
    $windowStart = $now - BR_FINBRO_RATE_LIMIT_WINDOW_SEC;
    $count = 0;

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        // Fail open if temp FS unavailable — still serve payroll.
        return;
    }

    try {
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            return;
        }

        $raw = stream_get_contents($fh);
        $hits = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $ts) {
                    $t = (int)$ts;
                    if ($t >= $windowStart) {
                        $hits[] = $t;
                    }
                }
            }
        }

        $count = count($hits);
        if ($count >= BR_FINBRO_RATE_LIMIT_MAX) {
            flock($fh, LOCK_UN);
            fclose($fh);
            br_finbro_json_response(429, ['error' => 'Too many requests']);
        }

        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($hits));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
    } catch (Throwable $e) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
        error_log('finbro_rate_limit: ' . $e->getMessage());
    }
}

/**
 * Read Authorization: Bearer token from common Apache / CGI headers.
 */
function br_finbro_read_bearer_token(): ?string
{
    $header = null;
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, 'Authorization') === 0) {
                $header = trim((string)$value);
                break;
            }
        }
    }

    if ($header !== null && preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Why: Shared server secret only — reject missing/invalid Bearer with 401.
 * Exits the request on failure.
 */
function br_finbro_require_auth(): void
{
    if (!class_exists('Environment')) {
        require_once __DIR__ . '/../config/environment.php';
    }

    $expected = getenv('FINBRO_INTEGRATION_TOKEN');
    if ($expected === false || $expected === '') {
        $expected = (string)(Environment::get('FINBRO_INTEGRATION_TOKEN') ?? '');
    }
    $expected = trim((string)$expected);
    $provided = br_finbro_read_bearer_token();

    if ($expected === '' || $provided === null || !hash_equals($expected, $provided)) {
        br_finbro_json_response(401, ['error' => 'Unauthorized']);
    }
}

/**
 * Emit raw JSON (Finbro contract — not BugRicer {success,message,data} wrap) and exit.
 * Always Content-Type application/json — never HTML error pages.
 *
 * @param array<string,mixed> $payload
 */
function br_finbro_json_response(int $status, array $payload): void
{
    br_finbro_log_request($status);
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Map users.account_active → Finbro accountStatus.
 */
function br_finbro_account_status(?int $accountActive): string
{
    return ($accountActive !== null && (int)$accountActive === 0) ? 'deactivated' : 'active';
}

/**
 * ISO-8601 UTC timestamp from DB datetime (or now).
 */
function br_finbro_iso_utc(?string $dbDatetime): string
{
    try {
        if ($dbDatetime !== null && trim($dbDatetime) !== '') {
            $dt = new DateTime($dbDatetime);
        } else {
            $dt = new DateTime('now');
        }
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s.000\Z');
    } catch (Throwable $e) {
        return gmdate('Y-m-d\TH:i:s.000\Z');
    }
}

/**
 * Parse break entry strings the same way Team Analytics does.
 *
 * @return list<string>
 */
function br_finbro_parse_break_entries($breakEntriesRaw, $notesRaw): array
{
    $entries = [];
    if (is_string($breakEntriesRaw) && trim($breakEntriesRaw) !== '') {
        $decoded = json_decode($breakEntriesRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                $entry = trim((string)$entry);
                if ($entry !== '') {
                    $entries[] = $entry;
                }
            }
        }
    }
    if (empty($entries)) {
        $noteMatches = [];
        if (preg_match_all('/^\[BREAK\].*$/im', (string)$notesRaw, $noteMatches)) {
            foreach (($noteMatches[0] ?? []) as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $entries[] = $line;
                }
            }
        }
    }
    return array_values(array_unique($entries));
}

/**
 * @param list<string> $entries
 */
function br_finbro_break_minutes_from_entries(array $entries): int
{
    $total = 0;
    foreach ($entries as $entry) {
        if (preg_match('/\((\d+)\s*min\)/i', (string)$entry, $matches)) {
            $total += (int)$matches[1];
        }
    }
    return $total;
}

/**
 * Break minutes for one submission row (Team Analytics rules).
 */
function br_finbro_submission_break_minutes(array $submission): int
{
    $breakMinutes = (int)($submission['total_break_minutes'] ?? 0);
    if ($breakMinutes <= 0) {
        $entries = br_finbro_parse_break_entries(
            $submission['break_entries'] ?? null,
            $submission['notes'] ?? ''
        );
        $breakMinutes = br_finbro_break_minutes_from_entries($entries);
    }
    return $breakMinutes;
}

/**
 * Detect optional users.name column.
 */
function br_finbro_users_has_name_column(PDO $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'name'");
        $cached = $res && $res->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Detect users.account_active column.
 */
function br_finbro_users_has_account_active(PDO $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'account_active'");
        $cached = $res && $res->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Detect users.deleted_at (recycle bin soft-delete).
 */
function br_finbro_users_has_deleted_at(PDO $conn): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $res = $conn->query("SHOW COLUMNS FROM users LIKE 'deleted_at'");
        $cached = $res && $res->fetch(PDO::FETCH_ASSOC) ? true : false;
    } catch (Throwable $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Cache of work_submissions column presence (avoids SELECT * and missing-column fatals).
 *
 * @return array<string, bool>
 */
function br_finbro_ws_column_map(PDO $conn): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $wanted = [
        'user_id' => true,
        'submission_date' => true,
        'hours_today' => true,
        'overtime_hours' => true,
        'requested_extra_hours' => true,
        'approval_reason' => true,
        'extra_hours_approval_status' => true,
        'total_break_minutes' => true,
        'break_entries' => true,
        'notes' => true,
        'deleted_at' => true,
    ];
    $found = array_fill_keys(array_keys($wanted), false);
    try {
        $res = $conn->query('SHOW COLUMNS FROM work_submissions');
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $field = (string)($row['Field'] ?? '');
                if ($field !== '' && array_key_exists($field, $found)) {
                    $found[$field] = true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('br_finbro_ws_column_map: ' . $e->getMessage());
    }
    $cached = $found;
    return $cached;
}

/**
 * Narrow SELECT list for Finbro hours aggregation (no blob/JSON overfetch).
 * OT approval columns are included only when present so OT helper never fatals.
 *
 * @return array{0: string, 1: bool} [selectSqlFragment, hasDeletedAt]
 */
function br_finbro_ws_select_sql(PDO $conn): array
{
    $map = br_finbro_ws_column_map($conn);
    $cols = [];
    foreach (
        [
            'user_id',
            'submission_date',
            'hours_today',
            'overtime_hours',
            'requested_extra_hours',
            'approval_reason',
            'extra_hours_approval_status',
            'total_break_minutes',
            'break_entries',
            'notes',
        ] as $col
    ) {
        if (!empty($map[$col])) {
            $cols[] = $col;
        }
    }
    if (empty($cols)) {
        // Extremely old schema fallback — still avoid SELECT * of huge tables.
        $cols = ['user_id', 'submission_date', 'hours_today', 'overtime_hours'];
    }
    return [implode(', ', $cols), !empty($map['deleted_at'])];
}

/**
 * Load staff rows for Finbro (all users with non-empty email).
 *
 * @return list<array<string,mixed>>
 */
function br_finbro_load_users(PDO $conn, ?string $emailFilter = null): array
{
    $hasName = br_finbro_users_has_name_column($conn);
    $hasActive = br_finbro_users_has_account_active($conn);
    $hasDeleted = br_finbro_users_has_deleted_at($conn);
    $nameExpr = $hasName
        ? "COALESCE(NULLIF(TRIM(name), ''), username) AS name"
        : 'username AS name';
    $activeExpr = $hasActive
        ? 'COALESCE(account_active, 1) AS account_active'
        : '1 AS account_active';

    $sql = "SELECT id, email, username, {$nameExpr}, role, {$activeExpr}, updated_at
            FROM users
            WHERE email IS NOT NULL AND TRIM(email) <> ''";
    $params = [];

    if ($hasDeleted) {
        $sql .= ' AND deleted_at IS NULL';
    }

    if ($emailFilter !== null && trim($emailFilter) !== '') {
        $sql .= ' AND LOWER(TRIM(email)) = LOWER(TRIM(?))';
        $params[] = $emailFilter;
    }

    $sql .= ' ORDER BY updated_at DESC';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Aggregate work_submissions for a date range (inclusive), keyed by user_id.
 * Matches Team Analytics accumulateSubmissionMetrics for hours / OT / break / days.
 *
 * Why: Select only stats columns (not SELECT *) so month scans stay light under
 * parallel Finbro payroll GETs. Optional $userIds scopes by-user / email filters.
 *
 * @param list<string|int>|null $userIds When non-empty, restrict to these user ids.
 * @return array<string, array{hours: float, overtime_hours: float, break_minutes: int, dates: array<string,bool>}>
 */
function br_finbro_aggregate_hours_by_user(PDO $conn, string $from, string $to, ?array $userIds = null): array
{
    [$selectCols, $hasDeletedAt] = br_finbro_ws_select_sql($conn);

    $sql = "SELECT {$selectCols}
            FROM work_submissions
            WHERE submission_date >= ? AND submission_date <= ?";
    $params = [$from, $to];

    if ($hasDeletedAt) {
        $sql .= ' AND deleted_at IS NULL';
    }

    $scopedIds = [];
    if ($userIds !== null) {
        foreach ($userIds as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $scopedIds[] = $id;
            }
        }
        $scopedIds = array_values(array_unique($scopedIds));
        if (empty($scopedIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
        $sql .= " AND user_id IN ({$placeholders})";
        foreach ($scopedIds as $id) {
            $params[] = $id;
        }
    }

    $sql .= ' ORDER BY submission_date ASC';

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // Why: Older DBs may lack optional OT/break columns mid-migration; fall back
        // to a minimal column set so OT helper still receives safe arrays (no fatal).
        error_log('br_finbro_aggregate_hours_by_user primary: ' . $e->getMessage());
        $fallbackSql = 'SELECT user_id, submission_date, hours_today, overtime_hours
                        FROM work_submissions
                        WHERE submission_date >= ? AND submission_date <= ?';
        $fallbackParams = [$from, $to];
        if ($hasDeletedAt) {
            $fallbackSql .= ' AND deleted_at IS NULL';
        }
        if (!empty($scopedIds)) {
            $placeholders = implode(',', array_fill(0, count($scopedIds), '?'));
            $fallbackSql .= " AND user_id IN ({$placeholders})";
            foreach ($scopedIds as $id) {
                $fallbackParams[] = $id;
            }
        }
        $fallbackSql .= ' ORDER BY submission_date ASC';
        $stmt = $conn->prepare($fallbackSql);
        $stmt->execute($fallbackParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $buckets = [];
    foreach ($rows as $submission) {
        $uid = (string)($submission['user_id'] ?? '');
        if ($uid === '') {
            continue;
        }
        if (!isset($buckets[$uid])) {
            $buckets[$uid] = [
                'hours' => 0.0,
                'overtime_hours' => 0.0,
                'break_minutes' => 0,
                'dates' => [],
            ];
        }
        $date = (string)($submission['submission_date'] ?? '');
        if ($date !== '') {
            $buckets[$uid]['dates'][$date] = true;
        }
        $buckets[$uid]['hours'] += (float)($submission['hours_today'] ?? 0);
        // OT helper uses array_key_exists — missing approval columns → stored OT (never fatal).
        $buckets[$uid]['overtime_hours'] += br_effective_overtime_hours_for_stats($submission);
        $buckets[$uid]['break_minutes'] += br_finbro_submission_break_minutes($submission);
    }

    return $buckets;
}

/**
 * Build Finbro member objects for a period.
 *
 * @param list<array<string,mixed>> $users
 * @param array<string, array{hours: float, overtime_hours: float, break_minutes: int, dates: array<string,bool>}> $buckets
 * @return list<array<string,mixed>>
 */
function br_finbro_build_members(array $users, array $buckets): array
{
    $members = [];
    foreach ($users as $user) {
        $uid = (string)$user['id'];
        $bucket = $buckets[$uid] ?? [
            'hours' => 0.0,
            'overtime_hours' => 0.0,
            'break_minutes' => 0,
            'dates' => [],
        ];
        $breakMinutes = (int)$bucket['break_minutes'];
        $members[] = [
            'userId' => $uid,
            'email' => (string)($user['email'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'name' => (string)($user['name'] ?? $user['username'] ?? ''),
            'role' => (string)($user['role'] ?? ''),
            'accountStatus' => br_finbro_account_status(
                isset($user['account_active']) ? (int)$user['account_active'] : 1
            ),
            'totalHours' => round((float)$bucket['hours'], 2),
            'overtimeHours' => round((float)$bucket['overtime_hours'], 2),
            'breakHours' => round($breakMinutes / 60, 2),
            'trackedDays' => count($bucket['dates']),
        ];
    }
    return $members;
}

/**
 * Validate calendar year/month; return [from, to] Y-m-d or null on invalid.
 *
 * @return array{0: string, 1: string}|null
 */
function br_finbro_month_bounds(int $year, int $month): ?array
{
    if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
        return null;
    }
    $tz = new DateTimeZone('Asia/Kolkata');
    try {
        $start = new DateTime(sprintf('%04d-%02d-01', $year, $month), $tz);
        $end = clone $start;
        $end->modify('last day of this month');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Validate YYYY-MM-DD; return normalized date or null.
 */
function br_finbro_parse_date(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $parts = explode('-', $value);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        return null;
    }
    return $value;
}

/**
 * Why: Cap by-user ranges so a wide from/to cannot scan the whole table under load.
 * Inclusive day count must be <= 366.
 */
function br_finbro_range_day_count(string $from, string $to): int
{
    try {
        $a = new DateTimeImmutable($from . ' 00:00:00', new DateTimeZone('UTC'));
        $b = new DateTimeImmutable($to . ' 00:00:00', new DateTimeZone('UTC'));
        return (int)$a->diff($b)->days + 1;
    } catch (Throwable $e) {
        return PHP_INT_MAX;
    }
}

/**
 * User id list from loaded Finbro user rows (for scoped hours aggregation).
 *
 * @param list<array<string,mixed>> $users
 * @return list<string>
 */
function br_finbro_user_ids(array $users): array
{
    $ids = [];
    foreach ($users as $user) {
        $id = trim((string)($user['id'] ?? ''));
        if ($id !== '') {
            $ids[] = $id;
        }
    }
    return array_values(array_unique($ids));
}
