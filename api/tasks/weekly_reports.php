<?php
/**
 * Why: Admins review team weekly reports; developers read their own history.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/weekly_report.php';
require_once __DIR__ . '/../../utils/work_period.php';

class WeeklyReportsListController extends BaseAPI
{
    public function handle(): void
    {
        $decoded = $this->validateToken();
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Authentication failed');
            return;
        }

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        $role = strtolower((string)($decoded->role ?? ''));
        if ($role === 'tester') {
            $this->sendJsonResponse(403, 'Weekly reports are available to admins and developers.');
            return;
        }

        br_ensure_weekly_reports_schema($this->conn);

        $userId = (string)$decoded->user_id;
        $scope = strtolower(trim((string)($_GET['scope'] ?? '')));
        if ($scope !== 'team' && $scope !== 'mine') {
            $scope = $this->canViewTeam($decoded) ? 'team' : 'mine';
        }

        if ($scope === 'team' && !$this->canViewTeam($decoded)) {
            $this->sendJsonResponse(403, 'You can only view your own weekly reports.');
            return;
        }

        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $weekStart = substr(trim((string)($_GET['week_start'] ?? '')), 0, 10);
        $q = trim((string)($_GET['q'] ?? ''));

        $opts = [
            'page' => $page,
            'limit' => $limit,
            'week_start' => $weekStart,
            'q' => $q,
        ];
        if ($scope === 'mine') {
            $opts['user_id'] = $userId;
        }

        try {
            $result = br_list_weekly_reports($this->conn, $opts);
        } catch (Throwable $e) {
            error_log('WeeklyReportsListController: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to load weekly reports.');
            return;
        }

        $today = br_server_today();
        $current = br_monday_saturday_week_bounds($today);

        $this->sendJsonResponse(200, 'OK', [
            'scope' => $scope,
            'can_view_team' => $this->canViewTeam($decoded),
            'current_week_start' => $current['week_start'],
            'current_week_end' => $current['week_end'],
            'current_week_label' => br_weekly_report_week_label($current['week_start'], $current['week_end']),
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
            'week_start' => $result['week_start'],
            'week_end' => $result['week_end'],
            'week_label' => $result['week_label'],
        ]);
    }

    private function canViewTeam($decoded): bool
    {
        $role = strtolower((string)($decoded->role ?? ''));
        if ($role === 'admin') {
            return true;
        }
        $pm = PermissionManager::getInstance();
        $userId = (string)($decoded->user_id ?? '');
        return $pm->hasPermissionOrAdmin($userId, 'DAILY_UPDATE_VIEW', $decoded->role ?? null)
            || $pm->hasPermissionOrAdmin($userId, 'USERS_VIEW', $decoded->role ?? null);
    }
}

$controller = new WeeklyReportsListController();
$controller->handle();
