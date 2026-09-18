<?php
/**
 * Why: Shared BugAssets auth, pagination, finance stripping, and recycle-bin
 * deletes so every resource controller stays consistent.
 */
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/asset_billing.php';

class AssetsAuth extends BaseAPI
{
    const FINANCE_KEYS = ['vendor_cost', 'client_charge', 'margin_amount', 'vendor_account'];

    /** @var object|null */
    protected $decoded = null;

    protected function authenticate()
    {
        try {
            $this->decoded = $this->validateToken();
            if (!$this->decoded || !isset($this->decoded->user_id)) {
                $this->sendJsonResponse(401, 'Authentication failed');
                return null;
            }
            return $this->decoded;
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, $e->getMessage() ?: 'Authentication failed');
            return null;
        }
    }

    protected function can(string $key): bool
    {
        if (!$this->decoded) {
            return false;
        }
        $pm = PermissionManager::getInstance();
        return $pm->hasPermissionOrAdmin(
            $this->decoded->user_id ?? '',
            $key,
            $this->decoded->role ?? null
        );
    }

    protected function requirePerm(string $key)
    {
        $decoded = $this->authenticate();
        if (!$decoded) {
            return null;
        }
        if (!$this->can($key)) {
            $this->sendJsonResponse(403, 'Access denied. Required permission: ' . $key);
            return null;
        }
        return $decoded;
    }

    protected function requireView()
    {
        return $this->requirePerm('ASSETS_VIEW');
    }

    protected function requireCreate()
    {
        return $this->requirePerm('ASSETS_CREATE');
    }

    protected function requireEdit()
    {
        return $this->requirePerm('ASSETS_EDIT');
    }

    protected function requireDelete()
    {
        return $this->requirePerm('ASSETS_DELETE');
    }

    protected function requireVaultReveal()
    {
        return $this->requirePerm('ASSETS_VAULT_REVEAL');
    }

    protected function canFinance(): bool
    {
        return $this->can('ASSETS_FINANCE_VIEW');
    }

    /**
     * @return array{page: int, limit: int, offset: int}
     */
    protected function pagination(): array
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = (int) ($_GET['limit'] ?? 20);
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }
        return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
    }

    protected function liveSql(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';
        return "{$p}deleted_at IS NULL";
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function maybeStripFinance(array $row): array
    {
        if ($this->canFinance()) {
            return $row;
        }
        return assetStripFinanceKeys($row);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    protected function mapFinance(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->maybeStripFinance($row);
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    protected function sendPage(array $items, int $total, int $page, int $limit, string $message = 'OK'): void
    {
        $this->sendJsonResponse(200, $message, [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    protected function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '[]', true);
        return is_array($data) ? $data : [];
    }

    protected function tableReady(string $table): bool
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

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    protected function attachHasSecret(string $entityType, array $rows): array
    {
        if ($rows === [] || !$this->tableReady('assets_vault_secrets')) {
            foreach ($rows as &$row) {
                $row['has_secret'] = false;
                $row['secret_fingerprint'] = null;
            }
            unset($row);
            return $rows;
        }
        $ids = [];
        foreach ($rows as $row) {
            if (!empty($row['id'])) {
                $ids[] = (string) $row['id'];
            }
        }
        if ($ids === []) {
            return $rows;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare(
            "SELECT entity_id, fingerprint FROM assets_vault_secrets
             WHERE entity_type = ? AND entity_id IN ({$placeholders})
             ORDER BY created_at DESC"
        );
        $stmt->execute(array_merge([$entityType], $ids));
        $map = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $eid = (string) $r['entity_id'];
            if (!isset($map[$eid])) {
                $map[$eid] = $r['fingerprint'];
            }
        }
        foreach ($rows as &$row) {
            $id = (string) ($row['id'] ?? '');
            $row['has_secret'] = array_key_exists($id, $map);
            $row['secret_fingerprint'] = $map[$id] ?? null;
        }
        unset($row);
        return $rows;
    }

    protected function softDeleteAsset(string $entityType, string $id, string $title, ?string $subtitle = null): void
    {
        require_once __DIR__ . '/../recycle_bin/RecycleBinService.php';
        $rb = new RecycleBinService($this->conn);
        $rb->softDelete($entityType, $id, $this->decoded->user_id ?? '', [
            'title' => $title,
            'subtitle' => $subtitle,
        ]);
    }

    /**
     * @param list<string> $allowed
     */
    protected function enumOr(string $value, array $allowed, string $fallback): string
    {
        $v = strtolower(trim($value));
        return in_array($v, $allowed, true) ? $v : $fallback;
    }

    protected function clientRequestIp(): ?string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if (is_string($ip) && strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return is_string($ip) ? substr($ip, 0, 45) : null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<mixed> $extraValues
     */
    protected function insertRow(string $table, array $fields): bool
    {
        $cols = array_keys($fields);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . $placeholders . ')';
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute(array_values($fields));
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function updateRow(string $table, string $id, array $fields): bool
    {
        if ($fields === []) {
            return true;
        }
        $sets = [];
        $values = [];
        foreach ($fields as $col => $val) {
            $sets[] = "`{$col}` = ?";
            $values[] = $val;
        }
        $values[] = $id;
        $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE id = ? AND deleted_at IS NULL';
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchLive(string $table, string $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM `{$table}` WHERE id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
