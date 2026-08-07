<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/checkin_policy.php';
require_once __DIR__ . '/../../utils/work_period.php';

/**
 * Why: Same-day WFH requests need employee submit + admin approve/reject,
 * separate from admin-granted attendance_day_exceptions.
 */
class WfhRequestController extends BaseAPI
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

    private function isAdmin($decoded): bool
    {
        $pm = PermissionManager::getInstance();
        return $pm->hasPermissionOrAdmin(
            $decoded->user_id ?? '',
            'ATTENDANCE_MANAGE',
            $decoded->role ?? null
        );
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

    private function notifyAdminsOfRequest(string $userId, string $date, ?string $userNote): void
    {
        $username = $this->usernameFor($userId);

        try {
            require_once __DIR__ . '/../NotificationManager.php';
            $nm = NotificationManager::getInstance();
            $nm->notifyWfhRequest($userId, $date, $userNote);
        } catch (Throwable $e) {
            error_log('WfhRequestController notify push: ' . $e->getMessage());
        }

        try {
            $adminStmt = $this->conn->prepare(
                "SELECT email, phone FROM users
                 WHERE account_active = 1 AND (role = 'admin' OR role_id = 1)
                   AND (email IS NOT NULL OR phone IS NOT NULL)"
            );
            $adminStmt->execute();
            $adminRows = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
            $adminEmails = array_values(array_filter(array_column($adminRows, 'email')));
            $adminPhones = array_values(array_filter(array_column($adminRows, 'phone')));

            require_once __DIR__ . '/../../utils/email.php';
            foreach ($adminEmails as $adminEmail) {
                sendWfhRequestNotificationEmail($adminEmail, $username, $date, $userNote);
            }

            require_once __DIR__ . '/../../utils/whatsapp.php';
            foreach ($adminPhones as $adminPhone) {
                sendWfhRequestNotificationWhatsApp($adminPhone, $username, $date, $userNote);
            }
        } catch (Throwable $e) {
            error_log('WfhRequestController notify mail/wa: ' . $e->getMessage());
        }
    }

    private function notifyRequesterOfDecision(
        string $userId,
        string $date,
        string $status,
        ?string $adminNote
    ): void {
        try {
            require_once __DIR__ . '/../NotificationManager.php';
            $nm = NotificationManager::getInstance();
            $nm->notifyWfhRequestDecision($userId, $date, $status, $adminNote);
        } catch (Throwable $e) {
            error_log('WfhRequestController decision push: ' . $e->getMessage());
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
                sendWfhRequestDecisionEmail($email, $username, $date, $status, $adminNote);
            }
            if ($phone !== '') {
                require_once __DIR__ . '/../../utils/whatsapp.php';
                sendWfhRequestDecisionWhatsApp($phone, $username, $date, $status, $adminNote);
            }
        } catch (Throwable $e) {
            error_log('WfhRequestController decision mail/wa: ' . $e->getMessage());
        }
    }

    /**
     * Why: Email/WhatsApp/FCM are slow — flush JSON to the client first, then notify.
     */
    private function respondThen(callable $afterResponse, int $statusCode, string $message, $data = null): void
    {
        ignore_user_abort(true);
        if (function_exists('session_write_close')) {
            @session_write_close();
        }

        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
        }

        $response = [
            'success' => $statusCode >= 200 && $statusCode < 300,
            'message' => $message,
        ];
        if ($data !== null) {
            $response['data'] = $data;
        }

        echo json_encode($response);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            if (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
        }

        try {
            $afterResponse();
        } catch (Throwable $e) {
            error_log('WfhRequestController deferred work: ' . $e->getMessage());
        }
    }

    /**
     * GET — admin: pending list; user: own request for ?date=
     */
    public function list()
    {
        $decoded = $this->requireAuth();
        if (!$decoded) {
            return;
        }

        br_ensure_checkin_policy_schema($this->conn);
        $actorId = (string)$decoded->user_id;

        if ($this->isAdmin($decoded) && (isset($_GET['pending']) || ($_GET['scope'] ?? '') === 'pending')) {
            $pending = br_list_pending_wfh_requests($this->conn, 100);
            $this->sendJsonResponse(200, 'OK', [
                'today' => br_server_today(),
                'pending' => $pending,
                'pending_count' => count($pending),
            ]);
            return;
        }

        // Admin: full WFH request history for one user (includes rejected).
        if (
            $this->isAdmin($decoded)
            && !empty($_GET['user_id'])
            && (isset($_GET['history']) || ($_GET['scope'] ?? '') === 'history')
        ) {
            $userId = trim((string)$_GET['user_id']);
            $statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : null;
            if ($statusFilter === '' || $statusFilter === 'all') {
                $statusFilter = null;
            }
            $requests = br_list_wfh_requests_for_user($this->conn, $userId, $statusFilter, 100);
            $rejected = array_values(array_filter(
                $requests,
                static fn($r) => ($r['status'] ?? '') === 'rejected'
            ));
            $this->sendJsonResponse(200, 'OK', [
                'today' => br_server_today(),
                'user_id' => $userId,
                'requests' => $requests,
                'rejected' => $rejected,
                'request_count' => count($requests),
                'rejected_count' => count($rejected),
            ]);
            return;
        }

        $date = trim((string)($_GET['date'] ?? br_server_today()));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->sendJsonResponse(400, 'Invalid date');
            return;
        }

        $userId = $actorId;
        if ($this->isAdmin($decoded) && !empty($_GET['user_id'])) {
            $userId = trim((string)$_GET['user_id']);
        }

        $request = br_wfh_request_for_day($this->conn, $userId, $date);
        $policy = br_checkin_policy_status($this->conn, $userId, $date);

        $this->sendJsonResponse(200, 'OK', [
            'user_id' => $userId,
            'date' => $date,
            'request' => $request,
            'wfh_request_status' => $policy['wfh_request_status'] ?? 'none',
            'can_request_wfh' => !empty($policy['can_request_wfh']),
            'allow_wfh_today' => !empty($policy['allow_wfh_today']),
            'office_only' => !empty($policy['office_only']),
        ]);
    }

    /**
     * POST — create request, or admin approve/reject/delete.
     */
    public function save()
    {
        $decoded = $this->requireAuth();
        if (!$decoded) {
            return;
        }

        $input = $this->getRequestData() ?: [];
        $action = strtolower(trim((string)($input['action'] ?? 'request')));
        $actorId = (string)$decoded->user_id;

        if ($action === 'delete') {
            if (!$this->isAdmin($decoded)) {
                $this->sendJsonResponse(403, 'Only admins can delete WFH requests');
                return;
            }
            $userId = trim((string)($input['user_id'] ?? ''));
            $date = trim((string)($input['date'] ?? $input['request_date'] ?? ''));
            if ($userId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->sendJsonResponse(400, 'user_id and date are required');
                return;
            }
            $result = br_delete_wfh_request($this->conn, $userId, $date);
            if (!$result['ok']) {
                $this->sendJsonResponse(400, $result['message'] ?? 'Delete failed', $result);
                return;
            }
            $this->sendJsonResponse(200, $result['message'] ?? 'WFH request deleted.', [
                'request' => null,
                'deleted' => $result['deleted'] ?? null,
                'exception' => $result['exception'] ?? null,
                'policy' => br_checkin_policy_status($this->conn, $userId, $date),
            ]);
            return;
        }

        if (in_array($action, ['approve', 'reject'], true)) {
            if (!$this->isAdmin($decoded)) {
                $this->sendJsonResponse(403, 'Only admins can review WFH requests');
                return;
            }
            $userId = trim((string)($input['user_id'] ?? ''));
            $date = trim((string)($input['date'] ?? $input['request_date'] ?? ''));
            if ($userId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->sendJsonResponse(400, 'user_id and date are required');
                return;
            }
            $adminNote = isset($input['admin_note']) ? (string)$input['admin_note'] : null;
            $result = br_review_wfh_request($this->conn, $userId, $date, $action, $actorId, $adminNote);
            if (!$result['ok']) {
                $this->sendJsonResponse(400, $result['message'] ?? 'Review failed', $result);
                return;
            }
            $status = $result['request']['status'] ?? ($action === 'approve' ? 'approved' : 'rejected');
            $decisionNote = $result['request']['admin_note'] ?? $adminNote;
            $payload = [
                'request' => $result['request'],
                'exception' => $result['exception'],
                'policy' => br_checkin_policy_status($this->conn, $userId, $date),
            ];
            $this->respondThen(
                function () use ($userId, $date, $status, $decisionNote) {
                    $this->notifyRequesterOfDecision($userId, $date, $status, $decisionNote);
                },
                200,
                $result['message'] ?? 'OK',
                $payload
            );
            return;
        }

        // Create / refresh pending request
        $date = trim((string)($input['date'] ?? $input['request_date'] ?? br_server_today()));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->sendJsonResponse(400, 'Invalid date');
            return;
        }

        $userId = $actorId;
        if ($this->isAdmin($decoded) && !empty($input['user_id'])) {
            $userId = trim((string)$input['user_id']);
        } elseif (!$this->isAdmin($decoded) && !empty($input['user_id']) && (string)$input['user_id'] !== $actorId) {
            $this->sendJsonResponse(403, 'You can only request WFH for yourself');
            return;
        }

        $userNote = isset($input['user_note']) ? (string)$input['user_note'] : (isset($input['note']) ? (string)$input['note'] : null);
        $result = br_upsert_wfh_request($this->conn, $userId, $date, $userNote);
        if (!$result['ok']) {
            $this->sendJsonResponse(400, $result['message'] ?? 'Request failed', [
                'request' => $result['request'] ?? null,
            ]);
            return;
        }

        $noteForNotify = $result['request']['user_note'] ?? $userNote;
        $payload = [
            'request' => $result['request'],
            'policy' => br_checkin_policy_status($this->conn, $userId, $date),
        ];
        $this->respondThen(
            function () use ($userId, $date, $noteForNotify) {
                $this->notifyAdminsOfRequest($userId, $date, $noteForNotify);
            },
            200,
            $result['message'] ?? 'OK',
            $payload
        );
    }
}
