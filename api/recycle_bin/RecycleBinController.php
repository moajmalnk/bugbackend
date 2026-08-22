<?php
/**
 * Admin Recycle Bin API — list, restore, purge, bulk, stats.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/RecycleBinService.php';

class RecycleBinController extends BaseAPI
{
    private RecycleBinService $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new RecycleBinService($this->conn);
    }

    public function handleList(): void
    {
        $decoded = $this->requireRecycleBinView();
        if (!$decoded) {
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
        $filters = [
            'entity_type' => trim((string) ($_GET['entity_type'] ?? 'all')),
            'q' => trim((string) ($_GET['q'] ?? '')),
            'deleted_by' => trim((string) ($_GET['deleted_by'] ?? '')),
            'date_from' => trim((string) ($_GET['date_from'] ?? '')),
            'date_to' => trim((string) ($_GET['date_to'] ?? '')),
        ];

        try {
            $result = $this->service->list($filters, $page, $limit);
            $this->sendJsonResponse(200, 'OK', $result);
        } catch (Throwable $e) {
            error_log('RecycleBinController::handleList: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to load recycle bin.');
        }
    }

    public function handleStats(): void
    {
        if (!$this->requireRecycleBinView()) {
            return;
        }

        try {
            $stats = $this->service->stats();
            $this->sendJsonResponse(200, 'OK', ['stats' => $stats, 'total' => $stats['all'] ?? 0]);
        } catch (Throwable $e) {
            error_log('RecycleBinController::handleStats: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to load recycle bin stats.');
        }
    }

    public function handleRestore(): void
    {
        $decoded = $this->requireRecycleBinManage();
        if (!$decoded) {
            return;
        }

        $payload = $this->getRequestData() ?: [];
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(400, 'Bin item id is required.');
            return;
        }

        try {
            $adminId = (string) ($decoded->user_id ?? '');
            $this->service->restore($id, $adminId);
            $this->sendJsonResponse(200, 'Item restored successfully.');
        } catch (Throwable $e) {
            error_log('RecycleBinController::handleRestore: ' . $e->getMessage());
            $this->sendJsonResponse(400, $e->getMessage());
        }
    }

    public function handlePurge(): void
    {
        $decoded = $this->requireRecycleBinManage();
        if (!$decoded) {
            return;
        }

        $payload = $this->getRequestData() ?: [];
        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(400, 'Bin item id is required.');
            return;
        }

        try {
            $adminId = (string) ($decoded->user_id ?? '');
            $this->service->purge($id, $adminId);
            $this->sendJsonResponse(200, 'Item permanently deleted.');
        } catch (Throwable $e) {
            error_log('RecycleBinController::handlePurge: ' . $e->getMessage());
            $this->sendJsonResponse(400, $e->getMessage());
        }
    }

    public function handleBulk(): void
    {
        $decoded = $this->requireRecycleBinManage();
        if (!$decoded) {
            return;
        }

        $payload = $this->getRequestData() ?: [];
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $ids = $payload['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            $this->sendJsonResponse(400, 'ids array is required.');
            return;
        }
        $ids = array_values(array_filter(array_map('strval', $ids)));

        try {
            $adminId = (string) ($decoded->user_id ?? '');
            if ($action === 'restore') {
                $result = $this->service->bulkRestore($ids, $adminId);
                $this->sendJsonResponse(200, 'Bulk restore completed.', $result);
                return;
            }
            if ($action === 'purge') {
                $result = $this->service->bulkPurge($ids, $adminId);
                $this->sendJsonResponse(200, 'Bulk purge completed.', $result);
                return;
            }
            $this->sendJsonResponse(400, 'Invalid action. Use restore or purge.');
        } catch (Throwable $e) {
            error_log('RecycleBinController::handleBulk: ' . $e->getMessage());
            $this->sendJsonResponse(500, $e->getMessage());
        }
    }

    /**
     * @return object|null decoded JWT payload
     */
    private function requireRecycleBinView()
    {
        try {
            $decoded = $this->validateToken();
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, 'Unauthorized');
            return null;
        }
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Unauthorized');
            return null;
        }
        if (!$this->canAccessRecycleBin($decoded)) {
            $this->sendJsonResponse(403, 'Access denied.');
            return null;
        }
        return $decoded;
    }

    /**
     * @return object|null
     */
    private function requireRecycleBinManage()
    {
        $decoded = $this->requireRecycleBinView();
        if (!$decoded) {
            return null;
        }
        if (strtolower((string) ($decoded->role ?? '')) === 'admin') {
            return $decoded;
        }
        $pm = PermissionManager::getInstance();
        $legacyRole = $decoded->role ?? null;
        if (!$pm->hasPermissionOrAdmin($decoded->user_id, 'RECYCLE_BIN_MANAGE', $legacyRole)) {
            $this->sendJsonResponse(403, 'Manage permission required.');
            return null;
        }
        return $decoded;
    }

    private function canAccessRecycleBin($decoded): bool
    {
        if (strtolower((string) ($decoded->role ?? '')) === 'admin') {
            return true;
        }
        $pm = PermissionManager::getInstance();
        return $pm->hasPermissionOrAdmin($decoded->user_id, 'RECYCLE_BIN_VIEW', $decoded->role ?? null);
    }
}
