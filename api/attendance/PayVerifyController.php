<?php
/**
 * Pay Verify API — weekly + monthly attendance hour verification with salary estimates.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/pay_verify.php';
require_once __DIR__ . '/../../utils/workforce_access.php';
require_once __DIR__ . '/../../utils/pay_verify_notifications.php';

class PayVerifyController extends BaseAPI
{
    private function auth()
    {
        $decoded = $this->validateToken();
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Unauthorized');
            return null;
        }
        return $decoded;
    }

    /**
     * Why: Flush JSON first — email/WhatsApp/FCM must not block Pay Verify actions.
     */
    private function respondThen(callable $afterResponse, int $statusCode, string $message, $data = null): void
    {
        $this->sendJsonThen($afterResponse, $statusCode, $message, $data);
    }

    private function isAdmin($decoded): bool
    {
        return strtolower(trim((string)($decoded->role ?? ''))) === 'admin';
    }

    private function requireAdmin($decoded)
    {
        if (!$this->isAdmin($decoded)) {
            $pm = PermissionManager::getInstance();
            if (!$pm->hasPermissionOrAdmin(
                $decoded->user_id,
                'USERS_VIEW',
                $decoded->role ?? null
            )) {
                $this->sendJsonResponse(403, 'Admin access required');
                return false;
            }
        }
        return true;
    }

    private function canAccessUser($decoded, string $targetUserId): bool
    {
        if ((string)$decoded->user_id === $targetUserId) {
            return true;
        }
        return $this->isAdmin($decoded)
            || PermissionManager::getInstance()->hasPermissionOrAdmin(
                $decoded->user_id,
                'USERS_VIEW',
                $decoded->role ?? null
            );
    }

    public function pendingCounts(): void
    {
        $decoded = $this->auth();
        if (!$decoded) {
            return;
        }
        if (!$this->isAdmin($decoded) && !br_require_workforce($this, $this->conn, $decoded)) {
            return;
        }
        br_pay_verify_ensure_schema($this->conn);
        $counts = br_pay_verify_pending_counts(
            $this->conn,
            (string)$decoded->user_id,
            $this->isAdmin($decoded)
        );
        $isAdmin = $this->isAdmin($decoded);
        // Why: Admins manage Pay Verify but are not required to verify hours — no nag totals.
        $this->sendJsonResponse(200, 'OK', [
            'mine' => $isAdmin ? 0 : (int)$counts['mine'],
            'admin' => 0,
            'total' => $isAdmin ? 0 : (int)$counts['mine'],
        ]);
    }

    public function listMonth(): void
    {
        try {
            $this->listMonthInner();
        } catch (Throwable $e) {
            error_log('PayVerifyController::listMonth: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Pay Verify failed: ' . $e->getMessage());
        }
    }

    private function listMonthInner(): void
    {
        $decoded = $this->auth();
        if (!$decoded) {
            return;
        }
        $isAdmin = $this->isAdmin($decoded);
        if (!$isAdmin && !br_require_workforce($this, $this->conn, $decoded)) {
            return;
        }

        $yearMonth = trim((string)($_GET['month'] ?? ''));
        if ($yearMonth === '' || !preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            $yearMonth = br_pay_verify_today_ym();
        }

        $role = strtolower(trim((string)($_GET['role'] ?? 'all')));
        if (!in_array($role, ['all', 'developer', 'creator', 'codo_tester', 'mine'], true)) {
            $role = 'all';
        }

        $scopeMine = $role === 'mine' || (!$isAdmin && $role === 'all');
        $roleFilter = $scopeMine ? 'all' : $role;

        br_pay_verify_ensure_schema($this->conn);

        if ($scopeMine) {
            $users = array_values(array_filter(
                br_pay_verify_roster_users($this->conn, 'all'),
                static function ($u) use ($decoded) {
                    return (string)($u['id'] ?? '') === (string)$decoded->user_id;
                }
            ));
            if ($users === []) {
                // Still allow self if workforce but not in roster filter edge case
                $users = [[
                    'id' => (string)$decoded->user_id,
                    'username' => '',
                    'name' => '',
                    'role' => (string)($decoded->role ?? ''),
                    'role_bucket' => 'developer',
                ]];
                try {
                    $st = $this->conn->prepare(
                        "SELECT id, username, COALESCE(NULLIF(name, ''), username) AS name, role, tester_type
                         FROM users WHERE id = ? LIMIT 1"
                    );
                    $st->execute([(string)$decoded->user_id]);
                    $row = $st->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $users[0] = $row;
                        $r = strtolower((string)($row['role'] ?? ''));
                        $tt = strtolower((string)($row['tester_type'] ?? ''));
                        $users[0]['role_bucket'] = $r === 'creator'
                            ? 'creator'
                            : ($r === 'tester' && $tt === 'codo' ? 'codo_tester' : 'developer');
                    }
                } catch (Throwable $e) {
                    // ignore
                }
            }
        } else {
            if (!$this->requireAdmin($decoded)) {
                return;
            }
            $users = br_pay_verify_roster_users($this->conn, $roleFilter);
        }

        $monthNav = br_pay_verify_month_nav_bounds(
            $this->conn,
            (string)$decoded->user_id,
            $users,
            $scopeMine
        );
        $requestedMonth = $yearMonth;
        $yearMonth = br_pay_verify_clamp_ym($yearMonth, $monthNav);
        $bounds = br_pay_verify_month_bounds($yearMonth);
        if (!$bounds) {
            $this->sendJsonResponse(422, 'Invalid month (use YYYY-MM)');
            return;
        }

        $weeksMeta = br_pay_verify_weeks_overlapping_month($bounds['start'], $bounds['end']);

        $roster = [];
        $totals = [
            'hours' => 0.0,
            'gross' => 0.0,
            'net' => 0.0,
            'adjustments' => 0.0,
            'verified_weeks' => 0,
            'pending_weeks' => 0,
            'locked_months' => 0,
        ];

        foreach ($users as $user) {
            $uid = (string)$user['id'];
            $weeks = [];
            foreach ($weeksMeta as $w) {
                $weekRow = br_pay_verify_ensure_week(
                    $this->conn,
                    $uid,
                    $w,
                    $yearMonth,
                    $bounds['start'],
                    $bounds['end']
                );
                unset($weekRow['attendance_days']);
                $weeks[] = $weekRow;
                $emp = (string)($weekRow['employee_status'] ?? 'pending');
                if ($emp === 'verified') {
                    $totals['verified_weeks']++;
                } else {
                    $totals['pending_weeks']++;
                }
            }

            $monthRow = br_pay_verify_ensure_month(
                $this->conn,
                $uid,
                $yearMonth,
                $bounds['start'],
                $bounds['end']
            );
            unset($monthRow['attendance_days']);

            $totals['hours'] += (float)($monthRow['total_hours'] ?? 0);
            $totals['gross'] += (float)($monthRow['gross_estimate'] ?? 0);
            $totals['net'] += (float)($monthRow['net_estimate'] ?? 0);
            $totals['adjustments'] += (float)($monthRow['adjustments_total'] ?? 0);
            if (($monthRow['admin_status'] ?? '') === 'approved') {
                $totals['locked_months']++;
            }

            $roster[] = [
                'user' => [
                    'id' => $uid,
                    'username' => (string)($user['username'] ?? ''),
                    'name' => (string)($user['name'] ?? $user['username'] ?? ''),
                    'role' => (string)($user['role'] ?? ''),
                    'role_bucket' => (string)($user['role_bucket'] ?? 'developer'),
                    'tester_type' => $user['tester_type'] ?? null,
                ],
                'weeks' => $weeks,
                'month' => $monthRow,
                'rate_info' => br_pay_verify_rate_snapshot($this->conn, $uid, $bounds['end']),
            ];
        }

        $totals['hours'] = round($totals['hours'], 2);
        $totals['gross'] = round($totals['gross'], 2);
        $totals['net'] = round($totals['net'], 2);
        $totals['adjustments'] = round($totals['adjustments'], 2);

        $this->sendJsonResponse(200, 'OK', [
            'month' => $yearMonth,
            'period_start' => $bounds['start'],
            'period_end' => $bounds['end'],
            'period_label' => $this->formatPeriodLabel($bounds['start'], $bounds['end']),
            'role' => $scopeMine ? 'mine' : $roleFilter,
            'weeks_meta' => $weeksMeta,
            'roster' => $roster,
            'totals' => $totals,
            'is_admin' => $isAdmin,
            'month_bounds' => [
                'min' => $monthNav['min'],
                'max' => $monthNav['max'],
                'joining_date' => $monthNav['joining_date'],
                'clamped' => $requestedMonth !== $yearMonth,
                'requested' => $requestedMonth,
            ],
        ]);
    }

    public function getUserMonth(): void
    {
        try {
            $decoded = $this->auth();
            if (!$decoded) {
                return;
            }

            $userId = trim((string)($_GET['user_id'] ?? ''));
            if ($userId === '') {
                $userId = (string)$decoded->user_id;
            }
            if (!$this->canAccessUser($decoded, $userId)) {
                $this->sendJsonResponse(403, 'Forbidden');
                return;
            }
            if ((string)$decoded->user_id === $userId
                && !$this->isAdmin($decoded)
                && !br_require_workforce($this, $this->conn, $decoded)) {
                return;
            }

            $yearMonth = trim((string)($_GET['month'] ?? ''));
            if ($yearMonth === '' || !preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
                $yearMonth = br_pay_verify_today_ym();
            }

            $monthNav = br_pay_verify_month_nav_bounds(
                $this->conn,
                $userId,
                [['id' => $userId]],
                true
            );
            $requestedMonth = $yearMonth;
            $yearMonth = br_pay_verify_clamp_ym($yearMonth, $monthNav);
            $bounds = br_pay_verify_month_bounds($yearMonth);
            if (!$bounds) {
                $this->sendJsonResponse(422, 'Invalid month (use YYYY-MM)');
                return;
            }

            br_pay_verify_ensure_schema($this->conn);
            $weeksMeta = br_pay_verify_weeks_overlapping_month($bounds['start'], $bounds['end']);
            $weeks = [];
            foreach ($weeksMeta as $w) {
                $weekRow = br_pay_verify_ensure_week(
                    $this->conn,
                    $userId,
                    $w,
                    $yearMonth,
                    $bounds['start'],
                    $bounds['end']
                );
                $weeks[] = $weekRow;
            }
            $monthRow = br_pay_verify_ensure_month(
                $this->conn,
                $userId,
                $yearMonth,
                $bounds['start'],
                $bounds['end']
            );

            $user = null;
            try {
                $st = $this->conn->prepare(
                    "SELECT id, username, COALESCE(NULLIF(name, ''), username) AS name, role, tester_type
                     FROM users WHERE id = ? LIMIT 1"
                );
                $st->execute([$userId]);
                $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                try {
                    $st = $this->conn->prepare(
                        'SELECT id, username, role FROM users WHERE id = ? LIMIT 1'
                    );
                    $st->execute([$userId]);
                    $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e2) {
                    $user = ['id' => $userId, 'username' => '', 'role' => ''];
                }
            }

            $this->sendJsonResponse(200, 'OK', [
                'month' => $yearMonth,
                'period_start' => $bounds['start'],
                'period_end' => $bounds['end'],
                'period_label' => $this->formatPeriodLabel($bounds['start'], $bounds['end']),
                'user' => $user,
                'weeks' => $weeks,
                'month_verification' => $monthRow,
                'rate_info' => br_pay_verify_rate_snapshot($this->conn, $userId, $bounds['end']),
                'rate_history' => $this->isAdmin($decoded)
                    ? br_pay_verify_rate_history($this->conn, $userId)
                    : [],
                'is_admin' => $this->isAdmin($decoded),
                'is_self' => (string)$decoded->user_id === $userId,
                'month_bounds' => [
                    'min' => $monthNav['min'],
                    'max' => $monthNav['max'],
                    'joining_date' => $monthNav['joining_date'],
                    'clamped' => $requestedMonth !== $yearMonth,
                    'requested' => $requestedMonth,
                ],
            ]);
        } catch (Throwable $e) {
            error_log('PayVerifyController::getUserMonth: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Pay Verify failed: ' . $e->getMessage());
        }
    }

    public function employeeVerifyWeek(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !br_require_workforce($this, $this->conn, $decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $userId = trim((string)($input['user_id'] ?? $decoded->user_id));
        if ($userId !== (string)$decoded->user_id && !$this->isAdmin($decoded)) {
            $this->sendJsonResponse(403, 'You can only verify your own weeks');
            return;
        }
        $weekStart = trim((string)($input['week_start'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? '')));
        $note = trim((string)($input['note'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            $this->sendJsonResponse(422, 'week_start required (YYYY-MM-DD Monday)');
            return;
        }
        if (!in_array($status, ['verified', 'correction_needed'], true)) {
            $this->sendJsonResponse(422, 'status must be verified or correction_needed');
            return;
        }
        if ($status === 'correction_needed' && $note === '') {
            $this->sendJsonResponse(422, 'Note is required when marking correction needed');
            return;
        }

        br_pay_verify_ensure_schema($this->conn);
        $monthLocked = $this->isMonthLockedForWeek($userId, $weekStart);
        if ($monthLocked) {
            $this->sendJsonResponse(409, 'Month is locked. Ask an admin to unlock before changing verification.');
            return;
        }

        $stmt = $this->conn->prepare(
            'SELECT * FROM attendance_week_verifications WHERE user_id = ? AND week_start = ? LIMIT 1'
        );
        $stmt->execute([$userId, $weekStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Week verification not found. Open the month view first.');
            return;
        }
        if (($row['admin_status'] ?? '') === 'approved') {
            $this->sendJsonResponse(409, 'Week already admin-approved');
            return;
        }
        $weekEnd = (string)($row['week_end'] ?? '');
        if (!br_pay_verify_period_is_complete($weekEnd)) {
            $this->sendJsonResponse(
                422,
                'This week is still open. Verify only after the week ends (no future days).'
            );
            return;
        }

        $adminStatusSql = $status === 'correction_needed'
            ? "admin_status = 'pending',"
            : '';
        $upd = $this->conn->prepare(
            "UPDATE attendance_week_verifications SET
                employee_status = ?,
                employee_note = ?,
                employee_verified_at = NOW(),
                {$adminStatusSql}
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $upd->execute([
            $status,
            $note !== '' ? mb_substr($note, 0, 2000) : null,
            $row['id'],
        ]);

        $stmt->execute([$userId, $weekStart]);
        $weekRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $actorId = (string)$decoded->user_id;
        $this->respondThen(
            function () use ($userId, $actorId, $weekStart, $status, $note) {
                br_notify_pay_verify_employee_week(
                    $this->conn,
                    $userId,
                    $actorId,
                    $weekStart,
                    $status,
                    $note !== '' ? $note : null
                );
            },
            200,
            'Week verification saved',
            ['week' => $weekRow]
        );
    }

    public function adminVerifyWeek(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $userId = trim((string)($input['user_id'] ?? ''));
        $weekStart = trim((string)($input['week_start'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? '')));
        $note = trim((string)($input['note'] ?? ''));
        if ($userId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            $this->sendJsonResponse(422, 'user_id and week_start required');
            return;
        }
        if (!in_array($status, ['approved', 'correction_requested'], true)) {
            $this->sendJsonResponse(422, 'status must be approved or correction_requested');
            return;
        }
        if ($status === 'correction_requested' && $note === '') {
            $this->sendJsonResponse(422, 'Note is required when requesting correction');
            return;
        }

        br_pay_verify_ensure_schema($this->conn);
        $stmt = $this->conn->prepare(
            'SELECT * FROM attendance_week_verifications WHERE user_id = ? AND week_start = ? LIMIT 1'
        );
        $stmt->execute([$userId, $weekStart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Week verification not found');
            return;
        }
        if ($status === 'approved' && ($row['employee_status'] ?? '') !== 'verified') {
            $this->sendJsonResponse(422, 'Employee must verify the week before admin approval');
            return;
        }
        $weekEnd = (string)($row['week_end'] ?? '');
        if (!br_pay_verify_period_is_complete($weekEnd)) {
            $this->sendJsonResponse(
                422,
                'This week is still open. Approve only after the week ends (no future days).'
            );
            return;
        }

        $empStatus = $status === 'correction_requested' ? 'correction_needed' : ($row['employee_status'] ?? 'verified');
        $locked = $status === 'approved' ? 1 : 0;
        $upd = $this->conn->prepare(
            'UPDATE attendance_week_verifications SET
                admin_status = ?,
                admin_note = ?,
                admin_verified_at = NOW(),
                admin_id = ?,
                employee_status = ?,
                snapshot_locked = ?,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $upd->execute([
            $status,
            $note !== '' ? mb_substr($note, 0, 2000) : null,
            (string)$decoded->user_id,
            $empStatus,
            $locked,
            $row['id'],
        ]);

        $stmt->execute([$userId, $weekStart]);
        $weekRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->respondThen(
            function () use ($userId, $weekStart, $status, $note) {
                br_notify_pay_verify_admin_week(
                    $this->conn,
                    $userId,
                    $weekStart,
                    $status,
                    $note !== '' ? $note : null
                );
            },
            200,
            'Admin week verification saved',
            ['week' => $weekRow]
        );
    }

    public function employeeVerifyMonth(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !br_require_workforce($this, $this->conn, $decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $userId = trim((string)($input['user_id'] ?? $decoded->user_id));
        if ($userId !== (string)$decoded->user_id && !$this->isAdmin($decoded)) {
            $this->sendJsonResponse(403, 'You can only verify your own month');
            return;
        }
        $yearMonth = trim((string)($input['month'] ?? ''));
        $status = strtolower(trim((string)($input['status'] ?? '')));
        $note = trim((string)($input['note'] ?? ''));
        $bounds = br_pay_verify_month_bounds($yearMonth);
        if (!$bounds) {
            $this->sendJsonResponse(422, 'Invalid month');
            return;
        }
        if (!in_array($status, ['verified', 'correction_needed'], true)) {
            $this->sendJsonResponse(422, 'status must be verified or correction_needed');
            return;
        }
        if ($status === 'correction_needed' && $note === '') {
            $this->sendJsonResponse(422, 'Note is required when marking correction needed');
            return;
        }

        br_pay_verify_ensure_schema($this->conn);
        $monthRow = br_pay_verify_ensure_month(
            $this->conn,
            $userId,
            $yearMonth,
            $bounds['start'],
            $bounds['end']
        );
        if (($monthRow['admin_status'] ?? '') === 'approved') {
            $this->sendJsonResponse(409, 'Month is locked by admin');
            return;
        }
        if (!br_pay_verify_period_is_complete((string)$bounds['end'])) {
            $this->sendJsonResponse(
                422,
                'This month is still open. Verify only after the month ends (no future days).'
            );
            return;
        }

        if ($status === 'verified') {
            $isAdminCaller = $this->isAdmin($decoded);
            $weeksMeta = br_pay_verify_weeks_overlapping_month($bounds['start'], $bounds['end']);
            foreach ($weeksMeta as $w) {
                $week = br_pay_verify_ensure_week(
                    $this->conn,
                    $userId,
                    $w,
                    $yearMonth,
                    $bounds['start'],
                    $bounds['end']
                );
                $weekEmp = (string)($week['employee_status'] ?? '');
                $weekAdmin = (string)($week['admin_status'] ?? '');
                if ($weekEmp === 'verified' || $weekAdmin === 'approved') {
                    continue;
                }
                // Why: Admins verifying on behalf of staff should not be blocked by
                // unfinished week checkboxes — auto-complete those weeks in the same action.
                // Avoid COALESCE(column, ?) — MariaDB #1267 when note collations differ.
                if ($isAdminCaller) {
                    $existingNote = trim((string)($week['employee_note'] ?? ''));
                    $weekNote = $existingNote !== ''
                        ? $existingNote
                        : 'Verified by admin with month verify';
                    $autoWeek = $this->conn->prepare(
                        "UPDATE attendance_week_verifications SET
                            employee_status = 'verified',
                            employee_note = ?,
                            employee_verified_at = IFNULL(employee_verified_at, NOW()),
                            updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?"
                    );
                    $autoWeek->execute([$weekNote, $week['id']]);
                    continue;
                }
                $this->sendJsonResponse(
                    422,
                    'All overlapping weeks must be employee-verified before month verify'
                );
                return;
            }
        }

        $adminStatusSql = $status === 'correction_needed'
            ? "admin_status = 'pending',"
            : '';
        $upd = $this->conn->prepare(
            "UPDATE attendance_month_verifications SET
                employee_status = ?,
                employee_note = ?,
                employee_verified_at = NOW(),
                {$adminStatusSql}
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $upd->execute([
            $status,
            $note !== '' ? mb_substr($note, 0, 2000) : null,
            $monthRow['id'],
        ]);

        $fresh = br_pay_verify_ensure_month(
            $this->conn,
            $userId,
            $yearMonth,
            $bounds['start'],
            $bounds['end']
        );
        $actorId = (string)$decoded->user_id;
        $net = $fresh['net_estimate'] ?? null;
        $this->respondThen(
            function () use ($userId, $actorId, $yearMonth, $status, $note, $net) {
                br_notify_pay_verify_employee_month(
                    $this->conn,
                    $userId,
                    $actorId,
                    $yearMonth,
                    $status,
                    $note !== '' ? $note : null,
                    $net
                );
            },
            200,
            'Month verification saved',
            ['month' => $fresh]
        );
    }

    public function adminLockMonth(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $userId = trim((string)($input['user_id'] ?? ''));
        $yearMonth = trim((string)($input['month'] ?? ''));
        $action = strtolower(trim((string)($input['action'] ?? 'lock')));
        $note = trim((string)($input['note'] ?? ''));
        $includeOt = !empty($input['include_ot']);
        $bounds = br_pay_verify_month_bounds($yearMonth);
        if ($userId === '' || !$bounds) {
            $this->sendJsonResponse(422, 'user_id and month required');
            return;
        }

        br_pay_verify_ensure_schema($this->conn);

        if ($action === 'unlock') {
            $upd = $this->conn->prepare(
                "UPDATE attendance_month_verifications SET
                    admin_status = 'pending',
                    admin_note = ?,
                    admin_verified_at = NULL,
                    admin_id = NULL,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = ? AND `year_month` = ?"
            );
            $upd->execute([
                $note !== '' ? mb_substr($note, 0, 2000) : 'Unlocked by admin',
                $userId,
                $yearMonth,
            ]);
            // Unlock weeks for the month
            $this->conn->prepare(
                "UPDATE attendance_week_verifications SET
                    snapshot_locked = 0,
                    admin_status = CASE WHEN admin_status = 'approved' THEN 'pending' ELSE admin_status END
                 WHERE user_id = ? AND `year_month` = ?"
            )->execute([$userId, $yearMonth]);

            $fresh = br_pay_verify_ensure_month(
                $this->conn,
                $userId,
                $yearMonth,
                $bounds['start'],
                $bounds['end']
            );
            $unlockNote = $note !== '' ? $note : 'Unlocked by admin';
            $this->respondThen(
                function () use ($userId, $yearMonth, $unlockNote, $fresh) {
                    br_notify_pay_verify_admin_month(
                        $this->conn,
                        $userId,
                        $yearMonth,
                        'unlock',
                        $unlockNote,
                        $fresh['net_estimate'] ?? null
                    );
                },
                200,
                'Month unlocked',
                ['month' => $fresh]
            );
            return;
        }

        if ($action === 'correction_requested') {
            if ($note === '') {
                $this->sendJsonResponse(422, 'Note required');
                return;
            }
            $upd = $this->conn->prepare(
                "UPDATE attendance_month_verifications SET
                    admin_status = 'correction_requested',
                    employee_status = 'correction_needed',
                    admin_note = ?,
                    admin_verified_at = NOW(),
                    admin_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE user_id = ? AND `year_month` = ?"
            );
            $upd->execute([
                mb_substr($note, 0, 2000),
                (string)$decoded->user_id,
                $userId,
                $yearMonth,
            ]);
            $fresh = br_pay_verify_ensure_month(
                $this->conn,
                $userId,
                $yearMonth,
                $bounds['start'],
                $bounds['end']
            );
            $this->respondThen(
                function () use ($userId, $yearMonth, $note, $fresh) {
                    br_notify_pay_verify_admin_month(
                        $this->conn,
                        $userId,
                        $yearMonth,
                        'correction_requested',
                        $note,
                        $fresh['net_estimate'] ?? null
                    );
                },
                200,
                'Correction requested',
                ['month' => $fresh]
            );
            return;
        }

        // lock / approve
        $monthRow = br_pay_verify_ensure_month(
            $this->conn,
            $userId,
            $yearMonth,
            $bounds['start'],
            $bounds['end'],
            $includeOt
        );
        if (($monthRow['employee_status'] ?? '') !== 'verified') {
            $this->sendJsonResponse(422, 'Employee must verify the month before salary lock');
            return;
        }

        // Refresh with include_ot if toggled
        if ($includeOt !== ((int)($monthRow['include_ot'] ?? 0) === 1)) {
            $this->conn->prepare(
                'UPDATE attendance_month_verifications SET include_ot = ? WHERE id = ?'
            )->execute([$includeOt ? 1 : 0, $monthRow['id']]);
            // Force recalculation by temporarily clearing admin approved (not yet)
            $monthRow = br_pay_verify_ensure_month(
                $this->conn,
                $userId,
                $yearMonth,
                $bounds['start'],
                $bounds['end'],
                $includeOt
            );
        }

        $upd = $this->conn->prepare(
            "UPDATE attendance_month_verifications SET
                admin_status = 'approved',
                admin_note = ?,
                admin_verified_at = NOW(),
                admin_id = ?,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $upd->execute([
            $note !== '' ? mb_substr($note, 0, 2000) : null,
            (string)$decoded->user_id,
            $monthRow['id'],
        ]);

        // Lock all weeks in month
        $this->conn->prepare(
            "UPDATE attendance_week_verifications SET
                snapshot_locked = 1,
                admin_status = 'approved',
                admin_id = ?,
                admin_verified_at = IFNULL(admin_verified_at, NOW())
             WHERE user_id = ? AND `year_month` = ?"
        )->execute([(string)$decoded->user_id, $userId, $yearMonth]);

        $fresh = br_pay_verify_ensure_month(
            $this->conn,
            $userId,
            $yearMonth,
            $bounds['start'],
            $bounds['end']
        );
        $this->respondThen(
            function () use ($userId, $yearMonth, $note, $fresh) {
                br_notify_pay_verify_admin_month(
                    $this->conn,
                    $userId,
                    $yearMonth,
                    'lock',
                    $note !== '' ? $note : null,
                    $fresh['net_estimate'] ?? null
                );
            },
            200,
            'Month salary locked',
            ['month' => $fresh]
        );
    }

    public function setRate(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $userId = trim((string)($input['user_id'] ?? ''));
        $rate = isset($input['hourly_rate']) ? (float)$input['hourly_rate'] : -1;
        $effectiveFrom = trim((string)($input['effective_from'] ?? ''));
        $note = trim((string)($input['note'] ?? ''));
        if ($userId === '' || $rate < 0) {
            $this->sendJsonResponse(422, 'user_id and hourly_rate required');
            return;
        }
        if ($rate > 99999) {
            $this->sendJsonResponse(422, 'hourly_rate too large');
            return;
        }
        if ($effectiveFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom)) {
            $effectiveFrom = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-01');
        }
        if (strlen($note) > 500) {
            $note = substr($note, 0, 500);
        }

        br_pay_verify_ensure_schema($this->conn);
        $before = br_pay_verify_rate_snapshot(
            $this->conn,
            $userId,
            (new DateTimeImmutable($effectiveFrom, new DateTimeZone('Asia/Kolkata')))
                ->modify('-1 day')
                ->format('Y-m-d')
        );
        $previousRate = $before['current_rate'];

        $existing = $this->conn->prepare(
            'SELECT id FROM user_hourly_rates WHERE user_id = ? AND effective_from = ? LIMIT 1'
        );
        $existing->execute([$userId, $effectiveFrom]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $this->conn->prepare(
                'UPDATE user_hourly_rates
                 SET hourly_rate = ?, note = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?'
            )->execute([
                round($rate, 2),
                $note !== '' ? $note : null,
                (string)$decoded->user_id,
                $row['id'],
            ]);
            $id = $row['id'];
        } else {
            $id = br_pay_verify_uuid();
            $this->conn->prepare(
                'INSERT INTO user_hourly_rates (id, user_id, hourly_rate, effective_from, note, updated_by)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                $id,
                $userId,
                round($rate, 2),
                $effectiveFrom,
                $note !== '' ? $note : null,
                (string)$decoded->user_id,
            ]);
        }

        $hikeAmount = $previousRate !== null ? round($rate - $previousRate, 2) : null;
        $hikePct = ($previousRate !== null && $previousRate > 0)
            ? round(($hikeAmount / $previousRate) * 100, 1)
            : null;

        $this->respondThen(
            function () use ($userId, $rate, $effectiveFrom, $previousRate, $hikePct, $note) {
                br_notify_pay_verify_salary_hike(
                    $this->conn,
                    $userId,
                    round($rate, 2),
                    $effectiveFrom,
                    $previousRate !== null ? (float)$previousRate : null,
                    $hikePct !== null ? (float)$hikePct : null,
                    $note !== '' ? $note : null
                );
            },
            200,
            'Salary hike saved',
            [
                'id' => $id,
                'user_id' => $userId,
                'hourly_rate' => round($rate, 2),
                'effective_from' => $effectiveFrom,
                'note' => $note !== '' ? $note : null,
                'previous_rate' => $previousRate,
                'hike_amount' => $hikeAmount,
                'hike_pct' => $hikePct,
                'rate_info' => br_pay_verify_rate_snapshot($this->conn, $userId, $effectiveFrom),
                'history' => br_pay_verify_rate_history($this->conn, $userId),
            ]
        );
    }

    public function rateHistory(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $userId = trim((string)($_GET['user_id'] ?? ''));
        if ($userId === '') {
            $this->sendJsonResponse(422, 'user_id required');
            return;
        }
        $asOf = trim((string)($_GET['as_of'] ?? ''));
        if ($asOf === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            $asOf = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        }
        br_pay_verify_ensure_schema($this->conn);
        $this->sendJsonResponse(200, 'OK', [
            'user_id' => $userId,
            'as_of' => $asOf,
            'rate_info' => br_pay_verify_rate_snapshot($this->conn, $userId, $asOf),
            'history' => br_pay_verify_rate_history($this->conn, $userId),
        ]);
    }

    public function deleteRate(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $id = trim((string)($input['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(422, 'id required');
            return;
        }
        br_pay_verify_ensure_schema($this->conn);
        $stmt = $this->conn->prepare(
            'SELECT id, user_id, effective_from, hourly_rate FROM user_hourly_rates WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Rate entry not found');
            return;
        }

        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        // Why: only allow deleting scheduled (future) hikes — past rates are payroll history.
        if ((string)$row['effective_from'] <= $today) {
            $count = $this->conn->prepare(
                'SELECT COUNT(*) FROM user_hourly_rates WHERE user_id = ?'
            );
            $count->execute([(string)$row['user_id']]);
            if ((int)$count->fetchColumn() <= 1) {
                $this->sendJsonResponse(422, 'Cannot delete the only rate for this user');
                return;
            }
            if ((string)$row['effective_from'] < $today) {
                $this->sendJsonResponse(422, 'Past salary rates cannot be deleted — add a new hike instead');
                return;
            }
        }

        $this->conn->prepare('DELETE FROM user_hourly_rates WHERE id = ?')->execute([$id]);
        $userId = (string)$row['user_id'];
        $removedRate = (float)$row['hourly_rate'];
        $effectiveFrom = (string)$row['effective_from'];
        $this->respondThen(
            function () use ($userId, $removedRate, $effectiveFrom) {
                br_notify_pay_verify_salary_hike_removed(
                    $this->conn,
                    $userId,
                    $removedRate,
                    $effectiveFrom
                );
            },
            200,
            'Rate entry removed',
            [
                'id' => $id,
                'user_id' => $userId,
                'rate_info' => br_pay_verify_rate_snapshot($this->conn, $userId, $today),
                'history' => br_pay_verify_rate_history($this->conn, $userId),
            ]
        );
    }

    public function listRates(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        br_pay_verify_ensure_schema($this->conn);
        $users = br_pay_verify_roster_users($this->conn, 'all');
        $asOf = trim((string)($_GET['as_of'] ?? ''));
        if ($asOf === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            $asOf = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        }
        $rates = [];
        foreach ($users as $u) {
            $uid = (string)$u['id'];
            $snap = br_pay_verify_rate_snapshot($this->conn, $uid, $asOf);
            $rates[] = [
                'user_id' => $uid,
                'username' => $u['username'] ?? '',
                'name' => $u['name'] ?? '',
                'role_bucket' => $u['role_bucket'] ?? '',
                'hourly_rate' => $snap['current_rate'],
                'effective_from' => $snap['effective_from'],
                'previous_rate' => $snap['previous_rate'],
                'previous_from' => $snap['previous_from'],
            ];
        }
        $this->sendJsonResponse(200, 'OK', ['as_of' => $asOf, 'rates' => $rates]);
    }

    public function userProjects(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $userId = trim((string)($_GET['user_id'] ?? ''));
        if ($userId === '') {
            $this->sendJsonResponse(422, 'user_id required');
            return;
        }
        br_pay_verify_ensure_schema($this->conn);
        $projects = br_pay_verify_user_projects($this->conn, $userId);
        $this->sendJsonResponse(200, 'OK', [
            'user_id' => $userId,
            'projects' => $projects,
        ]);
    }

    public function addAdjustment(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $userId = trim((string)($input['user_id'] ?? ''));
        $yearMonth = trim((string)($input['month'] ?? ''));
        $type = strtolower(trim((string)($input['type'] ?? 'deduction')));
        $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
        $reason = trim((string)($input['reason'] ?? ''));
        $projectId = trim((string)($input['project_id'] ?? ''));
        $bounds = br_pay_verify_month_bounds($yearMonth);
        if ($userId === '' || !$bounds) {
            $this->sendJsonResponse(422, 'user_id and month required');
            return;
        }
        $allowedTypes = ['advance', 'deduction', 'credit', 'other', 'project_incentive'];
        if (!in_array($type, $allowedTypes, true)) {
            $this->sendJsonResponse(422, 'Invalid adjustment type');
            return;
        }

        $project = null;
        if ($type === 'project_incentive') {
            if ($projectId === '') {
                $this->sendJsonResponse(422, 'Select a project for this incentive');
                return;
            }
            $project = br_pay_verify_assert_user_project($this->conn, $userId, $projectId);
            if (!$project) {
                $this->sendJsonResponse(
                    422,
                    'That project is not assigned to this employee'
                );
                return;
            }
            // Why: Keep audit trail clear — always name the project; optional note appends.
            $projectLabel = trim($project['name']) !== '' ? $project['name'] : 'Project';
            if ($reason === '') {
                $reason = 'Project incentive · ' . $projectLabel;
            } elseif (stripos($reason, $projectLabel) === false) {
                $reason = 'Project incentive · ' . $projectLabel . ' — ' . $reason;
            }
        } elseif ($reason === '') {
            $this->sendJsonResponse(422, 'Reason required');
            return;
        }

        if (!is_finite($amount) || abs($amount) < 0.01) {
            $this->sendJsonResponse(422, 'Amount required');
            return;
        }

        // Normalize sign: deductions/advances reduce net (negative); credits & incentives positive
        if (in_array($type, ['advance', 'deduction'], true) && $amount > 0) {
            $amount = -abs($amount);
        }
        if (in_array($type, ['credit', 'project_incentive'], true) && $amount < 0) {
            $amount = abs($amount);
        }
        if ($type === 'project_incentive') {
            $amount = abs($amount);
        }

        br_pay_verify_ensure_schema($this->conn);
        $monthRow = br_pay_verify_ensure_month(
            $this->conn,
            $userId,
            $yearMonth,
            $bounds['start'],
            $bounds['end']
        );
        if (($monthRow['admin_status'] ?? '') === 'approved') {
            $this->sendJsonResponse(409, 'Unlock the month before editing adjustments');
            return;
        }

        $id = br_pay_verify_uuid();
        $this->conn->prepare(
            'INSERT INTO attendance_month_adjustments
             (id, month_verification_id, type, amount, reason, project_id, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $id,
            $monthRow['id'],
            $type,
            round($amount, 2),
            mb_substr($reason, 0, 500),
            $type === 'project_incentive' ? $projectId : null,
            (string)$decoded->user_id,
        ]);

        $fresh = br_pay_verify_ensure_month(
            $this->conn,
            $userId,
            $yearMonth,
            $bounds['start'],
            $bounds['end']
        );
        $adjAmount = round($amount, 2);
        $adjReason = mb_substr($reason, 0, 500);
        $this->respondThen(
            function () use ($userId, $yearMonth, $type, $adjAmount, $adjReason) {
                br_notify_pay_verify_adjustment(
                    $this->conn,
                    $userId,
                    $yearMonth,
                    $type,
                    $adjAmount,
                    $adjReason,
                    false
                );
            },
            200,
            'Adjustment added',
            ['month' => $fresh]
        );
    }

    public function deleteAdjustment(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $adjId = trim((string)($input['id'] ?? $_GET['id'] ?? ''));
        if ($adjId === '') {
            $this->sendJsonResponse(422, 'Adjustment id required');
            return;
        }
        br_pay_verify_ensure_schema($this->conn);
        $stmt = $this->conn->prepare(
            'SELECT a.*, m.user_id, m.`year_month`, m.admin_status, m.period_start, m.period_end
             FROM attendance_month_adjustments a
             INNER JOIN attendance_month_verifications m ON m.id = a.month_verification_id
             WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$adjId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Adjustment not found');
            return;
        }
        if (($row['admin_status'] ?? '') === 'approved') {
            $this->sendJsonResponse(409, 'Unlock the month before editing adjustments');
            return;
        }
        $this->conn->prepare('DELETE FROM attendance_month_adjustments WHERE id = ?')->execute([$adjId]);
        $fresh = br_pay_verify_ensure_month(
            $this->conn,
            (string)$row['user_id'],
            (string)$row['year_month'],
            (string)$row['period_start'],
            (string)$row['period_end']
        );
        $this->respondThen(
            function () use ($row) {
                br_notify_pay_verify_adjustment(
                    $this->conn,
                    (string)$row['user_id'],
                    (string)$row['year_month'],
                    (string)$row['type'],
                    (float)$row['amount'],
                    (string)($row['reason'] ?? ''),
                    true
                );
            },
            200,
            'Adjustment deleted',
            ['month' => $fresh]
        );
    }

    public function seedRates(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        $input = $this->getJsonInput();
        $custom = is_array($input['rates'] ?? null) ? $input['rates'] : null;
        $map = $custom ?: br_pay_verify_default_rate_seed_map();
        $normalized = [];
        foreach ($map as $k => $v) {
            $normalized[(string)$k] = (float)$v;
        }
        $count = br_pay_verify_seed_rates(
            $this->conn,
            $normalized,
            (string)$decoded->user_id,
            trim((string)($input['effective_from'] ?? '2026-01-01')) ?: '2026-01-01'
        );
        $this->sendJsonResponse(200, 'Rates seeded', ['inserted' => $count]);
    }

    public function seedSeptemberAdjustments(): void
    {
        $decoded = $this->auth();
        if (!$decoded || !$this->requireAdmin($decoded)) {
            return;
        }
        // Marva −5200, Jubairiya −3060 for 2026-09
        $specs = [
            ['username' => 'Fathima_marva', 'amount' => -5200, 'reason' => 'September advance / adjustment'],
            ['username' => 'Jubairiya', 'amount' => -3060, 'reason' => 'September advance / adjustment'],
        ];
        $yearMonth = '2026-09';
        $bounds = br_pay_verify_month_bounds($yearMonth);
        $added = 0;
        foreach ($specs as $spec) {
            $st = $this->conn->prepare('SELECT id FROM users WHERE LOWER(username) = LOWER(?) LIMIT 1');
            $st->execute([$spec['username']]);
            $user = $st->fetch(PDO::FETCH_ASSOC);
            if (!$user || !$bounds) {
                continue;
            }
            $monthRow = br_pay_verify_ensure_month(
                $this->conn,
                (string)$user['id'],
                $yearMonth,
                $bounds['start'],
                $bounds['end']
            );
            if (($monthRow['admin_status'] ?? '') === 'approved') {
                continue;
            }
            // Skip if same reason already exists
            $dup = false;
            foreach ($monthRow['adjustments'] ?? [] as $a) {
                if ((float)($a['amount'] ?? 0) === (float)$spec['amount']) {
                    $dup = true;
                    break;
                }
            }
            if ($dup) {
                continue;
            }
            $this->conn->prepare(
                'INSERT INTO attendance_month_adjustments
                 (id, month_verification_id, type, amount, reason, created_by)
                 VALUES (?,?,?,?,?,?)'
            )->execute([
                br_pay_verify_uuid(),
                $monthRow['id'],
                'advance',
                $spec['amount'],
                $spec['reason'],
                (string)$decoded->user_id,
            ]);
            br_pay_verify_ensure_month(
                $this->conn,
                (string)$user['id'],
                $yearMonth,
                $bounds['start'],
                $bounds['end']
            );
            $added++;
        }
        $this->sendJsonResponse(200, 'September adjustments seeded', ['added' => $added]);
    }

    private function formatPeriodLabel(string $start, string $end): string
    {
        $s = DateTimeImmutable::createFromFormat('Y-m-d', $start);
        $e = DateTimeImmutable::createFromFormat('Y-m-d', $end);
        if (!$s || !$e) {
            return $start . ' – ' . $end;
        }
        return $s->format('F d') . ' – ' . $e->format('F d, Y');
    }

    private function isMonthLockedForWeek(string $userId, string $weekStart): bool
    {
        $stmt = $this->conn->prepare(
            'SELECT `year_month` FROM attendance_week_verifications WHERE user_id = ? AND week_start = ? LIMIT 1'
        );
        $stmt->execute([$userId, $weekStart]);
        $ym = $stmt->fetchColumn();
        if (!$ym) {
            return false;
        }
        $m = $this->conn->prepare(
            "SELECT admin_status FROM attendance_month_verifications WHERE user_id = ? AND `year_month` = ? LIMIT 1"
        );
        $m->execute([$userId, $ym]);
        return ($m->fetchColumn() ?: '') === 'approved';
    }

    /** @return array<string,mixed> */
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return $_POST ?: [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
