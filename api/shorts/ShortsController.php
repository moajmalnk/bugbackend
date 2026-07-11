<?php
require_once __DIR__ . '/../BaseAPI.php';

class ShortsController extends BaseAPI
{
    private const CATEGORIES = ['ui_ux', 'bug', 'project', 'stack', 'other'];
    private const SOURCE_TYPES = ['youtube', 'instagram', 'facebook', 'upload'];
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;
    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime'];
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct()
    {
        parent::__construct();
    }

    private function requireAdmin()
    {
        try {
            $decoded = $this->validateToken();
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, $e->getMessage() ?: 'Authentication failed');
            return null;
        }
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Authentication failed');
            return null;
        }
        if (strtolower(trim((string)($decoded->role ?? ''))) !== 'admin') {
            $this->sendJsonResponse(403, 'Only administrators can manage Shorts');
            return null;
        }
        return $decoded;
    }

    private function ensureTable(): bool
    {
        try {
            $res = $this->conn->query("SHOW TABLES LIKE 'shorts'");
            if ($res && $res->fetch(PDO::FETCH_NUM)) {
                return true;
            }
            $sql = file_get_contents(__DIR__ . '/../../migrations/026_shorts.sql');
            if ($sql) {
                $this->conn->exec($sql);
            }
            $res2 = $this->conn->query("SHOW TABLES LIKE 'shorts'");
            return (bool)($res2 && $res2->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {
            error_log('Shorts ensureTable: ' . $e->getMessage());
            return false;
        }
    }

    private function uploadDir(): string
    {
        $dir = __DIR__ . '/../../uploads/shorts/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function detectSourceTypeFromUrl(?string $url): ?string
    {
        if (!$url) return null;
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host);
        if (in_array($host, ['youtube.com', 'youtu.be', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
            return 'youtube';
        }
        if (in_array($host, ['instagram.com', 'instagr.am'], true)) {
            return 'instagram';
        }
        if (in_array($host, ['facebook.com', 'fb.com', 'fb.watch', 'm.facebook.com'], true)) {
            return 'facebook';
        }
        return null;
    }

    public static function extractYoutubeId(?string $url): ?string
    {
        if (!$url) return null;
        if (preg_match('/(?:youtube\.com\/(?:shorts\/|watch\?v=|embed\/|live\/)|youtu\.be\/)([A-Za-z0-9_-]{6,})/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function youtubeThumbnailUrl(?string $videoId): ?string
    {
        if (!$videoId) return null;
        return 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
    }

    private function enrichRow(array $row): array
    {
        $row['is_published'] = (int)($row['is_published'] ?? 0) === 1;
        $row['sort_order'] = (int)($row['sort_order'] ?? 0);
        $ytId = null;
        if (($row['source_type'] ?? '') === 'youtube') {
            $ytId = self::extractYoutubeId($row['source_url'] ?? null);
        }
        $row['youtube_id'] = $ytId;
        $row['embed_url'] = null;
        if ($ytId) {
            $row['embed_url'] = 'https://www.youtube.com/embed/' . $ytId;
            if (empty($row['thumbnail_path'])) {
                $row['thumbnail_url'] = self::youtubeThumbnailUrl($ytId);
            }
        }
        if (!empty($row['thumbnail_path'])) {
            $row['thumbnail_url'] = $row['thumbnail_path'];
        } elseif (!isset($row['thumbnail_url'])) {
            $row['thumbnail_url'] = null;
        }
        if (!empty($row['video_path'])) {
            $row['video_url'] = $row['video_path'];
        } else {
            $row['video_url'] = null;
        }
        return $row;
    }

    public function list()
    {
        $decoded = $this->requireAdmin();
        if (!$decoded) return;
        if (!$this->ensureTable()) {
            $this->sendJsonResponse(500, 'Shorts table is not available');
            return;
        }

        $category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
        $published = isset($_GET['published']) ? trim((string)$_GET['published']) : '';

        $sql = 'SELECT s.*, u.username AS created_by_name, p.name AS project_name
                FROM shorts s
                LEFT JOIN users u ON u.id = s.created_by
                LEFT JOIN projects p ON p.id = s.project_id
                WHERE 1=1';
        $params = [];

        if ($category !== '' && in_array($category, self::CATEGORIES, true)) {
            $sql .= ' AND s.category = ?';
            $params[] = $category;
        }
        if ($published === '1' || $published === 'true') {
            $sql .= ' AND s.is_published = 1';
        } elseif ($published === '0' || $published === 'false') {
            $sql .= ' AND s.is_published = 0';
        }

        $sql .= ' ORDER BY s.sort_order ASC, s.created_at DESC';

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = array_map([$this, 'enrichRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $this->sendJsonResponse(200, 'Shorts retrieved successfully', $rows);
    }

    public function get($id)
    {
        $decoded = $this->requireAdmin();
        if (!$decoded) return;
        if (!$this->ensureTable()) {
            $this->sendJsonResponse(500, 'Shorts table is not available');
            return;
        }
        if (!$id) {
            $this->sendJsonResponse(400, 'Short ID is required');
            return;
        }

        $stmt = $this->conn->prepare(
            'SELECT s.*, u.username AS created_by_name, p.name AS project_name
             FROM shorts s
             LEFT JOIN users u ON u.id = s.created_by
             LEFT JOIN projects p ON p.id = s.project_id
             WHERE s.id = ?
             LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Short not found');
            return;
        }
        $this->sendJsonResponse(200, 'Short retrieved successfully', $this->enrichRow($row));
    }

    public function create(array $payload)
    {
        $decoded = $this->requireAdmin();
        if (!$decoded) return;
        if (!$this->ensureTable()) {
            $this->sendJsonResponse(500, 'Shorts table is not available');
            return;
        }

        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            $this->sendJsonResponse(400, 'Title is required');
            return;
        }

        $category = trim((string)($payload['category'] ?? 'other'));
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'other';
        }

        $sourceUrl = isset($payload['source_url']) ? trim((string)$payload['source_url']) : null;
        $videoPath = isset($payload['video_path']) ? trim((string)$payload['video_path']) : null;
        $thumbnailPath = isset($payload['thumbnail_path']) ? trim((string)$payload['thumbnail_path']) : null;
        $projectId = isset($payload['project_id']) && $payload['project_id'] !== ''
            ? trim((string)$payload['project_id'])
            : null;
        $description = isset($payload['description']) ? trim((string)$payload['description']) : null;
        $isPublished = array_key_exists('is_published', $payload)
            ? ((int)!!$payload['is_published'])
            : 1;
        $sortOrder = isset($payload['sort_order']) ? (int)$payload['sort_order'] : 0;

        $sourceType = isset($payload['source_type']) ? trim((string)$payload['source_type']) : null;
        if ($sourceUrl) {
            $detected = self::detectSourceTypeFromUrl($sourceUrl);
            if (!$detected) {
                $this->sendJsonResponse(400, 'Unsupported video URL. Use YouTube, Instagram, or Facebook.');
                return;
            }
            $sourceType = $detected;
            $videoPath = null;
            if ($sourceType === 'youtube' && !$thumbnailPath) {
                $ytId = self::extractYoutubeId($sourceUrl);
                // Leave thumbnail_path null; enrichRow supplies YouTube CDN URL
            }
        } elseif ($videoPath) {
            $sourceType = 'upload';
            $sourceUrl = null;
        } else {
            $this->sendJsonResponse(400, 'Provide a video URL or an uploaded video_path');
            return;
        }

        if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
            $this->sendJsonResponse(400, 'Invalid source type');
            return;
        }

        $id = $this->utils->generateUUID();
        $stmt = $this->conn->prepare(
            'INSERT INTO shorts
             (id, title, description, category, source_type, source_url, video_path, thumbnail_path, project_id, created_by, is_published, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $title,
            $description !== '' ? $description : null,
            $category,
            $sourceType,
            $sourceUrl,
            $videoPath !== '' ? $videoPath : null,
            $thumbnailPath !== '' ? $thumbnailPath : null,
            $projectId,
            $decoded->user_id,
            $isPublished,
            $sortOrder,
        ]);

        $this->get($id);
    }

    public function update($id, array $payload)
    {
        $decoded = $this->requireAdmin();
        if (!$decoded) return;
        if (!$this->ensureTable()) {
            $this->sendJsonResponse(500, 'Shorts table is not available');
            return;
        }
        if (!$id) {
            $this->sendJsonResponse(400, 'Short ID is required');
            return;
        }

        $check = $this->conn->prepare('SELECT * FROM shorts WHERE id = ? LIMIT 1');
        $check->execute([$id]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            $this->sendJsonResponse(404, 'Short not found');
            return;
        }

        $fields = [];
        $params = [];

        if (isset($payload['title'])) {
            $title = trim((string)$payload['title']);
            if ($title === '') {
                $this->sendJsonResponse(400, 'Title cannot be empty');
                return;
            }
            $fields[] = 'title = ?';
            $params[] = $title;
        }
        if (array_key_exists('description', $payload)) {
            $fields[] = 'description = ?';
            $params[] = $payload['description'] !== null && $payload['description'] !== ''
                ? trim((string)$payload['description'])
                : null;
        }
        if (isset($payload['category'])) {
            $category = trim((string)$payload['category']);
            if (!in_array($category, self::CATEGORIES, true)) {
                $this->sendJsonResponse(400, 'Invalid category');
                return;
            }
            $fields[] = 'category = ?';
            $params[] = $category;
        }
        if (array_key_exists('project_id', $payload)) {
            $fields[] = 'project_id = ?';
            $params[] = $payload['project_id'] !== null && $payload['project_id'] !== ''
                ? trim((string)$payload['project_id'])
                : null;
        }
        if (array_key_exists('is_published', $payload)) {
            $fields[] = 'is_published = ?';
            $params[] = (int)!!$payload['is_published'];
        }
        if (isset($payload['sort_order'])) {
            $fields[] = 'sort_order = ?';
            $params[] = (int)$payload['sort_order'];
        }
        if (array_key_exists('thumbnail_path', $payload)) {
            $fields[] = 'thumbnail_path = ?';
            $params[] = $payload['thumbnail_path'] !== null && $payload['thumbnail_path'] !== ''
                ? trim((string)$payload['thumbnail_path'])
                : null;
        }

        if (isset($payload['source_url']) && trim((string)$payload['source_url']) !== '') {
            $sourceUrl = trim((string)$payload['source_url']);
            $detected = self::detectSourceTypeFromUrl($sourceUrl);
            if (!$detected) {
                $this->sendJsonResponse(400, 'Unsupported video URL. Use YouTube, Instagram, or Facebook.');
                return;
            }
            $fields[] = 'source_url = ?';
            $params[] = $sourceUrl;
            $fields[] = 'source_type = ?';
            $params[] = $detected;
            $fields[] = 'video_path = ?';
            $params[] = null;
        } elseif (isset($payload['video_path']) && trim((string)$payload['video_path']) !== '') {
            $fields[] = 'video_path = ?';
            $params[] = trim((string)$payload['video_path']);
            $fields[] = 'source_type = ?';
            $params[] = 'upload';
            $fields[] = 'source_url = ?';
            $params[] = null;
        }

        if (empty($fields)) {
            $this->sendJsonResponse(400, 'No fields to update');
            return;
        }

        $params[] = $id;
        $stmt = $this->conn->prepare('UPDATE shorts SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);
        $this->get($id);
    }

    public function delete($id)
    {
        $decoded = $this->requireAdmin();
        if (!$decoded) return;
        if (!$this->ensureTable()) {
            $this->sendJsonResponse(500, 'Shorts table is not available');
            return;
        }
        if (!$id) {
            $this->sendJsonResponse(400, 'Short ID is required');
            return;
        }

        $check = $this->conn->prepare('SELECT video_path, thumbnail_path FROM shorts WHERE id = ? LIMIT 1');
        $check->execute([$id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Short not found');
            return;
        }

        $stmt = $this->conn->prepare('DELETE FROM shorts WHERE id = ?');
        $stmt->execute([$id]);

        foreach (['video_path', 'thumbnail_path'] as $key) {
            $rel = $row[$key] ?? null;
            if ($rel && strpos($rel, 'uploads/shorts/') === 0) {
                $abs = __DIR__ . '/../../' . $rel;
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
        }

        $this->sendJsonResponse(200, 'Short deleted successfully');
    }

    public function upload()
    {
        $decoded = $this->requireAdmin();
        if (!$decoded) return;

        $dir = $this->uploadDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            $this->sendJsonResponse(500, 'Upload folder is not writable. chmod backend/uploads/shorts');
            return;
        }

        if (!isset($_FILES['file'])) {
            $this->sendJsonResponse(400, 'No file uploaded');
            return;
        }

        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->sendJsonResponse(400, 'File upload error: ' . $file['error']);
            return;
        }
        if ($file['size'] > self::MAX_UPLOAD_BYTES) {
            $this->sendJsonResponse(400, 'File size exceeds maximum of 100MB');
            return;
        }

        $kind = isset($_POST['kind']) ? trim((string)$_POST['kind']) : 'video';
        $sniffed = @mime_content_type($file['tmp_name']) ?: '';
        $clientType = isset($file['type']) ? trim((string)$file['type']) : '';
        $mime = strtolower($sniffed ?: $clientType);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: '');

        if ($kind === 'thumbnail') {
            $ok = in_array($mime, self::IMAGE_MIMES, true)
                || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
            if (!$ok) {
                $this->sendJsonResponse(400, 'Thumbnail must be an image (jpg/png/gif/webp)');
                return;
            }
            if ($ext === '') $ext = 'jpg';
        } else {
            $ok = in_array($mime, self::VIDEO_MIMES, true)
                || in_array($ext, ['mp4', 'webm', 'mov'], true);
            if (!$ok) {
                $this->sendJsonResponse(400, 'Video must be mp4, webm, or mov');
                return;
            }
            if ($ext === '') $ext = 'mp4';
        }

        $filename = $this->utils->generateUUID() . '.' . $ext;
        $filepath = $dir . $filename;
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            $this->sendJsonResponse(500, 'Failed to save uploaded file');
            return;
        }

        $relative = 'uploads/shorts/' . $filename;
        $this->sendJsonResponse(200, 'File uploaded successfully', [
            'path' => $relative,
            'kind' => $kind === 'thumbnail' ? 'thumbnail' : 'video',
            'mime' => $mime,
            'size' => (int)$file['size'],
            'original_name' => $file['name'],
        ]);
    }
}
