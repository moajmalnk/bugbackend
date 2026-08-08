<?php
/**
 * Finbro M2M integration helpers (auth + Team Analytics–aligned hours aggregation).
 *
 * Why: Finbro pulls work hours and account status from BugRicer. Hours must match
 * Team Analytics month totals (SUM hours_today + effective OT + break minutes),
 * not the leave-credited individual work-stats path.
 */

require_once __DIR__ . '/work_submission_ot.php';

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
 *
 * @param array<string,mixed> $payload
 */
function br_finbro_json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
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
 * Load staff rows for Finbro (all users with non-empty email).
 *
 * @return list<array<string,mixed>>
 */
function br_finbro_load_users(PDO $conn, ?string $emailFilter = null): array
{
    $hasName = br_finbro_users_has_name_column($conn);
    $hasActive = br_finbro_users_has_account_active($conn);
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
 * @return array<string, array{hours: float, overtime_hours: float, break_minutes: int, dates: array<string,bool>}>
 */
function br_finbro_aggregate_hours_by_user(PDO $conn, string $from, string $to): array
{
    // Why: OT approval columns may be absent on older DBs; SELECT * avoids hard-coded column failures.
    $stmt = $conn->prepare(
        'SELECT *
         FROM work_submissions
         WHERE submission_date >= ? AND submission_date <= ?
         ORDER BY submission_date ASC'
    );
    $stmt->execute([$from, $to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
