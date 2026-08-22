<?php
/**
 * Lightweight admin sidebar counts (permission-gated).
 * GET /api/admin/sidebar_counts.php
 *
 * Why: One round-trip for nav badges — avoid loading full Users/OT/Leave lists
 * on every sidebar render.
 */
require_once __DIR__ . '/../BaseAPI.php';

class AdminSidebarCountsController extends BaseAPI
{
    /**
     * @param string $sql
     * @param array<int, mixed> $params
     */
    private function countOrZero(string $sql, array $params = []): int
    {
        try {
            $stmt = $params === [] ? $this->conn->query($sql) : $this->conn->prepare($sql);
            if ($params !== []) {
                $stmt->execute($params);
            }
            if (!$stmt) {
                return 0;
            }
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('AdminSidebarCountsController count: ' . $e->getMessage());
            return 0;
        }
    }

    public function get(): void
    {
        try {
            $decoded = $this->validateToken();
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, $e->getMessage() ?: 'Unauthorized');
            return;
        }

        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Unauthorized');
            return;
        }

        $pm = PermissionManager::getInstance();
        $userId = (string) $decoded->user_id;
        $legacyRole = $decoded->role ?? null;
        $can = static function (string $key) use ($pm, $userId, $legacyRole): bool {
            return $pm->hasPermissionOrAdmin($userId, $key, $legacyRole);
        };

        $counts = [
            'users' => 0,
            'clients' => 0,
            'ot' => 0,
            'leave' => 0,
            'attendance' => 0,
            'whatsapp' => 0,
        ];

        if ($can('USERS_VIEW') && $this->dbTableExists('users')) {
            $counts['users'] = $this->countOrZero('SELECT COUNT(*) FROM users');
        }

        if ($can('CLIENTS_VIEW') && $this->dbTableExists('clients')) {
            $counts['clients'] = $this->countOrZero('SELECT COUNT(*) FROM clients');
        }

        if (
            $can('OVERTIME_MANAGE')
            && $this->dbTableExists('work_submissions')
            && $this->dbColumnExists('work_submissions', 'extra_hours_approval_status')
        ) {
            $counts['ot'] = $this->countOrZero(
                "SELECT COUNT(*) FROM work_submissions
                 WHERE extra_hours_approval_status = 'pending'"
            );
        }

        if ($can('LEAVE_MANAGE') && $this->dbTableExists('leave_requests')) {
            $counts['leave'] = $this->countOrZero(
                "SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'"
            );
        }

        if ($can('ATTENDANCE_MANAGE') && $this->dbTableExists('attendance_wfh_requests')) {
            $counts['attendance'] = $this->countOrZero(
                "SELECT COUNT(*) FROM attendance_wfh_requests WHERE status = 'pending'"
            );
        }

        $showWhatsApp = $can('MESSAGING_CREATE')
            && strtolower(trim((string) $legacyRole)) === 'admin';
        if ($showWhatsApp && $this->dbTableExists('users') && $this->dbColumnExists('users', 'phone')) {
            $counts['whatsapp'] = $this->countOrZero(
                "SELECT COUNT(*) FROM users
                 WHERE phone IS NOT NULL AND TRIM(phone) <> ''"
            );
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        $this->sendJsonResponse(200, 'OK', $counts);
    }
}
