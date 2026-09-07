<?php
/**
 * Why: Shared BugCreative folder tree — create/rename/reparent/delete with
 * depth and cycle guards so the UI stays Drive-like and safe.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

class CreativeFoldersController extends BaseAPI
{
    private const MAX_DEPTH = 6;
    private const NAME_MAX = 100;

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

    private function canManageFolders(object $decoded): bool
    {
        return $this->isAdmin($decoded)
            || $this->can($decoded, 'CREATIVE_MANAGE')
            || $this->can($decoded, 'CREATIVE_CREATE');
    }

    private function foldersReady(): bool
    {
        try {
            $this->conn->query('SELECT 1 FROM creative_folders LIMIT 1');
            $stmt = $this->conn->query("SHOW COLUMNS FROM creative_assets LIKE 'folder_id'");
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Why: API may deploy before an admin runs migration 100 manually.
     * Uses direct DDL (not the prepared-statement migration file) so bootstrap is reliable.
     */
    public function ensureFoldersSchema(): void
    {
        if ($this->foldersReady()) {
            return;
        }
        try {
            $this->conn->exec(
                "CREATE TABLE IF NOT EXISTS `creative_folders` (
                  `id` VARCHAR(36) NOT NULL,
                  `parent_id` VARCHAR(36) NULL,
                  `name` VARCHAR(100) NOT NULL,
                  `created_by` VARCHAR(36) NOT NULL,
                  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_creative_folders_parent` (`parent_id`),
                  KEY `idx_creative_folders_created_by` (`created_by`),
                  KEY `idx_creative_folders_created` (`created_at`),
                  KEY `idx_creative_folders_name` (`name`),
                  CONSTRAINT `fk_creative_folders_parent`
                    FOREIGN KEY (`parent_id`) REFERENCES `creative_folders`(`id`) ON DELETE RESTRICT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
            );

            $col = $this->conn->query("SHOW COLUMNS FROM creative_assets LIKE 'folder_id'");
            if (!$col->fetch(PDO::FETCH_ASSOC)) {
                $this->conn->exec(
                    'ALTER TABLE `creative_assets` ADD COLUMN `folder_id` VARCHAR(36) NULL DEFAULT NULL AFTER `project_id`'
                );
            }

            $idx = $this->conn->query("SHOW INDEX FROM creative_assets WHERE Key_name = 'idx_creative_assets_folder_id'");
            if (!$idx->fetch(PDO::FETCH_ASSOC)) {
                $this->conn->exec(
                    'ALTER TABLE `creative_assets` ADD KEY `idx_creative_assets_folder_id` (`folder_id`)'
                );
            }

            $fk = $this->conn->query(
                "SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'creative_assets'
                   AND CONSTRAINT_NAME = 'fk_creative_assets_folder'
                 LIMIT 1"
            );
            if (!$fk->fetch(PDO::FETCH_ASSOC)) {
                $this->conn->exec(
                    'ALTER TABLE `creative_assets`
                     ADD CONSTRAINT `fk_creative_assets_folder`
                     FOREIGN KEY (`folder_id`) REFERENCES `creative_folders`(`id`) ON DELETE SET NULL'
                );
            }
        } catch (Throwable $e) {
            error_log('CreativeFoldersController::ensureFoldersSchema: ' . $e->getMessage());
        }
    }

    private function ensureReady(): bool
    {
        $this->ensureFoldersSchema();
        if (!$this->foldersReady()) {
            $this->sendJsonResponse(
                503,
                'BugCreative folders are not set up. Run migration 100_creative_folders.sql.'
            );
            return false;
        }
        return true;
    }

    private function sanitizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim(strip_tags($value));
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) > self::NAME_MAX) {
            $clean = mb_substr($clean, 0, self::NAME_MAX);
        }
        return $clean;
    }

    private function formatFolder(array $row): array
    {
        return [
            'id' => $row['id'],
            'parent_id' => $row['parent_id'] ?? null,
            'name' => $row['name'],
            'created_by' => $row['created_by'],
            'created_by_name' => $row['created_by_name'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'asset_count' => isset($row['asset_count']) ? (int)$row['asset_count'] : null,
            'child_count' => isset($row['child_count']) ? (int)$row['child_count'] : null,
        ];
    }

    private function fetchFolder(string $id): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT f.*, u.username AS created_by_name
             FROM creative_folders f
             LEFT JOIN users u ON u.id = f.created_by
             WHERE f.id = ?
             LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Why: Sibling names must be unique per parent (including root) even though
     * MySQL UNIQUE allows multiple NULLs for parent_id.
     */
    private function siblingNameTaken(?string $parentId, string $name, ?string $excludeId = null): bool
    {
        if ($parentId === null) {
            $sql = 'SELECT id FROM creative_folders WHERE parent_id IS NULL AND LOWER(name) = LOWER(?)';
            $params = [$name];
        } else {
            $sql = 'SELECT id FROM creative_folders WHERE parent_id = ? AND LOWER(name) = LOWER(?)';
            $params = [$parentId, $name];
        }
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    private function folderDepth(?string $folderId): int
    {
        if ($folderId === null || $folderId === '') {
            return 0;
        }
        $depth = 0;
        $current = $folderId;
        $guard = 0;
        while ($current !== null && $guard < 64) {
            $depth++;
            $stmt = $this->conn->prepare('SELECT parent_id FROM creative_folders WHERE id = ? LIMIT 1');
            $stmt->execute([$current]);
            $parent = $stmt->fetchColumn();
            $current = $parent !== false && $parent !== null && $parent !== '' ? (string)$parent : null;
            $guard++;
        }
        return $depth;
    }

    /**
     * Why: Reparenting into a descendant would create a cycle and break breadcrumbs.
     */
    private function isDescendant(string $ancestorId, string $candidateId): bool
    {
        $current = $candidateId;
        $guard = 0;
        while ($current !== null && $guard < 64) {
            if ($current === $ancestorId) {
                return true;
            }
            $stmt = $this->conn->prepare('SELECT parent_id FROM creative_folders WHERE id = ? LIMIT 1');
            $stmt->execute([$current]);
            $parent = $stmt->fetchColumn();
            $current = $parent !== false && $parent !== null && $parent !== '' ? (string)$parent : null;
            $guard++;
        }
        return false;
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

        $parentRaw = isset($_GET['parent_id']) ? trim((string)$_GET['parent_id']) : 'all';

        $where = ['1=1'];
        $params = [];
        if ($parentRaw === 'root' || $parentRaw === '') {
            $where[] = 'f.parent_id IS NULL';
        } elseif ($parentRaw !== 'all') {
            if (!Utils::isValidUUID($parentRaw)) {
                $this->sendJsonResponse(400, 'Valid parent_id is required');
                return;
            }
            $where[] = 'f.parent_id = ?';
            $params[] = $parentRaw;
        }

        $whereSql = implode(' AND ', $where);
        $sql = "SELECT f.*,
                u.username AS created_by_name,
                (SELECT COUNT(*) FROM creative_folders c WHERE c.parent_id = f.id) AS child_count,
                (SELECT COUNT(*) FROM creative_assets a WHERE a.folder_id = f.id) AS asset_count
             FROM creative_folders f
             LEFT JOIN users u ON u.id = f.created_by
             WHERE {$whereSql}
             ORDER BY f.name ASC, f.created_at ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(function ($row) {
            return $this->formatFolder($row);
        }, $rows);

        $this->sendJsonResponse(200, 'OK', ['items' => $items]);
    }

    public function create()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->canManageFolders($decoded)) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $data = $this->getRequestData() ?: [];
        $name = $this->sanitizeName($data['name'] ?? null);
        if ($name === null) {
            $this->sendJsonResponse(400, 'Folder name is required');
            return;
        }

        $parentId = null;
        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null && $data['parent_id'] !== '' && $data['parent_id'] !== 'root') {
            $candidate = trim(strip_tags((string)$data['parent_id']));
            if (!Utils::isValidUUID($candidate)) {
                $this->sendJsonResponse(400, 'Valid parent folder is required');
                return;
            }
            $parent = $this->fetchFolder($candidate);
            if (!$parent) {
                $this->sendJsonResponse(404, 'Parent folder not found');
                return;
            }
            if ($this->folderDepth($candidate) >= self::MAX_DEPTH) {
                $this->sendJsonResponse(400, 'Maximum folder depth (' . self::MAX_DEPTH . ') reached');
                return;
            }
            $parentId = $candidate;
        }

        if ($this->siblingNameTaken($parentId, $name)) {
            $this->sendJsonResponse(409, 'A folder with this name already exists here');
            return;
        }

        $id = Utils::generateUUID();
        $stmt = $this->conn->prepare(
            'INSERT INTO creative_folders (id, parent_id, name, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$id, $parentId, $name, $decoded->user_id]);

        $row = $this->fetchFolder($id);
        $this->sendJsonResponse(201, 'Folder created', $this->formatFolder($row ?: [
            'id' => $id,
            'parent_id' => $parentId,
            'name' => $name,
            'created_by' => $decoded->user_id,
        ]));
    }

    public function update()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->canManageFolders($decoded)) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $data = $this->getRequestData() ?: [];
        $id = isset($data['id']) ? trim((string)$data['id']) : '';
        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid folder id is required');
            return;
        }

        $existing = $this->fetchFolder($id);
        if (!$existing) {
            $this->sendJsonResponse(404, 'Folder not found');
            return;
        }

        $name = array_key_exists('name', $data)
            ? $this->sanitizeName(is_string($data['name'] ?? null) ? $data['name'] : null)
            : $existing['name'];
        if ($name === null) {
            $this->sendJsonResponse(400, 'Folder name is required');
            return;
        }

        $parentId = $existing['parent_id'] ?? null;
        if (array_key_exists('parent_id', $data)) {
            $raw = $data['parent_id'];
            if ($raw === null || $raw === '' || $raw === 'root') {
                $parentId = null;
            } else {
                $candidate = trim(strip_tags((string)$raw));
                if (!Utils::isValidUUID($candidate)) {
                    $this->sendJsonResponse(400, 'Valid parent folder is required');
                    return;
                }
                if ($candidate === $id) {
                    $this->sendJsonResponse(400, 'A folder cannot be its own parent');
                    return;
                }
                if (!$this->fetchFolder($candidate)) {
                    $this->sendJsonResponse(404, 'Parent folder not found');
                    return;
                }
                if ($this->isDescendant($id, $candidate)) {
                    $this->sendJsonResponse(400, 'Cannot move a folder into one of its descendants');
                    return;
                }
                if ($this->folderDepth($candidate) >= self::MAX_DEPTH) {
                    $this->sendJsonResponse(400, 'Maximum folder depth (' . self::MAX_DEPTH . ') reached');
                    return;
                }
                $parentId = $candidate;
            }
        }

        if ($this->siblingNameTaken($parentId, $name, $id)) {
            $this->sendJsonResponse(409, 'A folder with this name already exists here');
            return;
        }

        $stmt = $this->conn->prepare(
            'UPDATE creative_folders SET name = ?, parent_id = ? WHERE id = ?'
        );
        $stmt->execute([$name, $parentId, $id]);

        $row = $this->fetchFolder($id);
        $this->sendJsonResponse(200, 'Folder updated', $this->formatFolder($row));
    }

    public function delete()
    {
        $decoded = $this->requireAuth();
        if (!$decoded || !$this->ensureReady()) {
            return;
        }
        if (!$this->canManageFolders($decoded)) {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $data = $this->getRequestData() ?: [];
        $id = isset($data['id']) ? trim((string)$data['id']) : (isset($_GET['id']) ? trim((string)$_GET['id']) : '');
        if ($id === '' || !Utils::isValidUUID($id)) {
            $this->sendJsonResponse(400, 'Valid folder id is required');
            return;
        }

        $existing = $this->fetchFolder($id);
        if (!$existing) {
            $this->sendJsonResponse(404, 'Folder not found');
            return;
        }

        $childStmt = $this->conn->prepare('SELECT COUNT(*) FROM creative_folders WHERE parent_id = ?');
        $childStmt->execute([$id]);
        if ((int)$childStmt->fetchColumn() > 0) {
            $this->sendJsonResponse(409, 'Folder is not empty. Move or delete subfolders first.');
            return;
        }

        $assetStmt = $this->conn->prepare('SELECT COUNT(*) FROM creative_assets WHERE folder_id = ?');
        $assetStmt->execute([$id]);
        if ((int)$assetStmt->fetchColumn() > 0) {
            $this->sendJsonResponse(409, 'Folder is not empty. Move or delete assets first.');
            return;
        }

        $stmt = $this->conn->prepare('DELETE FROM creative_folders WHERE id = ?');
        $stmt->execute([$id]);
        $this->sendJsonResponse(200, 'Folder deleted');
    }
}
