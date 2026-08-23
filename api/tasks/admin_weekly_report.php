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
            $this->deleteReport($decoded);
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

    private function deleteReport($decoded): void
    {
        $id = $this->resolveReportId();
        if ($id === null) {
            $this->sendJsonResponse(400, 'Report id is required.');
            return;
        }

        try {
            require_once __DIR__ . '/../recycle_bin/RecycleBinService.php';
            $rb = new RecycleBinService($this->conn);
            $rb->ensureSchema('weekly_report');
            $rb->softDelete('weekly_report', $id, $decoded->user_id);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(400, $e->getMessage());
            return;
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            $lower = strtolower($msg);
            $code = (str_contains($lower, 'not found') || str_contains($lower, 'already'))
                ? 404
                : 400;
            error_log('AdminWeeklyReportController::deleteReport: ' . $msg);
            $this->sendJsonResponse($code, $msg);
            return;
        } catch (Throwable $e) {
            error_log('AdminWeeklyReportController::deleteReport: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to delete weekly report.');
            return;
        }

        $this->sendJsonResponse(200, 'Weekly report moved to recycle bin');
    }
}

$controller = new AdminWeeklyReportController();
$controller->handle();
