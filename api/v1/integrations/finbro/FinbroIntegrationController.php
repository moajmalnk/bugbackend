<?php
/**
 * Finbro integration producer endpoints (M2M pull-only).
 *
 * Why: Finbro is SoT for rates/payroll; BugRicer exposes hours + account status only.
 */

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../utils/finbro_integration.php';

class FinbroIntegrationController
{
    /** @var PDO */
    private $conn;

    public function __construct()
    {
        $this->conn = Database::getInstance()->getConnection();
    }

    public function dispatch(string $route): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            br_finbro_json_response(405, ['error' => 'Method not allowed']);
        }

        br_finbro_require_auth();
        br_finbro_rate_limit_check();

        switch ($route) {
            case 'users/status':
                $this->usersStatus();
                break;
            case 'hours':
                $this->hoursByMonth();
                break;
            case 'hours/by-user':
                $this->hoursByUserRange();
                break;
            default:
                br_finbro_json_response(404, ['error' => 'Not found']);
        }
    }

    /**
     * GET /v1/integrations/finbro/users/status
     */
    private function usersStatus(): void
    {
        $rows = br_finbro_load_users($this->conn, null);
        $users = [];
        foreach ($rows as $row) {
            $users[] = [
                'id' => (string)$row['id'],
                'email' => (string)($row['email'] ?? ''),
                'username' => (string)($row['username'] ?? ''),
                'name' => (string)($row['name'] ?? $row['username'] ?? ''),
                'role' => (string)($row['role'] ?? ''),
                'accountStatus' => br_finbro_account_status(
                    isset($row['account_active']) ? (int)$row['account_active'] : 1
                ),
                'updatedAt' => br_finbro_iso_utc($row['updated_at'] ?? null),
            ];
        }

        br_finbro_json_response(200, ['users' => $users]);
    }

    /**
     * GET /v1/integrations/finbro/hours?year=&month=&email=
     */
    private function hoursByMonth(): void
    {
        $yearRaw = $_GET['year'] ?? null;
        $monthRaw = $_GET['month'] ?? null;

        if ($yearRaw === null || $monthRaw === null || $yearRaw === '' || $monthRaw === '') {
            br_finbro_json_response(422, ['error' => 'year and month are required']);
        }
        if (!ctype_digit((string)$yearRaw) || !ctype_digit((string)$monthRaw)) {
            br_finbro_json_response(422, ['error' => 'year and month must be integers']);
        }

        $year = (int)$yearRaw;
        $month = (int)$monthRaw;
        $bounds = br_finbro_month_bounds($year, $month);
        if ($bounds === null) {
            br_finbro_json_response(422, ['error' => 'Invalid year or month']);
        }

        [$from, $to] = $bounds;
        $email = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
        $emailFilter = $email !== '' ? $email : null;

        $users = br_finbro_load_users($this->conn, $emailFilter);
        // Scope month scan to loaded users when email filter is set; full roster otherwise.
        $userIds = $emailFilter !== null ? br_finbro_user_ids($users) : null;
        $buckets = br_finbro_aggregate_hours_by_user($this->conn, $from, $to, $userIds);
        $members = br_finbro_build_members($users, $buckets);

        br_finbro_json_response(200, [
            'period' => ['year' => $year, 'month' => $month],
            'members' => $members,
        ]);
    }

    /**
     * GET /v1/integrations/finbro/hours/by-user?email=&from=&to=
     */
    private function hoursByUserRange(): void
    {
        $email = isset($_GET['email']) ? trim((string)$_GET['email']) : '';
        $fromRaw = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
        $toRaw = isset($_GET['to']) ? trim((string)$_GET['to']) : '';

        if ($email === '' || $fromRaw === '' || $toRaw === '') {
            br_finbro_json_response(422, ['error' => 'email, from, and to are required']);
        }

        $from = br_finbro_parse_date($fromRaw);
        $to = br_finbro_parse_date($toRaw);
        if ($from === null || $to === null) {
            br_finbro_json_response(422, ['error' => 'from and to must be valid YYYY-MM-DD dates']);
        }
        if ($from > $to) {
            br_finbro_json_response(422, ['error' => 'from must be less than or equal to to']);
        }
        if (br_finbro_range_day_count($from, $to) > 366) {
            br_finbro_json_response(422, ['error' => 'Date range must be at most 366 days']);
        }

        $users = br_finbro_load_users($this->conn, $email);
        $buckets = br_finbro_aggregate_hours_by_user(
            $this->conn,
            $from,
            $to,
            br_finbro_user_ids($users)
        );
        $members = br_finbro_build_members($users, $buckets);

        br_finbro_json_response(200, [
            'period' => ['from' => $from, 'to' => $to],
            'members' => $members,
        ]);
    }
}
