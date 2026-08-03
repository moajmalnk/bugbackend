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
        if (strtolower((string)($decoded->role ?? '')) !== 'admin') {
            $this->sendJsonResponse(403, 'Only admins can manage attendance exceptions');
            return null;
        }
        return $decoded;
    }

    /**
     * GET — list exceptions + recent late days for a user.
     */
    public function listForUser()
    {
        $decoded = $this->requireAdminAuth();
        if (!$decoded) {
            return;
        }

        $userId = isset($_GET['user_id']) ? trim((string)$_GET['user_id']) : '';
        if ($userId === '') {
            $this->sendJsonResponse(400, 'user_id is required');
            return;
        }

        br_ensure_checkin_policy_schema($this->conn);
        $today = br_server_today();

        $exceptions = [];
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, user_id, exception_date, allow_wfh, forgive_late, admin_note, created_by, created_at, updated_at
                 FROM attendance_day_exceptions
                 WHERE user_id = ? AND exception_date >= DATE_SUB(?, INTERVAL 14 DAY)
                 ORDER BY exception_date DESC
                 LIMIT 60'
            );
            $stmt->execute([$userId, $today]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $exceptions[] = [
                    'id' => (int)$row['id'],
                    'user_id' => $row['user_id'],
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
                 ORDER BY submission_date DESC
                 LIMIT 30'
            );
            $stmt->execute([$userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $lateDays[] = [
                    'id' => (int)$row['id'],
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
        ]);
    }

    /**
     * POST — upsert day exception and/or forgive late.
     * Body: user_id, date, allow_wfh?, forgive_late?, admin_note?, action?: 'save'|'forgive_late'|'clear'
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
        $date = trim((string)($input['date'] ?? $input['exception_date'] ?? ''));
        $action = strtolower(trim((string)($input['action'] ?? 'save')));
        $adminNote = isset($input['admin_note']) ? trim((string)$input['admin_note']) : null;

        if ($userId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->sendJsonResponse(400, 'user_id and date (YYYY-MM-DD) are required');
            return;
        }

        br_ensure_checkin_policy_schema($this->conn);

        if ($action === 'forgive_late') {
            $result = br_forgive_late_day(
                $this->conn,
                $userId,
                $date,
                $decoded->user_id,
                $adminNote
            );
            if (empty($result['ok'])) {
                $this->sendJsonResponse(400, $result['message'] ?? 'Failed to forgive late');
                return;
            }
            // Optionally also set allow_wfh if provided
            if (array_key_exists('allow_wfh', $input)) {
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
            $this->sendJsonResponse(200, $result['message'], [
                'exception' => br_day_exception($this->conn, $userId, $date),
                'recalc' => $result['recalc'] ?? null,
                'policy' => br_checkin_policy_status($this->conn, $userId, br_server_today()),
            ]);
            return;
        }

        if ($action === 'clear') {
            try {
                $del = $this->conn->prepare(
                    'DELETE FROM attendance_day_exceptions WHERE user_id = ? AND exception_date = ?'
                );
                $del->execute([$userId, $date]);
            } catch (Throwable $e) {
                $this->sendJsonResponse(500, 'Failed to clear exception');
                return;
            }
            $this->sendJsonResponse(200, 'Exception cleared', [
                'exception' => null,
                'policy' => br_checkin_policy_status($this->conn, $userId, br_server_today()),
            ]);
            return;
        }

        $allowWfh = array_key_exists('allow_wfh', $input) ? !empty($input['allow_wfh']) : null;
        $forgiveLate = array_key_exists('forgive_late', $input) ? !empty($input['forgive_late']) : null;

        if ($allowWfh === null && $forgiveLate === null) {
            $this->sendJsonResponse(400, 'Provide allow_wfh and/or forgive_late');
            return;
        }

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
            $this->sendJsonResponse(400, $saved['message'] ?? 'Failed to save');
            return;
        }

        // If forgive_late turned on, also clear any existing late flag that day
        $exc = $saved['exception'];
        if (!empty($exc['forgive_late'])) {
            br_forgive_late_day($this->conn, $userId, $date, $decoded->user_id, $adminNote);
            $exc = br_day_exception($this->conn, $userId, $date);
        }

        $this->sendJsonResponse(200, $saved['message'] ?? 'Saved', [
            'exception' => $exc,
            'policy' => br_checkin_policy_status($this->conn, $userId, br_server_today()),
        ]);
    }
}
