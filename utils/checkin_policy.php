<?php
/**
 * Why: Company check-in policy — 10:00 AM IST cutoff (Mon–Sat), Sunday holiday
 * (anytime, never late), Office/WFH mode, rolling late strikes that force
 * Office-only for the next calendar week after 3 unconsumed lates, and
 * Office geofence at Wired In Coworks (500 m).
 */

if (!defined('BR_CHECKIN_LATE_LIMIT')) {
    define('BR_CHECKIN_LATE_LIMIT', 3);
}

if (!defined('BR_OFFICE_LAT')) {
    define('BR_OFFICE_LAT', 10.98738553867724);
}
if (!defined('BR_OFFICE_LNG')) {
    define('BR_OFFICE_LNG', 75.97612159776808);
}
if (!defined('BR_OFFICE_RADIUS_M')) {
    define('BR_OFFICE_RADIUS_M', 500);
}
if (!defined('BR_OFFICE_LABEL')) {
    define('BR_OFFICE_LABEL', 'Wired In Coworks, Kottakkal');
}

if (!defined('BR_CHECKIN_CUTOFF_TIME')) {
    define('BR_CHECKIN_CUTOFF_TIME', '10:00:00');
}

/**
 * Default late cutoff (HH:MM:SS, Asia/Kolkata). Prefer br_checkin_cutoff_config($conn).
 */
function br_checkin_cutoff_time(?PDO $conn = null): string
{
    return br_checkin_cutoff_config($conn)['time'];
}

/**
 * Why: Admins can disable late strikes or change the before-time (e.g. 10:00 AM) in Settings.
 *
 * @return array{enabled:bool,time:string,label:string}
 */
function br_checkin_cutoff_config(?PDO $conn = null): array
{
    $enabled = true;
    $time = (string)BR_CHECKIN_CUTOFF_TIME;

    if ($conn) {
        $rows = br_load_setting_values($conn, [
            'checkin_cutoff_enabled',
            'checkin_cutoff_time',
        ]);
        if (isset($rows['checkin_cutoff_enabled']) && $rows['checkin_cutoff_enabled'] !== '') {
            $raw = strtolower(trim((string)$rows['checkin_cutoff_enabled']));
            $enabled = in_array($raw, ['1', 'true', 'yes', 'on'], true);
        }
        if (!empty($rows['checkin_cutoff_time'])) {
            $normalized = br_normalize_cutoff_time((string)$rows['checkin_cutoff_time']);
            if ($normalized !== null) {
                $time = $normalized;
            }
        }
    }

    return [
        'enabled' => $enabled,
        'time' => $time,
        'label' => br_format_cutoff_label($time),
    ];
}

/**
 * Normalize admin input to HH:MM:SS or null if invalid.
 */
function br_normalize_cutoff_time(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
        return null;
    }
    $h = (int)$m[1];
    $min = (int)$m[2];
    $s = isset($m[3]) ? (int)$m[3] : 0;
    if ($h < 0 || $h > 23 || $min < 0 || $min > 59 || $s < 0 || $s > 59) {
        return null;
    }
    return sprintf('%02d:%02d:%02d', $h, $min, $s);
}

/**
 * Human label for IST cutoff, e.g. "10:00 AM IST".
 */
function br_format_cutoff_label(string $time): string
{
    $parts = explode(':', $time);
    $h = isset($parts[0]) ? (int)$parts[0] : 10;
    $min = isset($parts[1]) ? (int)$parts[1] : 0;
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12;
    if ($h12 === 0) {
        $h12 = 12;
    }
    return sprintf('%d:%02d %s IST', $h12, $min, $ampm);
}

function br_checkin_late_limit(): int
{
    return BR_CHECKIN_LATE_LIMIT;
}

/**
 * @return array{lat:float,lng:float}
 */
function br_office_coords(?PDO $conn = null): array
{
    $cfg = br_office_config($conn);
    return [
        'lat' => $cfg['lat'],
        'lng' => $cfg['lng'],
    ];
}

function br_office_radius_m(?PDO $conn = null): int
{
    return br_office_config($conn)['radius_m'];
}

function br_office_label(?PDO $conn = null): string
{
    return br_office_config($conn)['label'];
}

/**
 * Load key/value rows from settings (request-cached).
 *
 * @param list<string> $keys
 * @return array<string,string>
 */
function br_load_setting_values(?PDO $conn, array $keys): array
{
    static $cache = [];
    static $bustSeen = 0;
    $bust = (int)($GLOBALS['br_settings_cache_bust'] ?? 0);
    if ($bust !== $bustSeen) {
        $cache = [];
        $bustSeen = $bust;
    }

    if (!$conn || empty($keys)) {
        return [];
    }

    $missing = [];
    $out = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $cache)) {
            $out[$key] = $cache[$key];
        } else {
            $missing[] = $key;
        }
    }
    if (empty($missing)) {
        return $out;
    }

    try {
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $stmt = $conn->prepare(
            "SELECT key_name, value FROM settings WHERE key_name IN ($placeholders)"
        );
        $stmt->execute($missing);
        $found = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $k = (string)($row['key_name'] ?? '');
            $found[$k] = (string)($row['value'] ?? '');
            $cache[$k] = $found[$k];
            $out[$k] = $found[$k];
        }
        foreach ($missing as $key) {
            if (!array_key_exists($key, $found)) {
                $cache[$key] = '';
            }
        }
    } catch (Throwable $e) {
        error_log('br_load_setting_values: ' . $e->getMessage());
    }

    return $out;
}

/**
 * Clear request-level settings cache (call after admin updates).
 */
function br_clear_setting_cache(): void
{
    $GLOBALS['br_settings_cache_bust'] = ($GLOBALS['br_settings_cache_bust'] ?? 0) + 1;
}

/**
 * @return array{lat:float,lng:float,radius_m:int,label:string}
 */
function br_office_config(?PDO $conn = null): array
{
    $cfg = [
        'lat' => (float)BR_OFFICE_LAT,
        'lng' => (float)BR_OFFICE_LNG,
        'radius_m' => (int)BR_OFFICE_RADIUS_M,
        'label' => (string)BR_OFFICE_LABEL,
    ];

    if (!$conn) {
        return $cfg;
    }

    $rows = br_load_setting_values($conn, [
        'office_lat',
        'office_lng',
        'office_radius_m',
        'office_label',
    ]);

    if (isset($rows['office_lat']) && $rows['office_lat'] !== '' && is_numeric($rows['office_lat'])) {
        $lat = (float)$rows['office_lat'];
        if ($lat >= -90 && $lat <= 90) {
            $cfg['lat'] = $lat;
        }
    }
    if (isset($rows['office_lng']) && $rows['office_lng'] !== '' && is_numeric($rows['office_lng'])) {
        $lng = (float)$rows['office_lng'];
        if ($lng >= -180 && $lng <= 180) {
            $cfg['lng'] = $lng;
        }
    }
    if (isset($rows['office_radius_m']) && $rows['office_radius_m'] !== '' && is_numeric($rows['office_radius_m'])) {
        $radius = (int)round((float)$rows['office_radius_m']);
        if ($radius >= 50 && $radius <= 5000) {
            $cfg['radius_m'] = $radius;
        }
    }
    if (isset($rows['office_label']) && trim($rows['office_label']) !== '') {
        $cfg['label'] = mb_substr(trim($rows['office_label']), 0, 120);
    }

    return $cfg;
}

/**
 * Upsert a settings key (admin).
 */
function br_upsert_setting(PDO $conn, string $key, string $value): void
{
    $stmt = $conn->prepare(
        'INSERT INTO settings (key_name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
    $stmt->execute([$key, $value]);
}

/**
 * Great-circle distance in meters (Haversine).
 */
function br_haversine_meters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earth = 6371000.0;
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dPhi = deg2rad($lat2 - $lat1);
    $dLambda = deg2rad($lng2 - $lng1);
    $a = sin($dPhi / 2) ** 2
        + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    return $earth * $c;
}

/**
 * Why: Server must re-check Office GPS so clients cannot spoof location.
 *
 * @return array{ok:bool,distance_m:?float,message:?string}
 */
function br_validate_office_location($lat, $lng, ?PDO $conn = null): array
{
    if (!is_numeric($lat) || !is_numeric($lng)) {
        return [
            'ok' => false,
            'distance_m' => null,
            'message' => 'Office check-in requires a valid location. Enable GPS and try again.',
        ];
    }

    $latF = (float)$lat;
    $lngF = (float)$lng;
    if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
        return [
            'ok' => false,
            'distance_m' => null,
            'message' => 'Invalid GPS coordinates for Office check-in.',
        ];
    }

    $office = br_office_coords($conn);
    $distance = br_haversine_meters($latF, $lngF, $office['lat'], $office['lng']);
    $radius = br_office_radius_m($conn);
    $label = br_office_label($conn);

    if ($distance > $radius) {
        return [
            'ok' => false,
            'distance_m' => round($distance, 1),
            'message' => sprintf(
                'You are about %.0f m away. Move closer to %s (within %d m) to check in as Office.',
                $distance,
                $label,
                $radius
            ),
        ];
    }

    return [
        'ok' => true,
        'distance_m' => round($distance, 1),
        'message' => null,
    ];
}

/**
 * Ensure late/WFH/geo columns and restrictions table exist (safe to call repeatedly).
 */
function br_ensure_checkin_policy_schema(PDO $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }

    try {
        $cols = [];
        $res = $conn->query('SHOW COLUMNS FROM work_submissions');
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
        }

        if (!in_array('work_mode', $cols, true)) {
            $conn->exec("ALTER TABLE work_submissions ADD COLUMN work_mode ENUM('office','wfh') NULL DEFAULT NULL AFTER check_in_time");
            $cols[] = 'work_mode';
        }
        if (!in_array('is_late', $cols, true)) {
            $conn->exec('ALTER TABLE work_submissions ADD COLUMN is_late TINYINT(1) NOT NULL DEFAULT 0 AFTER work_mode');
            $cols[] = 'is_late';
        }
        if (!in_array('late_strike_consumed', $cols, true)) {
            $conn->exec('ALTER TABLE work_submissions ADD COLUMN late_strike_consumed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_late');
            $cols[] = 'late_strike_consumed';
        }
        if (!in_array('check_in_lat', $cols, true)) {
            $conn->exec('ALTER TABLE work_submissions ADD COLUMN check_in_lat DECIMAL(10,7) NULL DEFAULT NULL AFTER late_strike_consumed');
            $cols[] = 'check_in_lat';
        }
        if (!in_array('check_in_lng', $cols, true)) {
            $conn->exec('ALTER TABLE work_submissions ADD COLUMN check_in_lng DECIMAL(10,7) NULL DEFAULT NULL AFTER check_in_lat');
            $cols[] = 'check_in_lng';
        }
        if (!in_array('check_in_accuracy_m', $cols, true)) {
            $conn->exec('ALTER TABLE work_submissions ADD COLUMN check_in_accuracy_m DECIMAL(8,2) NULL DEFAULT NULL AFTER check_in_lng');
            $cols[] = 'check_in_accuracy_m';
        }
        if (!in_array('check_in_distance_m', $cols, true)) {
            $conn->exec('ALTER TABLE work_submissions ADD COLUMN check_in_distance_m DECIMAL(8,2) NULL DEFAULT NULL AFTER check_in_accuracy_m');
        }

        $conn->exec(
            "CREATE TABLE IF NOT EXISTS attendance_office_restrictions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id VARCHAR(64) NOT NULL,
                week_start DATE NOT NULL,
                week_end DATE NOT NULL,
                triggered_at DATETIME NOT NULL,
                trigger_late_count INT NOT NULL DEFAULT 3,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_week_start (user_id, week_start),
                KEY idx_user_week_range (user_id, week_start, week_end)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $conn->exec(
            "CREATE TABLE IF NOT EXISTS attendance_day_exceptions (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id VARCHAR(64) NOT NULL,
                exception_date DATE NOT NULL,
                allow_wfh TINYINT(1) NOT NULL DEFAULT 0,
                forgive_late TINYINT(1) NOT NULL DEFAULT 0,
                admin_note VARCHAR(255) NULL DEFAULT NULL,
                created_by VARCHAR(64) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_exception_date (user_id, exception_date),
                KEY idx_exception_date (exception_date),
                KEY idx_user_flags (user_id, allow_wfh, forgive_late)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $conn->exec(
            "CREATE TABLE IF NOT EXISTS attendance_wfh_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id VARCHAR(64) NOT NULL,
                request_date DATE NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                user_note VARCHAR(255) NULL DEFAULT NULL,
                admin_note VARCHAR(255) NULL DEFAULT NULL,
                reviewed_by VARCHAR(64) NULL DEFAULT NULL,
                reviewed_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_request_date (user_id, request_date),
                KEY idx_status_date (status, request_date),
                KEY idx_request_date (request_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Why: users / work_submissions are often utf8mb4_general_ci; JOINs fail without a shared collation.
        try {
            $conn->exec(
                'ALTER TABLE attendance_day_exceptions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (Throwable $ignored) {
        }
        try {
            $conn->exec(
                'ALTER TABLE attendance_office_restrictions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (Throwable $ignored) {
        }
        try {
            $conn->exec(
                'ALTER TABLE attendance_wfh_requests CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (Throwable $ignored) {
        }
    } catch (Throwable $e) {
        error_log('br_ensure_checkin_policy_schema: ' . $e->getMessage());
    }

    $done = true;
}

function br_is_sunday(string $date): bool
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = DateTime::createFromFormat('Y-m-d', $date, $tz);
    if (!$dt) {
        return false;
    }
    return (int)$dt->format('N') === 7;
}

/**
 * Late only on Mon–Sat when cutoff policy is enabled, server time is at/after
 * the configured IST cutoff, and the submission date is today.
 * Sunday is never late. Past/future admin dates are not marked late from "now".
 */
function br_is_late_checkin(
    ?DateTimeInterface $now,
    string $submissionDate,
    ?PDO $conn = null
): bool {
    $cutoff = br_checkin_cutoff_config($conn);
    if (!$cutoff['enabled']) {
        return false;
    }

    if (br_is_sunday($submissionDate)) {
        return false;
    }

    $tz = new DateTimeZone('Asia/Kolkata');
    if ($now === null) {
        $now = new DateTime('now', $tz);
    } elseif ($now->getTimezone()->getName() !== 'Asia/Kolkata') {
        $now = DateTime::createFromInterface($now)->setTimezone($tz);
    }

    $serverToday = $now->format('Y-m-d');
    if ($submissionDate !== $serverToday) {
        return false;
    }

    return $now->format('H:i:s') >= $cutoff['time'];
}

/**
 * Monday–Sunday of the calendar week AFTER the week containing $fromDate.
 *
 * @return array{0:string,1:string} [week_start, week_end]
 */
function br_next_week_bounds(string $fromDate): array
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $dt = DateTime::createFromFormat('Y-m-d', $fromDate, $tz) ?: new DateTime($fromDate, $tz);
    $dow = (int)$dt->format('N'); // 1=Mon … 7=Sun
    $daysUntilNextMonday = 8 - $dow;
    $nextMon = clone $dt;
    $nextMon->modify('+' . $daysUntilNextMonday . ' days');
    $nextSun = clone $nextMon;
    $nextSun->modify('+6 days');
    return [$nextMon->format('Y-m-d'), $nextSun->format('Y-m-d')];
}

/**
 * @return array{week_start:string,week_end:string,triggered_at?:string}|null
 */
function br_active_office_restriction(PDO $conn, $userId, string $date): ?array
{
    br_ensure_checkin_policy_schema($conn);
    try {
        $stmt = $conn->prepare(
            'SELECT week_start, week_end, triggered_at
             FROM attendance_office_restrictions
             WHERE user_id = ? AND week_start <= ? AND week_end >= ?
             ORDER BY week_start DESC
             LIMIT 1'
        );
        $stmt->execute([(string)$userId, $date, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'week_start' => (string)$row['week_start'],
            'week_end' => (string)$row['week_end'],
            'triggered_at' => $row['triggered_at'] ?? null,
        ];
    } catch (Throwable $e) {
        error_log('br_active_office_restriction: ' . $e->getMessage());
        return null;
    }
}

function br_count_unconsumed_lates(PDO $conn, $userId): int
{
    br_ensure_checkin_policy_schema($conn);
    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) AS c
             FROM work_submissions
             WHERE user_id = ? AND is_late = 1 AND late_strike_consumed = 0'
        );
        $stmt->execute([(string)$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        error_log('br_count_unconsumed_lates: ' . $e->getMessage());
        return 0;
    }
}

function br_normalize_work_mode($value): ?string
{
    $mode = strtolower(trim((string)$value));
    if ($mode === 'office' || $mode === 'wfh') {
        return $mode;
    }
    return null;
}

/**
 * After a late check-in is persisted: if unconsumed lates >= 3 and next week
 * has no restriction yet, create Office-only week and consume 3 oldest strikes.
 *
 * @return array{
 *   late_count:int,
 *   late_limit:int,
 *   restriction_created:bool,
 *   office_only_week:?array{week_start:string,week_end:string},
 *   warning:?string
 * }
 */
function br_apply_late_strike_and_maybe_restrict(PDO $conn, $userId, string $fromDate, bool $justMarkedLate): array
{
    br_ensure_checkin_policy_schema($conn);
    $limit = br_checkin_late_limit();
    $lateCount = br_count_unconsumed_lates($conn, $userId);
    $result = [
        'late_count' => $lateCount,
        'late_limit' => $limit,
        'restriction_created' => false,
        'office_only_week' => null,
        'warning' => null,
    ];

    if (!$justMarkedLate || $lateCount < $limit) {
        if ($justMarkedLate && $lateCount > 0) {
            $result['warning'] = sprintf(
                'Late check-in (%d/%d). After %d late check-ins, next week is Office only (no WFH).',
                $lateCount,
                $limit,
                $limit
            );
        }
        return $result;
    }

    [$weekStart, $weekEnd] = br_next_week_bounds($fromDate);

    try {
        $existsStmt = $conn->prepare(
            'SELECT id FROM attendance_office_restrictions WHERE user_id = ? AND week_start = ? LIMIT 1'
        );
        $existsStmt->execute([(string)$userId, $weekStart]);
        if ($existsStmt->fetch(PDO::FETCH_ASSOC)) {
            $result['office_only_week'] = ['week_start' => $weekStart, 'week_end' => $weekEnd];
            $result['warning'] = sprintf(
                'You already have an Office-only week scheduled (%s – %s). WFH will be blocked that week.',
                $weekStart,
                $weekEnd
            );
            return $result;
        }

        $insert = $conn->prepare(
            'INSERT INTO attendance_office_restrictions
                (user_id, week_start, week_end, triggered_at, trigger_late_count)
             VALUES (?, ?, ?, NOW(), ?)'
        );
        $insert->execute([(string)$userId, $weekStart, $weekEnd, $limit]);

        // Consume oldest unconsumed late rows (exactly $limit)
        $idsStmt = $conn->prepare(
            'SELECT id FROM work_submissions
             WHERE user_id = ? AND is_late = 1 AND late_strike_consumed = 0
             ORDER BY submission_date ASC, id ASC
             LIMIT ' . (int)$limit
        );
        $idsStmt->execute([(string)$userId]);
        $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $consume = $conn->prepare(
                "UPDATE work_submissions SET late_strike_consumed = 1 WHERE id IN ($placeholders)"
            );
            $consume->execute(array_map('intval', $ids));
        }

        $lateCount = br_count_unconsumed_lates($conn, $userId);
        $result['late_count'] = $lateCount;
        $result['restriction_created'] = true;
        $result['office_only_week'] = ['week_start' => $weekStart, 'week_end' => $weekEnd];
        $result['warning'] = sprintf(
            'Late check-in limit reached. Next week (%s – %s) is Office only — WFH is not allowed.',
            $weekStart,
            $weekEnd
        );
    } catch (Throwable $e) {
        error_log('br_apply_late_strike_and_maybe_restrict: ' . $e->getMessage());
        $result['warning'] = 'Late check-in recorded. Could not schedule Office-only week — contact admin.';
    }

    return $result;
}

/**
 * @return array{id?:int,allow_wfh:bool,forgive_late:bool,admin_note:?string}|null
 */
function br_day_exception(PDO $conn, $userId, string $date): ?array
{
    br_ensure_checkin_policy_schema($conn);
    try {
        $stmt = $conn->prepare(
            'SELECT id, allow_wfh, forgive_late, admin_note
             FROM attendance_day_exceptions
             WHERE user_id = ? AND exception_date = ?
             LIMIT 1'
        );
        $stmt->execute([(string)$userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)($row['id'] ?? 0),
            'allow_wfh' => (int)($row['allow_wfh'] ?? 0) === 1,
            'forgive_late' => (int)($row['forgive_late'] ?? 0) === 1,
            'admin_note' => $row['admin_note'] ?? null,
        ];
    } catch (Throwable $e) {
        error_log('br_day_exception: ' . $e->getMessage());
        return null;
    }
}

/**
 * Why: Normalize admin multi-date payloads to unique YYYY-MM-DD values only.
 *
 * @param mixed $dates
 * @return list<string>
 */
function br_normalize_ymd_dates($dates): array
{
    $out = [];
    if (!is_array($dates)) {
        return $out;
    }
    foreach ($dates as $d) {
        $d = trim((string)$d);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $out[$d] = $d;
        }
    }
    $list = array_values($out);
    sort($list);
    return $list;
}

/**
 * Why: Clear many day exceptions in one statement for multi-remove admin UX.
 *
 * @param list<string> $dates
 * @return array{ok:bool,message:string,cleared:int,dates:list<string>}
 */
function br_clear_day_exceptions(PDO $conn, $userId, array $dates): array
{
    br_ensure_checkin_policy_schema($conn);
    $dates = br_normalize_ymd_dates($dates);
    if ($dates === []) {
        return ['ok' => false, 'message' => 'No valid dates.', 'cleared' => 0, 'dates' => []];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($dates), '?'));
        $stmt = $conn->prepare(
            "DELETE FROM attendance_day_exceptions
             WHERE user_id = ? AND exception_date IN ($placeholders)"
        );
        $params = array_merge([(string)$userId], $dates);
        $stmt->execute($params);
        return [
            'ok' => true,
            'message' => count($dates) === 1 ? 'Exception cleared.' : 'Exceptions cleared.',
            'cleared' => (int)$stmt->rowCount(),
            'dates' => $dates,
        ];
    } catch (Throwable $e) {
        error_log('br_clear_day_exceptions: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to clear exceptions.', 'cleared' => 0, 'dates' => $dates];
    }
}

/**
 * Upsert a day exception. Passing null for a flag keeps the existing value (or 0 on insert).
 *
 * @return array{ok:bool,message:?string,exception:?array}
 */
function br_upsert_day_exception(
    PDO $conn,
    $userId,
    string $date,
    $allowWfh,
    $forgiveLate,
    $adminId,
    ?string $adminNote = null
): array {
    br_ensure_checkin_policy_schema($conn);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'message' => 'Invalid date.', 'exception' => null];
    }

    $existing = br_day_exception($conn, $userId, $date);
    $allow = $allowWfh === null
        ? (int)($existing['allow_wfh'] ?? false)
        : (!empty($allowWfh) ? 1 : 0);
    $forgive = $forgiveLate === null
        ? (int)($existing['forgive_late'] ?? false)
        : (!empty($forgiveLate) ? 1 : 0);

    if ($allow === 0 && $forgive === 0) {
        // Nothing to keep — delete row if present
        try {
            $del = $conn->prepare(
                'DELETE FROM attendance_day_exceptions WHERE user_id = ? AND exception_date = ?'
            );
            $del->execute([(string)$userId, $date]);
        } catch (Throwable $e) {
            // non-fatal
        }
        return [
            'ok' => true,
            'message' => 'Exception cleared.',
            'exception' => null,
        ];
    }

    $note = $adminNote !== null ? mb_substr(trim($adminNote), 0, 255) : ($existing['admin_note'] ?? null);

    try {
        $stmt = $conn->prepare(
            'INSERT INTO attendance_day_exceptions
                (user_id, exception_date, allow_wfh, forgive_late, admin_note, created_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                allow_wfh = VALUES(allow_wfh),
                forgive_late = VALUES(forgive_late),
                admin_note = VALUES(admin_note),
                created_by = VALUES(created_by)'
        );
        $stmt->execute([
            (string)$userId,
            $date,
            $allow,
            $forgive,
            $note !== '' ? $note : null,
            $adminId !== null ? (string)$adminId : null,
        ]);
    } catch (Throwable $e) {
        error_log('br_upsert_day_exception: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to save exception.', 'exception' => null];
    }

    return [
        'ok' => true,
        'message' => 'Exception saved.',
        'exception' => br_day_exception($conn, $userId, $date),
    ];
}

/**
 * Why: After forgiving a late day, rebuild strike pool and cancel/rebuild
 * Office-only weeks so admin overrides stay consistent.
 */
function br_recalc_late_strikes(PDO $conn, $userId): array
{
    br_ensure_checkin_policy_schema($conn);
    $userId = (string)$userId;
    $today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

    try {
        // Cancel current + future Office-only weeks; past weeks stay for history
        $del = $conn->prepare(
            'DELETE FROM attendance_office_restrictions
             WHERE user_id = ? AND week_end >= ?'
        );
        $del->execute([$userId, $today]);

        // Return all remaining late days to the unconsumed pool
        $reset = $conn->prepare(
            'UPDATE work_submissions
             SET late_strike_consumed = 0
             WHERE user_id = ? AND is_late = 1'
        );
        $reset->execute([$userId]);
    } catch (Throwable $e) {
        error_log('br_recalc_late_strikes reset: ' . $e->getMessage());
    }

    $lateCount = br_count_unconsumed_lates($conn, $userId);
    $limit = br_checkin_late_limit();
    $created = null;

    if ($lateCount >= $limit) {
        $result = br_apply_late_strike_and_maybe_restrict($conn, $userId, $today, true);
        $created = $result['office_only_week'] ?? null;
        $lateCount = (int)($result['late_count'] ?? br_count_unconsumed_lates($conn, $userId));
    }

    return [
        'late_count' => $lateCount,
        'late_limit' => $limit,
        'office_only_week' => $created,
    ];
}

/**
 * Clear late flag for day(s), record forgive exception, recalc strikes once.
 *
 * @param string|list<string> $dateOrDates
 * @return array{ok:bool,message:string,recalc?:array,dates?:list<string>}
 */
function br_forgive_late_day(PDO $conn, $userId, $dateOrDates, $adminId, ?string $adminNote = null): array
{
    br_ensure_checkin_policy_schema($conn);
    $dates = is_array($dateOrDates)
        ? br_normalize_ymd_dates($dateOrDates)
        : br_normalize_ymd_dates([(string)$dateOrDates]);

    if ($dates === []) {
        return ['ok' => false, 'message' => 'Invalid date.'];
    }

    foreach ($dates as $date) {
        try {
            $upd = $conn->prepare(
                'UPDATE work_submissions
                 SET is_late = 0, late_strike_consumed = 0
                 WHERE user_id = ? AND submission_date = ?'
            );
            $upd->execute([(string)$userId, $date]);
        } catch (Throwable $e) {
            error_log('br_forgive_late_day update: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Failed to clear late flag.', 'dates' => $dates];
        }

        $existing = br_day_exception($conn, $userId, $date);
        br_upsert_day_exception(
            $conn,
            $userId,
            $date,
            $existing['allow_wfh'] ?? false,
            true,
            $adminId,
            $adminNote
        );
    }

    $recalc = br_recalc_late_strikes($conn, $userId);

    return [
        'ok' => true,
        'message' => count($dates) === 1
            ? 'Late check-in unmarked for this day.'
            : ('Late check-ins unmarked for ' . count($dates) . ' days.'),
        'recalc' => $recalc,
        'dates' => $dates,
    ];
}

/**
 * @return array{id:int,user_id:string,request_date:string,status:string,user_note:?string,admin_note:?string,reviewed_by:?string,reviewed_at:?string,created_at:?string,updated_at:?string}|null
 */
function br_wfh_request_for_day(PDO $conn, $userId, string $date): ?array
{
    br_ensure_checkin_policy_schema($conn);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    try {
        $stmt = $conn->prepare(
            'SELECT id, user_id, request_date, status, user_note, admin_note,
                    reviewed_by, reviewed_at, created_at, updated_at
             FROM attendance_wfh_requests
             WHERE user_id = ? AND request_date = ?
             LIMIT 1'
        );
        $stmt->execute([(string)$userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'id' => (int)$row['id'],
            'user_id' => (string)$row['user_id'],
            'request_date' => (string)$row['request_date'],
            'status' => (string)$row['status'],
            'user_note' => $row['user_note'] !== null ? (string)$row['user_note'] : null,
            'admin_note' => $row['admin_note'] !== null ? (string)$row['admin_note'] : null,
            'reviewed_by' => $row['reviewed_by'] !== null ? (string)$row['reviewed_by'] : null,
            'reviewed_at' => $row['reviewed_at'] !== null ? (string)$row['reviewed_at'] : null,
            'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
        ];
    } catch (Throwable $e) {
        error_log('br_wfh_request_for_day: ' . $e->getMessage());
        return null;
    }
}

/**
 * Create or refresh a pending WFH request for the day.
 *
 * @return array{ok:bool,message:?string,request:?array}
 */
function br_upsert_wfh_request(PDO $conn, $userId, string $date, ?string $userNote = null): array
{
    br_ensure_checkin_policy_schema($conn);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['ok' => false, 'message' => 'Invalid date.', 'request' => null];
    }

    $existing = br_wfh_request_for_day($conn, $userId, $date);
    if ($existing && ($existing['status'] ?? '') === 'approved') {
        return [
            'ok' => false,
            'message' => 'WFH is already approved for this day. You can check in as WFH.',
            'request' => $existing,
        ];
    }

    $exception = br_day_exception($conn, $userId, $date);
    if (!empty($exception['allow_wfh'])) {
        return [
            'ok' => false,
            'message' => 'WFH is already allowed for this day. You can check in as WFH.',
            'request' => $existing,
        ];
    }

    $note = $userNote !== null ? mb_substr(trim($userNote), 0, 255) : ($existing['user_note'] ?? null);

    try {
        $stmt = $conn->prepare(
            'INSERT INTO attendance_wfh_requests
                (user_id, request_date, status, user_note, admin_note, reviewed_by, reviewed_at)
             VALUES (?, ?, \'pending\', ?, NULL, NULL, NULL)
             ON DUPLICATE KEY UPDATE
                status = \'pending\',
                user_note = VALUES(user_note),
                admin_note = NULL,
                reviewed_by = NULL,
                reviewed_at = NULL'
        );
        $stmt->execute([
            (string)$userId,
            $date,
            $note !== '' ? $note : null,
        ]);
    } catch (Throwable $e) {
        error_log('br_upsert_wfh_request: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to save WFH request.', 'request' => null];
    }

    return [
        'ok' => true,
        'message' => 'WFH request submitted. Waiting for admin approval.',
        'request' => br_wfh_request_for_day($conn, $userId, $date),
    ];
}

/**
 * Admin approve/reject a WFH request. Approve also grants allow_wfh for the day.
 *
 * @return array{ok:bool,message:?string,request:?array,exception:?array}
 */
function br_review_wfh_request(
    PDO $conn,
    $userId,
    string $date,
    string $decision,
    $adminId,
    ?string $adminNote = null
): array {
    br_ensure_checkin_policy_schema($conn);
    $decision = strtolower(trim($decision));
    if (!in_array($decision, ['approve', 'approved', 'reject', 'rejected'], true)) {
        return ['ok' => false, 'message' => 'Invalid decision.', 'request' => null, 'exception' => null];
    }
    $status = in_array($decision, ['approve', 'approved'], true) ? 'approved' : 'rejected';

    $existing = br_wfh_request_for_day($conn, $userId, $date);
    if (!$existing) {
        return ['ok' => false, 'message' => 'No WFH request found for this day.', 'request' => null, 'exception' => null];
    }
    if (($existing['status'] ?? '') !== 'pending') {
        return [
            'ok' => false,
            'message' => 'This WFH request was already ' . $existing['status'] . '.',
            'request' => $existing,
            'exception' => br_day_exception($conn, $userId, $date),
        ];
    }

    $note = $adminNote !== null ? mb_substr(trim($adminNote), 0, 255) : null;
    $now = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d H:i:s');

    try {
        $stmt = $conn->prepare(
            'UPDATE attendance_wfh_requests
             SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = ?
             WHERE user_id = ? AND request_date = ? AND status = \'pending\''
        );
        $stmt->execute([
            $status,
            $note !== '' ? $note : null,
            $adminId !== null ? (string)$adminId : null,
            $now,
            (string)$userId,
            $date,
        ]);
        if ($stmt->rowCount() < 1) {
            return [
                'ok' => false,
                'message' => 'Could not update WFH request (already reviewed).',
                'request' => br_wfh_request_for_day($conn, $userId, $date),
                'exception' => br_day_exception($conn, $userId, $date),
            ];
        }
    } catch (Throwable $e) {
        error_log('br_review_wfh_request: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Failed to review WFH request.', 'request' => null, 'exception' => null];
    }

    $exception = null;
    if ($status === 'approved') {
        $upsert = br_upsert_day_exception(
            $conn,
            $userId,
            $date,
            true,
            null,
            $adminId,
            $note !== '' ? $note : 'Approved WFH request'
        );
        if (!$upsert['ok']) {
            return [
                'ok' => false,
                'message' => $upsert['message'] ?? 'Approved request but failed to grant WFH exception.',
                'request' => br_wfh_request_for_day($conn, $userId, $date),
                'exception' => null,
            ];
        }
        $exception = $upsert['exception'];
    }

    return [
        'ok' => true,
        'message' => $status === 'approved'
            ? 'WFH request approved. User can check in as WFH today.'
            : 'WFH request rejected.',
        'request' => br_wfh_request_for_day($conn, $userId, $date),
        'exception' => $exception,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function br_list_pending_wfh_requests(PDO $conn, int $limit = 100): array
{
    br_ensure_checkin_policy_schema($conn);
    $limit = max(1, min(200, $limit));
    $rows = [];
    try {
        $sql = "SELECT r.id, r.user_id, r.request_date, r.status, r.user_note, r.admin_note,
                       r.reviewed_by, r.reviewed_at, r.created_at, r.updated_at,
                       COALESCE(NULLIF(TRIM(u.username), ''), CONCAT('user #', r.user_id)) AS username,
                       COALESCE(u.role, '') AS role
                FROM attendance_wfh_requests r
                LEFT JOIN users u ON u.id COLLATE utf8mb4_unicode_ci = r.user_id COLLATE utf8mb4_unicode_ci
                WHERE r.status = 'pending'
                ORDER BY r.request_date DESC, r.created_at DESC
                LIMIT {$limit}";
        $stmt = $conn->query($sql);
        if (!$stmt) {
            return [];
        }
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'id' => (int)$row['id'],
                'user_id' => (string)$row['user_id'],
                'username' => (string)$row['username'],
                'role' => (string)$row['role'],
                'request_date' => (string)$row['request_date'],
                'status' => (string)$row['status'],
                'user_note' => $row['user_note'] !== null ? (string)$row['user_note'] : null,
                'admin_note' => $row['admin_note'] !== null ? (string)$row['admin_note'] : null,
                'reviewed_by' => $row['reviewed_by'] !== null ? (string)$row['reviewed_by'] : null,
                'reviewed_at' => $row['reviewed_at'] !== null ? (string)$row['reviewed_at'] : null,
                'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
                'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            ];
        }
    } catch (Throwable $e) {
        error_log('br_list_pending_wfh_requests: ' . $e->getMessage());
    }
    return $rows;
}

/**
 * Why: Admin user detail needs rejected/approved/pending WFH request history, not only pending queue.
 *
 * @param string|null $statusFilter pending|approved|rejected or null for all
 * @return list<array<string,mixed>>
 */
function br_list_wfh_requests_for_user(PDO $conn, $userId, ?string $statusFilter = null, int $limit = 100): array
{
    br_ensure_checkin_policy_schema($conn);
    $limit = max(1, min(200, $limit));
    $statusFilter = $statusFilter !== null ? strtolower(trim($statusFilter)) : null;
    if ($statusFilter !== null && !in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
        $statusFilter = null;
    }

    $rows = [];
    try {
        $sql = "SELECT r.id, r.user_id, r.request_date, r.status, r.user_note, r.admin_note,
                       r.reviewed_by, r.reviewed_at, r.created_at, r.updated_at,
                       COALESCE(NULLIF(TRIM(u.username), ''), CONCAT('user #', r.user_id)) AS username,
                       COALESCE(u.role, '') AS role,
                       COALESCE(NULLIF(TRIM(rev.username), ''), NULL) AS reviewed_by_username
                FROM attendance_wfh_requests r
                LEFT JOIN users u ON u.id COLLATE utf8mb4_unicode_ci = r.user_id COLLATE utf8mb4_unicode_ci
                LEFT JOIN users rev ON rev.id COLLATE utf8mb4_unicode_ci = r.reviewed_by COLLATE utf8mb4_unicode_ci
                WHERE r.user_id = ?";
        $params = [(string)$userId];
        if ($statusFilter !== null) {
            $sql .= ' AND r.status = ?';
            $params[] = $statusFilter;
        }
        $sql .= " ORDER BY r.request_date DESC, r.updated_at DESC, r.created_at DESC LIMIT {$limit}";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'id' => (int)$row['id'],
                'user_id' => (string)$row['user_id'],
                'username' => (string)$row['username'],
                'role' => (string)$row['role'],
                'request_date' => (string)$row['request_date'],
                'status' => (string)$row['status'],
                'user_note' => $row['user_note'] !== null ? (string)$row['user_note'] : null,
                'admin_note' => $row['admin_note'] !== null ? (string)$row['admin_note'] : null,
                'reviewed_by' => $row['reviewed_by'] !== null ? (string)$row['reviewed_by'] : null,
                'reviewed_by_username' => $row['reviewed_by_username'] !== null
                    ? (string)$row['reviewed_by_username']
                    : null,
                'reviewed_at' => $row['reviewed_at'] !== null ? (string)$row['reviewed_at'] : null,
                'created_at' => $row['created_at'] !== null ? (string)$row['created_at'] : null,
                'updated_at' => $row['updated_at'] !== null ? (string)$row['updated_at'] : null,
            ];
        }
    } catch (Throwable $e) {
        error_log('br_list_wfh_requests_for_user: ' . $e->getMessage());
    }
    return $rows;
}

/**
 * Policy snapshot for attendance_status / check-in UI.
 *
 * @return array<string,mixed>
 */
function br_checkin_policy_status(PDO $conn, $userId, string $date): array
{
    br_ensure_checkin_policy_schema($conn);
    $isSunday = br_is_sunday($date);
    $restriction = br_active_office_restriction($conn, $userId, $date);
    $lateCount = br_count_unconsumed_lates($conn, $userId);
    $exception = br_day_exception($conn, $userId, $date);
    $wfhRequest = br_wfh_request_for_day($conn, $userId, $date);
    $wfhRequestStatus = $wfhRequest['status'] ?? 'none';
    if ($wfhRequestStatus === '' || $wfhRequestStatus === null) {
        $wfhRequestStatus = 'none';
    }

    // Why: WFH is never a free choice — only Attendance exception days (or approved WFH request).
    $allowWfhToday = !empty($exception['allow_wfh']) || $wfhRequestStatus === 'approved';
    $forgiveLateToday = !empty($exception['forgive_late']);

    // Penalty week banner (late strikes) — separate from daily WFH gating.
    $officeOnly = $restriction !== null && !$allowWfhToday;

    // Why: Request only when WFH is not already granted/approved for today.
    $canRequestWfh = !$allowWfhToday
        && $wfhRequestStatus !== 'pending'
        && $wfhRequestStatus !== 'approved';

    // Upcoming restriction (next week) for banner preview when not currently restricted
    $upcoming = null;
    if (!$officeOnly && $restriction === null) {
        try {
            $stmt = $conn->prepare(
                'SELECT week_start, week_end FROM attendance_office_restrictions
                 WHERE user_id = ? AND week_start > ?
                 ORDER BY week_start ASC LIMIT 1'
            );
            $stmt->execute([(string)$userId, $date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $upcoming = [
                    'week_start' => (string)$row['week_start'],
                    'week_end' => (string)$row['week_end'],
                ];
            }
        } catch (Throwable $e) {
            // non-fatal
        }
    } elseif ($restriction !== null && $allowWfhToday) {
        // Still show that a restriction week exists, but WFH is allowed today
        $upcoming = null;
    }

    $cutoff = br_checkin_cutoff_config($conn);

    return [
        'checkin_cutoff' => $cutoff['time'],
        'checkin_cutoff_enabled' => $cutoff['enabled'],
        'checkin_cutoff_label' => $cutoff['label'],
        'is_sunday' => $isSunday,
        'late_count' => $lateCount,
        'late_limit' => br_checkin_late_limit(),
        'office_only' => $officeOnly,
        'office_only_week_start' => $restriction['week_start'] ?? null,
        'office_only_week_end' => $restriction['week_end'] ?? null,
        'upcoming_office_only_week' => $upcoming,
        'work_mode_locked_to' => $allowWfhToday ? null : 'office',
        'allow_wfh_today' => $allowWfhToday,
        'forgive_late_today' => $forgiveLateToday,
        'day_exception' => $exception,
        'wfh_request_status' => $wfhRequest ? $wfhRequestStatus : 'none',
        'wfh_request' => $wfhRequest,
        'can_request_wfh' => $canRequestWfh,
        'office_lat' => br_office_coords($conn)['lat'],
        'office_lng' => br_office_coords($conn)['lng'],
        'office_radius_m' => br_office_radius_m($conn),
        'office_label' => br_office_label($conn),
    ];
}
