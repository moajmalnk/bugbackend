<?php
require_once __DIR__ . '/AssetsAuth.php';

class AssetsEmailsController extends AssetsAuth
{
    public function listAll(): void
    {
        if (!$this->requireView()) {
            return;
        }
        if (!$this->tableReady('assets_emails')) {
            $p = $this->pagination();
            $this->sendPage([], 0, $p['page'], $p['limit']);
            return;
        }
        $p = $this->pagination();
        $where = ['e.deleted_at IS NULL', 'd.deleted_at IS NULL'];
        $params = [];
        $domainId = trim((string) ($_GET['domain_id'] ?? ''));
        if ($domainId !== '') {
            $where[] = 'e.domain_id = ?';
            $params[] = $domainId;
        }
        $userId = trim((string) ($_GET['user_id'] ?? ''));
        if ($userId !== '') {
            $where[] = 'e.assigned_user_id = ?';
            $params[] = $userId;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(e.address LIKE ? OR e.assigned_contact LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        try {
            $count = $this->conn->prepare(
                "SELECT COUNT(*) FROM assets_emails e JOIN assets_domains d ON d.id = e.domain_id WHERE {$sqlWhere}"
            );
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            // Why: users table uses username (not name) across BugRicer.
            $stmt = $this->conn->prepare(
                "SELECT e.*, d.fqdn AS domain_fqdn, d.client_id, c.client_code, c.corporate_name AS client_name,
                        u.username AS assigned_user_name
                 FROM assets_emails e
                 JOIN assets_domains d ON d.id = e.domain_id
                 LEFT JOIN clients c ON c.id = d.client_id
                 LEFT JOIN users u ON u.id = e.assigned_user_id
                 WHERE {$sqlWhere}
                 ORDER BY e.created_at DESC
                 LIMIT {$p['limit']} OFFSET {$p['offset']}"
            );
            $stmt->execute($params);
            $rows = $this->mapFinance($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            $rows = $this->attachHasSecret('email', $rows);
            $this->sendPage($rows, $total, $p['page'], $p['limit']);
        } catch (Throwable $e) {
            error_log('assets emails list failed: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Unable to load mailboxes');
        }
    }

    public function create(): void
    {
        if (!$this->requireCreate()) {
            return;
        }
        $data = $this->jsonInput();
        $fields = $this->buildFields($data, true);
        if ($fields === null) {
            return;
        }
        try {
            $this->insertRow('assets_emails', $fields);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Mailbox already exists');
                return;
            }
            throw $e;
        }
        $this->sendJsonResponse(201, 'Mailbox created', $this->maybeStripFinance($this->fetchLive('assets_emails', $fields['id'])));
    }

    public function update(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? ''));
        if ($id === '' || !$this->fetchLive('assets_emails', $id)) {
            $this->sendJsonResponse(404, 'Mailbox not found');
            return;
        }
        $fields = $this->buildFields($data, false);
        if ($fields === null) {
            return;
        }
        unset($fields['id'], $fields['created_by']);
        $this->updateRow('assets_emails', $id, $fields);
        $this->sendJsonResponse(200, 'Mailbox updated', $this->maybeStripFinance($this->fetchLive('assets_emails', $id)));
    }

    public function delete(): void
    {
        if (!$this->requireDelete()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? $this->jsonInput()['id'] ?? ''));
        $row = $id !== '' ? $this->fetchLive('assets_emails', $id) : null;
        if (!$row) {
            $this->sendJsonResponse(404, 'Mailbox not found');
            return;
        }
        $this->softDeleteAsset('asset_email', $id, (string) $row['address']);
        $this->sendJsonResponse(200, 'Mailbox moved to recycle bin');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildFields(array $data, bool $isCreate): ?array
    {
        $address = strtolower(trim((string) ($data['address'] ?? '')));
        if ($isCreate && ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL))) {
            $this->sendJsonResponse(400, 'Valid mailbox address is required');
            return null;
        }
        $domainId = trim((string) ($data['domain_id'] ?? ''));
        if ($isCreate && ($domainId === '' || !$this->fetchLive('assets_domains', $domainId))) {
            $this->sendJsonResponse(400, 'Valid domain_id is required');
            return null;
        }
        $fields = assetExtractBilling($data, false);
        if ($isCreate || array_key_exists('domain_id', $data)) {
            $fields['domain_id'] = $domainId;
        }
        if ($isCreate || array_key_exists('address', $data)) {
            if ($address !== '' && !filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $this->sendJsonResponse(400, 'Invalid mailbox address');
                return null;
            }
            if ($address !== '') {
                $fields['address'] = substr($address, 0, 255);
            }
        }
        if (array_key_exists('provider', $data) || $isCreate) {
            $fields['provider'] = $this->enumOr((string) ($data['provider'] ?? 'hostinger'), ['hostinger','google','zoho','microsoft','other'], 'hostinger');
        }
        if (array_key_exists('storage_quota_mb', $data)) {
            $fields['storage_quota_mb'] = $data['storage_quota_mb'] === '' || $data['storage_quota_mb'] === null
                ? null
                : max(0, (int) $data['storage_quota_mb']);
        }
        if (array_key_exists('assigned_user_id', $data) || $isCreate) {
            $fields['assigned_user_id'] = assetNullableString($data['assigned_user_id'] ?? null, 36);
        }
        if (array_key_exists('assigned_contact', $data) || $isCreate) {
            $fields['assigned_contact'] = assetNullableString($data['assigned_contact'] ?? null, 255);
        }
        if (array_key_exists('notes', $data)) {
            $fields['notes'] = assetNullableString($data['notes'], 5000);
        }
        if (array_key_exists('status', $data) || $isCreate) {
            $fields['status'] = $this->enumOr((string) ($data['status'] ?? 'active'), ['active','suspended','deleted'], 'active');
        }
        if ($isCreate) {
            $fields['id'] = Utils::generateUUID();
            $fields['created_by'] = $this->decoded->user_id;
        }
        return $fields;
    }
}
