<?php
/**
 * Why: Collect a short Saturday weekly report before checkout.
 * GET returns the current week report + daily-task suggestions.
 * POST upserts the report (Saturday only). Notifications fire later with checkout.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/weekly_report.php';
require_once __DIR__ . '/../../utils/work_period.php';

class WeeklyReportController extends BaseAPI
{
    public function handle(): void
    {
        $decoded = $this->validateToken();
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Authentication failed');
            return;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'GET') {
            $this->getReport($decoded);
            return;
        }
        if ($method === 'POST') {
            $this->saveReport($decoded);
            return;
        }

        $this->sendJsonResponse(405, 'Method not allowed');
    }

    private function getReport($decoded): void
    {
        $userId = (string)$decoded->user_id;
        br_ensure_weekly_reports_schema($this->conn);

        $requested = substr(trim((string)($_GET['week_start'] ?? $_GET['date'] ?? br_server_today())), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested)) {
            $requested = br_server_today();
        }

        $bounds = br_monday_saturday_week_bounds($requested);
        $today = br_server_today();
        $reportDate = br_is_saturday_date($requested) ? $requested : (br_is_saturday_date($today) ? $today : $bounds['week_end']);
        $isSaturday = br_is_saturday_date($today) || br_is_saturday_date($requested);
        $report = br_get_weekly_report($this->conn, $userId, $bounds['week_start']);
        $suggestions = br_weekly_report_suggestions(
            $this->conn,
            $userId,
            $bounds['week_start'],
            $bounds['week_end']
        );

        $attendance = br_weekly_attendance_summary(
            $this->conn,
            $userId,
            $bounds['week_start'],
            $bounds['week_end']
        );

        $this->sendJsonResponse(200, 'OK', [
            'required' => $isSaturday && !$report,
            'is_saturday' => $isSaturday,
            'week_start' => $bounds['week_start'],
            'week_end' => $bounds['week_end'],
            'week_label' => br_weekly_report_week_label($bounds['week_start'], $bounds['week_end']),
            'report_date' => $reportDate,
            'date_label' => br_weekly_report_date_label($reportDate),
            'user_name' => br_display_user_name($this->conn, $userId, (string)($decoded->username ?? 'User')),
            'report' => $report,
            'suggestions' => $suggestions,
            'attendance' => $attendance,
            'attendance_text' => br_weekly_attendance_document_block($attendance),
        ]);
    }

    private function saveReport($decoded): void
    {
        $userId = (string)$decoded->user_id;
        $payload = $this->getRequestData() ?: [];

        try {
            $saved = br_save_weekly_report($this->conn, $userId, is_array($payload) ? $payload : []);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(400, $e->getMessage());
            return;
        } catch (Throwable $e) {
            error_log('WeeklyReportController::saveReport: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to save weekly report.');
            return;
        }

        $this->sendJsonResponse(200, 'Weekly report saved', [
            'required' => false,
            'is_saturday' => true,
            'week_start' => $saved['week_start'],
            'week_end' => $saved['week_end'],
            'week_label' => br_weekly_report_week_label((string)$saved['week_start'], (string)$saved['week_end']),
            'report_date' => $saved['report_date'],
            'date_label' => br_weekly_report_date_label((string)$saved['report_date']),
            'user_name' => br_display_user_name($this->conn, $userId, (string)($decoded->username ?? 'User')),
            'report' => $saved,
        ]);
    }
}

$controller = new WeeklyReportController();
$controller->handle();
