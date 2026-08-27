<?php
/**
 * Why: BugCreative is a first-class asset pipeline (draft → review → publish)
 * for Creator users, without coupling to the bug tracker.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

class CreativeAssetsController extends BaseAPI
{
    private const STATUSES = ['Draft', 'In Review', 'Completed', 'Published', 'Rejected'];
    private const MATERIAL_TYPES = [
        'Poster', 'Reel', 'Carousel', 'Mockup Web', 'Mockup App',
        'Tips', 'Document', 'Logo', 'Brochure', 'Other',
    ];
    private const PLATFORMS = ['Insta', 'Web', 'YouTube', 'LinkedIn', 'Other'];
    private const SOURCES = ['link', 'upload'];
    private const REVIEW_STATUSES = ['Approved', 'Changes Requested', 'Rejected'];
    private const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;
    private const ALLOWED_EXT = [
        'webp', 'jpg', 'jpeg', 'png', 'gif', 'pdf', 'mp4', 'zip',
    ];
    private const IMAGE_EXT = ['webp', 'jpg', 'jpeg', 'png', 'gif'];

    private function requireAuth()
    {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, 'Authentication failed');
                return null;
            }
            return $decoded;
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, $e->getMessage() ?: 'Authentication failed');
            return null;
        }
    }

    private function can(object $decoded, string $key): bool
    {
        $pm = PermissionManager::getInstance();
        return $pm->hasPermissionOrAdmin(
            $decoded->user_id ?? '',
            $key,
            $decoded->role ?? null
        );
    }

    private function isAdmin(object $decoded): bool
    {
        return strtolower(trim((string)($decoded->role ?? ''))) === 'admin';
    }

    private function isCreatorRole(object $decoded): bool
    {
        return strtolower(trim((string)($decoded->role ?? ''))) === 'creator';
    }

    private function tablesReady(): bool
    {
        try {
            $this->conn->query('SELECT 1 FROM creative_assets LIMIT 1');
            $this->conn->query('SELECT 1 FROM creative_reviews LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Why: Production may ship API files before an admin runs 089 manually.
     */
    private function ensureSchema(): void
    {
        if ($this->tablesReady()) {
            return;
        }
        $migration = realpath(__DIR__ . '/../../migrations/089_creator_role_and_bugcreative.sql');
        if (!$migration || !is_readable($migration)) {
            error_log('CreativeAssetsController::ensureSchema: migration file missing');
            return;
        }
        try {
            $sql = file_get_contents($migration);
            if ($sql === false || trim($sql) === '') {
                return;
            }
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $statement) {
                if ($statement === '') {
                    continue;
                }
                $this->conn->exec($statement);
            }
        } catch (Throwable $e) {
            error_log('CreativeAssetsController::ensureSchema: ' . $e->getMessage());
        }
    }

    private function ensureReady(): bool
    {
        $this->ensureSchema();
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(
                503,
                'BugCreative is not set up. Run migration 089_creator_role_and_bugcreative.sql.'
            );
            return false;
        }
        return true;
    }

    private function sanitizeText(?string $value, int $maxLen): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim(strip_tags($value));
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) > $maxLen) {
            $clean = mb_substr($clean, 0, $maxLen);
        }
        return $clean;
    }

    private function sanitizeUrl(?string $value): ?string
    {
        $clean = $this->sanitizeText($value, 2000);
        if ($clean === null) {
            return null;
        }
        if (!preg_match('#^https?://#i', $clean)) {
            return null;
        }
        return $clean;
    }

    private function sanitizeDate(?string $value): ?string
    {
        $clean = $this->sanitizeText($value, 10);
        if ($clean === null) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean)) {
            return null;
        }
        return $clean;
    }

    private function loadReviews(string $assetId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT r.*, u.username AS reviewer_name
             FROM creative_reviews r
             LEFT JOIN users u ON u.id = r.reviewer_id
             WHERE r.asset_id = ?
             ORDER BY r.created_at DESC, r.id DESC"
        );
        $stmt->execute([$assetId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row) {
            return [
                'id' => $row['id'],
                'asset_id' => $row['asset_id'],
                'reviewer_id' => $row['reviewer_id'],
                'reviewer_name' => $row['reviewer_name'] ?? null,
                'status' => $row['status'],
                'comments' => $row['comments'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows);
    }

    private function formatAsset(array $row, ?array $reviews = null): array
    {
        return [
            'id' => $row['id'],
            'project_id' => $row['project_id'] ?? null,
            'project_name' => $row['project_name'] ?? null,
            'creator_id' => $row['creator_id'],
            'creator_name' => $row['creator_name'] ?? null,
            'title' => $row['title'],
            'material_type' => $row['material_type'],
            'platform' => $row['platform'],
            'hook_content' => $row['hook_content'] ?? null,
            'asset_source' => $row['asset_source'],
            'drive_link' => $row['drive_link'] ?? null,
            'uploaded_file_path' => $row['uploaded_file_path'] ?? null,
            'preview_thumbnail_url' => $row['preview_thumbnail_url'] ?? null,
            'status' => $row['status'],
            'admin_feedback' => $row['admin_feedback'] ?? null,
            'scheduled_date' => $row['scheduled_date'] ?? null,
            'published_date' => $row['published_date'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'reviews' => $reviews,
        ];
    }

    private function assetSelectSql(): string
    {
        return "SELECT a.*,
            p.name AS project_name,
            u.username AS creator_name
            FROM creative_assets a
            LEFT JOIN projects p ON p.id = a.project_id
            LEFT JOIN users u ON u.id = a.creator_id";
    }

    private function fetchAsset(string $id): ?array
    {
        $stmt = $this->conn->prepare($this->assetSelectSql() . ' WHERE a.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Why: Creators only see their own work; reviewers/managers see the full queue.
     */
    private function canSeeAsset(object $decoded, array $row): bool
    {
        if ($this->isAdmin($decoded) || $this->can($decoded, 'CREATIVE_MANAGE') || $this->can($decoded, 'CREATIVE_REVIEW')) {
            return true;
        }
        return (string)$row['creator_id'] === (string)$decoded->user_id;
    }

    private function canEditAsset(object $decoded, array $row): bool
    {
        if ($this->isAdmin($decoded) || $this->can($decoded, 'CREATIVE_MANAGE')) {
            return true;
        }
        if ((string)$row['creator_id'] !== (string)$decoded->user_id) {
            return false;
        }
        if (!$this->can($decoded, 'CREATIVE_CREATE')) {
            return false;
        }
        // Why: Creators may edit their own assets in any workflow status (Draft → Published).
        return in_array($row['status'], self::STATUSES, true);
    }

    private function applyOwnerScope(object $decoded, array &$where, array &$params): void
    {
        if ($this->isAdmin($decoded) || $this->can($decoded, 'CREATIVE_MANAGE') || $this->can($decoded, 'CREATIVE_REVIEW')) {
            return;
        }
        $where[] = 'a.creator_id = ?';
        $params[] = $decoded->user_id;
    }

    /**
     * Why: Dashboard-style period filter scopes counts and lists to created_at.
     */
    private function applyCreatedPeriod(?string $from, ?string $to, array &$where, array &$params): void
    {
        $from = $this->sanitizeDate($from);
        $to = $this->sanitizeDate($to);
        if ($from !== null && $to !== null) {
            $where[] = 'DATE(a.created_at) BETWEEN ? AND ?';
            $params[] = $from;
            $params[] = $to;
            return;
        }
        if ($from !== null) {
            $where[] = 'DATE(a.created_at) >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'DATE(a.created_at) <= ?';
            $params[] = $to;
        }
    }

    private function parsePayload(array $data, object $decoded, ?array $existing = null): array
    {
        $title = $this->sanitizeText($data['title'] ?? ($existing['title'] ?? null), 255);
        if ($title === null) {
            throw new InvalidArgumentException('Title is required');
        }

        $material = trim((string)($data['material_type'] ?? ($existing['material_type'] ?? '')));
        if (!in_array($material, self::MATERIAL_TYPES, true)) {
            throw new InvalidArgumentException('Valid material type is required');
        }

        $platform = trim((string)($data['platform'] ?? ($existing['platform'] ?? 'Insta')));
        if (!in_array($platform, self::PLATFORMS, true)) {
            $platform = 'Insta';
        }

        $source = trim((string)($data['asset_source'] ?? ($existing['asset_source'] ?? 'link')));
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'link';
        }

        $status = trim((string)($data['status'] ?? ($existing['status'] ?? 'Draft')));
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'Draft';
        }

        $creatorId = $existing['creator_id'] ?? $decoded->user_id;
        if (($this->isAdmin($decoded) || $this->can($decoded, 'CREATIVE_MANAGE')) && !empty($data['creator_id'])) {
            $candidate = $this->sanitizeText((string)$data['creator_id'], 36);
            if ($candidate && Utils::isValidUUID($candidate)) {
                $creatorId = $candidate;
            }
        }

        $projectId = $this->sanitizeText(
            isset($data['project_id']) ? (string)$data['project_id'] : ($existing['project_id'] ?? null),
            36
        );
        if ($projectId !== null && !Utils::isValidUUID($projectId)) {
            $projectId = null;
        }

        $published = $this->sanitizeDate($data['published_date'] ?? ($existing['published_date'] ?? null));
        if ($status === 'Published' && $published === null) {
            $published = date('Y-m-d');
        }

        return [
            'title' => $title,
            'material_type' => $material,
            'platform' => $platform,
            'hook_content' => $this->sanitizeText($data['hook_content'] ?? ($existing['hook_content'] ?? null), 2000),
            'asset_source' => $source,
            'drive_link' => $this->sanitizeUrl($data['drive_link'] ?? ($existing['drive_link'] ?? null)),
            'uploaded_file_path' => $this->sanitizeText(
                $data['uploaded_file_path'] ?? ($existing['uploaded_file_path'] ?? null),
                500
            ),
            'preview_thumbnail_url' => $this->sanitizeText(
                $data['preview_thumbnail_url'] ?? ($existing['preview_thumbnail_url'] ?? null),
                500
            ),
            'status' => $status,
            'scheduled_date' => $this->sanitizeDate($data['scheduled_date'] ?? ($existing['scheduled_date'] ?? null)),
            'published_date' => $published,
            'creator_id' => $creatorId,
            'project_id' => $projectId,
        ];
    }

    public function listAll()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'CREATIVE_VIEW')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
        $material = isset($_GET['material_type']) ? trim((string)$_GET['material_type']) : '';
        $platform = isset($_GET['platform']) ? trim((string)$_GET['platform']) : '';
        $projectId = isset($_GET['project_id']) ? trim((string)$_GET['project_id']) : '';
        $from = isset($_GET['from']) ? (string)$_GET['from'] : null;
        $to = isset($_GET['to']) ? (string)$_GET['to'] : null;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $params = [];
        $this->applyOwnerScope($decoded, $where, $params);
        $this->applyCreatedPeriod($from, $to, $where, $params);

        if ($q !== '') {
            $where[] = '(a.title LIKE ? OR a.hook_content LIKE ? OR u.username LIKE ? OR p.name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($status !== '' && $status !== 'all' && in_array($status, self::STATUSES, true)) {
            $where[] = 'a.status = ?';
            $params[] = $status;
        }
        if ($material !== '' && $material !== 'all' && in_array($material, self::MATERIAL_TYPES, true)) {
            $where[] = 'a.material_type = ?';
            $params[] = $material;
        }
        if ($platform !== '' && $platform !== 'all' && in_array($platform, self::PLATFORMS, true)) {
            $where[] = 'a.platform = ?';
            $params[] = $platform;
        }
        if ($projectId !== '' && $projectId !== 'all' && Utils::isValidUUID($projectId)) {
            $where[] = 'a.project_id = ?';
            $params[] = $projectId;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM creative_assets a
             LEFT JOIN projects p ON p.id = a.project_id
             LEFT JOIN users u ON u.id = a.creator_id
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = $this->assetSelectSql() . "
            WHERE {$whereSql}
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(function ($row) {
            return $this->formatAsset($row);
        }, $rows);

        $this->sendJsonResponse(200, 'OK', [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function getOne()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'CREATIVE_VIEW')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid asset id is required');
            return;
        }

        $row = $this->fetchAsset($id);
        if (!$row || !$this->canSeeAsset($decoded, $row)) {
            $this->sendJsonResponse(404, 'Asset not found');
            return;
        }

        $this->sendJsonResponse(200, 'OK', $this->formatAsset($row, $this->loadReviews($id)));
    }

    public function create()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'CREATIVE_CREATE')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $data = $this->getRequestData() ?: [];
        try {
            $payload = $this->parsePayload($data, $decoded);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(400, $e->getMessage());
            return;
        }

        if (!empty($data['submit'])) {
            $payload['status'] = 'In Review';
        } elseif ($this->isCreatorRole($decoded) && !in_array($payload['status'], ['Draft', 'In Review'], true)) {
            $payload['status'] = 'Draft';
        }

        $id = Utils::generateUUID();
        $stmt = $this->conn->prepare(
            'INSERT INTO creative_assets (
                id, project_id, creator_id, title, material_type, platform,
                hook_content, asset_source, drive_link, uploaded_file_path,
                preview_thumbnail_url, status, scheduled_date, published_date
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $payload['project_id'],
            $payload['creator_id'],
            $payload['title'],
            $payload['material_type'],
            $payload['platform'],
            $payload['hook_content'],
            $payload['asset_source'],
            $payload['drive_link'],
            $payload['uploaded_file_path'],
            $payload['preview_thumbnail_url'],
            $payload['status'],
            $payload['scheduled_date'],
            $payload['published_date'],
        ]);

        $row = $this->fetchAsset($id);
        $this->sendJsonResponse(201, 'Asset created', $this->formatAsset($row, []));
    }

    public function update()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }

        $data = $this->getRequestData() ?: [];
        $id = isset($data['id']) ? trim((string)$data['id']) : '';
        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid asset id is required');
            return;
        }

        $existing = $this->fetchAsset($id);
        if (!$existing || !$this->canSeeAsset($decoded, $existing)) {
            $this->sendJsonResponse(404, 'Asset not found');
            return;
        }
        if (!$this->canEditAsset($decoded, $existing) && empty($data['submit']) && empty($data['publish'])) {
            $this->sendJsonResponse(403, 'This asset cannot be edited in its current status');
            return;
        }

        if (!empty($data['submit'])) {
            if ((string)$existing['creator_id'] !== (string)$decoded->user_id
                && !$this->isAdmin($decoded)
                && !$this->can($decoded, 'CREATIVE_MANAGE')) {
                $this->sendJsonResponse(403, 'Only the owner can submit this asset');
                return;
            }
            $stmt = $this->conn->prepare(
                "UPDATE creative_assets SET status = 'In Review' WHERE id = ?"
            );
            $stmt->execute([$id]);
            $row = $this->fetchAsset($id);
            $this->sendJsonResponse(200, 'Submitted for review', $this->formatAsset($row, $this->loadReviews($id)));
            return;
        }

        if (!empty($data['publish'])) {
            if (!$this->isAdmin($decoded) && !$this->can($decoded, 'CREATIVE_MANAGE')
                && (string)$existing['creator_id'] !== (string)$decoded->user_id) {
                $this->sendJsonResponse(403, 'Access denied');
                return;
            }
            if (!in_array($existing['status'], ['Completed', 'Published'], true)
                && !$this->isAdmin($decoded)
                && !$this->can($decoded, 'CREATIVE_MANAGE')) {
                $this->sendJsonResponse(400, 'Only completed assets can be published');
                return;
            }
            $published = $this->sanitizeDate($data['published_date'] ?? null) ?: date('Y-m-d');
            $stmt = $this->conn->prepare(
                "UPDATE creative_assets SET status = 'Published', published_date = ? WHERE id = ?"
            );
            $stmt->execute([$published, $id]);
            $row = $this->fetchAsset($id);
            $this->sendJsonResponse(200, 'Asset published', $this->formatAsset($row, $this->loadReviews($id)));
            return;
        }

        try {
            $payload = $this->parsePayload($data, $decoded, $existing);
        } catch (InvalidArgumentException $e) {
            $this->sendJsonResponse(400, $e->getMessage());
            return;
        }

        if ($this->isCreatorRole($decoded) && !$this->can($decoded, 'CREATIVE_MANAGE')) {
            // Why: Field edits must not bounce Completed/Published back to Draft; status moves via submit/publish/review.
            $payload['status'] = $existing['status'];
        }

        $stmt = $this->conn->prepare(
            'UPDATE creative_assets SET
                project_id = ?, creator_id = ?, title = ?, material_type = ?, platform = ?,
                hook_content = ?, asset_source = ?, drive_link = ?, uploaded_file_path = ?,
                preview_thumbnail_url = ?, status = ?, scheduled_date = ?, published_date = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $payload['project_id'],
            $payload['creator_id'],
            $payload['title'],
            $payload['material_type'],
            $payload['platform'],
            $payload['hook_content'],
            $payload['asset_source'],
            $payload['drive_link'],
            $payload['uploaded_file_path'],
            $payload['preview_thumbnail_url'],
            $payload['status'],
            $payload['scheduled_date'],
            $payload['published_date'],
            $id,
        ]);

        $row = $this->fetchAsset($id);
        $this->sendJsonResponse(200, 'Asset updated', $this->formatAsset($row, $this->loadReviews($id)));
    }

    public function delete()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }

        $data = $this->getRequestData() ?: [];
        $id = isset($data['id']) ? trim((string)$data['id']) : (isset($_GET['id']) ? trim((string)$_GET['id']) : '');
        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid asset id is required');
            return;
        }

        $existing = $this->fetchAsset($id);
        if (!$existing) {
            $this->sendJsonResponse(404, 'Asset not found');
            return;
        }

        $isOwnerDeletable = (string)$existing['creator_id'] === (string)$decoded->user_id
            && in_array($existing['status'], ['Draft', 'In Review'], true)
            && $this->can($decoded, 'CREATIVE_CREATE');
        if (!$this->can($decoded, 'CREATIVE_MANAGE') && !$this->isAdmin($decoded) && !$isOwnerDeletable) {
            $this->sendJsonResponse(403, 'Only Draft or In Review assets can be deleted by their owner');
            return;
        }

        $stmt = $this->conn->prepare('DELETE FROM creative_assets WHERE id = ?');
        $stmt->execute([$id]);
        $this->sendJsonResponse(200, 'Asset deleted');
    }

    public function review()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'CREATIVE_REVIEW')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $data = $this->getRequestData() ?: [];
        $id = isset($data['asset_id']) ? trim((string)$data['asset_id']) : '';
        $status = isset($data['status']) ? trim((string)$data['status']) : '';
        $comments = $this->sanitizeText($data['comments'] ?? null, 4000);

        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid asset id is required');
            return;
        }
        if (!in_array($status, self::REVIEW_STATUSES, true)) {
            $this->sendJsonResponse(400, 'Valid review status is required');
            return;
        }

        $existing = $this->fetchAsset($id);
        if (!$existing) {
            $this->sendJsonResponse(404, 'Asset not found');
            return;
        }

        $nextStatus = 'In Review';
        if ($status === 'Approved') {
            $nextStatus = 'Completed';
        } elseif ($status === 'Changes Requested') {
            $nextStatus = 'Draft';
        } else {
            $nextStatus = 'Rejected';
        }

        $reviewId = Utils::generateUUID();
        $ins = $this->conn->prepare(
            'INSERT INTO creative_reviews (id, asset_id, reviewer_id, status, comments)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$reviewId, $id, $decoded->user_id, $status, $comments]);

        $upd = $this->conn->prepare(
            'UPDATE creative_assets SET status = ?, admin_feedback = ? WHERE id = ?'
        );
        $upd->execute([$nextStatus, $comments, $id]);

        $row = $this->fetchAsset($id);
        $this->sendJsonResponse(200, 'Review saved', $this->formatAsset($row, $this->loadReviews($id)));
    }

    public function upload()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'CREATIVE_CREATE') && !$this->can($decoded, 'CREATIVE_MANAGE')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            $this->sendJsonResponse(400, 'No file uploaded');
            return;
        }

        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->sendJsonResponse(400, 'Upload failed');
            return;
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            $this->sendJsonResponse(400, 'File must be 25MB or smaller');
            return;
        }

        $original = basename((string)($file['name'] ?? 'file'));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            $this->sendJsonResponse(400, 'File type is not allowed');
            return;
        }

        $uploadDir = __DIR__ . '/../../uploads/creative/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original) ?: ('file.' . $ext);
        $targetPath = $uploadDir . uniqid('cre_', true) . '_' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            $this->sendJsonResponse(500, 'Could not save file');
            return;
        }

        $relativePath = 'uploads/creative/' . basename($targetPath);
        $isImage = in_array($ext, self::IMAGE_EXT, true);

        $this->sendJsonResponse(200, 'Uploaded', [
            'file_path' => $relativePath,
            'file_name' => $original,
            'file_size' => $size,
            'preview_thumbnail_url' => $isImage ? $relativePath : null,
        ]);
    }

    public function stats()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->can($decoded, 'CREATIVE_VIEW')) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $from = isset($_GET['from']) ? (string)$_GET['from'] : null;
        $to = isset($_GET['to']) ? (string)$_GET['to'] : null;

        $where = ['1=1'];
        $params = [];
        $this->applyOwnerScope($decoded, $where, $params);
        $this->applyCreatedPeriod($from, $to, $where, $params);
        $whereSql = implode(' AND ', $where);

        $counts = [];
        foreach (self::STATUSES as $status) {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) FROM creative_assets a WHERE {$whereSql} AND a.status = ?"
            );
            $stmt->execute(array_merge($params, [$status]));
            $counts[$status] = (int)$stmt->fetchColumn();
        }

        $dueStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM creative_assets a
             WHERE {$whereSql}
               AND a.scheduled_date IS NOT NULL
               AND a.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
               AND a.status NOT IN ('Published','Rejected')"
        );
        $dueStmt->execute($params);
        $dueThisWeek = (int)$dueStmt->fetchColumn();

        $pubStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM creative_assets a WHERE {$whereSql} AND a.status = 'Published'"
        );
        $pubStmt->execute($params);
        $publishedInPeriod = (int)$pubStmt->fetchColumn();

        $feedbackStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM creative_assets a
             WHERE {$whereSql} AND a.status = 'In Review'"
        );
        $feedbackStmt->execute($params);
        $inReview = (int)$feedbackStmt->fetchColumn();

        $this->sendJsonResponse(200, 'OK', [
            'by_status' => $counts,
            'total' => array_sum($counts),
            'due_this_week' => $dueThisWeek,
            'published_in_period' => $publishedInPeriod,
            'in_review' => $inReview,
        ]);
    }
}
