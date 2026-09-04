<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../NotificationManager.php';
require_once __DIR__ . '/../../utils/work_period.php';
require_once __DIR__ . '/../../utils/leave_attendance.php';
require_once __DIR__ . '/../../utils/bug_dates_recurrence.php';

class LeaveController extends BaseAPI
{
    private function requireAuth()
    {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, 'Authentication failed');
                return null;
            }
            return $decoded;
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, $e->getMessage() ?: 'Authentication failed');
            return null;
        }
    }

    private function requireAdmin($decoded): bool
    {
        $pm = PermissionManager::getInstance();
        if (!$pm->hasPermissionOrAdmin(
            $decoded->user_id ?? '',
            'LEAVE_MANAGE',
            $decoded->role ?? null
        )) {
            $this->sendJsonResponse(403, 'Access denied');
            return false;
        }
        return true;
    }

    private function ensureLeaveReady(): bool
    {
        if (!br_leave_tables_ready($this->conn)) {
            $this->sendJsonResponse(503, 'Leave management is not set up. Run migration 020_leave_management.sql.');
            return false;
        }
        return true;
    }

    private function formatRequestRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'user_id' => $row['user_id'],
            'username' => $row['username'] ?? null,
            'role' => $row['role'] ?? null,
            'leave_type_id' => (int)$row['leave_type_id'],
            'leave_type_code' => $row['leave_type_code'] ?? null,
            'leave_type_name' => $row['leave_type_name'] ?? null,
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'days_count' => (float)$row['days_count'],
            'hours_per_day' => array_key_exists('hours_per_day', $row) && $row['hours_per_day'] !== null && $row['hours_per_day'] !== ''
                ? (float)$row['hours_per_day']
                : null,
            'is_half_day' => (bool)(int)($row['is_half_day'] ?? 0),
            'half_day_type' => $row['half_day_type'] ?? null,
            'reason' => $row['reason'] ?? null,
            'emergency_contact' => $row['emergency_contact'] ?? null,
            'status' => $row['status'],
            'reviewed_by' => $row['reviewed_by'] ?? null,
            'reviewed_at' => $row['reviewed_at'] ?? null,
            'admin_note' => $row['admin_note'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function selectSql(): string
    {
        return "SELECT lr.*, u.username, u.role, lt.code AS leave_type_code, lt.name AS leave_type_name
                FROM leave_requests lr
                LEFT JOIN users u ON u.id = lr.user_id
                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id";
    }

    public function types()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureLeaveReady()) {
            return;
        }
        $month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', (string)$_GET['month'])
            ? (string)$_GET['month']
            : (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m');
        $userId = (string)$decoded->user_id;
        if (isset($_GET['user_id']) && trim((string)$_GET['user_id']) !== '') {
            if (!$this->requireAdmin($decoded)) {
                return;
            }
            $userId = trim((string)$_GET['user_id']);
        }
        $balances = br_leave_balances_for_month($this->conn, $userId, $month);
        $this->sendJsonResponse(200, 'OK', [
            'month' => $month,
            'user_id' => $userId,
            'types' => $balances,
        ]);
    }

    public function balance()
    {
        $this->types();
    }

    public function mine()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureLeaveReady()) {
            return;
        }
        $userId = (string)$decoded->user_id;
        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
        $sql = $this->selectSql() . ' WHERE lr.user_id = ?';
        $params = [$userId];
        if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $sql .= ' AND lr.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY lr.created_at DESC, lr.id DESC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $this->sendJsonResponse(200, 'OK', array_map([$this, 'formatRequestRow'], $rows));
    }

    public function listAll()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireAdmin($decoded) || !$this->ensureLeaveReady()) {
            return;
        }
        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
        $userId = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';
        $month = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
        $pendingOnly = isset($_GET['pending_only']) && (string)$_GET['pending_only'] === '1';

        $sql = $this->selectSql() . ' WHERE 1=1';
        $params = [];
        if ($pendingOnly) {
            $sql .= " AND lr.status = 'pending'";
        } elseif ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $sql .= ' AND lr.status = ?';
            $params[] = $status;
        }
        if ($userId !== '') {
            $sql .= ' AND lr.user_id = ?';
            $params[] = $userId;
        }
        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $monthStart = $month . '-01';
            $tz = new DateTimeZone('Asia/Kolkata');
            $me = new DateTime($monthStart, $tz);
            $me->modify('last day of this month');
            $monthEnd = $me->format('Y-m-d');
            $sql .= ' AND lr.start_date <= ? AND lr.end_date >= ?';
            $params[] = $monthEnd;
            $params[] = $monthStart;
        }
        $sql .= ' ORDER BY FIELD(lr.status, \'pending\', \'approved\', \'rejected\', \'cancelled\'), lr.created_at DESC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $this->sendJsonResponse(200, 'OK', array_map([$this, 'formatRequestRow'], $rows));
    }

    public function request($payload)
    {
        try {
            $decoded = $this->requireAuth();
            if (!$decoded || !$this->ensureLeaveReady()) {
                return;
            }
            $userId = (string)$decoded->user_id;
            $leaveTypeId = isset($payload['leave_type_id']) ? (int)$payload['leave_type_id'] : 0;
            $startDate = trim((string)($payload['start_date'] ?? ''));
            $endDate = trim((string)($payload['end_date'] ?? $startDate));
            $reason = trim((string)($payload['reason'] ?? ''));

            if ($leaveTypeId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $this->sendJsonResponse(400, 'leave_type_id, start_date, and end_date are required');
                return;
            }
            if ($endDate < $startDate) {
                $this->sendJsonResponse(400, 'end_date cannot be before start_date');
                return;
            }

            $typeStmt = $this->conn->prepare('SELECT id, code, name, monthly_quota FROM leave_types WHERE id = ? AND is_active = 1 LIMIT 1');
            $typeStmt->execute([$leaveTypeId]);
            $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$type) {
                $this->sendJsonResponse(400, 'Invalid leave type');
                return;
            }
            // Personal Leave is retired — only Paid (1), Sick (1), Unpaid (max 5).
            if (strtolower((string)$type['code']) === 'personal') {
                $this->sendJsonResponse(400, 'Personal Leave is no longer available. Use Paid, Sick, or Unpaid Leave.');
                return;
            }
            // Official Leave is admin-granted only (company holidays).
            if (strtolower((string)$type['code']) === 'corporate') {
                $this->sendJsonResponse(400, 'Official Leave can only be granted by an admin.');
                return;
            }

            $joining = br_user_joining_date($this->conn, $userId);
            if ($joining !== null && $startDate < $joining) {
                $this->sendJsonResponse(400, "Leave cannot start before joining date ({$joining}).");
                return;
            }

            $isHalfDay = !empty($payload['is_half_day']);
            $halfDayType = null;
            if ($isHalfDay) {
                $halfDayType = strtolower(trim((string)($payload['half_day_type'] ?? '')));
                if (!in_array($halfDayType, ['first_half', 'second_half'], true)) {
                    $this->sendJsonResponse(400, 'half_day_type must be first_half or second_half');
                    return;
                }
                if ($startDate !== $endDate) {
                    $this->sendJsonResponse(400, 'Half-day leave must be a single date');
                    return;
                }
            }

            $emergencyContact = trim((string)($payload['emergency_contact'] ?? ''));
            if (mb_strlen($emergencyContact) > 50) {
                $emergencyContact = mb_substr($emergencyContact, 0, 50);
            }

            $hasHalfCols = false;
            try {
                $c = $this->conn->query("SHOW COLUMNS FROM leave_requests LIKE 'is_half_day'");
                $hasHalfCols = (bool)($c && $c->fetch(PDO::FETCH_ASSOC));
            } catch (Throwable $e) {
                $hasHalfCols = false;
            }

            $days = $isHalfDay
                ? 0.5
                : br_leave_working_days($startDate, $endDate, $this->conn);
            if ($days <= 0) {
                $this->sendJsonResponse(400, 'Invalid leave duration (no working days in range)');
                return;
            }

            if (br_leave_has_overlap($this->conn, $userId, $startDate, $endDate)) {
                $this->sendJsonResponse(409, 'Overlapping pending or approved leave already exists for these dates.');
                return;
            }

            // on_duty / corporate / zero-quota types: no monthly balance gate
            $typeCode = strtolower((string)$type['code']);
            $unlimitedQuota = $typeCode === 'on_duty' || $typeCode === 'corporate' || (float)$type['monthly_quota'] <= 0;

            // Split the request into segments: days within the monthly balance keep the
            // requested type; overflow days are automatically marked Unpaid Leave.
            // Unpaid Leave is capped at monthly_quota (max 5 days / month).
            $isUnpaidRequest = $typeCode === 'unpaid';
            $unpaidType = null;
            if (!$isUnpaidRequest && !$unlimitedQuota) {
                $u = $this->conn->prepare("SELECT id, code, name, monthly_quota FROM leave_types WHERE code = 'unpaid' AND is_active = 1 LIMIT 1");
                $u->execute();
                $unpaidType = $u->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $segments = [];
            $tz = new DateTimeZone('Asia/Kolkata');
            $cursor = DateTime::createFromFormat('Y-m-d', $startDate, $tz);
            $endDt = DateTime::createFromFormat('Y-m-d', $endDate, $tz);
            $remainingByMonth = [];
            $unpaidRemainingByMonth = [];
            $current = null;

            if ($isHalfDay) {
                if (!$unlimitedQuota && !$isUnpaidRequest) {
                    $ym = substr($startDate, 0, 7);
                    $used = br_leave_used_days_in_month($this->conn, $userId, $leaveTypeId, $ym);
                    $remaining = max(0.0, (float)$type['monthly_quota'] - $used);
                    if ($remaining < 0.5 - 0.001) {
                        // Try unpaid overflow for half day
                        if ($unpaidType) {
                            $unpaidUsed = br_leave_used_days_in_month($this->conn, $userId, (int)$unpaidType['id'], $ym);
                            $unpaidRem = max(0.0, (float)$unpaidType['monthly_quota'] - $unpaidUsed);
                            if ($unpaidRem < 0.5 - 0.001) {
                                $this->sendJsonResponse(400, "Insufficient leave balance for half-day in {$ym}.");
                                return;
                            }
                            $segments[] = [
                                'type' => $unpaidType,
                                'start' => $startDate,
                                'end' => $endDate,
                                'days' => 0.5,
                            ];
                        } else {
                            $this->sendJsonResponse(400, "Insufficient {$type['name']} balance for half-day in {$ym}.");
                            return;
                        }
                    } else {
                        $segments[] = [
                            'type' => $type,
                            'start' => $startDate,
                            'end' => $endDate,
                            'days' => 0.5,
                        ];
                    }
                } elseif ($isUnpaidRequest) {
                    $ym = substr($startDate, 0, 7);
                    $used = br_leave_used_days_in_month($this->conn, $userId, $leaveTypeId, $ym);
                    $remaining = max(0.0, (float)$type['monthly_quota'] - $used);
                    if ($remaining < 0.5 - 0.001) {
                        $this->sendJsonResponse(400, "Insufficient Unpaid Leave balance for half-day in {$ym}.");
                        return;
                    }
                    $segments[] = [
                        'type' => $type,
                        'start' => $startDate,
                        'end' => $endDate,
                        'days' => 0.5,
                    ];
                } else {
                    $segments[] = [
                        'type' => $type,
                        'start' => $startDate,
                        'end' => $endDate,
                        'days' => 0.5,
                    ];
                }
            } else {
            while ($cursor && $endDt && $cursor <= $endDt) {
                $ym = $cursor->format('Y-m');
                $date = $cursor->format('Y-m-d');

                if (br_is_office_closed($date, $this->conn)) {
                    $cursor->modify('+1 day');
                    continue;
                }

                $dayCost = 1.0;

                if ($unlimitedQuota) {
                    $dayType = $type;
                } elseif ($isUnpaidRequest) {
                    if (!isset($remainingByMonth[$ym])) {
                        $used = br_leave_used_days_in_month($this->conn, $userId, $leaveTypeId, $ym);
                        $remainingByMonth[$ym] = max(0.0, (float)$type['monthly_quota'] - $used);
                    }
                    if ($remainingByMonth[$ym] < $dayCost - 0.001) {
                        $this->sendJsonResponse(
                            400,
                            "Insufficient Unpaid Leave balance for {$ym}. Remaining: {$remainingByMonth[$ym]} (max {$type['monthly_quota']} / month)."
                        );
                        return;
                    }
                    $dayType = $type;
                    $remainingByMonth[$ym] -= $dayCost;
                } else {
                    if (!isset($remainingByMonth[$ym])) {
                        $used = br_leave_used_days_in_month($this->conn, $userId, $leaveTypeId, $ym);
                        $remainingByMonth[$ym] = max(0.0, (float)$type['monthly_quota'] - $used);
                    }
                    if ($remainingByMonth[$ym] >= $dayCost - 0.001) {
                        $dayType = $type;
                        $remainingByMonth[$ym] -= $dayCost;
                    } elseif ($unpaidType) {
                        $unpaidId = (int)$unpaidType['id'];
                        if (!isset($unpaidRemainingByMonth[$ym])) {
                            $unpaidUsed = br_leave_used_days_in_month($this->conn, $userId, $unpaidId, $ym);
                            $unpaidRemainingByMonth[$ym] = max(
                                0.0,
                                (float)$unpaidType['monthly_quota'] - $unpaidUsed
                            );
                        }
                        if ($unpaidRemainingByMonth[$ym] < $dayCost - 0.001) {
                            $this->sendJsonResponse(
                                400,
                                "Insufficient leave balance for {$ym}. {$type['name']} and Unpaid Leave (max {$unpaidType['monthly_quota']} / month) are exhausted."
                            );
                            return;
                        }
                        $dayType = $unpaidType;
                        $unpaidRemainingByMonth[$ym] -= $dayCost;
                    } else {
                        $needed = br_leave_days_in_month($startDate, $endDate, $ym, $this->conn);
                        $remaining = $remainingByMonth[$ym];
                        $this->sendJsonResponse(
                            400,
                            "Insufficient {$type['name']} balance for {$ym}. Remaining: {$remaining}, requested in month: {$needed}."
                        );
                        return;
                    }
                }

                if ($current && (int)$current['type']['id'] === (int)$dayType['id']) {
                    $current['end'] = $date;
                    $current['days'] = ($current['days'] ?? 0) + $dayCost;
                } else {
                    if ($current) {
                        $segments[] = $current;
                    }
                    $current = ['type' => $dayType, 'start' => $date, 'end' => $date, 'days' => $dayCost];
                }
                $cursor->modify('+1 day');
            }
            if ($current) {
                $segments[] = $current;
            }
            }

            if (empty($segments)) {
                $this->sendJsonResponse(400, 'Invalid leave duration (no working days in range)');
                return;
            }

            if ($hasHalfCols) {
                $stmt = $this->conn->prepare(
                    "INSERT INTO leave_requests
                     (user_id, leave_type_id, start_date, end_date, days_count, is_half_day, half_day_type, reason, emergency_contact, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
                );
            } else {
                $stmt = $this->conn->prepare(
                    "INSERT INTO leave_requests
                     (user_id, leave_type_id, start_date, end_date, days_count, reason, status)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending')"
                );
            }

            $createdIds = [];
            $summaryParts = [];
            $notifyQueue = [];
            foreach ($segments as $segment) {
                $segDays = isset($segment['days'])
                    ? (float)$segment['days']
                    : br_leave_working_days($segment['start'], $segment['end'], $this->conn);
                if ($hasHalfCols) {
                    $stmt->execute([
                        $userId,
                        (int)$segment['type']['id'],
                        $segment['start'],
                        $segment['end'],
                        $segDays,
                        $isHalfDay ? 1 : 0,
                        $isHalfDay ? $halfDayType : null,
                        $reason !== '' ? $reason : null,
                        $emergencyContact !== '' ? $emergencyContact : null,
                    ]);
                } else {
                    $stmt->execute([
                        $userId,
                        (int)$segment['type']['id'],
                        $segment['start'],
                        $segment['end'],
                        $segDays,
                        $reason !== '' ? $reason : null,
                    ]);
                }
                $segId = (int)$this->conn->lastInsertId();
                $createdIds[] = $segId;
                $segDaysLabel = rtrim(rtrim(number_format($segDays, 2, '.', ''), '0'), '.');
                $summaryParts[] = "{$segDaysLabel} day" . ($segDays == 1.0 ? '' : 's') . " {$segment['type']['name']}";
                $notifyQueue[] = [
                    'id' => $segId,
                    'userId' => $userId,
                    'typeName' => (string)$segment['type']['name'],
                    'start' => $segment['start'],
                    'end' => $segment['end'],
                ];
            }

            $message = count($createdIds) > 1
                ? 'Leave request submitted — split as ' . implode(' + ', $summaryParts) . ' (balance exceeded, extra days marked Unpaid Leave).'
                : 'Leave request submitted';

            $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
            $fetch = $this->conn->prepare($this->selectSql() . " WHERE lr.id IN ({$placeholders}) ORDER BY lr.start_date ASC");
            $fetch->execute($createdIds);
            $rows = $fetch->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $formatted = array_map([$this, 'formatRequestRow'], $rows);
            $primary = $formatted[0] ?? ['id' => $createdIds[0]];
            $primary['requests'] = $formatted;
            $this->sendJsonThen(
                function () use ($notifyQueue) {
                    foreach ($notifyQueue as $item) {
                        try {
                            NotificationManager::getInstance()->notifyLeaveRequested(
                                $item['id'],
                                $item['userId'],
                                $item['typeName'],
                                $item['start'],
                                $item['end']
                            );
                        } catch (Throwable $e) {
                            error_log('notifyLeaveRequested: ' . $e->getMessage());
                        }
                    }
                },
                201,
                $message,
                $primary
            );
        } catch (Throwable $e) {
            error_log('LeaveController::request: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to submit leave request: ' . $e->getMessage());
        }
    }

    public function cancel($payload)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureLeaveReady()) {
            return;
        }
        $userId = (string)$decoded->user_id;
        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $stmt = $this->conn->prepare('SELECT * FROM leave_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Leave request not found');
            return;
        }
        if ((string)$row['user_id'] !== $userId) {
            $this->sendJsonResponse(403, 'You can only cancel your own leave requests');
            return;
        }
        if ((string)$row['status'] !== 'pending') {
            $this->sendJsonResponse(400, 'Only pending leave requests can be cancelled');
            return;
        }
        $upd = $this->conn->prepare("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?");
        $upd->execute([$id]);
        $fetch = $this->conn->prepare($this->selectSql() . ' WHERE lr.id = ? LIMIT 1');
        $fetch->execute([$id]);
        $out = $fetch->fetch(PDO::FETCH_ASSOC);
        $this->sendJsonResponse(200, 'Leave request cancelled', $out ? $this->formatRequestRow($out) : null);
    }

    public function review($payload)
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->requireAdmin($decoded) || !$this->ensureLeaveReady()) {
            return;
        }
        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        $action = strtolower(trim((string)($payload['action'] ?? '')));
        $adminNote = trim((string)($payload['admin_note'] ?? ''));
        if ($id <= 0 || !in_array($action, ['approve', 'reject'], true)) {
            $this->sendJsonResponse(400, 'id and action (approve|reject) are required');
            return;
        }
        $stmt = $this->conn->prepare('SELECT * FROM leave_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Leave request not found');
            return;
        }
        if ((string)$row['status'] !== 'pending') {
            $this->sendJsonResponse(400, 'Only pending leave requests can be reviewed');
            return;
        }

        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        if ($action === 'approve') {
            // Re-check overlap against other approved/pending (exclude self)
            if (br_leave_has_overlap($this->conn, (string)$row['user_id'], (string)$row['start_date'], (string)$row['end_date'], $id)) {
                $this->sendJsonResponse(409, 'Cannot approve: overlapping leave already exists for these dates.');
                return;
            }
            // Re-check balance for all types (including Unpaid Leave max 5 / month)
            $typeStmt = $this->conn->prepare('SELECT id, code, name, monthly_quota FROM leave_types WHERE id = ? LIMIT 1');
            $typeStmt->execute([(int)$row['leave_type_id']]);
            $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
            if ($type) {
                $tz = new DateTimeZone('Asia/Kolkata');
                $cursor = DateTime::createFromFormat('Y-m-d', (string)$row['start_date'], $tz);
                $endDt = DateTime::createFromFormat('Y-m-d', (string)$row['end_date'], $tz);
                $monthsChecked = [];
                while ($cursor && $endDt && $cursor <= $endDt) {
                    $ym = $cursor->format('Y-m');
                    if (!isset($monthsChecked[$ym])) {
                        $needed = br_leave_days_in_month((string)$row['start_date'], (string)$row['end_date'], $ym);
                        $used = br_leave_used_days_in_month($this->conn, (string)$row['user_id'], (int)$row['leave_type_id'], $ym);
                        $quota = (float)$type['monthly_quota'];
                        if ($used + $needed > $quota + 0.001) {
                            $this->sendJsonResponse(400, "Insufficient {$type['name']} balance for {$ym} to approve this request.");
                            return;
                        }
                        $monthsChecked[$ym] = true;
                    }
                    $cursor->modify('+1 day');
                }
            }
        }

        $upd = $this->conn->prepare(
            "UPDATE leave_requests
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(), admin_note = ?
             WHERE id = ?"
        );
        $upd->execute([
            $newStatus,
            (string)$decoded->user_id,
            $adminNote !== '' ? $adminNote : null,
            $id,
        ]);

        $fetch = $this->conn->prepare($this->selectSql() . ' WHERE lr.id = ? LIMIT 1');
        $fetch->execute([$id]);
        $out = $fetch->fetch(PDO::FETCH_ASSOC);
        $formatted = $out ? $this->formatRequestRow($out) : null;
        $reviewUserId = (string)$row['user_id'];
        $reviewStart = (string)$row['start_date'];
        $reviewEnd = (string)$row['end_date'];

        $this->sendJsonThen(
            function () use ($id, $reviewUserId, $newStatus, $reviewStart, $reviewEnd, $adminNote) {
                try {
                    NotificationManager::getInstance()->notifyLeaveReviewed(
                        $id,
                        $reviewUserId,
                        $newStatus,
                        $reviewStart,
                        $reviewEnd,
                        $adminNote
                    );
                } catch (Throwable $e) {
                    error_log('notifyLeaveReviewed: ' . $e->getMessage());
                }
            },
            200,
            'Leave request ' . $newStatus,
            $formatted
        );
    }

    /**
     * Admin-only: grant Official Leave (corporate, 8h) to all or selected users for a date range.
     * Why: Company holidays (e.g. Meelad Nabi) must credit work hours without using personal leave quotas
     * or the forgot-checkout admin-hours path.
     *
     * @param array<string,mixed> $payload
     */
    public function adminGrantOfficialLeave($payload)
    {
        try {
            $decoded = $this->requireAuth();
            if (!$decoded || !$this->ensureLeaveReady()) {
                return;
            }
            if (!$this->requireAdmin($decoded)) {
                return;
            }

            $startDate = trim((string)($payload['start_date'] ?? $payload['date'] ?? ''));
            $endDate = trim((string)($payload['end_date'] ?? $startDate));
            $title = trim((string)($payload['title'] ?? $payload['reason'] ?? ''));
            $scope = strtolower(trim((string)($payload['scope'] ?? 'all')));
            $userIdsRaw = $payload['user_ids'] ?? [];
            $doNotify = !isset($payload['notify']) || !empty($payload['notify']);
            $replaceAdminHours = !empty($payload['replace_admin_hours']);
            $hoursPerDay = 8.0;
            if (array_key_exists('hours_per_day', $payload) || array_key_exists('hours', $payload)) {
                $rawH = $payload['hours_per_day'] ?? $payload['hours'] ?? 8;
                $hoursPerDay = round((float)$rawH, 2);
                if ($hoursPerDay < 0) {
                    $hoursPerDay = 0.0;
                }
                if ($hoursPerDay > 24) {
                    $hoursPerDay = 24.0;
                }
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $this->sendJsonResponse(400, 'date or start_date/end_date (YYYY-MM-DD) are required');
                return;
            }
            if ($endDate < $startDate) {
                $this->sendJsonResponse(400, 'end_date cannot be before start_date');
                return;
            }
            if ($title === '') {
                $this->sendJsonResponse(400, 'title is required (e.g. Meelad Nabi)');
                return;
            }
            if (mb_strlen($title) > 255) {
                $title = mb_substr($title, 0, 255);
            }
            if (!in_array($scope, ['all', 'users'], true)) {
                $this->sendJsonResponse(400, 'scope must be all or users');
                return;
            }

            $type = $this->ensureCorporateLeaveType();
            if (!$type) {
                $this->sendJsonResponse(500, 'Official Leave type is not available');
                return;
            }

            // Calendar-day span (do not skip office-closed days — this leave IS the holiday credit).
            $tz = new DateTimeZone('Asia/Kolkata');
            $startDt = DateTime::createFromFormat('Y-m-d', $startDate, $tz);
            $endDt = DateTime::createFromFormat('Y-m-d', $endDate, $tz);
            if (!$startDt || !$endDt) {
                $this->sendJsonResponse(400, 'Invalid date format');
                return;
            }
            $daysCount = (float)($startDt->diff($endDt)->days + 1);

            $targetIds = [];
            if ($scope === 'users') {
                if (!is_array($userIdsRaw) || count($userIdsRaw) === 0) {
                    $this->sendJsonResponse(400, 'user_ids is required when scope=users');
                    return;
                }
                foreach ($userIdsRaw as $uid) {
                    $uid = trim((string)$uid);
                    if ($uid !== '') {
                        $targetIds[$uid] = true;
                    }
                }
                $targetIds = array_keys($targetIds);
            } else {
                $targetIds = $this->listActiveEmployeeIds();
            }

            if (count($targetIds) === 0) {
                $this->sendJsonResponse(400, 'No users to grant Official Leave');
                return;
            }

            $adminId = (string)($decoded->user_id ?? '');
            $created = 0;
            $skipped = [];
            $createdRows = [];
            $adminHoursRemoved = 0;

            if ($replaceAdminHours) {
                $adminHoursRemoved = $this->removeAdminHoursEntriesForUsers(
                    $targetIds,
                    $startDate,
                    $endDate,
                    $adminId
                );
            }

            $hasHalfCols = false;
            try {
                $c = $this->conn->query("SHOW COLUMNS FROM leave_requests LIKE 'is_half_day'");
                $hasHalfCols = (bool)($c && $c->fetch(PDO::FETCH_ASSOC));
            } catch (Throwable $e) {
                $hasHalfCols = false;
            }
            $hasHoursCol = $this->ensureHoursPerDayColumn();

            if ($hasHoursCol && $hasHalfCols) {
                $ins = $this->conn->prepare(
                    "INSERT INTO leave_requests
                     (user_id, leave_type_id, start_date, end_date, days_count, hours_per_day, is_half_day, half_day_type, reason, status, reviewed_by, reviewed_at, admin_note)
                     VALUES (?, ?, ?, ?, ?, ?, 0, NULL, ?, 'approved', ?, NOW(), ?)"
                );
            } elseif ($hasHoursCol) {
                $ins = $this->conn->prepare(
                    "INSERT INTO leave_requests
                     (user_id, leave_type_id, start_date, end_date, days_count, hours_per_day, reason, status, reviewed_by, reviewed_at, admin_note)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), ?)"
                );
            } elseif ($hasHalfCols) {
                $ins = $this->conn->prepare(
                    "INSERT INTO leave_requests
                     (user_id, leave_type_id, start_date, end_date, days_count, is_half_day, half_day_type, reason, status, reviewed_by, reviewed_at, admin_note)
                     VALUES (?, ?, ?, ?, ?, 0, NULL, ?, 'approved', ?, NOW(), ?)"
                );
            } else {
                $ins = $this->conn->prepare(
                    "INSERT INTO leave_requests
                     (user_id, leave_type_id, start_date, end_date, days_count, reason, status, reviewed_by, reviewed_at, admin_note)
                     VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), ?)"
                );
            }

            foreach ($targetIds as $uid) {
                $joining = br_user_joining_date($this->conn, $uid);
                if ($joining !== null && $endDate < $joining) {
                    $skipped[] = ['user_id' => $uid, 'reason' => 'before_joining'];
                    continue;
                }
                $effectiveStart = $startDate;
                if ($joining !== null && $joining > $startDate && $joining <= $endDate) {
                    $effectiveStart = $joining;
                }

                if (br_leave_has_overlap($this->conn, $uid, $effectiveStart, $endDate)) {
                    $skipped[] = ['user_id' => $uid, 'reason' => 'overlap'];
                    continue;
                }

                $effStartDt = DateTime::createFromFormat('Y-m-d', $effectiveStart, $tz);
                $segDays = $effStartDt
                    ? (float)($effStartDt->diff($endDt)->days + 1)
                    : $daysCount;

                $adminNote = 'Official Leave granted by admin';
                try {
                    if ($hasHoursCol && $hasHalfCols) {
                        $ins->execute([
                            $uid,
                            (int)$type['id'],
                            $effectiveStart,
                            $endDate,
                            $segDays,
                            $hoursPerDay,
                            $title,
                            $adminId !== '' ? $adminId : null,
                            $adminNote,
                        ]);
                    } elseif ($hasHoursCol) {
                        $ins->execute([
                            $uid,
                            (int)$type['id'],
                            $effectiveStart,
                            $endDate,
                            $segDays,
                            $hoursPerDay,
                            $title,
                            $adminId !== '' ? $adminId : null,
                            $adminNote,
                        ]);
                    } elseif ($hasHalfCols) {
                        $ins->execute([
                            $uid,
                            (int)$type['id'],
                            $effectiveStart,
                            $endDate,
                            $segDays,
                            $title,
                            $adminId !== '' ? $adminId : null,
                            $adminNote,
                        ]);
                    } else {
                        $ins->execute([
                            $uid,
                            (int)$type['id'],
                            $effectiveStart,
                            $endDate,
                            $segDays,
                            $title,
                            $adminId !== '' ? $adminId : null,
                            $adminNote,
                        ]);
                    }
                    $newId = (int)$this->conn->lastInsertId();
                    $created += 1;
                    $createdRows[] = [
                        'id' => $newId,
                        'user_id' => $uid,
                        'start_date' => $effectiveStart,
                        'end_date' => $endDate,
                        'days_count' => $segDays,
                        'hours_per_day' => $hoursPerDay,
                    ];
                } catch (Throwable $e) {
                    error_log('adminGrantOfficialLeave insert: ' . $e->getMessage());
                    $skipped[] = ['user_id' => $uid, 'reason' => 'insert_failed'];
                }
            }

            $responsePayload = [
                'title' => $title,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'leave_type_code' => 'corporate',
                'credited_hours_per_day' => $hoursPerDay,
                'created' => $created,
                'skipped' => $skipped,
                'requests' => $createdRows,
                'admin_hours_removed' => $adminHoursRemoved,
                'notify' => $doNotify,
            ];

            $notifyRows = $createdRows;
            $notifyTitle = $title;
            $this->sendJsonThen(
                function () use ($doNotify, $notifyRows, $notifyTitle, $startDate, $endDate) {
                    if (!$doNotify || $notifyRows === []) {
                        return;
                    }
                    try {
                        require_once __DIR__ . '/../NotificationManager.php';
                        require_once __DIR__ . '/../../utils/email.php';
                        require_once __DIR__ . '/../../utils/whatsapp.php';
                        $nm = NotificationManager::getInstance();
                        foreach ($notifyRows as $row) {
                            $uid = (string)($row['user_id'] ?? '');
                            if ($uid === '') {
                                continue;
                            }
                            try {
                                $nm->notifyOfficialLeaveGranted(
                                    $uid,
                                    $notifyTitle,
                                    (string)($row['start_date'] ?? $startDate),
                                    (string)($row['end_date'] ?? $endDate),
                                    $row['id'] ?? null
                                );
                            } catch (Throwable $e) {
                                error_log('notifyOfficialLeaveGranted push: ' . $e->getMessage());
                            }
                            try {
                                $uStmt = $this->conn->prepare(
                                    'SELECT username, email, phone FROM users WHERE id = ? LIMIT 1'
                                );
                                $uStmt->execute([$uid]);
                                $u = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                                $uname = trim((string)($u['username'] ?? '')) ?: 'teammate';
                                $email = trim((string)($u['email'] ?? ''));
                                $phone = trim((string)($u['phone'] ?? ''));
                                $sDate = (string)($row['start_date'] ?? $startDate);
                                $eDate = (string)($row['end_date'] ?? $endDate);
                                if ($email !== '') {
                                    sendOfficialLeaveEmail($email, $uname, $notifyTitle, $sDate, $eDate);
                                }
                                if ($phone !== '') {
                                    sendOfficialLeaveWhatsApp($phone, $uname, $notifyTitle, $sDate, $eDate);
                                }
                            } catch (Throwable $chanErr) {
                                error_log('official leave mail/wa: ' . $chanErr->getMessage());
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('adminGrantOfficialLeave notify batch: ' . $e->getMessage());
                    }
                },
                200,
                'Official Leave granted',
                $responsePayload
            );
        } catch (Throwable $e) {
            error_log('adminGrantOfficialLeave: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to grant Official Leave');
        }
    }

    /**
     * Admin: list Official Leave (corporate) grants for history / edit / delete.
     */
    public function adminListOfficialLeave()
    {
        try {
            $decoded = $this->requireAuth();
            if (!$decoded || !$this->ensureLeaveReady()) {
                return;
            }
            if (!$this->requireAdmin($decoded)) {
                return;
            }

            $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
            $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
            $title = isset($_GET['title']) ? trim((string)$_GET['title']) : '';
            $startDate = isset($_GET['start_date']) ? trim((string)$_GET['start_date']) : '';
            $endDate = isset($_GET['end_date']) ? trim((string)$_GET['end_date']) : '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = (int)($_GET['limit'] ?? 30);
            if ($limit < 1) {
                $limit = 30;
            }
            if ($limit > 200) {
                $limit = 200;
            }
            $offset = ($page - 1) * $limit;

            $where = " WHERE lt.code = 'corporate'";
            $params = [];
            if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
                $where .= ' AND lr.status = ?';
                $params[] = $status;
            }
            if ($title !== '') {
                if (strcasecmp($title, 'Official Leave') === 0) {
                    $where .= " AND (TRIM(COALESCE(lr.reason, '')) = '' OR lr.reason = ?)";
                    $params[] = $title;
                } else {
                    $where .= ' AND lr.reason = ?';
                    $params[] = $title;
                }
            }
            if ($startDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                $where .= ' AND lr.start_date = ?';
                $params[] = $startDate;
            }
            if ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $where .= ' AND lr.end_date = ?';
                $params[] = $endDate;
            }
            if ($q !== '') {
                $where .= ' AND (u.username LIKE ? OR lr.reason LIKE ? OR CAST(lr.user_id AS CHAR) LIKE ?)';
                $like = '%' . $q . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $countSql = "SELECT COUNT(*) AS cnt
                FROM leave_requests lr
                LEFT JOIN users u ON u.id = lr.user_id
                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                {$where}";
            $countStmt = $this->conn->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)(($countStmt->fetch(PDO::FETCH_ASSOC) ?: [])['cnt'] ?? 0);

            $sql = $this->selectSql() . $where . ' ORDER BY lr.created_at DESC, lr.id DESC LIMIT ? OFFSET ?';
            $stmt = $this->conn->prepare($sql);
            $i = 1;
            foreach ($params as $p) {
                $stmt->bindValue($i++, $p);
            }
            $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($i, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $this->sendJsonResponse(200, 'OK', [
                'items' => array_map([$this, 'formatRequestRow'], $rows),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ]);
        } catch (Throwable $e) {
            error_log('adminListOfficialLeave: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to list Official Leave');
        }
    }

    /**
     * Admin: list Official Leave celebrations grouped by title + date range.
     * Why: History should show "Onam" / "Independence Day" as entries; drill into users on a detail page.
     */
    public function adminListOfficialLeaveGroups()
    {
        try {
            $decoded = $this->requireAuth();
            if (!$decoded || !$this->ensureLeaveReady()) {
                return;
            }
            if (!$this->requireAdmin($decoded)) {
                return;
            }

            $this->ensureHoursPerDayColumn();

            $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
            $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = (int)($_GET['limit'] ?? 30);
            if ($limit < 1) {
                $limit = 30;
            }
            if ($limit > 100) {
                $limit = 100;
            }
            $offset = ($page - 1) * $limit;

            $where = " WHERE lt.code = 'corporate'";
            $params = [];
            if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
                $where .= ' AND lr.status = ?';
                $params[] = $status;
            }
            if ($q !== '') {
                $where .= ' AND lr.reason LIKE ?';
                $params[] = '%' . $q . '%';
            }

            $hoursExpr = br_leave_has_hours_per_day_col($this->conn)
                ? 'COALESCE(lr.hours_per_day, 8)'
                : '8';

            $countSql = "SELECT COUNT(*) AS cnt FROM (
                SELECT lr.reason, lr.start_date, lr.end_date
                FROM leave_requests lr
                LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                {$where}
                GROUP BY lr.reason, lr.start_date, lr.end_date
            ) g";
            $countStmt = $this->conn->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)(($countStmt->fetch(PDO::FETCH_ASSOC) ?: [])['cnt'] ?? 0);

            $sql = "SELECT
                        COALESCE(NULLIF(TRIM(lr.reason), ''), 'Official Leave') AS title,
                        lr.start_date,
                        lr.end_date,
                        COUNT(*) AS user_count,
                        SUM(CASE WHEN lr.status IN ('approved', 'pending') THEN 1 ELSE 0 END) AS active_count,
                        SUM(CASE WHEN lr.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                        AVG({$hoursExpr}) AS hours_per_day,
                        MAX(lr.days_count) AS days_count,
                        MAX(lr.created_at) AS last_granted_at,
                        MIN(lr.created_at) AS first_granted_at
                    FROM leave_requests lr
                    LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                    {$where}
                    GROUP BY COALESCE(NULLIF(TRIM(lr.reason), ''), 'Official Leave'), lr.start_date, lr.end_date
                    ORDER BY MAX(lr.created_at) DESC
                    LIMIT ? OFFSET ?";
            $stmt = $this->conn->prepare($sql);
            $i = 1;
            foreach ($params as $p) {
                $stmt->bindValue($i++, $p);
            }
            $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
            $stmt->bindValue($i, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $items = [];
            foreach ($rows as $row) {
                $title = (string)($row['title'] ?? 'Official Leave');
                $start = (string)($row['start_date'] ?? '');
                $end = (string)($row['end_date'] ?? '');
                $days = (float)($row['days_count'] ?? 1);
                $hpd = round((float)($row['hours_per_day'] ?? 8), 2);
                $items[] = [
                    'title' => $title,
                    'start_date' => $start,
                    'end_date' => $end,
                    'user_count' => (int)($row['user_count'] ?? 0),
                    'active_count' => (int)($row['active_count'] ?? 0),
                    'cancelled_count' => (int)($row['cancelled_count'] ?? 0),
                    'hours_per_day' => $hpd,
                    'days_count' => $days,
                    'total_hours_per_user' => round($days * $hpd, 2),
                    'last_granted_at' => $row['last_granted_at'] ?? null,
                    'first_granted_at' => $row['first_granted_at'] ?? null,
                ];
            }

            $this->sendJsonResponse(200, 'OK', [
                'items' => $items,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ]);
        } catch (Throwable $e) {
            error_log('adminListOfficialLeaveGroups: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to list Official Leave groups');
        }
    }

    /**
     * Admin: update an Official Leave grant (dates + title).
     *
     * @param array<string,mixed> $payload
     */
    public function adminUpdateOfficialLeave($payload)
    {
        try {
            $decoded = $this->requireAuth();
            if (!$decoded || !$this->ensureLeaveReady()) {
                return;
            }
            if (!$this->requireAdmin($decoded)) {
                return;
            }

            $id = isset($payload['id']) ? (int)$payload['id'] : 0;
            if ($id <= 0) {
                $this->sendJsonResponse(400, 'id is required');
                return;
            }

            $stmt = $this->conn->prepare(
                $this->selectSql() . ' WHERE lr.id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->sendJsonResponse(404, 'Official Leave not found');
                return;
            }
            if (strtolower((string)($row['leave_type_code'] ?? '')) !== 'corporate') {
                $this->sendJsonResponse(400, 'Only Official Leave records can be edited here');
                return;
            }
            if (!in_array((string)$row['status'], ['approved', 'pending'], true)) {
                $this->sendJsonResponse(400, 'Only active Official Leave can be edited');
                return;
            }

            $startDate = array_key_exists('start_date', $payload)
                ? trim((string)$payload['start_date'])
                : (string)$row['start_date'];
            $endDate = array_key_exists('end_date', $payload)
                ? trim((string)$payload['end_date'])
                : (string)$row['end_date'];
            $title = array_key_exists('title', $payload)
                ? trim((string)$payload['title'])
                : (array_key_exists('reason', $payload)
                    ? trim((string)$payload['reason'])
                    : trim((string)($row['reason'] ?? '')));

            $hoursPerDay = 8.0;
            if (array_key_exists('hours_per_day', $payload) || array_key_exists('hours', $payload)) {
                $rawH = $payload['hours_per_day'] ?? $payload['hours'] ?? 8;
                $hoursPerDay = round((float)$rawH, 2);
            } elseif (array_key_exists('hours_per_day', $row) && $row['hours_per_day'] !== null && $row['hours_per_day'] !== '') {
                $hoursPerDay = round((float)$row['hours_per_day'], 2);
            }
            if ($hoursPerDay < 0) {
                $hoursPerDay = 0.0;
            }
            if ($hoursPerDay > 24) {
                $hoursPerDay = 24.0;
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                $this->sendJsonResponse(400, 'start_date and end_date must be YYYY-MM-DD');
                return;
            }
            if ($endDate < $startDate) {
                $this->sendJsonResponse(400, 'end_date cannot be before start_date');
                return;
            }
            if ($title === '') {
                $this->sendJsonResponse(400, 'title is required');
                return;
            }
            if (mb_strlen($title) > 255) {
                $title = mb_substr($title, 0, 255);
            }

            $userId = (string)$row['user_id'];
            $joining = br_user_joining_date($this->conn, $userId);
            if ($joining !== null && $endDate < $joining) {
                $this->sendJsonResponse(400, "Leave ends before joining date ({$joining}).");
                return;
            }
            if ($joining !== null && $startDate < $joining) {
                $startDate = $joining;
            }

            if (br_leave_has_overlap($this->conn, $userId, $startDate, $endDate, $id)) {
                $this->sendJsonResponse(409, 'Overlapping leave already exists for these dates.');
                return;
            }

            $tz = new DateTimeZone('Asia/Kolkata');
            $startDt = DateTime::createFromFormat('Y-m-d', $startDate, $tz);
            $endDt = DateTime::createFromFormat('Y-m-d', $endDate, $tz);
            if (!$startDt || !$endDt) {
                $this->sendJsonResponse(400, 'Invalid date format');
                return;
            }
            $daysCount = (float)($startDt->diff($endDt)->days + 1);
            $adminId = (string)($decoded->user_id ?? '');
            $adminNote = trim((string)($row['admin_note'] ?? ''));
            if ($adminNote === '') {
                $adminNote = 'Official Leave updated by admin';
            }

            $hasHoursCol = $this->ensureHoursPerDayColumn();
            if ($hasHoursCol) {
                $upd = $this->conn->prepare(
                    "UPDATE leave_requests
                     SET start_date = ?, end_date = ?, days_count = ?, hours_per_day = ?, reason = ?,
                         reviewed_by = ?, reviewed_at = NOW(), admin_note = ?,
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $upd->execute([
                    $startDate,
                    $endDate,
                    $daysCount,
                    $hoursPerDay,
                    $title,
                    $adminId !== '' ? $adminId : null,
                    $adminNote,
                    $id,
                ]);
            } else {
                $upd = $this->conn->prepare(
                    "UPDATE leave_requests
                     SET start_date = ?, end_date = ?, days_count = ?, reason = ?,
                         reviewed_by = ?, reviewed_at = NOW(), admin_note = ?,
                         updated_at = NOW()
                     WHERE id = ?"
                );
                $upd->execute([
                    $startDate,
                    $endDate,
                    $daysCount,
                    $title,
                    $adminId !== '' ? $adminId : null,
                    $adminNote,
                    $id,
                ]);
            }

            $fetch = $this->conn->prepare($this->selectSql() . ' WHERE lr.id = ? LIMIT 1');
            $fetch->execute([$id]);
            $out = $fetch->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(
                200,
                'Official Leave updated',
                $out ? $this->formatRequestRow($out) : null
            );
        } catch (Throwable $e) {
            error_log('adminUpdateOfficialLeave: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to update Official Leave');
        }
    }

    /**
     * Admin: cancel Official Leave grant(s) (removes credited hours from attendance views).
     *
     * @param array<string,mixed> $payload
     */
    public function adminDeleteOfficialLeave($payload)
    {
        try {
            $decoded = $this->requireAuth();
            if (!$decoded || !$this->ensureLeaveReady()) {
                return;
            }
            if (!$this->requireAdmin($decoded)) {
                return;
            }

            $ids = [];
            if (isset($payload['ids']) && is_array($payload['ids'])) {
                foreach ($payload['ids'] as $raw) {
                    $n = (int)$raw;
                    if ($n > 0) {
                        $ids[$n] = true;
                    }
                }
            }
            $single = isset($payload['id']) ? (int)$payload['id'] : 0;
            if ($single > 0) {
                $ids[$single] = true;
            }
            $ids = array_keys($ids);
            if ($ids === []) {
                $this->sendJsonResponse(400, 'id or ids is required');
                return;
            }

            $adminId = (string)($decoded->user_id ?? '');
            $note = trim((string)($payload['admin_note'] ?? ''));
            if ($note === '') {
                $note = 'Official Leave cancelled by admin';
            }
            if (mb_strlen($note) > 500) {
                $note = mb_substr($note, 0, 500);
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $check = $this->conn->prepare(
                "SELECT lr.id, lt.code AS leave_type_code, lr.status
                 FROM leave_requests lr
                 LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
                 WHERE lr.id IN ({$placeholders})"
            );
            $check->execute($ids);
            $rows = $check->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) === 0) {
                $this->sendJsonResponse(404, 'Official Leave not found');
                return;
            }

            $cancelled = 0;
            $skipped = [];
            $upd = $this->conn->prepare(
                "UPDATE leave_requests
                 SET status = 'cancelled', reviewed_by = ?, reviewed_at = NOW(),
                     admin_note = ?, updated_at = NOW()
                 WHERE id = ? AND status IN ('approved', 'pending')"
            );

            foreach ($rows as $row) {
                $rid = (int)$row['id'];
                if (strtolower((string)($row['leave_type_code'] ?? '')) !== 'corporate') {
                    $skipped[] = ['id' => $rid, 'reason' => 'not_official'];
                    continue;
                }
                if (!in_array((string)$row['status'], ['approved', 'pending'], true)) {
                    $skipped[] = ['id' => $rid, 'reason' => 'already_' . (string)$row['status']];
                    continue;
                }
                $upd->execute([
                    $adminId !== '' ? $adminId : null,
                    $note,
                    $rid,
                ]);
                if ($upd->rowCount() > 0) {
                    $cancelled += 1;
                } else {
                    $skipped[] = ['id' => $rid, 'reason' => 'unchanged'];
                }
            }

            $this->sendJsonResponse(200, 'Official Leave cancelled', [
                'cancelled' => $cancelled,
                'skipped' => $skipped,
            ]);
        } catch (Throwable $e) {
            error_log('adminDeleteOfficialLeave: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to cancel Official Leave');
        }
    }

    /**
     * Soft-delete (or hard-delete) forgot-checkout admin hours rows for the date range.
     * Why: Converting Meelad Nabi-style admin entries into Official Leave must clear the red admin cards.
     *
     * @param list<string> $userIds
     */
    private function removeAdminHoursEntriesForUsers(
        array $userIds,
        string $startDate,
        string $endDate,
        string $adminId
    ): int {
        if ($userIds === []) {
            return 0;
        }
        $hasDeletedAt = false;
        try {
            $c = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'deleted_at'");
            $hasDeletedAt = (bool)($c && $c->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $hasDeletedAt = false;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $params = array_merge($userIds, [$startDate, $endDate]);
        $live = $hasDeletedAt ? ' AND deleted_at IS NULL' : '';
        $sel = $this->conn->prepare(
            "SELECT id FROM work_submissions
             WHERE user_id IN ({$placeholders})
               AND submission_date BETWEEN ? AND ?
               AND notes LIKE '%[ADMIN HOURS ENTRY%'
               {$live}"
        );
        $sel->execute($params);
        $ids = $sel->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($ids === []) {
            return 0;
        }

        $removed = 0;
        if ($hasDeletedAt) {
            require_once __DIR__ . '/../recycle_bin/RecycleBinService.php';
            $rb = new RecycleBinService($this->conn);
            foreach ($ids as $id) {
                try {
                    $rb->softDelete('work_submission', (string)$id, $adminId !== '' ? $adminId : 'system');
                    $removed += 1;
                } catch (Throwable $e) {
                    if (stripos($e->getMessage(), 'already in the recycle bin') !== false) {
                        $removed += 1;
                        continue;
                    }
                    // Fallback: mark deleted_at without recycle bin row
                    try {
                        $upd = $this->conn->prepare(
                            'UPDATE work_submissions SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL'
                        );
                        $upd->execute([$adminId !== '' ? $adminId : null, $id]);
                        if ($upd->rowCount() > 0) {
                            $removed += 1;
                        }
                    } catch (Throwable $e2) {
                        error_log('removeAdminHoursEntriesForUsers soft: ' . $e2->getMessage());
                    }
                }
            }
            return $removed;
        }

        $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
        $del = $this->conn->prepare("DELETE FROM work_submissions WHERE id IN ({$idPlaceholders})");
        $del->execute(array_values($ids));
        return (int)$del->rowCount();
    }

    /**
     * Ensure leave_requests.hours_per_day exists (migration 099).
     */
    private function ensureHoursPerDayColumn(): bool
    {
        if (br_leave_has_hours_per_day_col($this->conn)) {
            return true;
        }
        try {
            $this->conn->exec(
                'ALTER TABLE leave_requests ADD COLUMN hours_per_day DECIMAL(5,2) NULL DEFAULT NULL AFTER days_count'
            );
            return br_leave_has_hours_per_day_col($this->conn, true);
        } catch (Throwable $e) {
            error_log('ensureHoursPerDayColumn: ' . $e->getMessage());
            return br_leave_has_hours_per_day_col($this->conn, true);
        }
    }

    /**
     * Ensure corporate leave type exists (migration 098 may not have run yet).
     *
     * @return array{id:int,code:string,name:string,monthly_quota:float}|null
     */
    private function ensureCorporateLeaveType(): ?array
    {
        try {
            $stmt = $this->conn->prepare(
                "SELECT id, code, name, monthly_quota, is_active FROM leave_types WHERE code = 'corporate' LIMIT 1"
            );
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!(int)($row['is_active'] ?? 1)) {
                    $this->conn->prepare(
                        "UPDATE leave_types SET is_active = 1, name = 'Official Leave', monthly_quota = 0 WHERE code = 'corporate'"
                    )->execute();
                }
                return [
                    'id' => (int)$row['id'],
                    'code' => 'corporate',
                    'name' => 'Official Leave',
                    'monthly_quota' => 0.0,
                ];
            }
            $this->conn->prepare(
                "INSERT INTO leave_types (code, name, monthly_quota, is_active) VALUES ('corporate', 'Official Leave', 0.00, 1)"
            )->execute();
            $id = (int)$this->conn->lastInsertId();
            if ($id <= 0) {
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $id = $row ? (int)$row['id'] : 0;
            }
            return $id > 0
                ? ['id' => $id, 'code' => 'corporate', 'name' => 'Official Leave', 'monthly_quota' => 0.0]
                : null;
        } catch (Throwable $e) {
            error_log('ensureCorporateLeaveType: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function listActiveEmployeeIds(): array
    {
        $hasDeleted = false;
        $hasActive = false;
        try {
            $c = $this->conn->query("SHOW COLUMNS FROM users LIKE 'deleted_at'");
            $hasDeleted = (bool)($c && $c->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $hasDeleted = false;
        }
        try {
            $c = $this->conn->query("SHOW COLUMNS FROM users LIKE 'account_active'");
            $hasActive = (bool)($c && $c->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $hasActive = false;
        }

        $sql = 'SELECT id FROM users WHERE 1=1';
        if ($hasDeleted) {
            $sql .= ' AND deleted_at IS NULL';
        }
        if ($hasActive) {
            $sql .= ' AND COALESCE(account_active, 1) = 1';
        }
        $sql .= ' ORDER BY username ASC';
        $stmt = $this->conn->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Lightweight status for a user/date — used by admin hours + daily work UI.
     */
    public function attendanceStatus()
    {
        $decoded = $this->requireAuth();
        if (!$decoded) {
            return;
        }
        $userId = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : (string)$decoded->user_id;
        $date = isset($_GET['date']) ? trim((string)$_GET['date']) : br_server_today();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->sendJsonResponse(400, 'Invalid date');
            return;
        }
        if ($userId !== (string)$decoded->user_id && !$this->requireAdmin($decoded)) {
            return;
        }

        $joining = br_user_joining_date($this->conn, $userId);
        $leave = br_approved_leave_on_date($this->conn, $userId, $date);
        $gate = br_assert_attendance_allowed($this->conn, $userId, $date, 'attendance');

        require_once __DIR__ . '/../../utils/checkin_policy.php';
        $policy = br_checkin_policy_status($this->conn, $userId, $date);

        $this->sendJsonResponse(200, 'OK', array_merge([
            'user_id' => $userId,
            'date' => $date,
            'joining_date' => $joining,
            'allowed' => !empty($gate['ok']),
            'reason' => $gate['reason'] ?? null,
            'message' => $gate['message'] ?? null,
            'on_leave' => $leave ? true : false,
            'leave' => $leave,
            'before_joining' => ($joining !== null && $date < $joining),
        ], $policy));
    }
}
