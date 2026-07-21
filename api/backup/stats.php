<?php

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/backup_helpers.php';

class BackupStatsController extends BaseAPI
{
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
        }

        backup_require_settings_permission($this);
        backup_ensure_jobs_table($this->conn);
        backup_reap_stale_jobs($this->conn);

        $tableCount = backup_count_tables($this->conn);
        $databaseBytes = backup_estimate_database_size($this->conn);

        $backendPath = realpath(__DIR__ . '/../..') ?: dirname(dirname(__DIR__));
        $uploadsPath = backup_resolve_uploads_path();
        $uploadsBytes = backup_directory_size($uploadsPath);

        $lastBackup = null;
        $activeJobs = 0;
        $completedJobs = 0;

        if (backup_table_exists($this->conn, 'backup_jobs')) {
            $lastStmt = $this->conn->query(
                "SELECT id, status, backup_name, file_size_bytes, email, completed_at, created_at
                 FROM backup_jobs
                 ORDER BY created_at DESC
                 LIMIT 1"
            );
            $lastBackup = $lastStmt ? $lastStmt->fetch(PDO::FETCH_ASSOC) : null;

            $activeStmt = $this->conn->query(
                "SELECT COUNT(*) FROM backup_jobs WHERE status IN ('queued', 'processing')"
            );
            $activeJobs = (int) ($activeStmt ? $activeStmt->fetchColumn() : 0);

            $completedStmt = $this->conn->query(
                "SELECT COUNT(*) FROM backup_jobs WHERE status = 'completed'"
            );
            $completedJobs = (int) ($completedStmt ? $completedStmt->fetchColumn() : 0);
        }

        $estimatedTotal = $databaseBytes + $uploadsBytes;
        $etaSeconds = backup_estimate_eta_seconds($estimatedTotal);

        $this->sendJsonResponse(200, 'Backup stats loaded', [
            'database' => [
                'tables' => $tableCount,
                'size_bytes' => $databaseBytes,
                'size_label' => backup_format_bytes($databaseBytes),
            ],
            'uploads' => [
                'size_bytes' => $uploadsBytes,
                'size_label' => backup_format_bytes($uploadsBytes),
                'path_exists' => is_dir($uploadsPath),
            ],
            'estimate' => [
                'total_bytes' => $estimatedTotal,
                'total_label' => backup_format_bytes($estimatedTotal),
                'eta_seconds' => $etaSeconds,
                'eta_label' => backup_format_eta($etaSeconds),
            ],
            'jobs' => [
                'active' => $activeJobs,
                'completed' => $completedJobs,
            ],
            'last_backup' => $lastBackup,
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }
}

try {
    $controller = new BackupStatsController();
    $controller->handle();
} catch (Throwable $e) {
    error_log('Backup stats error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load backup stats',
    ]);
}
