<?php
/**
 * Why: Admins review team weekly reports; workforce members (incl. CODO testers)
 * read their own history. Client testers are blocked.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/weekly_report.php';
require_once __DIR__ . '/../../utils/work_period.php';
require_once __DIR__ . '/../../utils/workforce_access.php';

class WeeklyReportsListController extends BaseAPI
{
    /** Matches the 16 recent weeks the frontend week picker renders. */
    private const WEEK_COUNT_WINDOW = 16;

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

        if (!br_require_workforce($this, $this->conn, $decoded)) {
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

        $today = br_server_today();
        $current = br_monday_saturday_week_bounds($today);
        $countsFrom = date('Y-m-d', strtotime($current['week_start'] . ' -' . (self::WEEK_COUNT_WINDOW - 1) . ' weeks'));

        try {
            $result = br_list_weekly_reports($this->conn, $opts);
            $weekCounts = br_weekly_report_week_counts(
                $this->conn,
                $scope === 'mine' ? $userId : null,
                $countsFrom
            );
        } catch (Throwable $e) {
            error_log('WeeklyReportsListController: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to load weekly reports.');
            return;
        }

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
            'week_counts' => (object)$weekCounts,
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
