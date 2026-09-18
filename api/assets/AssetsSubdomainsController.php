<?php
require_once __DIR__ . '/AssetsAuth.php';

class AssetsSubdomainsController extends AssetsAuth
{
    public function listAll(): void
    {
        if (!$this->requireView()) {
            return;
        }
        if (!$this->tableReady('assets_subdomains')) {
            $p = $this->pagination();
            $this->sendPage([], 0, $p['page'], $p['limit']);
            return;
        }
        $p = $this->pagination();
        $where = ['s.deleted_at IS NULL', 'd.deleted_at IS NULL'];
        $params = [];
        $domainId = trim((string) ($_GET['domain_id'] ?? ''));
        if ($domainId !== '') {
            $where[] = 's.domain_id = ?';
            $params[] = $domainId;
        }
        $serverId = trim((string) ($_GET['server_id'] ?? ''));
        if ($serverId !== '') {
            $where[] = 's.target_server_id = ?';
            $params[] = $serverId;
        }
        $hostingId = trim((string) ($_GET['hosting_id'] ?? ''));
        if ($hostingId !== '') {
            $where[] = 's.target_hosting_id = ?';
            $params[] = $hostingId;
        }
        $vercelId = trim((string) ($_GET['vercel_id'] ?? ''));
        if ($vercelId !== '') {
            $where[] = 's.target_vercel_id = ?';
            $params[] = $vercelId;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(s.fqdn LIKE ? OR s.purpose LIKE ? OR s.target_value LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sqlWhere = implode(' AND ', $where);

        $count = $this->conn->prepare(
            "SELECT COUNT(*) FROM assets_subdomains s
             JOIN assets_domains d ON d.id = s.domain_id
             WHERE {$sqlWhere}"
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->conn->prepare(
            "SELECT s.*, d.fqdn AS apex, d.client_id, c.client_code, c.corporate_name AS client_name
             FROM assets_subdomains s
             JOIN assets_domains d ON d.id = s.domain_id
             LEFT JOIN clients c ON c.id = d.client_id
             WHERE {$sqlWhere}
             ORDER BY s.created_at DESC
             LIMIT {$p['limit']} OFFSET {$p['offset']}"
        );
        $stmt->execute($params);
        $this->sendPage($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $total, $p['page'], $p['limit']);
    }

    public function create(): void
    {
        if (!$this->requireCreate()) {
            return;
        }
        $data = $this->jsonInput();
        $built = $this->buildRow($data, true);
        if ($built === null) {
            return;
        }
        try {
            $this->insertRow('assets_subdomains', $built);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Subdomain already exists');
                return;
            }
            throw $e;
        }
        $row = $this->fetchLive('assets_subdomains', $built['id']);
        $this->sendJsonResponse(201, 'Subdomain created', $row);
    }

    public function update(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? ''));
        $existing = $id !== '' ? $this->fetchLive('assets_subdomains', $id) : null;
        if (!$existing) {
            $this->sendJsonResponse(404, 'Subdomain not found');
            return;
        }
        $merged = array_merge($existing, $data);
        $built = $this->buildRow($merged, false);
        if ($built === null) {
            return;
        }
        unset($built['id'], $built['created_by']);
        $this->updateRow('assets_subdomains', $id, $built);
        $this->sendJsonResponse(200, 'Subdomain updated', $this->fetchLive('assets_subdomains', $id));
    }

    public function delete(): void
    {
        if (!$this->requireDelete()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? $this->jsonInput()['id'] ?? ''));
        $row = $id !== '' ? $this->fetchLive('assets_subdomains', $id) : null;
        if (!$row) {
            $this->sendJsonResponse(404, 'Subdomain not found');
            return;
        }
        $stmt = $this->conn->prepare(
            'UPDATE assets_subdomains SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$this->decoded->user_id, $id]);
        $this->sendJsonResponse(200, 'Subdomain deleted');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildRow(array $data, bool $isCreate): ?array
    {
        $domainId = trim((string) ($data['domain_id'] ?? ''));
        $domain = $domainId !== '' ? $this->fetchLive('assets_domains', $domainId) : null;
        if (!$domain) {
            $this->sendJsonResponse(400, 'Valid domain_id is required');
            return null;
        }
        $host = assetSanitizeHost((string) ($data['host'] ?? ''));
        if ($host === null) {
            $this->sendJsonResponse(400, 'Invalid host');
            return null;
        }
        $fqdn = assetBuildFqdn($host, (string) $domain['fqdn']);
        $kind = $this->enumOr((string) ($data['target_kind'] ?? 'raw'), ['server','hosting','vercel','raw'], 'raw');
        $serverId = $kind === 'server' ? assetNullableString($data['target_server_id'] ?? null, 36) : null;
        $hostingId = $kind === 'hosting' ? assetNullableString($data['target_hosting_id'] ?? null, 36) : null;
        $vercelId = $kind === 'vercel' ? assetNullableString($data['target_vercel_id'] ?? null, 36) : null;
        $targetValue = assetNullableString($data['target_value'] ?? null, 500);
        if ($kind === 'server' && $serverId) {
            $node = $this->fetchLive('assets_servers', $serverId);
            if ($node && empty($targetValue)) {
                $targetValue = $node['public_ipv4'] ?? $node['hostname'] ?? null;
            }
        } elseif ($kind === 'hosting' && $hostingId) {
            $node = $this->fetchLive('assets_hosting', $hostingId);
            if ($node && empty($targetValue)) {
                $targetValue = $node['public_ipv4'] ?? $node['primary_domain'] ?? null;
            }
        } elseif ($kind === 'vercel' && $vercelId) {
            $node = $this->fetchLive('assets_vercel', $vercelId);
            if ($node && empty($targetValue)) {
                $targetValue = $node['production_domain'] ?? $node['project_name'] ?? null;
            }
        }
        $row = [
            'domain_id' => $domainId,
            'host' => $host,
            'fqdn' => $fqdn,
            'purpose' => assetNullableString($data['purpose'] ?? null, 255),
            'record_type' => strtoupper($this->enumOr(
                strtolower((string) ($data['record_type'] ?? 'a')),
                ['a','aaaa','cname','alias','mx','txt','ns'],
                'a'
            )),
            'target_kind' => $kind,
            'target_server_id' => $serverId,
            'target_hosting_id' => $hostingId,
            'target_vercel_id' => $vercelId,
            'target_value' => $targetValue,
            'ttl' => isset($data['ttl']) && $data['ttl'] !== '' ? (int) $data['ttl'] : null,
            'status' => $this->enumOr((string) ($data['status'] ?? 'active'), ['active','disabled'], 'active'),
        ];
        $row['record_type'] = strtoupper($row['record_type']);
        if (!in_array($row['record_type'], ['A','AAAA','CNAME','ALIAS','MX','TXT','NS'], true)) {
            $row['record_type'] = 'A';
        }
        if ($isCreate) {
            $row['id'] = Utils::generateUUID();
            $row['created_by'] = $this->decoded->user_id;
        }
        return $row;
    }
}
