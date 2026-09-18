<?php
require_once __DIR__ . '/AssetsAuth.php';

class AssetsHardwareController extends AssetsAuth
{
    public function listAll(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $p = $this->pagination();
        if (!$this->tableReady('assets_hardware')) {
            $this->sendPage([], 0, $p['page'], $p['limit']);
            return;
        }
        $where = ['h.deleted_at IS NULL'];
        $params = [];
        $assignee = trim((string) ($_GET['assignee'] ?? $_GET['user_id'] ?? ''));
        if ($assignee !== '') {
            $where[] = 'h.assigned_user_id = ?';
            $params[] = $assignee;
        }
        $status = trim((string) ($_GET['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'h.status = ?';
            $params[] = $status;
        }
        $category = trim((string) ($_GET['category'] ?? ''));
        if ($category !== '') {
            $where[] = 'h.category = ?';
            $params[] = $category;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(h.asset_tag LIKE ? OR h.model LIKE ? OR h.serial_no LIKE ? OR h.imei LIKE ? OR h.brand LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);
        try {
            $count = $this->conn->prepare("SELECT COUNT(*) FROM assets_hardware h WHERE {$sqlWhere}");
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            // Why: users table uses username (not name) across BugRicer.
            $clientCode = $this->clientCodeSql('c');
            $stmt = $this->conn->prepare(
                "SELECT h.*, u.username AS assigned_user_name, {$clientCode}, c.corporate_name AS client_name
                 FROM assets_hardware h
                 LEFT JOIN users u ON u.id = h.assigned_user_id
                 LEFT JOIN clients c ON c.id = h.client_id
                 WHERE {$sqlWhere}
                 ORDER BY h.created_at DESC
                 LIMIT {$p['limit']} OFFSET {$p['offset']}"
            );
            $stmt->execute($params);
            $rows = $this->mapFinance($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            $rows = $this->attachHasSecret('hardware', $rows);
            $this->sendPage($rows, $total, $p['page'], $p['limit']);
        } catch (Throwable $e) {
            error_log('assets hardware list failed: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Unable to load hardware');
        }
    }

    public function getOne(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(404, 'Hardware not found');
            return;
        }
        try {
            $clientCode = $this->clientCodeSql('c');
            $stmt = $this->conn->prepare(
                "SELECT h.*, u.username AS assigned_user_name, {$clientCode}, c.corporate_name AS client_name
                 FROM assets_hardware h
                 LEFT JOIN users u ON u.id = h.assigned_user_id
                 LEFT JOIN clients c ON c.id = h.client_id
                 WHERE h.id = ? AND h.deleted_at IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('assets hardware get failed: ' . $e->getMessage());
            $row = $this->fetchLive('assets_hardware', $id);
        }
        if (!$row) {
            $this->sendJsonResponse(404, 'Hardware not found');
            return;
        }
        $row = $this->maybeStripFinance($row);
        $row = $this->attachHasSecret('hardware', [$row])[0];
        $this->sendJsonResponse(200, 'OK', $row);
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
            $this->insertRow('assets_hardware', $fields);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Asset tag or serial already exists');
                return;
            }
            throw $e;
        }
        $_GET['id'] = $fields['id'];
        $this->getOne();
    }

    public function update(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? ''));
        if ($id === '' || !$this->fetchLive('assets_hardware', $id)) {
            $this->sendJsonResponse(404, 'Hardware not found');
            return;
        }
        $fields = $this->buildFields($data, false);
        if ($fields === null) {
            return;
        }
        unset($fields['id'], $fields['created_by']);
        $this->updateRow('assets_hardware', $id, $fields);
        $_GET['id'] = $id;
        $this->getOne();
    }

    public function assign(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $id = trim((string) ($data['id'] ?? ''));
        $row = $id !== '' ? $this->fetchLive('assets_hardware', $id) : null;
        if (!$row) {
            $this->sendJsonResponse(404, 'Hardware not found');
            return;
        }
        $userId = assetNullableString($data['assigned_user_id'] ?? null, 36);
        $status = $userId ? 'assigned' : 'in_stock';
        if (!empty($data['status'])) {
            $status = $this->enumOr((string) $data['status'], ['in_stock','assigned','repair','retired','lost'], $status);
        }
        $this->updateRow('assets_hardware', $id, [
            'assigned_user_id' => $userId,
            'status' => $status,
        ]);
        $_GET['id'] = $id;
        $this->getOne();
    }

    public function delete(): void
    {
        if (!$this->requireDelete()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? $this->jsonInput()['id'] ?? ''));
        $row = $id !== '' ? $this->fetchLive('assets_hardware', $id) : null;
        if (!$row) {
            $this->sendJsonResponse(404, 'Hardware not found');
            return;
        }
        $title = trim(($row['asset_tag'] ?? '') . ' ' . ($row['model'] ?? ''));
        $this->softDeleteAsset('asset_hardware', $id, $title !== '' ? $title : 'Hardware');
        $this->sendJsonResponse(200, 'Hardware moved to recycle bin');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildFields(array $data, bool $isCreate): ?array
    {
        $fields = assetExtractBilling($data, false);
        unset($fields['billing_cycle'], $fields['auto_renew'], $fields['purchased_at'], $fields['expires_at'], $fields['status']);
        if ($isCreate) {
            $fields['asset_tag'] = assetNullableString($data['asset_tag'] ?? null, 32)
                ?: assetNextHardwareTag($this->conn);
        } elseif (array_key_exists('asset_tag', $data)) {
            $tag = assetNullableString($data['asset_tag'], 32);
            if ($tag) {
                $fields['asset_tag'] = $tag;
            }
        }
        if (array_key_exists('category', $data) || $isCreate) {
            $fields['category'] = $this->enumOr(
                (string) ($data['category'] ?? 'laptop'),
                ['laptop','test_phone','office_device','network','other'],
                'laptop'
            );
        }
        foreach (['brand' => 80, 'model' => 120, 'notes' => 5000] as $col => $max) {
            if (array_key_exists($col, $data) || $isCreate) {
                $fields[$col] = assetNullableString($data[$col] ?? null, $max);
            }
        }
        if (array_key_exists('serial_no', $data) || $isCreate) {
            $serial = assetNullableString($data['serial_no'] ?? null, 120);
            $fields['serial_no'] = $serial;
        }
        if (array_key_exists('imei', $data) || $isCreate) {
            $fields['imei'] = assetClampImei(isset($data['imei']) ? (string) $data['imei'] : null);
        }
        if (array_key_exists('assigned_user_id', $data) || $isCreate) {
            $fields['assigned_user_id'] = assetNullableString($data['assigned_user_id'] ?? null, 36);
        }
        if (array_key_exists('client_id', $data) || $isCreate) {
            $fields['client_id'] = assetNullableString($data['client_id'] ?? null, 36);
        }
        if (array_key_exists('purchase_date', $data) || $isCreate) {
            $fields['purchase_date'] = assetDateOrNull($data['purchase_date'] ?? null);
        }
        if (array_key_exists('warranty_expires_at', $data) || $isCreate) {
            $fields['warranty_expires_at'] = assetDateOrNull($data['warranty_expires_at'] ?? null);
        }
        if (array_key_exists('status', $data) || $isCreate) {
            $default = !empty($fields['assigned_user_id']) ? 'assigned' : 'in_stock';
            $fields['status'] = $this->enumOr(
                (string) ($data['status'] ?? $default),
                ['in_stock','assigned','repair','retired','lost'],
                $default
            );
        }
        if ($isCreate) {
            $fields['id'] = Utils::generateUUID();
            $fields['created_by'] = $this->decoded->user_id;
        }
        return $fields;
    }
}
