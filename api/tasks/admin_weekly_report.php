<?php
/**
 * Why: Admins can edit or delete any filed weekly report from the team view.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/weekly_report.php';

class AdminWeeklyReportController extends BaseAPI
{
    public function handle(): void
    {
        $decoded = $this->validateToken();
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Authentication failed');
            return;
        }

        if (!$this->isAdmin($decoded)) {
            $this->sendJsonResponse(403, 'Only admins can manage weekly reports.');
            return;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'PUT' || $method === 'PATCH') {
            $this->updateReport();
            return;
        }
        if ($method === 'DELETE') {
            $this->deleteReport();
            return;
        }

        $this->sendJsonResponse(405, 'Method not allowed');
    }

    private function isAdmin($decoded): bool
    {
        return strtolower((string)($decoded->role ?? '')) === 'admin';
    }

    private function resolveReportId(): ?string
    {
        $payload = $this->getRequestData() ?: [];
        $id = trim((string)($_GET['id'] ?? ($payload['id'] ?? '')));
        return $id !== '' ? $id : null;
    }

    private function updateReport(): void
    {
        $id = $this->resolveReportId();
        if ($id === null) {
            $this->sendJsonResponse(400, 'Report id is required.');
            return;
        }

        $payload = $this->getRequestData() ?: [];
        if (!is_array($payload)) {
            $payload = [];
        }

        try {
            $saved = br_admin_update_weekly_report($this->conn, $id, $payload);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(400, $e->getMessage());
            return;
        } catch (Throwable $e) {
            error_log('AdminWeeklyReportController::updateReport: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to update weekly report.');
            return;
        }

        $this->sendJsonResponse(200, 'Weekly report updated', ['report' => $saved]);
    }

    private function deleteReport(): void
    {
        $id = $this->resolveReportId();
        if ($id === null) {
            $this->sendJsonResponse(400, 'Report id is required.');
            return;
        }

        try {
            $deleted = br_admin_delete_weekly_report($this->conn, $id);
        } catch (Throwable $e) {
            error_log('AdminWeeklyReportController::deleteReport: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to delete weekly report.');
            return;
        }

        if (!$deleted) {
            $this->sendJsonResponse(404, 'Weekly report not found.');
            return;
        }

        $this->sendJsonResponse(200, 'Weekly report deleted');
    }
}

$controller = new AdminWeeklyReportController();
$controller->handle();
