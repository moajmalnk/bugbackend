<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/checkin_policy.php';
require_once __DIR__ . '/../../utils/work_period.php';

class AttendanceExceptionController extends BaseAPI
{
    private function requireAdminAuth()
    {
        $decoded = $this->validateToken();
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Authentication failed');
            return null;
        }
        $pm = PermissionManager::getInstance();
        if (!$pm->hasPermissionOrAdmin(
            $decoded->user_id,
            'ATTENDANCE_MANAGE',
            $decoded->role ?? null
        )) {
            $this->sendJsonResponse(403, 'Only users with ATTENDANCE_MANAGE can manage attendance exceptions');
            return null;
        }
        return $decoded;
    }

    /**
     * Why: Accept `date` or `dates[]` so single and multi admin flows share one endpoint.
     *
     * @param array $input
     * @return list<string>
     */
    private function resolveDates(array $input): array
    {
        $dates = [];
        if (isset($input['dates']) && is_array($input['dates'])) {
            $dates = br_normalize_ymd_dates($input['dates']);
        }
        $single = trim((string)($input['date'] ?? $input['exception_date'] ?? ''));
        if ($single !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $single)) {
            $dates[] = $single;
        }
        return br_normalize_ymd_dates($dates);
    }

    private function usernameFor($userId): string
    {
        try {
            $stmt = $this->conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(string)$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $name = trim((string)($row['username'] ?? ''));
            return $name !== '' ? $name : ('user #' . $userId);
        } catch (Throwable $e) {
            return 'user #' . $userId;
        }
    }

    /**
     * Why: Employee should learn immediately when a late strike is cleared (push + email + WhatsApp).
     *
     * @param list<string> $dates
     */
    private function notifyUserLateForgiven(
        string $userId,
        array $dates,
        ?string $adminNote,
        string $adminId
    ): void {
        if ($dates === []) {
            return;
        }

        $adminName = $this->usernameFor($adminId);

        try {
            require_once __DIR__ . '/../NotificationManager.php';
            NotificationManager::getInstance()->notifyLateForgiven(
                $userId,
                $dates,
                $adminNote,
                $adminName
            );
        } catch (Throwable $e) {
            error_log('AttendanceExceptionController late forgive push: ' . $e->getMessage());
        }

        try {
            $stmt = $this->conn->prepare(
                'SELECT email, phone, username FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $email = trim((string)($user['email'] ?? ''));
            $phone = trim((string)($user['phone'] ?? ''));
            $username = trim((string)($user['username'] ?? '')) ?: $this->usernameFor($userId);

            if ($email !== '') {
                require_once __DIR__ . '/../../utils/email.php';
                sendLateForgivenEmail($email, $username, $dates, $adminNote, $adminName);
            }
            if ($phone !== '') {
                require_once __DIR__ . '/../../utils/whatsapp.php';
                sendLateForgivenWhatsApp($phone, $username, $dates, $adminNote, $adminName);
            }
        } catch (Throwable $e) {
            error_log('AttendanceExceptionController late forgive mail/wa: ' . $e->getMessage());
        }
    }

    /**
     * GET — list exceptions + recent late days for a user.
     * Omit user_id (or pass scope=all) for the admin overview across everyone.
     */
    public function listForUser()
    {
        $decoded = $this->requireAdminAuth();
        if (!$decoded) {
            return;
        }

        $userId = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';
        $scope = isset($_GET['scope']) ? strtolower(trim((string)$_GET['scope'])) : '';

        if ($userId === '' || $scope === 'all') {
            $this->listAll();
            return;
        }

        br_ensure_checkin_policy_schema($this->conn);
        $today = br_server_today();
        $fromDate = (new DateTimeImmutable($today . ' 00:00:00'))
            ->modify('-120 days')
            ->format('Y-m-d');

        $exceptions = [];
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, exception_date, allow_wfh, forgive_late, admin_note, created_by, created_at, updated_at
                 FROM attendance_day_exceptions
                 WHERE user_id = ? AND exception_date >= ?
                 ORDER BY exception_date DESC
                 LIMIT 120'
            );
            $stmt->execute([$userId, $fromDate]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $exceptions[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (string)$row['user_id'],
                    'exception_date' => $row['exception_date'],
                    'allow_wfh' => (int)$row['allow_wfh'] === 1,
                    'forgive_late' => (int)$row['forgive_late'] === 1,
                    'admin_note' => $row['admin_note'],
                    'created_by' => $row['created_by'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
            }
        } catch (Throwable $e) {
            error_log('AttendanceExceptionController::listForUser exceptions: ' . $e->getMessage());
        }

        $lateDays = [];
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, submission_date, check_in_time, is_late, late_strike_consumed, work_mode
                 FROM work_submissions
                 WHERE user_id = ? AND is_late = 1
                   AND submission_date >= ?
                 ORDER BY submission_date DESC
                 LIMIT 60'
            );
            $stmt->execute([$userId, $fromDate]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $lateDays[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (string)$userId,
                    'submission_date' => $row['submission_date'],
                    'check_in_time' => $row['check_in_time'],
                    'is_late' => true,
                    'late_strike_consumed' => (int)($row['late_strike_consumed'] ?? 0) === 1,
                    'work_mode' => $row['work_mode'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            error_log('AttendanceExceptionController::listForUser lates: ' . $e->getMessage());
        }

        $policy = br_checkin_policy_status($this->conn, $userId, $today);
        $restriction = br_active_office_restriction($this->conn, $userId, $today);

        $officeActiveDays = 0;
        $wfhActiveDays = 0;
        $attendanceDays = [];
        try {
            $attStmt = $this->conn->prepare(
                "SELECT submission_date, check_in_time, work_mode, is_late
                 FROM work_submissions
                 WHERE user_id = ?
                   AND check_in_time IS NOT NULL
                   AND submission_date >= ?
                 ORDER BY submission_date DESC
                 LIMIT 250"
            );
            $attStmt->execute([$userId, $fromDate]);
            $seenDates = [];
            while ($row = $attStmt->fetch(PDO::FETCH_ASSOC)) {
                $date = (string)($row['submission_date'] ?? '');
                if ($date === '' || isset($seenDates[$date])) {
                    continue;
                }
                $seenDates[$date] = true;
                $rawMode = strtolower(trim((string)($row['work_mode'] ?? '')));
                $mode = $rawMode === 'wfh' ? 'wfh' : 'office';
                if ($mode === 'office') {
                    $officeActiveDays++;
                } else {
                    $wfhActiveDays++;
                }
                $attendanceDays[] = [
                    'date' => $date,
                    'work_mode' => $mode,
                    'is_late' => (int)($row['is_late'] ?? 0) === 1,
                    'check_in_time' => $row['check_in_time'] ?? null,
                    'source' => 'checkin',
                ];
            }

            // Why: WFH exceptions without a check-in still count as planned WFH days in the tables.
            foreach ($exceptions as $exc) {
                if (empty($exc['allow_wfh'])) {
                    continue;
                }
                $date = (string)($exc['exception_date'] ?? '');
                if ($date === '' || isset($seenDates[$date])) {
                    continue;
                }
                $seenDates[$date] = true;
                $wfhActiveDays++;
                $attendanceDays[] = [
                    'date' => $date,
                    'work_mode' => 'wfh',
                    'is_late' => false,
                    'check_in_time' => null,
                    'source' => 'exception',
                ];
            }

            usort($attendanceDays, static function ($a, $b) {
                return strcmp((string)$b['date'], (string)$a['date']);
            });
        } catch (Throwable $e) {
            error_log('AttendanceExceptionController::listForUser attendance days: ' . $e->getMessage());
        }

        $this->sendJsonResponse(200, 'OK', [
            'user_id' => $userId,
            'today' => $today,
            'exceptions' => $exceptions,
            'late_days' => $lateDays,
            'late_count' => $policy['late_count'] ?? 0,
            'late_limit' => $policy['late_limit'] ?? 3,
            'office_only' => !empty($policy['office_only']),
            'office_only_week_start' => $policy['office_only_week_start'] ?? null,
            'office_only_week_end' => $policy['office_only_week_end'] ?? null,
            'upcoming_office_only_week' => $policy['upcoming_office_only_week'] ?? null,
            'active_restriction' => $restriction,
            'allow_wfh_today' => !empty($policy['allow_wfh_today']),
            'forgive_late_today' => !empty($policy['forgive_late_today']),
            'office_active_days' => $officeActiveDays,
            'wfh_active_days' => $wfhActiveDays,
            'attendance_days' => $attendanceDays,
            'attendance_from' => $fromDate,
        ]);
    }

    /**
     * Why: Admin sidebar page needs every user's exceptions / late days in one view.
     */
    public function listAll()
    {
        br_ensure_checkin_policy_schema($this->conn);
        $today = br_server_today();
        // Why: bind a plain Y-m-d floor date — avoids DATE_SUB(?)/collation quirks on some hosts.
        $fromDate = (new DateTimeImmutable($today . ' 00:00:00'))
            ->modify('-120 days')
            ->format('Y-m-d');

        $exceptions = [];
        try {
            $stmt = $this->conn->prepare(
                'SELECT e.id, e.user_id, e.exception_date, e.allow_wfh, e.forgive_late, e.admin_note,
                        e.created_by, e.created_at, e.updated_at,
                        u.username, u.role
                 FROM attendance_day_exceptions e
                 LEFT JOIN users u
                   ON u.id COLLATE utf8mb4_unicode_ci = e.user_id COLLATE utf8mb4_unicode_ci
                 WHERE e.exception_date >= ?
                 ORDER BY e.exception_date DESC, u.username ASC
                 LIMIT 500'
            );
            $stmt->execute([$fromDate]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $exceptions[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (string)$row['user_id'],
                    'username' => $row['username'] ?? 'Unknown',
                    'role' => $row['role'] ?? null,
                    'exception_date' => $row['exception_date'],
                    'allow_wfh' => (int)$row['allow_wfh'] === 1,
                    'forgive_late' => (int)$row['forgive_late'] === 1,
                    'admin_note' => $row['admin_note'],
                    'created_by' => $row['created_by'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ];
            }
        } catch (Throwable $e) {
            error_log('AttendanceExceptionController::listAll exceptions: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Could not load attendance exceptions: ' . $e->getMessage());
            return;
        }

        $lateDays = [];
        try {
            $stmt = $this->conn->prepare(
                'SELECT ws.id, ws.user_id, ws.submission_date, ws.check_in_time, ws.is_late,
                        ws.late_strike_consumed, ws.work_mode, u.username, u.role
                 FROM work_submissions ws
                 LEFT JOIN users u
                   ON u.id COLLATE utf8mb4_unicode_ci = ws.user_id COLLATE utf8mb4_unicode_ci
                 WHERE ws.is_late = 1
                   AND ws.submission_date >= ?
                 ORDER BY ws.submission_date DESC, u.username ASC
                 LIMIT 300'
            );
            $stmt->execute([$fromDate]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $lateDays[] = [
                    'id' => (int)$row['id'],
                    'user_id' => (string)$row['user_id'],
                    'username' => $row['username'] ?? 'Unknown',
                    'role' => $row['role'] ?? null,
                    'submission_date' => $row['submission_date'],
                    'check_in_time' => $row['check_in_time'],
                    'is_late' => true,
                    'late_strike_consumed' => (int)($row['late_strike_consumed'] ?? 0) === 1,
                    'work_mode' => $row['work_mode'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            error_log('AttendanceExceptionController::listAll lates: ' . $e->getMessage());
        }

        // Why: Admins need each teammate's office check-in day count on the roster / header.
        $officeActiveByUser = [];
        $officeActiveTotal = 0;
        try {
            $officeStmt = $this->conn->prepare(
                "SELECT ws.user_id, COUNT(DISTINCT ws.submission_date) AS office_active_days
                 FROM work_submissions ws
                 INNER JOIN users u
                   ON u.id COLLATE utf8mb4_unicode_ci = ws.user_id COLLATE utf8mb4_unicode_ci
                 WHERE ws.check_in_time IS NOT NULL
                   AND ws.submission_date >= ?
                   AND (ws.work_mode = 'office' OR ws.work_mode IS NULL OR ws.work_mode = '')
                   AND LOWER(TRIM(COALESCE(u.role, ''))) IN ('admin', 'developer', 'user')
                   AND (u.account_active IS NULL OR u.account_active = 1)
                 GROUP BY ws.user_id"
            );
            $officeStmt->execute([$fromDate]);
            while ($row = $officeStmt->fetch(PDO::FETCH_ASSOC)) {
                $uid = (string)($row['user_id'] ?? '');
                if ($uid === '') {
                    continue;
                }
                $days = (int)($row['office_active_days'] ?? 0);
                $officeActiveByUser[$uid] = $days;
                $officeActiveTotal += $days;
            }
        } catch (Throwable $e) {
            error_log('AttendanceExceptionController::listAll office days: ' . $e->getMessage());
        }

        // Why: user-first admin UI needs one row per teammate with counts.
        $byUser = [];
        foreach ($exceptions as $row) {
            $uid = (string)($row['user_id'] ?? '');
            if ($uid === '') {
                continue;
            }
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user_id' => $uid,
                    'username' => $row['username'] ?? 'Unknown',
                    'role' => $row['role'] ?? null,
                    'exception_count' => 0,
                    'late_count' => 0,
                    'office_active_days' => $officeActiveByUser[$uid] ?? 0,
                    'latest_exception_date' => null,
                    'latest_late_date' => null,
                ];
            }
            $byUser[$uid]['exception_count']++;
            $d = $row['exception_date'] ?? null;
            if ($d && ($byUser[$uid]['latest_exception_date'] === null || $d > $byUser[$uid]['latest_exception_date'])) {
                $byUser[$uid]['latest_exception_date'] = $d;
            }
        }
        foreach ($lateDays as $row) {
            $uid = (string)($row['user_id'] ?? '');
            if ($uid === '') {
                continue;
            }
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user_id' => $uid,
                    'username' => $row['username'] ?? 'Unknown',
                    'role' => $row['role'] ?? null,
                    'exception_count' => 0,
                    'late_count' => 0,
                    'office_active_days' => $officeActiveByUser[$uid] ?? 0,
                    'latest_exception_date' => null,
                    'latest_late_date' => null,
                ];
            }
            $byUser[$uid]['late_count']++;
            $d = $row['submission_date'] ?? null;
            if ($d && ($byUser[$uid]['latest_late_date'] === null || $d > $byUser[$uid]['latest_late_date'])) {
                $byUser[$uid]['latest_late_date'] = $d;
            }
        }
        // Ensure every user with office days appears even with zero exceptions/lates.
        foreach ($officeActiveByUser as $uid => $days) {
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user_id' => $uid,
                    'username' => 'Unknown',
                    'role' => null,
                    'exception_count' => 0,
                    'late_count' => 0,
                    'office_active_days' => $days,
                    'latest_exception_date' => null,
                    'latest_late_date' => null,
                ];
            } else {
                $byUser[$uid]['office_active_days'] = $days;
            }
        }
        $usersSummary = array_values($byUser);
        usort($usersSummary, static function ($a, $b) {
            $scoreA = ((int)$a['exception_count'] * 10) + (int)$a['late_count'];
            $scoreB = ((int)$b['exception_count'] * 10) + (int)$b['late_count'];
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }
            return strcasecmp((string)$a['username'], (string)$b['username']);
        });

        $this->sendJsonResponse(200, 'OK', [
            'today' => $today,
            'exceptions' => $exceptions,
            'late_days' => $lateDays,
            'users' => $usersSummary,
            'exception_count' => count($exceptions),
            'late_count' => count($lateDays),
            'office_active_days_total' => $officeActiveTotal,
            'late_limit' => br_checkin_late_limit(),
        ]);
    }

    /**
     * POST — upsert day exception(s) and/or forgive late / clear.
     * Body: user_id, date|dates[], allow_wfh?, forgive_late?, admin_note?, action?: 'save'|'forgive_late'|'clear'
     */
    public function save()
    {
        $decoded = $this->requireAdminAuth();
        if (!$decoded) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->sendJsonResponse(400, 'Invalid JSON body');
            return;
        }

        $userId = trim((string)($input['user_id'] ?? ''));
        $dates = $this->resolveDates($input);
        $action = strtolower(trim((string)($input['action'] ?? 'save')));
        $adminNote = isset($input['admin_note']) ? trim((string)$input['admin_note']) : null;

        if ($userId === '' || $dates === []) {
            $this->sendJsonResponse(400, 'user_id and date/dates (YYYY-MM-DD) are required');
            return;
        }

        br_ensure_checkin_policy_schema($this->conn);
        $today = br_server_today();

        if ($action === 'clear') {
            $result = br_clear_day_exceptions($this->conn, $userId, $dates);
            if (empty($result['ok'])) {
                $this->sendJsonResponse(400, $result['message'] ?? 'Failed to clear exception');
                return;
            }
            $this->sendJsonResponse(200, $result['message'], [
                'cleared' => $result['cleared'] ?? 0,
                'dates' => $result['dates'] ?? $dates,
                'exception' => null,
                'policy' => br_checkin_policy_status($this->conn, $userId, $today),
            ]);
            return;
        }

        if ($action === 'forgive_late') {
            $result = br_forgive_late_day(
                $this->conn,
                $userId,
                $dates,
                $decoded->user_id,
                $adminNote
            );
            if (empty($result['ok'])) {
                $this->sendJsonResponse(400, $result['message'] ?? 'Failed to forgive late');
                return;
            }
            // Optionally also set allow_wfh if provided (same flags for all dates)
            if (array_key_exists('allow_wfh', $input)) {
                foreach ($dates as $date) {
                    br_upsert_day_exception(
                        $this->conn,
                        $userId,
                        $date,
                        !empty($input['allow_wfh']),
                        true,
                        $decoded->user_id,
                        $adminNote
                    );
                }
            }
            $payload = [
                'dates' => $dates,
                'exception' => count($dates) === 1
                    ? br_day_exception($this->conn, $userId, $dates[0])
                    : null,
                'exceptions' => array_map(
                    fn($d) => array_merge(['exception_date' => $d], br_day_exception($this->conn, $userId, $d) ?? []),
                    $dates
                ),
                'recalc' => $result['recalc'] ?? null,
                'policy' => br_checkin_policy_status($this->conn, $userId, $today),
            ];
            $adminId = (string)$decoded->user_id;
            $this->sendJsonThen(
                function () use ($userId, $dates, $adminNote, $adminId) {
                    $this->notifyUserLateForgiven($userId, $dates, $adminNote, $adminId);
                },
                200,
                $result['message'],
                $payload
            );
            return;
        }

        $allowWfh = array_key_exists('allow_wfh', $input) ? !empty($input['allow_wfh']) : null;
        $forgiveLate = array_key_exists('forgive_late', $input) ? !empty($input['forgive_late']) : null;

        if ($allowWfh === null && $forgiveLate === null) {
            $this->sendJsonResponse(400, 'Provide allow_wfh and/or forgive_late');
            return;
        }

        $savedExceptions = [];
        $errors = [];
        $forgivenDates = [];
        foreach ($dates as $date) {
            $saved = br_upsert_day_exception(
                $this->conn,
                $userId,
                $date,
                $allowWfh,
                $forgiveLate,
                $decoded->user_id,
                $adminNote
            );
            if (empty($saved['ok'])) {
                $errors[] = $date . ': ' . ($saved['message'] ?? 'Failed');
                continue;
            }
            $exc = $saved['exception'];
            if (!empty($exc['forgive_late'])) {
                // Defer strike rebuild until after all days are forgiven
                try {
                    $upd = $this->conn->prepare(
                        'UPDATE work_submissions
                         SET is_late = 0, late_strike_consumed = 0
                         WHERE user_id = ? AND submission_date = ?'
                    );
                    $upd->execute([(string)$userId, $date]);
                    $forgivenDates[] = $date;
                } catch (Throwable $e) {
                    error_log('AttendanceExceptionController::save forgive flag: ' . $e->getMessage());
                }
            }
            $savedExceptions[] = array_merge(
                ['exception_date' => $date],
                $exc ?? []
            );
        }

        $recalc = null;
        if ($forgiveLate === true || ($forgiveLate === null && !empty(array_filter($savedExceptions, fn($e) => !empty($e['forgive_late']))))) {
            $recalc = br_recalc_late_strikes($this->conn, $userId);
        }

        if ($savedExceptions === [] && $errors !== []) {
            $this->sendJsonResponse(400, implode('; ', $errors));
            return;
        }

        $msg = count($dates) === 1
            ? ($savedExceptions[0] ? 'Exception saved.' : 'Save failed.')
            : (count($savedExceptions) . ' of ' . count($dates) . ' exceptions saved.');

        $payload = [
            'dates' => $dates,
            'saved_count' => count($savedExceptions),
            'errors' => $errors,
            'exception' => count($savedExceptions) === 1 ? $savedExceptions[0] : null,
            'exceptions' => $savedExceptions,
            'recalc' => $recalc,
            'policy' => br_checkin_policy_status($this->conn, $userId, $today),
        ];

        if ($forgivenDates !== []) {
            $adminId = (string)$decoded->user_id;
            $this->sendJsonThen(
                function () use ($userId, $forgivenDates, $adminNote, $adminId) {
                    $this->notifyUserLateForgiven($userId, $forgivenDates, $adminNote, $adminId);
                },
                200,
                $msg,
                $payload
            );
            return;
        }

        $this->sendJsonResponse(200, $msg, $payload);
    }
}
