<?php
require_once __DIR__ . '/AssetsAuth.php';

class AssetsNodesController extends AssetsAuth
{
    /** @var array<string, array{table: string, secret: string, title: string, recycle: string}> */
    private const KINDS = [
        'server' => ['table' => 'assets_servers', 'secret' => 'server', 'title' => 'hostname', 'recycle' => 'asset_server'],
        'hosting' => ['table' => 'assets_hosting', 'secret' => 'hosting', 'title' => 'label', 'recycle' => 'asset_hosting'],
        'vercel' => ['table' => 'assets_vercel', 'secret' => 'vercel', 'title' => 'project_name', 'recycle' => 'asset_vercel'],
    ];

    public function listKind(string $kind): void
    {
        if (!$this->requireView()) {
            return;
        }
        $cfg = self::KINDS[$kind] ?? null;
        if (!$cfg) {
            $this->sendJsonResponse(400, 'Invalid node kind');
            return;
        }
        $p = $this->pagination();
        if (!$this->tableReady($cfg['table'])) {
            $this->sendPage([], 0, $p['page'], $p['limit']);
            return;
        }
        $where = ['n.deleted_at IS NULL'];
        $params = [];
        $clientId = trim((string) ($_GET['client_id'] ?? ''));
        $join = '';
        if ($clientId !== '') {
            $join = 'INNER JOIN assets_client_nodes ln ON ln.node_kind = ? AND ln.node_id = n.id AND ln.client_id = ?';
            $params[] = $kind;
            $params[] = $clientId;
        }
        $status = trim((string) ($_GET['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'n.status = ?';
            $params[] = $status;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            if ($kind === 'server') {
                $where[] = '(n.hostname LIKE ? OR n.public_ipv4 LIKE ? OR n.os_name LIKE ?)';
            } elseif ($kind === 'hosting') {
                $where[] = '(n.label LIKE ? OR n.primary_domain LIKE ? OR n.public_ipv4 LIKE ?)';
            } else {
                $where[] = '(n.project_name LIKE ? OR n.production_domain LIKE ? OR n.team_slug LIKE ?)';
            }
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $expiresIn = (int) ($_GET['expires_in'] ?? 0);
        if ($expiresIn > 0) {
            $where[] = 'n.expires_at IS NOT NULL AND n.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)';
            $params[] = $expiresIn;
        }
        $sqlWhere = implode(' AND ', $where);
        $countSql = "SELECT COUNT(*) FROM `{$cfg['table']}` n {$join} WHERE {$sqlWhere}";
        $count = $this->conn->prepare($countSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $stmt = $this->conn->prepare(
            "SELECT n.* FROM `{$cfg['table']}` n {$join}
             WHERE {$sqlWhere}
             ORDER BY n.created_at DESC
             LIMIT {$p['limit']} OFFSET {$p['offset']}"
        );
        $stmt->execute($params);
        $rows = $this->mapFinance($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $rows = $this->attachHasSecret($cfg['secret'], $rows);
        $this->sendPage($rows, $total, $p['page'], $p['limit']);
    }

    public function getKind(string $kind): void
    {
        if (!$this->requireView()) {
            return;
        }
        $cfg = self::KINDS[$kind] ?? null;
        $id = trim((string) ($_GET['id'] ?? ''));
        if (!$cfg || $id === '') {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $row = $this->fetchLive($cfg['table'], $id);
        if (!$row) {
            $this->sendJsonResponse(404, 'Node not found');
            return;
        }
        $row = $this->maybeStripFinance($row);
        $row = $this->attachHasSecret($cfg['secret'], [$row])[0];
        $row['node_kind'] = $kind;
        $row['inbound_subdomains'] = $this->inboundSubdomains($kind, $id);
        $row['clients'] = $this->linkedClients($kind, $id);
        $this->sendJsonResponse(200, 'OK', $row);
    }

    public function createKind(string $kind): void
    {
        if (!$this->requireCreate()) {
            return;
        }
        $cfg = self::KINDS[$kind] ?? null;
        if (!$cfg) {
            $this->sendJsonResponse(400, 'Invalid node kind');
            return;
        }
        $data = $this->jsonInput();
        $fields = $this->buildFields($kind, $data, true);
        if ($fields === null) {
            return;
        }
        try {
            $this->insertRow($cfg['table'], $fields);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Node already exists');
                return;
            }
            throw $e;
        }
        $_GET['id'] = $fields['id'];
        $this->getKind($kind);
    }

    public function updateKind(string $kind): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $cfg = self::KINDS[$kind] ?? null;
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? ''));
        if (!$cfg || $id === '' || !$this->fetchLive($cfg['table'], $id)) {
            $this->sendJsonResponse(404, 'Node not found');
            return;
        }
        $fields = $this->buildFields($kind, $data, false);
        if ($fields === null) {
            return;
        }
        unset($fields['id'], $fields['created_by']);
        if ($fields === []) {
            $this->sendJsonResponse(400, 'No fields to update');
            return;
        }
        try {
            $this->updateRow($cfg['table'], $id, $fields);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Duplicate hostname or IP');
                return;
            }
            throw $e;
        }
        $_GET['id'] = $id;
        $this->getKind($kind);
    }

    public function deleteKind(string $kind): void
    {
        if (!$this->requireDelete()) {
            return;
        }
        $cfg = self::KINDS[$kind] ?? null;
        $id = trim((string) ($_GET['id'] ?? $this->jsonInput()['id'] ?? ''));
        $row = ($cfg && $id !== '') ? $this->fetchLive($cfg['table'], $id) : null;
        if (!$row) {
            $this->sendJsonResponse(404, 'Node not found');
            return;
        }
        $title = (string) ($row[$cfg['title']] ?? $kind);
        $this->softDeleteAsset($cfg['recycle'], $id, $title);
        $this->sendJsonResponse(200, 'Node moved to recycle bin');
    }

    public function link(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $clientId = trim((string) ($data['client_id'] ?? ''));
        $kind = $this->enumOr((string) ($data['node_kind'] ?? ''), ['server','hosting','vercel'], '');
        $nodeId = trim((string) ($data['node_id'] ?? ''));
        if ($clientId === '' || $kind === '' || $nodeId === '') {
            $this->sendJsonResponse(400, 'client_id, node_kind and node_id are required');
            return;
        }
        $cfg = self::KINDS[$kind];
        if (!$this->fetchLive($cfg['table'], $nodeId)) {
            $this->sendJsonResponse(404, 'Node not found');
            return;
        }
        $id = Utils::generateUUID();
        try {
            $this->insertRow('assets_client_nodes', [
                'id' => $id,
                'client_id' => $clientId,
                'project_id' => assetNullableString($data['project_id'] ?? null, 36),
                'node_kind' => $kind,
                'node_id' => $nodeId,
                'role' => assetNullableString($data['role'] ?? null, 80),
            ]);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Client already linked to this node');
                return;
            }
            throw $e;
        }
        $this->sendJsonResponse(201, 'Linked', ['id' => $id]);
    }

    public function unlink(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? $_GET['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        $stmt = $this->conn->prepare('DELETE FROM assets_client_nodes WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            $this->sendJsonResponse(404, 'Link not found');
            return;
        }
        $this->sendJsonResponse(200, 'Unlinked');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildFields(string $kind, array $data, bool $isCreate): ?array
    {
        $fields = assetExtractBilling($data, false);
        $statuses = ['active','pending','expired','cancelled','parked'];
        if (array_key_exists('status', $data) || $isCreate) {
            $fields['status'] = $this->enumOr((string) ($data['status'] ?? 'active'), $statuses, 'active');
        }
        if (array_key_exists('notes', $data)) {
            $fields['notes'] = assetNullableString($data['notes'], 5000);
        }
        if ($kind === 'server') {
            if ($isCreate || array_key_exists('hostname', $data)) {
                $host = assetSanitizeFqdn((string) ($data['hostname'] ?? ''));
                if ($host === null) {
                    $this->sendJsonResponse(400, 'Valid hostname is required');
                    return null;
                }
                $fields['hostname'] = $host;
            }
            if (array_key_exists('public_ipv4', $data) || $isCreate) {
                $ip = assetSanitizeIpv4(isset($data['public_ipv4']) ? (string) $data['public_ipv4'] : null);
                if (isset($data['public_ipv4']) && trim((string) $data['public_ipv4']) !== '' && $ip === null) {
                    $this->sendJsonResponse(400, 'Invalid IPv4');
                    return null;
                }
                $fields['public_ipv4'] = $ip;
            }
            if (array_key_exists('public_ipv6', $data)) {
                $fields['public_ipv6'] = assetSanitizeIpv6((string) $data['public_ipv6']);
            }
            if (array_key_exists('ssh_user', $data) || $isCreate) {
                $fields['ssh_user'] = assetNullableString($data['ssh_user'] ?? null, 64);
            }
            if (array_key_exists('ssh_port', $data)) {
                $port = (int) $data['ssh_port'];
                $fields['ssh_port'] = $port > 0 && $port <= 65535 ? $port : 22;
            }
            foreach (['os_name' => 80, 'panel_url' => 500] as $col => $max) {
                if (array_key_exists($col, $data) || $isCreate) {
                    $fields[$col] = assetNullableString($data[$col] ?? null, $max);
                }
            }
            foreach (['vcpus', 'ram_mb', 'disk_gb'] as $col) {
                if (array_key_exists($col, $data)) {
                    $fields[$col] = $data[$col] === '' || $data[$col] === null ? null : max(0, (int) $data[$col]);
                }
            }
            if (array_key_exists('ownership', $data) || $isCreate) {
                $fields['ownership'] = $this->enumOr((string) ($data['ownership'] ?? 'shared'), ['shared','dedicated','internal'], 'shared');
            }
        } elseif ($kind === 'hosting') {
            if ($isCreate || array_key_exists('label', $data)) {
                $label = assetNullableString($data['label'] ?? null, 255);
                if ($label === null) {
                    $this->sendJsonResponse(400, 'Label is required');
                    return null;
                }
                $fields['label'] = $label;
            }
            foreach (['panel_url' => 500, 'package_name' => 120, 'primary_domain' => 255] as $col => $max) {
                if (array_key_exists($col, $data) || $isCreate) {
                    $fields[$col] = $col === 'primary_domain'
                        ? assetSanitizeFqdn((string) ($data[$col] ?? ''))
                        : assetNullableString($data[$col] ?? null, $max);
                }
            }
            if (array_key_exists('public_ipv4', $data)) {
                $fields['public_ipv4'] = assetSanitizeIpv4((string) $data['public_ipv4']);
            }
        } else {
            if ($isCreate || array_key_exists('project_name', $data)) {
                $name = assetNullableString($data['project_name'] ?? null, 255);
                if ($name === null) {
                    $this->sendJsonResponse(400, 'project_name is required');
                    return null;
                }
                $fields['project_name'] = $name;
            }
            foreach (['team_slug' => 120, 'repo_url' => 500, 'framework' => 80] as $col => $max) {
                if (array_key_exists($col, $data) || $isCreate) {
                    $fields[$col] = assetNullableString($data[$col] ?? null, $max);
                }
            }
            if (array_key_exists('production_domain', $data) || $isCreate) {
                $fields['production_domain'] = assetSanitizeFqdn((string) ($data['production_domain'] ?? ''));
            }
        }
        if ($isCreate) {
            $fields['id'] = Utils::generateUUID();
            $fields['created_by'] = $this->decoded->user_id;
        }
        return $fields;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inboundSubdomains(string $kind, string $id): array
    {
        if (!$this->tableReady('assets_subdomains')) {
            return [];
        }
        $col = $kind === 'server' ? 'target_server_id' : ($kind === 'hosting' ? 'target_hosting_id' : 'target_vercel_id');
        $clientCode = $this->clientCodeSql('c');
        try {
            $stmt = $this->conn->prepare(
                "SELECT s.id, s.host, s.fqdn, s.record_type, s.target_value, s.purpose, s.status,
                        d.fqdn AS apex, d.client_id, {$clientCode}, c.corporate_name AS client_name
                 FROM assets_subdomains s
                 JOIN assets_domains d ON d.id = s.domain_id AND d.deleted_at IS NULL
                 LEFT JOIN clients c ON c.id = d.client_id
                 WHERE s.deleted_at IS NULL AND s.{$col} = ?
                 ORDER BY s.created_at DESC"
            );
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('assets inbound subdomains failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linkedClients(string $kind, string $id): array
    {
        if (!$this->tableReady('assets_client_nodes')) {
            return [];
        }
        $clientCode = $this->clientCodeSql('c');
        try {
            $stmt = $this->conn->prepare(
                "SELECT ln.id AS link_id, ln.role, ln.project_id, c.id AS client_id, {$clientCode}, c.corporate_name
                 FROM assets_client_nodes ln
                 JOIN clients c ON c.id = ln.client_id AND c.deleted_at IS NULL
                 WHERE ln.node_kind = ? AND ln.node_id = ?
                 ORDER BY ln.created_at DESC"
            );
            $stmt->execute([$kind, $id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('assets linked clients failed: ' . $e->getMessage());
            return [];
        }
    }
}
