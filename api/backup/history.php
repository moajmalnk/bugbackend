<?php

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/backup_helpers.php';

class BackupHistoryController extends BaseAPI
{
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
        }

        backup_require_settings_permission($this);
        backup_ensure_jobs_table($this->conn);

        $limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 20;

        if (!backup_table_exists($this->conn, 'backup_jobs')) {
            $this->sendJsonResponse(200, 'Backup history loaded', [
                'items' => [],
            ]);
        }

        backup_ensure_jobs_table($this->conn);
        backup_reap_stale_jobs($this->conn);
        backup_purge_expired_artifacts($this->conn);

        header('Cache-Control: private, no-store');
        header('Vary: Authorization');

        $hasMailStatus = backup_job_has_mail_status($this->conn);
        $hasArtifacts = backup_job_has_artifacts($this->conn);
        $mailColumns = $hasMailStatus ? 'bj.mail_status, bj.mail_error,' : '';
        $artifactColumns = $hasArtifacts
            ? 'bj.stage, bj.progress_percent, bj.artifacts, bj.download_base, bj.expires_at,'
            : '';

        $stmt = $this->conn->prepare(
            "SELECT
                bj.id,
                bj.email,
                bj.status,
                {$mailColumns}
                {$artifactColumns}
                bj.delivery_method,
                bj.include_database,
                bj.include_uploads,
                bj.include_config,
                bj.backup_name,
                bj.file_size_bytes,
                bj.table_count,
                bj.duration_seconds,
                bj.error_message,
                bj.started_at,
                bj.completed_at,
                bj.created_at,
                u.username AS requested_by
             FROM backup_jobs bj
             LEFT JOIN users u ON u.id = bj.user_id
             ORDER BY bj.created_at DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($items as &$item) {
            $bytes = (int) ($item['file_size_bytes'] ?? 0);
            $item['file_size_label'] =
                $item['status'] === 'processing' || $item['status'] === 'queued'
                    ? 'Preparing…'
                    : backup_format_bytes($bytes);
            $item['include_database'] = (bool) $item['include_database'];
            $item['include_uploads'] = (bool) $item['include_uploads'];
            $item['include_config'] = (bool) $item['include_config'];

            if (empty($item['mail_status'])) {
                if ($item['status'] === 'completed') {
                    $item['mail_status'] = 'sent';
                } elseif ($item['status'] === 'failed') {
                    $item['mail_status'] = 'failed';
                } else {
                    $item['mail_status'] = 'pending';
                }
            }

            if (in_array($item['status'], ['queued', 'processing'], true)) {
                $item['status_detail'] =
                    'We are preparing your backup. A secure download link will be emailed to ' .
                    ($item['email'] ?? 'your inbox') .
                    ' when it is ready.';
            } elseif (!empty($item['error_message'])) {
                $item['status_detail'] = $item['error_message'];
            } else {
                $item['status_detail'] = null;
            }

            $item['stage'] = $item['stage'] ?? null;
            $item['progress_percent'] = isset($item['progress_percent']) ? (int) $item['progress_percent'] : null;
            $item['expires_at'] = $item['expires_at'] ?? null;
            $item['downloads'] = $this->buildDownloads($item);
            unset($item['artifacts'], $item['download_base']);
        }
        unset($item);

        $this->sendJsonResponse(200, 'Backup history loaded', [
            'items' => $items,
        ]);
    }

    /**
     * Short-lived signed links for the console; never outlive the backup itself.
     *
     * @return array<int, array{type:string,label:string,file:string,size_bytes:int,size_label:string,sha256:?string,url:string}>
     */
    private function buildDownloads(array $item): array
    {
        if (($item['status'] ?? '') !== 'completed' || empty($item['expires_at'])) {
            return [];
        }
        $backupExpiry = strtotime((string) $item['expires_at']);
        if (!$backupExpiry || $backupExpiry < time()) {
            return [];
        }

        $expires = min($backupExpiry, time() + BACKUP_CONSOLE_LINK_TTL);
        $base = backup_detect_download_base();
        $downloads = [];
        foreach (backup_decode_artifacts($item['artifacts'] ?? null) as $artifact) {
            $type = (string) ($artifact['type'] ?? '');
            if (!in_array($type, ['database', 'files'], true)) {
                continue;
            }
            $bytes = (int) ($artifact['size_bytes'] ?? 0);
            $downloads[] = [
                'type' => $type,
                'label' => backup_artifact_label($type),
                'file' => (string) ($artifact['file'] ?? ''),
                'size_bytes' => $bytes,
                'size_label' => backup_format_bytes($bytes),
                'sha256' => $artifact['sha256'] ?? null,
                'url' => backup_download_url($base, (int) $item['id'], $type, $expires),
            ];
        }
        return $downloads;
    }
}

try {
    $controller = new BackupHistoryController();
    $controller->handle();
} catch (Throwable $e) {
    error_log('Backup history error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load backup history',
    ]);
}
