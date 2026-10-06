<?php
/**
 * Monthly attendance & salary verification helpers.
 *
 * Why: Dual-step (employee → admin) week/month hour attest with estimated
 * salary. Finbro remains paid payroll SoT; BugRicer locks hours + estimates.
 */

require_once __DIR__ . '/weekly_report.php';
require_once __DIR__ . '/leave_attendance.php';
require_once __DIR__ . '/attendance_roles.php';
require_once __DIR__ . '/workforce_access.php';

/**
 * Ensure pay-verify tables exist (migration 123 or inline DDL).
 * Why: Production must create tables even when migrations/ is not synced to the host.
 */
function br_pay_verify_ensure_schema(PDO $conn): void
{
    static $ready = null;
    if ($ready === true) {
        return;
    }

    $needed = [
        'user_hourly_rates',
        'attendance_week_verifications',
        'attendance_month_verifications',
        'attendance_month_adjustments',
    ];

    $tableExists = static function (PDO $conn, string $table): bool {
        try {
            $check = $conn->query('SHOW TABLES LIKE ' . $conn->quote($table));
            return (bool)($check && $check->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {
            return false;
        }
    };

    $missing = false;
    foreach ($needed as $table) {
        if (!$tableExists($conn, $table)) {
            $missing = true;
            break;
        }
    }
    if (!$missing) {
        $ready = true;
        return;
    }

    $ddl = [
        "CREATE TABLE IF NOT EXISTS `user_hourly_rates` (
          `id` VARCHAR(36) NOT NULL,
          `user_id` VARCHAR(36) NOT NULL,
          `hourly_rate` DECIMAL(12,2) NOT NULL,
          `effective_from` DATE NOT NULL,
          `note` VARCHAR(500) NULL DEFAULT NULL,
          `updated_by` VARCHAR(36) NULL DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_user_hourly_rates_user_from` (`user_id`, `effective_from`),
          KEY `idx_user_hourly_rates_user_from` (`user_id`, `effective_from`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `attendance_week_verifications` (
          `id` VARCHAR(36) NOT NULL,
          `user_id` VARCHAR(36) NOT NULL,
          `week_start` DATE NOT NULL,
          `week_end` DATE NOT NULL,
          `year_month` CHAR(7) NOT NULL,
          `worked_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `leave_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `ot_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `check_in_days` INT NOT NULL DEFAULT 0,
          `leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
          `office_days` INT NOT NULL DEFAULT 0,
          `wfh_days` INT NOT NULL DEFAULT 0,
          `late_days` INT NOT NULL DEFAULT 0,
          `days_worked` INT NOT NULL DEFAULT 0,
          `employee_status` ENUM('pending','verified','correction_needed') NOT NULL DEFAULT 'pending',
          `employee_note` TEXT NULL DEFAULT NULL,
          `employee_verified_at` DATETIME NULL DEFAULT NULL,
          `admin_status` ENUM('pending','approved','correction_requested') NOT NULL DEFAULT 'pending',
          `admin_note` TEXT NULL DEFAULT NULL,
          `admin_verified_at` DATETIME NULL DEFAULT NULL,
          `admin_id` VARCHAR(36) NULL DEFAULT NULL,
          `snapshot_locked` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_att_week_user_week` (`user_id`, `week_start`),
          KEY `idx_att_week_year_month` (`year_month`),
          KEY `idx_att_week_employee_status` (`employee_status`),
          KEY `idx_att_week_admin_status` (`admin_status`),
          KEY `idx_att_week_user_month` (`user_id`, `year_month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `attendance_month_verifications` (
          `id` VARCHAR(36) NOT NULL,
          `user_id` VARCHAR(36) NOT NULL,
          `year_month` CHAR(7) NOT NULL,
          `period_start` DATE NOT NULL,
          `period_end` DATE NOT NULL,
          `total_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `worked_days` INT NOT NULL DEFAULT 0,
          `leave_days` DECIMAL(6,2) NOT NULL DEFAULT 0.00,
          `leave_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `ot_hours` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
          `check_in_days` INT NOT NULL DEFAULT 0,
          `tasks_completed` INT NOT NULL DEFAULT 0,
          `hourly_rate_used` DECIMAL(12,2) NULL DEFAULT NULL,
          `gross_estimate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          `adjustments_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          `net_estimate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          `include_ot` TINYINT(1) NOT NULL DEFAULT 0,
          `employee_status` ENUM('pending','verified','correction_needed') NOT NULL DEFAULT 'pending',
          `employee_note` TEXT NULL DEFAULT NULL,
          `employee_verified_at` DATETIME NULL DEFAULT NULL,
          `admin_status` ENUM('pending','approved','correction_requested') NOT NULL DEFAULT 'pending',
          `admin_note` TEXT NULL DEFAULT NULL,
          `admin_verified_at` DATETIME NULL DEFAULT NULL,
          `admin_id` VARCHAR(36) NULL DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_att_month_user_ym` (`user_id`, `year_month`),
          KEY `idx_att_month_ym` (`year_month`),
          KEY `idx_att_month_employee_status` (`employee_status`),
          KEY `idx_att_month_admin_status` (`admin_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `attendance_month_adjustments` (
          `id` VARCHAR(36) NOT NULL,
          `month_verification_id` VARCHAR(36) NOT NULL,
          `type` ENUM('advance','deduction','credit','other') NOT NULL DEFAULT 'deduction',
          `amount` DECIMAL(14,2) NOT NULL,
          `reason` VARCHAR(500) NOT NULL DEFAULT '',
          `created_by` VARCHAR(36) NULL DEFAULT NULL,
          `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_att_adj_month` (`month_verification_id`),
          KEY `idx_att_adj_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($ddl as $stmt) {
        try {
            $conn->exec($stmt);
        } catch (Throwable $e) {
            error_log('br_pay_verify_ensure_schema ddl: ' . $e->getMessage());
        }
    }

    // Why: salary hike notes added after initial create — additive ALTER is safe to re-run.
    try {
        $cols = $conn->query('SHOW COLUMNS FROM user_hourly_rates LIKE \'note\'');
        if ($cols && !$cols->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec(
                'ALTER TABLE `user_hourly_rates`
                 ADD COLUMN `note` VARCHAR(500) NULL DEFAULT NULL AFTER `effective_from`'
            );
        }
    } catch (Throwable $e) {
        error_log('br_pay_verify_ensure_schema note col: ' . $e->getMessage());
    }

    // Fallback: also try migration file if present
    $migration = __DIR__ . '/../migrations/123_attendance_salary_verification.sql';
    if (is_readable($migration)) {
        $sql = file_get_contents($migration);
        if (is_string($sql) && $sql !== '' && preg_match_all('/CREATE TABLE IF NOT EXISTS[^;]+;/is', $sql, $m)) {
            foreach ($m[0] as $stmt) {
                try {
                    $conn->exec($stmt);
                } catch (Throwable $e) {
                    error_log('br_pay_verify_ensure_schema migration: ' . $e->getMessage());
                }
            }
        }
    }

    foreach ($needed as $table) {
        if (!$tableExists($conn, $table)) {
            $ready = false;
            throw new RuntimeException(
                'Pay Verify schema is missing table `' . $table . '`. Ask an admin to run migration 123.'
            );
        }
    }
    $ready = true;
}

function br_pay_verify_uuid(): string
{
    if (class_exists('Utils')) {
        return Utils::generateUUID();
    }
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
}

/** @return array{start:string,end:string}|null */
function br_pay_verify_month_bounds(string $yearMonth): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $yearMonth, $m)) {
        return null;
    }
    $y = (int)$m[1];
    $mo = (int)$m[2];
    if ($mo < 1 || $mo > 12) {
        return null;
    }
    $start = sprintf('%04d-%02d-01', $y, $mo);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $start, new DateTimeZone('Asia/Kolkata'));
    if (!$dt) {
        return null;
    }
    $end = $dt->modify('last day of this month')->format('Y-m-d');
    return ['start' => $start, 'end' => $end];
}

/**
 * Mon–Sat weeks whose date range overlaps [monthStart, monthEnd].
 *
 * @return list<array{week_start:string,week_end:string}>
 */
function br_pay_verify_weeks_overlapping_month(string $monthStart, string $monthEnd): array
{
    $tz = new DateTimeZone('Asia/Kolkata');
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $monthStart, $tz);
    $end = DateTimeImmutable::createFromFormat('Y-m-d', $monthEnd, $tz);
    if (!$start || !$end) {
        return [];
    }

    $dow = (int)$start->format('N');
    $cursor = $start->modify('-' . ($dow - 1) . ' days');
    $weeks = [];
    while ($cursor->format('Y-m-d') <= $monthEnd) {
        $weekStart = $cursor->format('Y-m-d');
        $weekEnd = $cursor->modify('+5 days')->format('Y-m-d');
        if ($weekEnd >= $monthStart && $weekStart <= $monthEnd) {
            $weeks[] = ['week_start' => $weekStart, 'week_end' => $weekEnd];
        }
        $cursor = $cursor->modify('+7 days');
        if (count($weeks) > 10) {
            break;
        }
    }
    return $weeks;
}

/**
 * @return array{summary:array<string,mixed>,days:array<int,array<string,mixed>>}
 */
function br_pay_verify_range_attendance(
    PDO $conn,
    string $userId,
    string $rangeStart,
    string $rangeEnd
): array {
    $full = br_weekly_attendance_summary($conn, $userId, $rangeStart, $rangeEnd);
    $days = [];
    $summary = [
        'days_worked' => 0,
        'total_hours' => 0.0,
        'break_minutes' => 0,
        'leave_days' => 0.0,
        'leave_hours' => 0.0,
        'check_ins' => 0,
        'office_days' => 0,
        'wfh_days' => 0,
        'late_days' => 0,
        'overtime_hours' => 0.0,
    ];

    foreach ($full['days'] as $day) {
        $date = (string)($day['date'] ?? '');
        if ($date < $rangeStart || $date > $rangeEnd) {
            continue;
        }
        $days[] = $day;
        $status = (string)($day['day_status'] ?? 'off');
        $hours = (float)($day['hours'] ?? 0);
        $ot = (float)($day['overtime_hours'] ?? 0);
        if ($status === 'worked') {
            $summary['days_worked'] += 1;
            $summary['total_hours'] += $hours;
            $summary['break_minutes'] += (int)($day['break_minutes'] ?? 0);
            $summary['overtime_hours'] += $ot;
            if (!empty($day['check_in'])) {
                $summary['check_ins'] += 1;
            }
            if (($day['work_mode'] ?? null) === 'wfh') {
                $summary['wfh_days'] += 1;
            } elseif (($day['work_mode'] ?? null) === 'office') {
                $summary['office_days'] += 1;
            }
            if (!empty($day['is_late'])) {
                $summary['late_days'] += 1;
            }
        } elseif ($status === 'leave') {
            $summary['leave_days'] += 1;
            $summary['leave_hours'] += $hours;
            if ($hours > 0) {
                $summary['total_hours'] += $hours;
                $summary['days_worked'] += 1;
            }
        }
    }

    $summary['total_hours'] = round($summary['total_hours'], 2);
    $summary['leave_hours'] = round($summary['leave_hours'], 2);
    $summary['overtime_hours'] = round($summary['overtime_hours'], 2);

    return ['summary' => $summary, 'days' => $days];
}

function br_pay_verify_rate_for_user(PDO $conn, string $userId, string $asOfDate): ?float
{
    $snap = br_pay_verify_rate_snapshot($conn, $userId, $asOfDate);
    return $snap['current_rate'];
}

/**
 * Why: salary hike UI needs current + previous rate and effective dates, not only a float.
 *
 * @return array{
 *   current_rate:?float,
 *   effective_from:?string,
 *   previous_rate:?float,
 *   previous_from:?string,
 *   note:?string,
 *   rate_id:?string
 * }
 */
function br_pay_verify_rate_snapshot(PDO $conn, string $userId, string $asOfDate): array
{
    br_pay_verify_ensure_schema($conn);
    $empty = [
        'current_rate' => null,
        'effective_from' => null,
        'previous_rate' => null,
        'previous_from' => null,
        'note' => null,
        'rate_id' => null,
    ];

    try {
        $stmt = $conn->prepare(
            'SELECT id, hourly_rate, effective_from, note FROM user_hourly_rates
             WHERE user_id = ? AND effective_from <= ?
             ORDER BY effective_from DESC LIMIT 2'
        );
        $stmt->execute([$userId, $asOfDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows !== []) {
            $cur = $rows[0];
            $prev = $rows[1] ?? null;
            return [
                'current_rate' => round((float)$cur['hourly_rate'], 2),
                'effective_from' => (string)$cur['effective_from'],
                'previous_rate' => $prev !== null ? round((float)$prev['hourly_rate'], 2) : null,
                'previous_from' => $prev !== null ? (string)$prev['effective_from'] : null,
                'note' => isset($cur['note']) && $cur['note'] !== '' ? (string)$cur['note'] : null,
                'rate_id' => (string)$cur['id'],
            ];
        }
    } catch (Throwable $e) {
        error_log('br_pay_verify_rate_snapshot: ' . $e->getMessage());
    }

    try {
        $check = $conn->query("SHOW TABLES LIKE 'finbro_payroll_acknowledgements'");
        if ($check && $check->fetch(PDO::FETCH_NUM)) {
            $stmt = $conn->prepare(
                'SELECT hourly_rate FROM finbro_payroll_acknowledgements
                 WHERE bugricer_user_id = ? AND hourly_rate IS NOT NULL AND hourly_rate > 0
                 ORDER BY pay_date DESC, created_at DESC LIMIT 1'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $empty['current_rate'] = round((float)$row['hourly_rate'], 2);
            }
        }
    } catch (Throwable $e) {
        error_log('br_pay_verify_rate_finbro: ' . $e->getMessage());
    }

    return $empty;
}

/**
 * @return list<array<string,mixed>>
 */
function br_pay_verify_rate_history(PDO $conn, string $userId): array
{
    br_pay_verify_ensure_schema($conn);
    try {
        $stmt = $conn->prepare(
            'SELECT r.id, r.user_id, r.hourly_rate, r.effective_from, r.note,
                    r.updated_by, r.created_at, r.updated_at,
                    u.username AS updated_by_username
             FROM user_hourly_rates r
             LEFT JOIN users u ON CONVERT(u.id USING utf8mb4) COLLATE utf8mb4_unicode_ci
               = CONVERT(r.updated_by USING utf8mb4) COLLATE utf8mb4_unicode_ci
             WHERE r.user_id = ?
             ORDER BY r.effective_from DESC, r.created_at DESC'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $prevRate = null;
        // Build hike % from older → newer by reversing once
        $asc = array_reverse($rows);
        $withDelta = [];
        foreach ($asc as $row) {
            $rate = round((float)$row['hourly_rate'], 2);
            $delta = null;
            $pct = null;
            if ($prevRate !== null && $prevRate > 0) {
                $delta = round($rate - $prevRate, 2);
                $pct = round(($delta / $prevRate) * 100, 1);
            }
            $withDelta[] = [
                'id' => (string)$row['id'],
                'user_id' => (string)$row['user_id'],
                'hourly_rate' => $rate,
                'effective_from' => (string)$row['effective_from'],
                'note' => isset($row['note']) && $row['note'] !== null && $row['note'] !== ''
                    ? (string)$row['note']
                    : null,
                'updated_by' => $row['updated_by'] ?? null,
                'updated_by_username' => $row['updated_by_username'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
                'hike_amount' => $delta,
                'hike_pct' => $pct,
            ];
            $prevRate = $rate;
        }
        return array_reverse($withDelta);
    } catch (Throwable $e) {
        error_log('br_pay_verify_rate_history: ' . $e->getMessage());
        return [];
    }
}

/** @return string YYYY-MM (Asia/Kolkata) */
function br_pay_verify_today_ym(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m');
}

/** @return string|null YYYY-MM */
function br_pay_verify_ym_from_date(?string $date): ?string
{
    $date = trim((string)$date);
    if ($date === '') {
        return null;
    }
    if (preg_match('/^(\d{4}-\d{2})/', $date, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Why: Period picker must stay between joining (or earliest roster join) and the current month.
 *
 * @param list<array<string,mixed>> $rosterUsers
 * @return array{min:string,max:string,joining_date:?string,source:string}
 */
function br_pay_verify_month_nav_bounds(
    PDO $conn,
    string $focusUserId,
    array $rosterUsers,
    bool $scopeMine
): array {
    $max = br_pay_verify_today_ym();
    $focusJoin = br_user_joining_date($conn, $focusUserId);
    $focusYm = br_pay_verify_ym_from_date($focusJoin);

    if ($scopeMine) {
        $min = $focusYm ?? $max;
        if ($min > $max) {
            $min = $max;
        }
        return [
            'min' => $min,
            'max' => $max,
            'joining_date' => $focusJoin,
            'source' => 'joining_date',
        ];
    }

    $earliest = $focusYm;
    // Why: one query for team earliest join — avoids N+1 when admin browses full roster.
    if (!$scopeMine && br_users_has_joining_date($conn)) {
        try {
            $ids = [];
            foreach ($rosterUsers as $u) {
                $uid = trim((string)($u['id'] ?? ''));
                if ($uid !== '') {
                    $ids[] = $uid;
                }
            }
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $conn->prepare(
                    "SELECT MIN(
                        CASE
                          WHEN joining_date IS NOT NULL AND joining_date <> ''
                          THEN DATE_FORMAT(joining_date, '%Y-%m')
                          ELSE DATE_FORMAT(created_at, '%Y-%m')
                        END
                     ) AS min_ym
                     FROM users
                     WHERE id IN ({$placeholders})"
                );
                $stmt->execute($ids);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $batchMin = br_pay_verify_ym_from_date($row['min_ym'] ?? null);
                if ($batchMin !== null) {
                    $earliest = $batchMin;
                }
            }
        } catch (Throwable $e) {
            error_log('br_pay_verify_month_nav_bounds batch: ' . $e->getMessage());
            foreach ($rosterUsers as $u) {
                $uid = trim((string)($u['id'] ?? ''));
                if ($uid === '') {
                    continue;
                }
                $jd = br_user_joining_date($conn, $uid);
                $ym = br_pay_verify_ym_from_date($jd);
                if ($ym === null) {
                    continue;
                }
                if ($earliest === null || $ym < $earliest) {
                    $earliest = $ym;
                }
            }
        }
    } else {
        foreach ($rosterUsers as $u) {
            $uid = trim((string)($u['id'] ?? ''));
            if ($uid === '') {
                continue;
            }
            $jd = br_user_joining_date($conn, $uid);
            $ym = br_pay_verify_ym_from_date($jd);
            if ($ym === null) {
                continue;
            }
            if ($earliest === null || $ym < $earliest) {
                $earliest = $ym;
            }
        }
    }

    $min = $earliest ?? $max;
    if ($min > $max) {
        $min = $max;
    }

    return [
        'min' => $min,
        'max' => $max,
        'joining_date' => $focusJoin,
        'source' => $earliest !== null ? 'roster_earliest_join' : 'today',
    ];
}

/**
 * @param array{min:string,max:string} $bounds
 */
function br_pay_verify_clamp_ym(string $yearMonth, array $bounds): string
{
    $min = (string)($bounds['min'] ?? '');
    $max = (string)($bounds['max'] ?? '');
    if ($min !== '' && $yearMonth < $min) {
        return $min;
    }
    if ($max !== '' && $yearMonth > $max) {
        return $max;
    }
    return $yearMonth;
}

/**
 * @return list<array<string,mixed>>
 */
function br_pay_verify_roster_users(PDO $conn, string $roleFilter = 'all'): array
{
    br_ensure_tester_type_schema($conn);
    $hasName = false;
    $hasActive = false;
    $hasTester = false;
    try {
        $cols = $conn->query('SHOW COLUMNS FROM users');
        if ($cols) {
            foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $f = (string)($c['Field'] ?? '');
                if ($f === 'name') {
                    $hasName = true;
                }
                if ($f === 'account_active') {
                    $hasActive = true;
                }
                if ($f === 'tester_type') {
                    $hasTester = true;
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $nameExpr = $hasName
        ? "COALESCE(NULLIF(u.name, ''), u.username) AS name"
        : 'u.username AS name';
    $activeExpr = $hasActive ? 'COALESCE(u.account_active, 1) AS account_active' : '1 AS account_active';
    $testerExpr = $hasTester ? 'u.tester_type' : 'NULL AS tester_type';
    $rosterSql = br_workforce_roster_sql('u', $conn);

    $sql = "SELECT u.id, u.username, {$nameExpr}, u.role, {$testerExpr}, {$activeExpr}
            FROM users u
            WHERE {$rosterSql}
              AND LOWER(TRIM(COALESCE(u.role,''))) <> 'admin'
            ORDER BY u.username ASC";
    $stmt = $conn->query($sql);
    $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $out = [];
    foreach ($users as $u) {
        if ($hasActive && (int)($u['account_active'] ?? 1) === 0) {
            continue;
        }
        $role = strtolower(trim((string)($u['role'] ?? '')));
        $testerType = strtolower(trim((string)($u['tester_type'] ?? '')));
        $bucket = 'developer';
        if ($role === 'creator') {
            $bucket = 'creator';
        } elseif ($role === 'tester' && $testerType === 'codo') {
            $bucket = 'codo_tester';
        } elseif ($role === 'developer' || $role === 'user') {
            $bucket = 'developer';
        } else {
            continue;
        }

        if ($roleFilter !== 'all' && $roleFilter !== $bucket) {
            continue;
        }

        $u['role_bucket'] = $bucket;
        $out[] = $u;
    }
    return $out;
}

/**
 * @param array{week_start:string,week_end:string} $week
 * @return array<string,mixed>
 */
function br_pay_verify_ensure_week(
    PDO $conn,
    string $userId,
    array $week,
    string $yearMonth,
    string $monthStart,
    string $monthEnd
): array {
    br_pay_verify_ensure_schema($conn);
    $weekStart = $week['week_start'];
    $weekEnd = $week['week_end'];
    $clipStart = max($weekStart, $monthStart);
    $clipEnd = min($weekEnd, $monthEnd);

    $stmt = $conn->prepare(
        'SELECT * FROM attendance_week_verifications WHERE user_id = ? AND week_start = ? LIMIT 1'
    );
    $stmt->execute([$userId, $weekStart]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $locked = $existing && (
        (int)($existing['snapshot_locked'] ?? 0) === 1
        || ($existing['admin_status'] ?? '') === 'approved'
    );

    $attendance = br_pay_verify_range_attendance($conn, $userId, $clipStart, $clipEnd);
    $s = $attendance['summary'];

    if ($existing && $locked) {
        $existing['attendance_days'] = $attendance['days'];
        $existing['clip_start'] = $clipStart;
        $existing['clip_end'] = $clipEnd;
        return $existing;
    }

    $id = $existing['id'] ?? br_pay_verify_uuid();
    if ($existing) {
        $upd = $conn->prepare(
            'UPDATE attendance_week_verifications SET
                `year_month` = ?, week_end = ?,
                worked_hours = ?, leave_hours = ?, ot_hours = ?,
                check_in_days = ?, leave_days = ?, office_days = ?, wfh_days = ?,
                late_days = ?, days_worked = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $upd->execute([
            $yearMonth,
            $weekEnd,
            $s['total_hours'],
            $s['leave_hours'],
            $s['overtime_hours'],
            $s['check_ins'],
            $s['leave_days'],
            $s['office_days'],
            $s['wfh_days'],
            $s['late_days'],
            $s['days_worked'],
            $id,
        ]);
    } else {
        $ins = $conn->prepare(
            'INSERT INTO attendance_week_verifications
             (id, user_id, week_start, week_end, `year_month`,
              worked_hours, leave_hours, ot_hours, check_in_days, leave_days,
              office_days, wfh_days, late_days, days_worked)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $id,
            $userId,
            $weekStart,
            $weekEnd,
            $yearMonth,
            $s['total_hours'],
            $s['leave_hours'],
            $s['overtime_hours'],
            $s['check_ins'],
            $s['leave_days'],
            $s['office_days'],
            $s['wfh_days'],
            $s['late_days'],
            $s['days_worked'],
        ]);
    }

    $stmt->execute([$userId, $weekStart]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $row['attendance_days'] = $attendance['days'];
    $row['clip_start'] = $clipStart;
    $row['clip_end'] = $clipEnd;
    return $row;
}

/**
 * @return list<array<string,mixed>>
 */
function br_pay_verify_month_adjustments(PDO $conn, string $monthVerificationId): array
{
    $stmt = $conn->prepare(
        'SELECT * FROM attendance_month_adjustments
         WHERE month_verification_id = ?
         ORDER BY created_at ASC'
    );
    $stmt->execute([$monthVerificationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function br_pay_verify_adjustments_signed_total(array $adjustments): float
{
    $sum = 0.0;
    foreach ($adjustments as $a) {
        $sum += (float)($a['amount'] ?? 0);
    }
    return round($sum, 2);
}

/**
 * @return array<string,mixed>
 */
function br_pay_verify_ensure_month(
    PDO $conn,
    string $userId,
    string $yearMonth,
    string $monthStart,
    string $monthEnd,
    bool $includeOtDefault = false
): array {
    br_pay_verify_ensure_schema($conn);

    $stmt = $conn->prepare(
        'SELECT * FROM attendance_month_verifications WHERE user_id = ? AND `year_month` = ? LIMIT 1'
    );
    $stmt->execute([$userId, $yearMonth]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $locked = $existing && ($existing['admin_status'] ?? '') === 'approved';
    $includeOt = $existing
        ? ((int)($existing['include_ot'] ?? 0) === 1)
        : $includeOtDefault;

    $attendance = br_pay_verify_range_attendance($conn, $userId, $monthStart, $monthEnd);
    $s = $attendance['summary'];
    $rate = br_pay_verify_rate_for_user($conn, $userId, $monthEnd);
    $billable = (float)$s['total_hours'];
    if ($includeOt) {
        $billable += (float)$s['overtime_hours'];
    }
    $billable = round($billable, 2);
    $gross = $rate !== null ? round($billable * $rate, 2) : 0.0;

    $id = $existing['id'] ?? br_pay_verify_uuid();
    $adjustments = [];
    $adjTotal = 0.0;

    if ($existing) {
        $adjustments = br_pay_verify_month_adjustments($conn, (string)$existing['id']);
        $adjTotal = br_pay_verify_adjustments_signed_total($adjustments);
    }

    $net = round($gross + $adjTotal, 2);

    if ($locked) {
        $existing['adjustments'] = $adjustments;
        $existing['attendance_days'] = $attendance['days'];
        $existing['hourly_rate'] = $existing['hourly_rate_used'] !== null
            ? (float)$existing['hourly_rate_used']
            : $rate;
        return $existing;
    }

    if ($existing) {
        $upd = $conn->prepare(
            'UPDATE attendance_month_verifications SET
                period_start = ?, period_end = ?,
                total_hours = ?, worked_days = ?, leave_days = ?, leave_hours = ?,
                ot_hours = ?, check_in_days = ?,
                hourly_rate_used = ?, gross_estimate = ?, adjustments_total = ?, net_estimate = ?,
                include_ot = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $upd->execute([
            $monthStart,
            $monthEnd,
            $s['total_hours'],
            $s['days_worked'],
            $s['leave_days'],
            $s['leave_hours'],
            $s['overtime_hours'],
            $s['check_ins'],
            $rate,
            $gross,
            $adjTotal,
            $net,
            $includeOt ? 1 : 0,
            $id,
        ]);
    } else {
        $ins = $conn->prepare(
            'INSERT INTO attendance_month_verifications
             (id, user_id, `year_month`, period_start, period_end,
              total_hours, worked_days, leave_days, leave_hours, ot_hours, check_in_days,
              hourly_rate_used, gross_estimate, adjustments_total, net_estimate, include_ot)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $ins->execute([
            $id,
            $userId,
            $yearMonth,
            $monthStart,
            $monthEnd,
            $s['total_hours'],
            $s['days_worked'],
            $s['leave_days'],
            $s['leave_hours'],
            $s['overtime_hours'],
            $s['check_ins'],
            $rate,
            $gross,
            0,
            $gross,
            $includeOt ? 1 : 0,
        ]);
    }

    $stmt->execute([$userId, $yearMonth]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $adjustments = br_pay_verify_month_adjustments($conn, (string)$row['id']);
    $adjTotal = br_pay_verify_adjustments_signed_total($adjustments);
    if (abs($adjTotal - (float)($row['adjustments_total'] ?? 0)) > 0.001) {
        $net = round((float)($row['gross_estimate'] ?? 0) + $adjTotal, 2);
        $conn->prepare(
            'UPDATE attendance_month_verifications SET adjustments_total = ?, net_estimate = ? WHERE id = ?'
        )->execute([$adjTotal, $net, $row['id']]);
        $row['adjustments_total'] = $adjTotal;
        $row['net_estimate'] = $net;
    }
    $row['adjustments'] = $adjustments;
    $row['attendance_days'] = $attendance['days'];
    $row['hourly_rate'] = $rate;
    return $row;
}

/**
 * @return array{mine:int,admin:int}
 */
function br_pay_verify_pending_counts(PDO $conn, string $viewerId, bool $isAdmin): array
{
    br_pay_verify_ensure_schema($conn);
    $tz = new DateTimeZone('Asia/Kolkata');
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');

    $mine = 0;
    try {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM attendance_week_verifications
             WHERE user_id = ? AND week_end < ? AND employee_status = 'pending'"
        );
        $stmt->execute([$viewerId, $today]);
        $mine += (int)$stmt->fetchColumn();

        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM attendance_week_verifications
             WHERE user_id = ? AND (employee_status = 'correction_needed' OR admin_status = 'correction_requested')"
        );
        $stmt->execute([$viewerId]);
        $mine += (int)$stmt->fetchColumn();

        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM attendance_month_verifications
             WHERE user_id = ? AND period_end < ? AND employee_status = 'pending'"
        );
        $stmt->execute([$viewerId, $today]);
        $mine += (int)$stmt->fetchColumn();

        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM attendance_month_verifications
             WHERE user_id = ? AND (employee_status = 'correction_needed' OR admin_status = 'correction_requested')"
        );
        $stmt->execute([$viewerId]);
        $mine += (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('br_pay_verify_pending_counts mine: ' . $e->getMessage());
    }

    $admin = 0;
    if ($isAdmin) {
        try {
            $stmt = $conn->query(
                "SELECT COUNT(*) FROM attendance_week_verifications
                 WHERE employee_status = 'verified' AND admin_status = 'pending'"
            );
            $admin += (int)$stmt->fetchColumn();

            $stmt = $conn->query(
                "SELECT COUNT(*) FROM attendance_week_verifications
                 WHERE employee_status = 'correction_needed'"
            );
            $admin += (int)$stmt->fetchColumn();

            $stmt = $conn->query(
                "SELECT COUNT(*) FROM attendance_month_verifications
                 WHERE employee_status = 'verified' AND admin_status = 'pending'"
            );
            $admin += (int)$stmt->fetchColumn();

            $stmt = $conn->query(
                "SELECT COUNT(*) FROM attendance_month_verifications
                 WHERE employee_status = 'correction_needed'"
            );
            $admin += (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('br_pay_verify_pending_counts admin: ' . $e->getMessage());
        }
    }

    return ['mine' => $mine, 'admin' => $admin];
}

/**
 * @param array<string,float> $usernameToRate
 */
function br_pay_verify_seed_rates(PDO $conn, array $usernameToRate, ?string $adminId, string $effectiveFrom = '2026-01-01'): int
{
    br_pay_verify_ensure_schema($conn);
    try {
        $check = $conn->query("SHOW TABLES LIKE 'users'");
        if (!$check || !$check->fetch(PDO::FETCH_NUM)) {
            return 0;
        }
    } catch (Throwable $e) {
        return 0;
    }
    $inserted = 0;
    $seen = [];
    foreach ($usernameToRate as $username => $rate) {
        $username = trim((string)$username);
        $key = strtolower($username);
        if ($username === '' || $rate <= 0 || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        try {
            $stmt = $conn->prepare(
                'SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1'
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                continue;
            }
            $uid = (string)$user['id'];
            $exists = $conn->prepare(
                'SELECT id FROM user_hourly_rates WHERE user_id = ? LIMIT 1'
            );
            $exists->execute([$uid]);
            if ($exists->fetch(PDO::FETCH_ASSOC)) {
                continue;
            }
            $ins = $conn->prepare(
                'INSERT INTO user_hourly_rates (id, user_id, hourly_rate, effective_from, updated_by)
                 VALUES (?,?,?,?,?)'
            );
            $ins->execute([
                br_pay_verify_uuid(),
                $uid,
                round((float)$rate, 2),
                $effectiveFrom,
                $adminId,
            ]);
            $inserted++;
        } catch (Throwable $e) {
            error_log('br_pay_verify_seed_rates: ' . $e->getMessage());
        }
    }
    return $inserted;
}

function br_pay_verify_default_rate_seed_map(): array
{
    return [
        'Fathima_marva' => 50.0,
        'irshad' => 90.0,
        'jidhin' => 75.0,
        'nadha_rahman' => 50.0,
        'Nihala' => 50.0,
        'shibin' => 75.0,
        'Jubairiya' => 90.0,
        'rumana' => 50.0,
        'hidha' => 30.0,
        'fathima_nidha' => 25.0,
    ];
}
