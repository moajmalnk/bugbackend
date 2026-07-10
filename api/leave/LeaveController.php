<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../NotificationManager.php';
require_once __DIR__ . '/../../utils/work_period.php';
require_once __DIR__ . '/../../utils/leave_attendance.php';

class LeaveController extends BaseAPI
{
    private function requireAuth()
    {
        $decoded = $this->validateToken();
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Authentication failed');
            return null;
        }
        return $decoded;
    }

    private function requireAdmin($decoded): bool
    {
        if (strtolower((string)($decoded->role ?? '')) !== 'admin') {
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
            'reason' => $row['reason'] ?? null,
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

        $joining = br_user_joining_date($this->conn, $userId);
        if ($joining !== null && $startDate < $joining) {
            $this->sendJsonResponse(400, "Leave cannot start before joining date ({$joining}).");
            return;
        }

        $days = br_leave_calendar_days($startDate, $endDate);
        if ($days <= 0) {
            $this->sendJsonResponse(400, 'Invalid leave duration');
            return;
        }

        if (br_leave_has_overlap($this->conn, $userId, $startDate, $endDate)) {
            $this->sendJsonResponse(409, 'Overlapping pending or approved leave already exists for these dates.');
            return;
        }

        // Balance check per month spanned by the request (against approved usage only)
        $tz = new DateTimeZone('Asia/Kolkata');
        $cursor = DateTime::createFromFormat('Y-m-d', $startDate, $tz);
        $endDt = DateTime::createFromFormat('Y-m-d', $endDate, $tz);
        $monthsChecked = [];
        while ($cursor && $endDt && $cursor <= $endDt) {
            $ym = $cursor->format('Y-m');
            if (!isset($monthsChecked[$ym])) {
                $needed = br_leave_days_in_month($startDate, $endDate, $ym);
                $used = br_leave_used_days_in_month($this->conn, $userId, $leaveTypeId, $ym);
                $quota = (float)$type['monthly_quota'];
                if ($used + $needed > $quota + 0.001) {
                    $remaining = max(0.0, $quota - $used);
                    $this->sendJsonResponse(
                        400,
                        "Insufficient {$type['name']} balance for {$ym}. Remaining: {$remaining}, requested in month: {$needed}."
                    );
                    return;
                }
                $monthsChecked[$ym] = true;
            }
            $cursor->modify('+1 day');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO leave_requests
             (user_id, leave_type_id, start_date, end_date, days_count, reason, status)
             VALUES (?, ?, ?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([$userId, $leaveTypeId, $startDate, $endDate, $days, $reason !== '' ? $reason : null]);
        $id = (int)$this->conn->lastInsertId();

        try {
            $nm = new NotificationManager();
            $nm->notifyLeaveRequested($id, $userId, (string)$type['name'], $startDate, $endDate);
        } catch (Exception $e) {
            error_log('notifyLeaveRequested: ' . $e->getMessage());
        }

        $fetch = $this->conn->prepare($this->selectSql() . ' WHERE lr.id = ? LIMIT 1');
        $fetch->execute([$id]);
        $row = $fetch->fetch(PDO::FETCH_ASSOC);
        $this->sendJsonResponse(201, 'Leave request submitted', $row ? $this->formatRequestRow($row) : ['id' => $id]);
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
            // Re-check balance
            $typeStmt = $this->conn->prepare('SELECT id, name, monthly_quota FROM leave_types WHERE id = ? LIMIT 1');
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

        try {
            $nm = new NotificationManager();
            $nm->notifyLeaveReviewed(
                $id,
                (string)$row['user_id'],
                $newStatus,
                (string)$row['start_date'],
                (string)$row['end_date'],
                $adminNote
            );
        } catch (Exception $e) {
            error_log('notifyLeaveReviewed: ' . $e->getMessage());
        }

        $fetch = $this->conn->prepare($this->selectSql() . ' WHERE lr.id = ? LIMIT 1');
        $fetch->execute([$id]);
        $out = $fetch->fetch(PDO::FETCH_ASSOC);
        $this->sendJsonResponse(200, 'Leave request ' . $newStatus, $out ? $this->formatRequestRow($out) : null);
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

        $this->sendJsonResponse(200, 'OK', [
            'user_id' => $userId,
            'date' => $date,
            'joining_date' => $joining,
            'allowed' => !empty($gate['ok']),
            'reason' => $gate['reason'] ?? null,
            'message' => $gate['message'] ?? null,
            'on_leave' => $leave ? true : false,
            'leave' => $leave,
            'before_joining' => ($joining !== null && $date < $joining),
        ]);
    }
}
