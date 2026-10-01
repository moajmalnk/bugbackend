<?php
/**
 * Why: The onboarding "Resize" action re-crops the saved avatar in a canvas on
 * the frontend origin. Static /uploads responses carry no CORS headers (and may
 * be cached that way by Cloudflare), so stream the file through the API, which
 * already answers CORS for the app origins.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/user_avatar.php';

class AvatarImageAPI extends BaseAPI
{
    private const MIME_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
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

            if ($requesterId === '' || $targetId === '') {
                $this->sendJsonResponse(400, 'Invalid request');
                return;
            }

            if (!hash_equals($requesterId, $targetId)) {
                $pm = PermissionManager::getInstance();
                if (!$pm->hasPermissionOrAdmin($requesterId, 'USERS_VIEW', $legacyRole)) {
                    $this->sendJsonResponse(403, 'Access denied');
                    return;
                }
            }

            $cols = [];
            $colRes = $this->conn->query('SHOW COLUMNS FROM users');
            if ($colRes) {
                while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }
            $select = br_user_avatar_select_cols([], $cols);
            if ($select === []) {
                $this->sendJsonResponse(404, 'No avatar on file');
                return;
            }

            $stmt = $this->conn->prepare(
                'SELECT `' . implode('`, `', $select) . '` FROM users WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$targetId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stored = $row ? br_user_resolve_avatar($row) : null;

            // Only local uploads are streamed; external URLs (e.g. Google) are never proxied.
            if (!$stored || !preg_match('#uploads/profile_pictures/[^?\#]+#i', $stored, $m)) {
                $this->sendJsonResponse(404, 'No uploaded avatar on file');
                return;
            }

            $absolute = realpath(__DIR__ . '/../../' . $m[0]);
            $baseDir = realpath(__DIR__ . '/../../uploads/profile_pictures');
            if ($absolute === false || $baseDir === false || strpos($absolute, $baseDir . DIRECTORY_SEPARATOR) !== 0) {
                $this->sendJsonResponse(404, 'Avatar missing on disk');
                return;
            }

            $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
            $mime = self::MIME_MAP[$ext] ?? null;
            if ($mime === null) {
                $this->sendJsonResponse(415, 'Unsupported avatar format');
                return;
            }

            if (ob_get_length()) {
                ob_end_clean();
            }
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($absolute));
            header('Cache-Control: private, no-store');
            header('Vary: Authorization');
            header('X-Content-Type-Options: nosniff');
            readfile($absolute);
            exit();
        } catch (Exception $e) {
            error_log('avatar_image error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to load avatar');
        }
    }
}

$api = new AvatarImageAPI();
$api->handle();
