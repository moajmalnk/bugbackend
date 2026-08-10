<?php
/**
 * Why: Statutory files are stored behind .htaccess deny; this endpoint streams them
 * only to the owner or an admin after JWT validation.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';

class DownloadStatutoryAPI extends BaseAPI
{
    private const ALLOWED_KEYS = [
        'aadhaar_file_path',
        'pan_file_path',
        'offer_letter_path',
        'nda_path',
    ];

    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $requesterId = (string) ($decoded->user_id ?? '');
            $legacyRole = isset($decoded->role) ? (string) $decoded->role : null;

            $targetId = isset($_GET['user_id']) ? trim((string) $_GET['user_id']) : $requesterId;
            $key = isset($_GET['file']) ? trim((string) $_GET['file']) : '';

            if ($requesterId === '' || $targetId === '' || !in_array($key, self::ALLOWED_KEYS, true)) {
                $this->sendJsonResponse(400, 'Invalid download request');
                return;
            }

            $isSelf = hash_equals($requesterId, $targetId);
            if (!$isSelf) {
                $pm = PermissionManager::getInstance();
                if (!$pm->hasPermissionOrAdmin($requesterId, 'USERS_VIEW', $legacyRole)) {
                    $this->sendJsonResponse(403, 'Access denied');
                    return;
                }
            }

            $stmt = $this->conn->prepare(
                "SELECT `{$key}` AS file_path FROM user_onboarding_details WHERE user_id = ? LIMIT 1"
            );
            $stmt->execute([$targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $relative = $row['file_path'] ?? null;
            if (!$relative) {
                $this->sendJsonResponse(404, 'File not found');
                return;
            }

            // Only allow paths under uploads/statutory/
            $relative = str_replace('\\', '/', (string) $relative);
            if (strpos($relative, 'uploads/statutory/') !== 0) {
                $this->sendJsonResponse(403, 'Invalid file path');
                return;
            }

            $absolute = realpath(__DIR__ . '/../../' . $relative);
            $baseDir = realpath(__DIR__ . '/../../uploads/statutory');
            if ($absolute === false || $baseDir === false || strpos($absolute, $baseDir) !== 0) {
                $this->sendJsonResponse(404, 'File missing on disk');
                return;
            }

            $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
            $mimeMap = [
                'pdf' => 'application/pdf',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'heic' => 'image/heic',
            ];
            $mime = $mimeMap[$ext] ?? 'application/octet-stream';
            $downloadName = basename($absolute);

            if (ob_get_length()) {
                ob_end_clean();
            }
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($absolute));
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($absolute);
            exit();
        } catch (Exception $e) {
            error_log('download_statutory error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to download file');
        }
    }
}

$api = new DownloadStatutoryAPI();
$api->handle();
