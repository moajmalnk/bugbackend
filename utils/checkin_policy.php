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

function br_checkin_cutoff_time(): string
{
    return '10:00:00';
}

function br_checkin_late_limit(): int
{
    return BR_CHECKIN_LATE_LIMIT;
}

/**
 * @return array{lat:float,lng:float}
 */
function br_office_coords(): array
{
    return [
        'lat' => (float)BR_OFFICE_LAT,
        'lng' => (float)BR_OFFICE_LNG,
    ];
}

function br_office_radius_m(): int
{
    return (int)BR_OFFICE_RADIUS_M;
}

function br_office_label(): string
{
    return (string)BR_OFFICE_LABEL;
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
function br_validate_office_location($lat, $lng): array
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

    $office = br_office_coords();
    $distance = br_haversine_meters($latF, $lngF, $office['lat'], $office['lng']);
    $radius = br_office_radius_m();

    if ($distance > $radius) {
        return [
            'ok' => false,
            'distance_m' => round($distance, 1),
            'message' => sprintf(
                'You are about %.0f m away. Move closer to %s (within %d m) to check in as Office.',
                $distance,
                br_office_label(),
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
 * Late only on Mon–Sat when server time is at/after 10:00 IST and date is today.
 * Sunday is never late. Past/future admin dates are not marked late from "now".
 */
function br_is_late_checkin(?DateTimeInterface $now, string $submissionDate): bool
{
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

    return $now->format('H:i:s') >= br_checkin_cutoff_time();
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
    $officeOnly = $restriction !== null;

    // Upcoming restriction (next week) for banner preview when not currently restricted
    $upcoming = null;
    if (!$officeOnly) {
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
    }

    return [
        'checkin_cutoff' => br_checkin_cutoff_time(),
        'is_sunday' => $isSunday,
        'late_count' => $lateCount,
        'late_limit' => br_checkin_late_limit(),
        'office_only' => $officeOnly,
        'office_only_week_start' => $restriction['week_start'] ?? null,
        'office_only_week_end' => $restriction['week_end'] ?? null,
        'upcoming_office_only_week' => $upcoming,
        'work_mode_locked_to' => $officeOnly ? 'office' : null,
        'office_lat' => br_office_coords()['lat'],
        'office_lng' => br_office_coords()['lng'],
        'office_radius_m' => br_office_radius_m(),
        'office_label' => br_office_label(),
    ];
}
