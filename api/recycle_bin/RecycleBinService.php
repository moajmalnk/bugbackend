<?php
/**
 * Why: Central registry for soft-delete / restore / purge so entity controllers stay thin.
 */
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/RecycleBinPurger.php';

class RecycleBinService
{
    /** @var PDO */
    private $conn;

    /** @var array<string, array{table: string, label: string}> */
    public const ENTITY_TYPES = [
        'bug' => ['table' => 'bugs', 'label' => 'Bug'],
        'project' => ['table' => 'projects', 'label' => 'Project'],
        'update' => ['table' => 'updates', 'label' => 'Update'],
        'user' => ['table' => 'users', 'label' => 'User'],
        'client' => ['table' => 'clients', 'label' => 'Client'],
        'weekly_report' => ['table' => 'weekly_reports', 'label' => 'Weekly Report'],
        'announcement' => ['table' => 'announcements', 'label' => 'Announcement'],
        'feedback' => ['table' => 'user_feedback', 'label' => 'Feedback'],
        'short' => ['table' => 'shorts', 'label' => 'Short'],
        'activity' => ['table' => 'project_activities', 'label' => 'Activity'],
        'doc' => ['table' => 'user_documents', 'label' => 'Document'],
        'sheet' => ['table' => 'user_sheets', 'label' => 'Sheet'],
        'role' => ['table' => 'roles', 'label' => 'Role'],
        'performance_review' => ['table' => 'performance_reviews', 'label' => 'Performance Review'],
        'work_submission' => ['table' => 'work_submissions', 'label' => 'Work Submission'],
        'shared_task' => ['table' => 'shared_tasks', 'label' => 'Shared Task'],
        'user_task' => ['table' => 'user_tasks', 'label' => 'Task'],
        'codo_rule' => ['table' => 'codo_common_rules', 'label' => 'CODO Rule'],
    ];

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    /**
     * SQL fragment: entity row is live (not soft-deleted).
     */
    public static function liveClause(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        return "({$p}deleted_at IS NULL)";
    }

    public function tableExists(string $table): bool
    {
        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->conn->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param array{title?: string, subtitle?: string, project_id?: string|null, metadata?: array|null} $meta
     */
    public function softDelete(string $entityType, string $entityId, string $deletedBy, array $meta = []): string
    {
        $entityType = strtolower(trim($entityType));
        if (!isset(self::ENTITY_TYPES[$entityType])) {
            throw new InvalidArgumentException('Unsupported entity type: ' . $entityType);
        }

        $cfg = self::ENTITY_TYPES[$entityType];
        $table = $cfg['table'];
        if (!$this->tableExists($table)) {
            throw new RuntimeException('Entity table not found: ' . $table);
        }
        if (!$this->columnExists($table, 'deleted_at')) {
            throw new RuntimeException('Recycle bin columns missing on ' . $table . '. Run migration 081.');
        }

        $row = $this->fetchEntityRow($entityType, $entityId);
        if (!$row) {
            throw new RuntimeException(ucfirst($cfg['label']) . ' not found.');
        }
        if (!empty($row['deleted_at'])) {
            throw new RuntimeException(ucfirst($cfg['label']) . ' is already in the recycle bin.');
        }

        $display = $this->buildDisplayMeta($entityType, $row, $meta);
        $binId = Utils::generateUUID();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $metadataJson = !empty($display['metadata'])
            ? json_encode($display['metadata'], JSON_UNESCAPED_UNICODE)
            : null;

        $this->conn->beginTransaction();
        try {
            $upd = $this->conn->prepare(
                "UPDATE `{$table}` SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL"
            );
            $upd->execute([$deletedBy, $entityId]);
            if ($upd->rowCount() === 0) {
                throw new RuntimeException('Failed to soft-delete ' . $entityType);
            }

            if ($entityType === 'codo_rule') {
                $this->conn->prepare('UPDATE codo_common_rules SET is_active = 0 WHERE id = ?')->execute([$entityId]);
            }

            $ins = $this->conn->prepare(
                'INSERT INTO recycle_bin_items
                 (id, entity_type, entity_id, title, subtitle, project_id, metadata, deleted_by, deleted_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            );
            $ins->execute([
                $binId,
                $entityType,
                (string) $entityId,
                mb_substr($display['title'], 0, 255),
                $display['subtitle'] !== null ? mb_substr($display['subtitle'], 0, 255) : null,
                $display['project_id'],
                $metadataJson,
                $deletedBy,
                $expiresAt,
            ]);

            $this->conn->commit();
            return $binId;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    public function restore(string $binItemId, string $adminId): void
    {
        $item = $this->getActiveBinItem($binItemId);
        $entityType = $item['entity_type'];
        $entityId = $item['entity_id'];
        $cfg = self::ENTITY_TYPES[$entityType];
        $table = $cfg['table'];

        $this->conn->beginTransaction();
        try {
            $upd = $this->conn->prepare(
                "UPDATE `{$table}` SET deleted_at = NULL, deleted_by = NULL WHERE id = ?"
            );
            $upd->execute([$entityId]);

            if ($entityType === 'codo_rule') {
                $this->conn->prepare('UPDATE codo_common_rules SET is_active = 1 WHERE id = ?')->execute([$entityId]);
            }

            $this->conn->prepare(
                'UPDATE recycle_bin_items SET restored_at = NOW() WHERE id = ?'
            )->execute([$binItemId]);

            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    public function purge(string $binItemId, string $adminId): void
    {
        $item = $this->getActiveBinItem($binItemId);
        $entityType = $item['entity_type'];
        $entityId = $item['entity_id'];

        $purger = new RecycleBinPurger($this->conn);
        $this->conn->beginTransaction();
        try {
            $purger->permanentDelete($entityType, $entityId);
            $this->conn->prepare(
                'UPDATE recycle_bin_items SET purged_at = NOW() WHERE id = ?'
            )->execute([$binItemId]);
            $this->conn->commit();
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(array $filters, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $where = ['r.restored_at IS NULL', 'r.purged_at IS NULL'];
        $params = [];

        if (!empty($filters['entity_type']) && $filters['entity_type'] !== 'all') {
            $where[] = 'r.entity_type = ?';
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['q'])) {
            $where[] = '(r.title LIKE ? OR r.subtitle LIKE ? OR r.entity_id LIKE ?)';
            $q = '%' . $filters['q'] . '%';
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }

        if (!empty($filters['deleted_by'])) {
            $where[] = 'r.deleted_by = ?';
            $params[] = $filters['deleted_by'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'r.deleted_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'r.deleted_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) FROM recycle_bin_items r WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listParams = array_merge($params, [$limit, $offset]);
        $stmt = $this->conn->prepare(
            "SELECT r.*, u.username AS deleted_by_username
             FROM recycle_bin_items r
             LEFT JOIN users u ON u.id = r.deleted_by AND u.deleted_at IS NULL
             WHERE {$whereSql}
             ORDER BY r.deleted_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($listParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            if (!empty($row['metadata']) && is_string($row['metadata'])) {
                $decoded = json_decode($row['metadata'], true);
                $row['metadata'] = is_array($decoded) ? $decoded : null;
            }
            $row['entity_label'] = self::ENTITY_TYPES[$row['entity_type']]['label'] ?? $row['entity_type'];
        }
        unset($row);

        return [
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        $stmt = $this->conn->query(
            "SELECT entity_type, COUNT(*) AS cnt
             FROM recycle_bin_items
             WHERE restored_at IS NULL AND purged_at IS NULL
             GROUP BY entity_type
             ORDER BY entity_type ASC"
        );
        $byType = [];
        $total = 0;
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $byType[$row['entity_type']] = (int) $row['cnt'];
                $total += (int) $row['cnt'];
            }
        }
        $byType['all'] = $total;
        return $byType;
    }

    public function activeCount(): int
    {
        $stmt = $this->conn->query(
            'SELECT COUNT(*) FROM recycle_bin_items WHERE restored_at IS NULL AND purged_at IS NULL'
        );
        return $stmt ? (int) $stmt->fetchColumn() : 0;
    }

    /**
     * @param array<int, string> $ids
     * @return array{restored: int, failed: array<int, array{id: string, error: string}>}
     */
    public function bulkRestore(array $ids, string $adminId): array
    {
        $restored = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                $this->restore($id, $adminId);
                $restored++;
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        return ['restored' => $restored, 'failed' => $failed];
    }

    /**
     * @param array<int, string> $ids
     * @return array{purged: int, failed: array<int, array{id: string, error: string}>}
     */
    public function bulkPurge(array $ids, string $adminId): array
    {
        $purged = 0;
        $failed = [];
        foreach ($ids as $id) {
            try {
                $this->purge($id, $adminId);
                $purged++;
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        return ['purged' => $purged, 'failed' => $failed];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchEntityRow(string $entityType, string $entityId): ?array
    {
        $table = self::ENTITY_TYPES[$entityType]['table'];
        $stmt = $this->conn->prepare("SELECT * FROM `{$table}` WHERE id = ? LIMIT 1");
        $stmt->execute([$entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $meta
     * @return array{title: string, subtitle: ?string, project_id: ?string, metadata: ?array}
     */
    private function buildDisplayMeta(string $entityType, array $row, array $meta): array
    {
        if (!empty($meta['title'])) {
            return [
                'title' => (string) $meta['title'],
                'subtitle' => isset($meta['subtitle']) ? (string) $meta['subtitle'] : null,
                'project_id' => $meta['project_id'] ?? null,
                'metadata' => $meta['metadata'] ?? null,
            ];
        }

        switch ($entityType) {
            case 'bug':
                return [
                    'title' => (string) ($row['title'] ?? 'Bug'),
                    'subtitle' => $this->projectName($row['project_id'] ?? null),
                    'project_id' => $row['project_id'] ?? null,
                    'metadata' => ['status' => $row['status'] ?? null],
                ];
            case 'project':
                return [
                    'title' => (string) ($row['name'] ?? 'Project'),
                    'subtitle' => (string) ($row['status'] ?? ''),
                    'project_id' => $row['id'] ?? null,
                    'metadata' => null,
                ];
            case 'update':
                return [
                    'title' => (string) ($row['title'] ?? 'Update'),
                    'subtitle' => $this->projectName($row['project_id'] ?? null),
                    'project_id' => $row['project_id'] ?? null,
                    'metadata' => null,
                ];
            case 'user':
                return [
                    'title' => (string) ($row['username'] ?? 'User'),
                    'subtitle' => (string) ($row['email'] ?? $row['role'] ?? ''),
                    'project_id' => null,
                    'metadata' => ['role' => $row['role'] ?? null],
                ];
            case 'client':
                return [
                    'title' => (string) ($row['name'] ?? $row['client_name'] ?? 'Client'),
                    'subtitle' => (string) ($row['location'] ?? $row['client_location'] ?? ''),
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'weekly_report':
                $subtitle = trim(($row['week_start'] ?? '') . ' – ' . ($row['week_end'] ?? ''));
                return [
                    'title' => $this->userName($row['user_id'] ?? '') . ' — Weekly Report',
                    'subtitle' => $subtitle !== '–' ? $subtitle : null,
                    'project_id' => null,
                    'metadata' => ['week_start' => $row['week_start'] ?? null],
                ];
            case 'announcement':
                return [
                    'title' => (string) ($row['title'] ?? 'Announcement'),
                    'subtitle' => null,
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'feedback':
                return [
                    'title' => 'Feedback #' . ($row['id'] ?? ''),
                    'subtitle' => $this->userName($row['user_id'] ?? ''),
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'short':
                return [
                    'title' => (string) ($row['title'] ?? 'Short'),
                    'subtitle' => null,
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'activity':
                return [
                    'title' => (string) ($row['title'] ?? $row['activity_type'] ?? 'Activity'),
                    'subtitle' => $this->projectName($row['project_id'] ?? null),
                    'project_id' => $row['project_id'] ?? null,
                    'metadata' => null,
                ];
            case 'doc':
                return [
                    'title' => (string) ($row['title'] ?? $row['document_name'] ?? 'Document'),
                    'subtitle' => $this->userName($row['user_id'] ?? ''),
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'sheet':
                return [
                    'title' => (string) ($row['title'] ?? $row['sheet_name'] ?? 'Sheet'),
                    'subtitle' => $this->userName($row['user_id'] ?? ''),
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'role':
                return [
                    'title' => (string) ($row['role_name'] ?? $row['name'] ?? 'Role'),
                    'subtitle' => null,
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'performance_review':
                return [
                    'title' => 'Review — ' . $this->userName($row['employee_id'] ?? ''),
                    'subtitle' => (string) ($row['review_month'] ?? ''),
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'work_submission':
                return [
                    'title' => 'Work submission — ' . $this->userName($row['user_id'] ?? ''),
                    'subtitle' => (string) ($row['submission_date'] ?? ''),
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'shared_task':
                return [
                    'title' => (string) ($row['title'] ?? 'Shared Task'),
                    'subtitle' => null,
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'user_task':
                return [
                    'title' => (string) ($row['title'] ?? 'Task'),
                    'subtitle' => $this->userName($row['user_id'] ?? ''),
                    'project_id' => null,
                    'metadata' => null,
                ];
            case 'codo_rule':
                return [
                    'title' => (string) ($row['title'] ?? $row['rule_title'] ?? 'CODO Rule'),
                    'subtitle' => (string) ($row['category'] ?? ''),
                    'project_id' => null,
                    'metadata' => null,
                ];
            default:
                return [
                    'title' => ucfirst(str_replace('_', ' ', $entityType)),
                    'subtitle' => null,
                    'project_id' => null,
                    'metadata' => null,
                ];
        }
    }

    private function projectName(?string $projectId): ?string
    {
        if (!$projectId) {
            return null;
        }
        $stmt = $this->conn->prepare('SELECT name FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$projectId]);
        $name = $stmt->fetchColumn();
        return $name ? (string) $name : null;
    }

    private function userName(string $userId): string
    {
        if ($userId === '') {
            return 'Unknown user';
        }
        $stmt = $this->conn->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $name = $stmt->fetchColumn();
        return $name ? (string) $name : 'Unknown user';
    }

    /**
     * @return array<string, mixed>
     */
    private function getActiveBinItem(string $binItemId): array
    {
        $stmt = $this->conn->prepare(
            'SELECT * FROM recycle_bin_items
             WHERE id = ? AND restored_at IS NULL AND purged_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$binItemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new RuntimeException('Recycle bin item not found or already processed.');
        }
        if (!isset(self::ENTITY_TYPES[$item['entity_type']])) {
            throw new RuntimeException('Unsupported entity type in bin item.');
        }
        return $item;
    }
}
