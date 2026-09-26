<?php
require_once __DIR__ . '/AssetsAuth.php';

class AssetsToolsController extends AssetsAuth
{
    private const CATEGORIES = ['ai', 'design', 'devops', 'productivity', 'marketing', 'communication', 'other'];
    private const STATUSES = ['active', 'trial', 'expired', 'cancelled', 'paused'];

    public function listAll(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $p = $this->pagination();
        if (!$this->tableReady('assets_tools')) {
            $this->sendPage([], 0, $p['page'], $p['limit']);
            return;
        }
        $where = ['t.deleted_at IS NULL'];
        $params = [];
        $status = trim((string) ($_GET['status'] ?? ''));
        if ($status !== '') {
            $where[] = 't.status = ?';
            $params[] = $status;
        }
        $category = trim((string) ($_GET['category'] ?? ''));
        if ($category !== '') {
            $where[] = 't.category = ?';
            $params[] = $category;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(t.name LIKE ? OR t.slug LIKE ? OR t.vendor LIKE ? OR t.plan_name LIKE ? OR t.account_email LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sqlWhere = implode(' AND ', $where);
        try {
            $count = $this->conn->prepare("SELECT COUNT(*) FROM assets_tools t WHERE {$sqlWhere}");
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $stmt = $this->conn->prepare(
                "SELECT t.*,
                        (SELECT COUNT(*) FROM assets_tool_seats s WHERE s.tool_id = t.id) AS seats_used
                 FROM assets_tools t
                 WHERE {$sqlWhere}
                 ORDER BY t.created_at DESC
                 LIMIT {$p['limit']} OFFSET {$p['offset']}"
            );
            $stmt->execute($params);
            $rows = $this->mapFinance($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            $rows = $this->attachHasSecret('tool', $rows);
            $this->sendPage($rows, $total, $p['page'], $p['limit']);
        } catch (Throwable $e) {
            error_log('assets tools list failed: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Unable to load premium tools');
        }
    }

    public function getOne(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? ''));
        if ($id === '') {
            $this->sendJsonResponse(404, 'Tool not found');
            return;
        }
        $row = $this->fetchTool($id);
        if (!$row) {
            $this->sendJsonResponse(404, 'Tool not found');
            return;
        }
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
            $this->insertRow('assets_tools', $fields);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Tool slug already exists');
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
        if ($id === '' || !$this->fetchLive('assets_tools', $id)) {
            $this->sendJsonResponse(404, 'Tool not found');
            return;
        }
        $fields = $this->buildFields($data, false);
        if ($fields === null) {
            return;
        }
        unset($fields['id'], $fields['created_by']);
        try {
            $this->updateRow('assets_tools', $id, $fields);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                $this->sendJsonResponse(409, 'Tool slug already exists');
                return;
            }
            throw $e;
        }
        $_GET['id'] = $id;
        $this->getOne();
    }

    public function delete(): void
    {
        if (!$this->requireDelete()) {
            return;
        }
        $id = trim((string) ($_GET['id'] ?? $this->jsonInput()['id'] ?? ''));
        $row = $id !== '' ? $this->fetchLive('assets_tools', $id) : null;
        if (!$row) {
            $this->sendJsonResponse(404, 'Tool not found');
            return;
        }
        $title = trim((string) ($row['name'] ?? 'Premium tool'));
        $this->softDeleteAsset('asset_tool', $id, $title !== '' ? $title : 'Premium tool');
        $this->sendJsonResponse(200, 'Tool moved to recycle bin');
    }

    public function listSeats(): void
    {
        if (!$this->requireView()) {
            return;
        }
        $toolId = trim((string) ($_GET['tool_id'] ?? $_GET['id'] ?? ''));
        if ($toolId === '' || !$this->fetchLive('assets_tools', $toolId)) {
            $this->sendJsonResponse(404, 'Tool not found');
            return;
        }
        $seats = $this->fetchSeats($toolId);
        $this->sendJsonResponse(200, 'OK', ['items' => $seats, 'total' => count($seats)]);
    }

    public function assignSeat(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        if (!$this->tableReady('assets_tool_seats')) {
            $this->sendJsonResponse(503, 'Seats table not ready — run migration 109');
            return;
        }
        $data = $this->jsonInput();
        $toolId = trim((string) ($data['tool_id'] ?? ''));
        if ($toolId === '' || !$this->fetchLive('assets_tools', $toolId)) {
            $this->sendJsonResponse(404, 'Tool not found');
            return;
        }
        $userId = assetNullableString($data['user_id'] ?? null, 36);
        $externalName = assetNullableString($data['external_name'] ?? null, 120);
        $externalEmail = assetNullableString($data['external_email'] ?? null, 255);
        if (!$userId && !$externalName) {
            $this->sendJsonResponse(400, 'Assign a BugRicer user or provide an external name');
            return;
        }
        if ($userId) {
            $chk = $this->conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $chk->execute([$userId]);
            if (!$chk->fetchColumn()) {
                $this->sendJsonResponse(400, 'User not found');
                return;
            }
            $dup = $this->conn->prepare(
                'SELECT id FROM assets_tool_seats WHERE tool_id = ? AND user_id = ? LIMIT 1'
            );
            $dup->execute([$toolId, $userId]);
            if ($dup->fetchColumn()) {
                $this->sendJsonResponse(409, 'User already has a seat on this tool');
                return;
            }
        }
        $seatId = Utils::generateUUID();
        $fields = [
            'id' => $seatId,
            'tool_id' => $toolId,
            'user_id' => $userId,
            'external_name' => $externalName,
            'external_email' => $externalEmail,
            'seat_role' => assetNullableString($data['seat_role'] ?? null, 80),
            'notes' => assetNullableString($data['notes'] ?? null, 500),
        ];
        $this->insertRow('assets_tool_seats', $fields);
        $_GET['id'] = $toolId;
        $this->getOne();
    }

    public function unassignSeat(): void
    {
        if (!$this->requireEdit()) {
            return;
        }
        $data = $this->jsonInput();
        $seatId = trim((string) ($data['id'] ?? $data['seat_id'] ?? ''));
        if ($seatId === '') {
            $this->sendJsonResponse(400, 'Seat id is required');
            return;
        }
        $stmt = $this->conn->prepare(
            'SELECT s.*, t.deleted_at AS tool_deleted
             FROM assets_tool_seats s
             JOIN assets_tools t ON t.id = s.tool_id
             WHERE s.id = ?
             LIMIT 1'
        );
        $stmt->execute([$seatId]);
        $seat = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$seat || $seat['tool_deleted'] !== null) {
            $this->sendJsonResponse(404, 'Seat not found');
            return;
        }
        $toolId = (string) $seat['tool_id'];
        $del = $this->conn->prepare('DELETE FROM assets_tool_seats WHERE id = ?');
        $del->execute([$seatId]);
        $_GET['id'] = $toolId;
        $this->getOne();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTool(string $id): ?array
    {
        if (!$this->tableReady('assets_tools')) {
            return null;
        }
        try {
            $stmt = $this->conn->prepare(
                "SELECT t.*,
                        (SELECT COUNT(*) FROM assets_tool_seats s WHERE s.tool_id = t.id) AS seats_used
                 FROM assets_tools t
                 WHERE t.id = ? AND t.deleted_at IS NULL
                 LIMIT 1"
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('assets tools get failed: ' . $e->getMessage());
            $row = $this->fetchLive('assets_tools', $id);
        }
        if (!$row) {
            return null;
        }
        $row = $this->maybeStripFinance($row);
        $row = $this->attachHasSecret('tool', [$row])[0];
        $row['seats'] = $this->fetchSeats($id);
        $row['seats_used'] = isset($row['seats_used'])
            ? (int) $row['seats_used']
            : count($row['seats']);
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSeats(string $toolId): array
    {
        if (!$this->tableReady('assets_tool_seats')) {
            return [];
        }
        try {
            $stmt = $this->conn->prepare(
                "SELECT s.*,
                        u.username AS user_name,
                        u.email AS user_email
                 FROM assets_tool_seats s
                 LEFT JOIN users u ON u.id = s.user_id
                 WHERE s.tool_id = ?
                 ORDER BY s.assigned_at DESC"
            );
            $stmt->execute([$toolId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('assets tool seats list failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function buildFields(array $data, bool $isCreate): ?array
    {
        $fields = assetExtractBilling($data, false);
        if (array_key_exists('name', $data) || $isCreate) {
            $name = assetNullableString($data['name'] ?? null, 150);
            if (!$name) {
                $this->sendJsonResponse(400, 'Tool name is required');
                return null;
            }
            $fields['name'] = $name;
        }
        if (array_key_exists('slug', $data) || $isCreate) {
            $slugSrc = assetNullableString($data['slug'] ?? null, 160)
                ?: assetNullableString($data['name'] ?? null, 160)
                ?: 'tool';
            $fields['slug'] = $this->slugify($slugSrc, $isCreate ? null : trim((string) ($data['id'] ?? '')));
        }
        if (array_key_exists('category', $data) || $isCreate) {
            $fields['category'] = $this->enumOr(
                (string) ($data['category'] ?? 'other'),
                self::CATEGORIES,
                'other'
            );
        }
        foreach (['plan_name' => 120, 'login_url' => 500, 'account_email' => 255, 'notes' => 5000] as $col => $max) {
            if (array_key_exists($col, $data) || $isCreate) {
                $fields[$col] = assetNullableString($data[$col] ?? null, $max);
            }
        }
        // vendor comes from billing extract; allow override if only name set
        if ((array_key_exists('vendor', $data) || $isCreate) && !array_key_exists('vendor', $fields)) {
            $fields['vendor'] = assetNullableString($data['vendor'] ?? null, 100);
        }
        if (array_key_exists('seats_total', $data) || $isCreate) {
            $raw = $data['seats_total'] ?? null;
            if ($raw === null || $raw === '') {
                $fields['seats_total'] = null;
            } else {
                $n = (int) $raw;
                $fields['seats_total'] = $n > 0 ? min($n, 100000) : null;
            }
        }
        if (array_key_exists('status', $data) || $isCreate) {
            $fields['status'] = $this->enumOr(
                (string) ($data['status'] ?? 'active'),
                self::STATUSES,
                'active'
            );
        }
        if ($isCreate) {
            $fields['id'] = Utils::generateUUID();
            $fields['created_by'] = $this->decoded->user_id;
        }
        return $fields;
    }

    private function slugify(string $raw, ?string $excludeId = null): string
    {
        $s = strtolower(trim($raw));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') {
            $s = 'tool';
        }
        if (function_exists('mb_substr')) {
            $s = mb_substr($s, 0, 140);
        } else {
            $s = substr($s, 0, 140);
        }
        $base = $s;
        $n = 0;
        while ($this->slugTaken($s, $excludeId)) {
            $n++;
            $suffix = '-' . $n;
            $s = substr($base, 0, 160 - strlen($suffix)) . $suffix;
            if ($n > 50) {
                $s = substr($base, 0, 120) . '-' . substr(Utils::generateUUID(), 0, 8);
                break;
            }
        }
        return $s;
    }

    private function slugTaken(string $slug, ?string $excludeId): bool
    {
        try {
            if ($excludeId) {
                $stmt = $this->conn->prepare(
                    'SELECT 1 FROM assets_tools WHERE slug = ? AND id <> ? AND deleted_at IS NULL LIMIT 1'
                );
                $stmt->execute([$slug, $excludeId]);
            } else {
                $stmt = $this->conn->prepare(
                    'SELECT 1 FROM assets_tools WHERE slug = ? AND deleted_at IS NULL LIMIT 1'
                );
                $stmt->execute([$slug]);
            }
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}
