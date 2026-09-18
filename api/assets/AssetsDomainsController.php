<?php
require_once __DIR__ . '/AssetsAuth.php';

class AssetsDomainsController extends AssetsAuth
{
    public function listAll(): void
    {
        if (!$this->requireView()) {
            return;
        }
        if (!$this->tableReady('assets_domains')) {
            $p = $this->pagination();
            $this->sendPage([], 0, $p['page'], $p['limit']);
            return;
        }

        $p = $this->pagination();
        $where = ['d.deleted_at IS NULL'];
        $params = [];
        $clientId = trim((string) ($_GET['client_id'] ?? ''));
        if ($clientId !== '') {
            $where[] = 'd.client_id = ?';
            $params[] = $clientId;
        }
        $status = trim((string) ($_GET['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'd.status = ?';
            $params[] = $status;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            if ($this->columnReady('clients', 'client_code')) {
                $where[] = '(d.fqdn LIKE ? OR c.corporate_name LIKE ? OR c.client_code LIKE ?)';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            } else {
                $where[] = '(d.fqdn LIKE ? OR c.corporate_name LIKE ?)';
                $params[] = $like;
                $params[] = $like;
            }
        }
        $expiresIn = (int) ($_GET['expires_in'] ?? 0);
        if ($expiresIn > 0) {
            $where[] = 'd.expires_at IS NOT NULL AND d.expires_at BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)';
            $params[] = $expiresIn;
        }
        $sqlWhere = implode(' AND ', $where);

        try {
            $count = $this->conn->prepare(
                "SELECT COUNT(*) FROM assets_domains d
                 LEFT JOIN clients c ON c.id = d.client_id
                 WHERE {$sqlWhere}"
            );
            $count->execute($params);
            $total = (int) $count->fetchColumn();

            $clientCode = $this->clientCodeSql('c');
            $stmt = $this->conn->prepare(
                "SELECT d.*, c.corporate_name AS client_name, {$clientCode},
                        p.name AS project_name
                 FROM assets_domains d
                 LEFT JOIN clients c ON c.id = d.client_id
                 LEFT JOIN projects p ON p.id = d.project_id
                 WHERE {$sqlWhere}
                 ORDER BY d.created_at DESC
                 LIMIT {$p['limit']} OFFSET {$p['offset']}"
            );
            $stmt->execute($params);
            $rows = $this->mapFinance($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            $rows = $this->attachHasSecret('domain', $rows);
            $this->decodeNameservers($rows);
            $this->sendPage($rows, $total, $p['page'], $p['limit']);
        } catch (Throwable $e) {
            error_log('assets domains list failed: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Unable to load domains');
        }
    }

    public function getOne(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }
        try {
            $clientCode = $this->clientCodeSql('c');
            $stmt = $this->conn->prepare(
                "SELECT d.*, c.corporate_name AS client_name, {$clientCode}, p.name AS project_name
                 FROM assets_domains d
                 LEFT JOIN clients c ON c.id = d.client_id
                 LEFT JOIN projects p ON p.id = d.project_id
                 WHERE d.id = ? AND d.deleted_at IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->sendJsonResponse(404, 'Domain not found');
                return;
            }
            $row = $this->maybeStripFinance($row);
            $row = $this->attachHasSecret('domain', [$row])[0];
            $nsRows = [$row];
            $this->decodeNameservers($nsRows);
            $row = $nsRows[0];

            $row['subdomains'] = [];
            if ($this->tableReady('assets_subdomains')) {
                $subs = $this->conn->prepare(
                    'SELECT * FROM assets_subdomains WHERE domain_id = ? AND deleted_at IS NULL ORDER BY created_at DESC'
                );
                $subs->execute([$id]);
                $row['subdomains'] = $subs->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $row['emails'] = [];
            if ($this->tableReady('assets_emails')) {
                $mails = $this->conn->prepare(
                    'SELECT * FROM assets_emails WHERE domain_id = ? AND deleted_at IS NULL ORDER BY created_at DESC'
                );
                $mails->execute([$id]);
                $row['emails'] = $this->mapFinance($mails->fetchAll(PDO::FETCH_ASSOC) ?: []);
                $row['emails'] = $this->attachHasSecret('email', $row['emails']);
            }

            $row['ssl_certs'] = [];
            if ($this->tableReady('assets_ssl_certs')) {
                $ssl = $this->conn->prepare(
                    'SELECT * FROM assets_ssl_certs WHERE domain_id = ? AND deleted_at IS NULL ORDER BY expires_at ASC'
                );
                $ssl->execute([$id]);
                $row['ssl_certs'] = $this->mapFinance($ssl->fetchAll(PDO::FETCH_ASSOC) ?: []);
            }

            $this->sendJsonResponse(200, 'OK', $row);
        } catch (Throwable $e) {
            error_log('assets domain get failed: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Unable to load domain');
        }
    }

    public function create(): void
    {
        if (!$this->requireCreate()) {
            return;
        }
        $data = $this->jsonInput();
        $fqdn = assetSanitizeFqdn((string) ($data['fqdn'] ?? ''));
        $clientId = trim((string) ($data['client_id'] ?? ''));
        if ($fqdn === null || $clientId === '') {
            $this->sendJsonResponse(400, 'Valid fqdn and client_id are required');
            return;
        }
        $id = Utils::generateUUID();
        $fields = array_merge([
            'id' => $id,
            'client_id' => $clientId,
            'project_id' => assetNullableString($data['project_id'] ?? null, 36),
            'fqdn' => $fqdn,
            'registrar' => assetNullableString($data['registrar'] ?? null, 80),
            'dns_provider' => assetNullableString($data['dns_provider'] ?? null, 80),
            'nameservers' => $this->encodeNameservers($data['nameservers'] ?? null),
            'whois_privacy' => assetBool($data['whois_privacy'] ?? true) ? 1 : 0,
            'notes' => assetNullableString($data['notes'] ?? null, 5000),
            'created_by' => $this->decoded->user_id,
            'status' => $this->enumOr((string) ($data['status'] ?? 'active'), ['active','pending','expired','cancelled','parked'], 'active'),
        ], assetExtractBilling($data, false));
        try {
            $this->insertRow('assets_domains', $fields);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Domain already exists');
                return;
            }
            throw $e;
        }
        $_GET['id'] = $id;
        $this->getOne();
    }

    public function update(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? $_GET['id'] ?? ''));
        if ($id === '' || !$this->fetchLive('assets_domains', $id)) {
            $this->sendJsonResponse(404, 'Domain not found');
            return;
        }
        $fields = assetExtractBilling($data, false);
        if (array_key_exists('fqdn', $data)) {
            $fqdn = assetSanitizeFqdn((string) $data['fqdn']);
            if ($fqdn === null) {
                $this->sendJsonResponse(400, 'Invalid fqdn');
                return;
            }
            $fields['fqdn'] = $fqdn;
        }
        foreach (['client_id', 'project_id', 'registrar', 'dns_provider', 'notes'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[$col] = $col === 'notes'
                    ? assetNullableString($data[$col], 5000)
                    : assetNullableString($data[$col], $col === 'client_id' || $col === 'project_id' ? 36 : 80);
            }
        }
        if (array_key_exists('nameservers', $data)) {
            $fields['nameservers'] = $this->encodeNameservers($data['nameservers']);
        }
        if (array_key_exists('whois_privacy', $data)) {
            $fields['whois_privacy'] = assetBool($data['whois_privacy']) ? 1 : 0;
        }
        if (array_key_exists('status', $data)) {
            $fields['status'] = $this->enumOr((string) $data['status'], ['active','pending','expired','cancelled','parked'], 'active');
        }
        if ($fields === []) {
            $this->sendJsonResponse(400, 'No fields to update');
            return;
        }
        $this->updateRow('assets_domains', $id, $fields);
        $_GET['id'] = $id;
        $this->getOne();
    }

    public function delete(): void
    {
        if (!$this->requireDelete()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? $this->jsonInput()['id'] ?? ''));
        $row = $id !== '' ? $this->fetchLive('assets_domains', $id) : null;
        if (!$row) {
            $this->sendJsonResponse(404, 'Domain not found');
            return;
        }
        $this->softDeleteAsset('asset_domain', $id, (string) $row['fqdn'], (string) ($row['client_id'] ?? ''));
        $this->sendJsonResponse(200, 'Domain moved to recycle bin');
    }

    public function createSsl(): void
    {
        if (!$this->requireCreate()) {
            return;
        }
        $data = $this->jsonInput();
        $domainId = trim((string) ($data['domain_id'] ?? ''));
        $expires = assetDateOrNull($data['expires_at'] ?? null);
        if ($domainId === '' || !$this->fetchLive('assets_domains', $domainId) || $expires === null) {
            $this->sendJsonResponse(400, 'domain_id and expires_at are required');
            return;
        }
        $id = Utils::generateUUID();
        $fields = array_merge([
            'id' => $id,
            'domain_id' => $domainId,
            'subdomain_id' => assetNullableString($data['subdomain_id'] ?? null, 36),
            'issuer' => assetNullableString($data['issuer'] ?? null, 80),
            'covers' => assetNullableString($data['covers'] ?? null, 255),
            'notes' => assetNullableString($data['notes'] ?? null, 5000),
            'created_by' => $this->decoded->user_id,
            'status' => $this->enumOr((string) ($data['status'] ?? 'active'), ['active','expiring','expired'], 'active'),
        ], assetExtractBilling($data, false));
        $fields['expires_at'] = $expires;
        $this->insertRow('assets_ssl_certs', $fields);
        $this->sendJsonResponse(201, 'SSL certificate added', $this->maybeStripFinance($this->fetchLive('assets_ssl_certs', $id)));
    }

    public function deleteSsl(): void
    {
        if (!$this->requireDelete()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? $this->jsonInput()['id'] ?? ''));
        $row = $id !== '' ? $this->fetchLive('assets_ssl_certs', $id) : null;
        if (!$row) {
            $this->sendJsonResponse(404, 'Certificate not found');
            return;
        }
        $stmt = $this->conn->prepare(
            'UPDATE assets_ssl_certs SET deleted_at = NOW(), deleted_by = ? WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$this->decoded->user_id, $id]);
        $this->sendJsonResponse(200, 'Certificate deleted');
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function decodeNameservers(array &$rows): void
    {
        foreach ($rows as &$row) {
            if (!array_key_exists('nameservers', $row)) {
                continue;
            }
            $ns = $row['nameservers'];
            if (is_string($ns) && $ns !== '') {
                $decoded = json_decode($ns, true);
                $row['nameservers'] = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($ns)) {
                $row['nameservers'] = [];
            }
        }
        unset($row);
    }

    /**
     * @param mixed $value
     */
    private function encodeNameservers($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', $value);
            $value = $parts ?: [];
        }
        if (!is_array($value)) {
            return null;
        }
        $clean = [];
        foreach ($value as $ns) {
            $s = assetSanitizeFqdn((string) $ns);
            if ($s) {
                $clean[] = $s;
            }
        }
        return $clean === [] ? null : json_encode(array_values($clean));
    }
}
