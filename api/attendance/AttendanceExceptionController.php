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
            $this->sendJsonResponse(200, $result['message'], [
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
            ]);
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

        $this->sendJsonResponse(200, $msg, [
            'dates' => $dates,
            'saved_count' => count($savedExceptions),
            'errors' => $errors,
            'exception' => count($savedExceptions) === 1 ? $savedExceptions[0] : null,
            'exceptions' => $savedExceptions,
            'recalc' => $recalc,
            'policy' => br_checkin_policy_status($this->conn, $userId, $today),
        ]);
    }
}
