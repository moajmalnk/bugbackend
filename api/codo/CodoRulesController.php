<?php
require_once __DIR__ . '/../BaseAPI.php';

class CodoRulesController extends BaseAPI
{
    private const TEAM_ROLES = ['admin', 'developer', 'tester'];
    private const PHASES = ['developer', 'tester', 'project'];

    public function __construct()
    {
        parent::__construct();
    }

    private function requireTeamAuth()
    {
        try {
            $decoded = $this->validateToken();
        } catch (Throwable $e) {
            $this->sendJsonResponse(401, $e->getMessage() ?: 'Authentication failed');
            return null;
        }
        if (!$decoded || !isset($decoded->user_id)) {
            $this->sendJsonResponse(401, 'Authentication failed');
            return null;
        }
        $role = strtolower(trim((string)($decoded->role ?? '')));
        if (!in_array($role, self::TEAM_ROLES, true)) {
            $this->sendJsonResponse(403, 'Access denied. Common CODO is available to admin, developer, and tester.');
            return null;
        }
        return $decoded;
    }

    private function tablesReady(): bool
    {
        try {
            $res = $this->conn->query("SHOW TABLES LIKE 'codo_common_rules'");
            return (bool)($res && $res->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {
            return false;
        }
    }

    private function ackTableReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $res = $this->conn->query("SHOW TABLES LIKE 'codo_rule_acknowledgements'");
            $ready = (bool)($res && $res->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    private function usersHasAccountActive(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $res = $this->conn->query("SHOW COLUMNS FROM users LIKE 'account_active'");
            $has = (bool)($res && $res->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /**
     * Roles that must acknowledge a rule for a given phase.
     * @return string[]
     */
    private function requiredRolesForPhase(string $phase): array
    {
        if ($phase === 'developer') {
            return ['developer'];
        }
        if ($phase === 'tester') {
            return ['tester'];
        }
        // project rules: both developers and testers
        return ['developer', 'tester'];
    }

    /**
     * Active users who must acknowledge rules for the given phase.
     * @return array<int, array{id:string,username:string,role:string}>
     */
    private function requiredUsersForPhase(string $phase): array
    {
        $roles = $this->requiredRolesForPhase($phase);
        if (empty($roles)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $sql = "SELECT id, username, role FROM users WHERE role IN ($placeholders)";
        $params = $roles;
        if ($this->usersHasAccountActive()) {
            $sql .= ' AND account_active = 1';
        }
        $sql .= ' ORDER BY username ASC';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function ($r) {
            return [
                'id' => (string)$r['id'],
                'username' => (string)$r['username'],
                'role' => strtolower((string)$r['role']),
            ];
        }, $rows);
    }

    private function buildAckSummary(int $ruleId, string $phase, string $currentUserId): array
    {
        $required = $this->requiredUsersForPhase($phase);
        $requiredIds = array_column($required, 'id');
        $ackMap = [];
        if ($this->ackTableReady() && !empty($requiredIds)) {
            $placeholders = implode(',', array_fill(0, count($requiredIds), '?'));
            $stmt = $this->conn->prepare(
                "SELECT user_id, acknowledged_at FROM codo_rule_acknowledgements
                 WHERE rule_id = ? AND user_id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$ruleId], $requiredIds));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ackMap[(string)$row['user_id']] = $row['acknowledged_at'];
            }
        }

        $acknowledged = [];
        $pending = [];
        foreach ($required as $u) {
            if (isset($ackMap[$u['id']])) {
                $acknowledged[] = [
                    'id' => $u['id'],
                    'username' => $u['username'],
                    'role' => $u['role'],
                    'acknowledged_at' => $ackMap[$u['id']],
                ];
            } else {
                $pending[] = [
                    'id' => $u['id'],
                    'username' => $u['username'],
                    'role' => $u['role'],
                ];
            }
        }

        $mustAck = in_array($currentUserId, $requiredIds, true);

        return [
            'required_total' => count($required),
            'acknowledged_count' => count($acknowledged),
            'pending_count' => count($pending),
            'current_user_acknowledged' => isset($ackMap[$currentUserId]),
            'current_user_must_acknowledge' => $mustAck && !isset($ackMap[$currentUserId]),
            'acknowledged' => $acknowledged,
            'pending' => $pending,
        ];
    }

    /**
     * @param array<int, array> $items formatted rules
     * @return array<int, array>
     */
    private function attachAckSummaries(array $items, string $currentUserId): array
    {
        if (empty($items)) {
            return $items;
        }

        $empty = [
            'required_total' => 0,
            'acknowledged_count' => 0,
            'pending_count' => 0,
            'current_user_acknowledged' => false,
            'current_user_must_acknowledge' => false,
            'acknowledged' => [],
            'pending' => [],
        ];

        if (!$this->ackTableReady()) {
            foreach ($items as &$item) {
                $item['acknowledgements'] = $empty;
            }
            unset($item);
            return $items;
        }

        $usersByPhase = [];
        foreach (self::PHASES as $phase) {
            $usersByPhase[$phase] = $this->requiredUsersForPhase($phase);
        }

        $ruleIds = array_map(static fn($r) => (int)$r['id'], $items);
        $ackByRule = [];
        if (!empty($ruleIds)) {
            $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
            $stmt = $this->conn->prepare(
                "SELECT rule_id, user_id, acknowledged_at
                 FROM codo_rule_acknowledgements
                 WHERE rule_id IN ($placeholders)"
            );
            $stmt->execute($ruleIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rid = (int)$row['rule_id'];
                if (!isset($ackByRule[$rid])) {
                    $ackByRule[$rid] = [];
                }
                $ackByRule[$rid][(string)$row['user_id']] = $row['acknowledged_at'];
            }
        }

        foreach ($items as &$item) {
            $phase = (string)$item['phase'];
            $required = $usersByPhase[$phase] ?? [];
            $requiredIds = array_column($required, 'id');
            $ackMap = $ackByRule[(int)$item['id']] ?? [];
            $acknowledged = [];
            $pending = [];
            foreach ($required as $u) {
                if (isset($ackMap[$u['id']])) {
                    $acknowledged[] = [
                        'id' => $u['id'],
                        'username' => $u['username'],
                        'role' => $u['role'],
                        'acknowledged_at' => $ackMap[$u['id']],
                    ];
                } else {
                    $pending[] = [
                        'id' => $u['id'],
                        'username' => $u['username'],
                        'role' => $u['role'],
                    ];
                }
            }
            $mustAck = in_array($currentUserId, $requiredIds, true);
            $item['acknowledgements'] = [
                'required_total' => count($required),
                'acknowledged_count' => count($acknowledged),
                'pending_count' => count($pending),
                'current_user_acknowledged' => isset($ackMap[$currentUserId]),
                'current_user_must_acknowledge' => $mustAck && !isset($ackMap[$currentUserId]),
                'acknowledged' => $acknowledged,
                'pending' => $pending,
            ];
        }
        unset($item);
        return $items;
    }

    public function acknowledge($payload)
    {
        $decoded = $this->requireTeamAuth();
        if (!$decoded) {
            return;
        }
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(503, 'Common CODO is not set up. Run migration 022_codo_common_rules.sql.');
            return;
        }
        if (!$this->ackTableReady()) {
            $this->sendJsonResponse(503, 'Acknowledgements are not set up. Run migration 023_codo_rule_acknowledgements.sql.');
            return;
        }

        $ruleId = isset($payload['rule_id']) ? (int)$payload['rule_id'] : 0;
        if ($ruleId <= 0) {
            $this->sendJsonResponse(400, 'rule_id is required');
            return;
        }

        $fetch = $this->conn->prepare(
            'SELECT id, phase, is_active FROM codo_common_rules WHERE id = ? LIMIT 1'
        );
        $fetch->execute([$ruleId]);
        $rule = $fetch->fetch(PDO::FETCH_ASSOC);
        if (!$rule || (int)($rule['is_active'] ?? 1) !== 1) {
            $this->sendJsonResponse(404, 'Rule not found');
            return;
        }

        $userId = (string)$decoded->user_id;
        $role = strtolower(trim((string)($decoded->role ?? '')));
        $requiredRoles = $this->requiredRolesForPhase((string)$rule['phase']);
        if (!in_array($role, $requiredRoles, true)) {
            $this->sendJsonResponse(
                403,
                'Only ' . implode(' / ', $requiredRoles) . ' roles must acknowledge this rule'
            );
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO codo_rule_acknowledgements (rule_id, user_id, acknowledged_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE acknowledged_at = acknowledged_at"
            );
            $stmt->execute([$ruleId, $userId]);

            $summary = $this->buildAckSummary($ruleId, (string)$rule['phase'], $userId);
            $this->sendJsonResponse(200, 'Rule acknowledged', [
                'rule_id' => $ruleId,
                'acknowledgements' => $summary,
            ]);
        } catch (Throwable $e) {
            error_log('CodoRulesController::acknowledge: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to acknowledge rule: ' . $e->getMessage());
        }
    }

    private function formatRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'phase' => $row['phase'],
            'rule_key' => $row['rule_key'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'] ?? null,
            'description' => $row['description'],
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'is_active' => (int)($row['is_active'] ?? 1) === 1,
            'created_by' => $row['created_by'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'created_by_username' => $row['created_by_username'] ?? null,
            'updated_by_username' => $row['updated_by_username'] ?? null,
        ];
    }

    private function slugifyKey(string $title, string $phase): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: 'rule';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'rule';
        }
        $prefix = $phase === 'tester' ? 'qa_' : ($phase === 'project' ? 'proj_' : 'dev_');
        if (strpos($base, $prefix) !== 0) {
            $base = $prefix . $base;
        }
        $base = substr($base, 0, 50);
        $candidate = $base;
        $n = 1;
        while (true) {
            $stmt = $this->conn->prepare(
                "SELECT id FROM codo_common_rules WHERE phase = ? AND rule_key = ? LIMIT 1"
            );
            $stmt->execute([$phase, $candidate]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return $candidate;
            }
            $n++;
            $candidate = substr($base, 0, 46) . '_' . $n;
        }
    }

    public function list()
    {
        $decoded = $this->requireTeamAuth();
        if (!$decoded) {
            return;
        }
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(503, 'Common CODO is not set up. Run migration 022_codo_common_rules.sql.');
            return;
        }

        $phase = isset($_GET['phase']) ? strtolower(trim((string)$_GET['phase'])) : '';
        $search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $includeInactive = isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] === '1';

        $sql = "SELECT r.*
                FROM codo_common_rules r
                WHERE 1=1";
        $params = [];
        if (!$includeInactive) {
            $sql .= ' AND r.is_active = 1';
        }
        if ($phase !== '' && in_array($phase, self::PHASES, true)) {
            $sql .= ' AND r.phase = ?';
            $params[] = $phase;
        }
        if ($search !== '') {
            $sql .= ' AND (r.title LIKE ? OR r.subtitle LIKE ? OR r.description LIKE ? OR r.rule_key LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY FIELD(r.phase, \'developer\', \'tester\', \'project\'), r.sort_order ASC, r.id ASC';

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $items = array_map([$this, 'formatRow'], $rows);
            $items = $this->attachAckSummaries($items, (string)$decoded->user_id);

            $counts = ['all' => 0, 'developer' => 0, 'tester' => 0, 'project' => 0];
            $countSql = 'SELECT phase, COUNT(*) AS c FROM codo_common_rules WHERE is_active = 1 GROUP BY phase';
            $cstmt = $this->conn->query($countSql);
            while ($cstmt && ($crow = $cstmt->fetch(PDO::FETCH_ASSOC))) {
                $p = $crow['phase'];
                $n = (int)$crow['c'];
                $counts[$p] = $n;
                $counts['all'] += $n;
            }

            $this->sendJsonResponse(200, 'OK', [
                'rules' => $items,
                'counts' => $counts,
                'ack_table_ready' => $this->ackTableReady(),
            ]);
        } catch (Throwable $e) {
            error_log('CodoRulesController::list: ' . $e->getMessage());
            $msg = $e->getMessage();
            if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'codo_common_rules') !== false) {
                $this->sendJsonResponse(503, 'Common CODO is not set up. Run migration 022_codo_common_rules.sql on the database.');
                return;
            }
            $this->sendJsonResponse(500, 'Failed to load CODO rules: ' . $msg);
        }
    }

    public function create($payload)
    {
        $decoded = $this->requireTeamAuth();
        if (!$decoded) {
            return;
        }
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(503, 'Common CODO is not set up. Run migration 022_codo_common_rules.sql.');
            return;
        }

        $phase = strtolower(trim((string)($payload['phase'] ?? 'developer')));
        $title = trim((string)($payload['title'] ?? ''));
        $subtitle = trim((string)($payload['subtitle'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $ruleKey = trim((string)($payload['rule_key'] ?? ''));
        $sortOrder = isset($payload['sort_order']) ? (int)$payload['sort_order'] : null;

        if (!in_array($phase, self::PHASES, true)) {
            $this->sendJsonResponse(400, 'phase must be developer, tester, or project');
            return;
        }
        if (strlen($title) < 3) {
            $this->sendJsonResponse(400, 'title must be at least 3 characters');
            return;
        }
        if (strlen($description) < 10) {
            $this->sendJsonResponse(400, 'description must be at least 10 characters');
            return;
        }

        if ($ruleKey === '') {
            $ruleKey = $this->slugifyKey($title, $phase);
        } else {
            $ruleKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $ruleKey);
            $ruleKey = substr($ruleKey, 0, 64);
            $chk = $this->conn->prepare(
                "SELECT id FROM codo_common_rules WHERE phase = ? AND rule_key = ? LIMIT 1"
            );
            $chk->execute([$phase, $ruleKey]);
            if ($chk->fetch(PDO::FETCH_ASSOC)) {
                $this->sendJsonResponse(409, 'rule_key already exists for this phase');
                return;
            }
        }

        if ($sortOrder === null) {
            $maxStmt = $this->conn->prepare(
                "SELECT COALESCE(MAX(sort_order), 0) AS m FROM codo_common_rules WHERE phase = ?"
            );
            $maxStmt->execute([$phase]);
            $sortOrder = (int)($maxStmt->fetch(PDO::FETCH_ASSOC)['m'] ?? 0) + 1;
        }

        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO codo_common_rules
                 (phase, rule_key, title, subtitle, description, sort_order, is_active, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)"
            );
            $uid = (string)$decoded->user_id;
            $stmt->execute([
                $phase,
                $ruleKey,
                $title,
                $subtitle !== '' ? $subtitle : null,
                $description,
                $sortOrder,
                $uid,
                $uid,
            ]);
            $id = (int)$this->conn->lastInsertId();
            $fetch = $this->conn->prepare('SELECT * FROM codo_common_rules WHERE id = ? LIMIT 1');
            $fetch->execute([$id]);
            $row = $fetch->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(201, 'Rule created', $row ? $this->formatRow($row) : ['id' => $id]);
        } catch (Throwable $e) {
            error_log('CodoRulesController::create: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to create rule: ' . $e->getMessage());
        }
    }

    public function update($payload)
    {
        $decoded = $this->requireTeamAuth();
        if (!$decoded) {
            return;
        }
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(503, 'Common CODO is not set up. Run migration 022_codo_common_rules.sql.');
            return;
        }

        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }

        $existing = $this->conn->prepare('SELECT * FROM codo_common_rules WHERE id = ? LIMIT 1');
        $existing->execute([$id]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Rule not found');
            return;
        }

        $phase = array_key_exists('phase', $payload)
            ? strtolower(trim((string)$payload['phase']))
            : $row['phase'];
        $title = array_key_exists('title', $payload)
            ? trim((string)$payload['title'])
            : $row['title'];
        $subtitle = array_key_exists('subtitle', $payload)
            ? trim((string)$payload['subtitle'])
            : ($row['subtitle'] ?? '');
        $description = array_key_exists('description', $payload)
            ? trim((string)$payload['description'])
            : $row['description'];
        $sortOrder = array_key_exists('sort_order', $payload)
            ? (int)$payload['sort_order']
            : (int)$row['sort_order'];
        $isActive = array_key_exists('is_active', $payload)
            ? (($payload['is_active'] === true || $payload['is_active'] === 1 || $payload['is_active'] === '1') ? 1 : 0)
            : (int)$row['is_active'];

        if (!in_array($phase, self::PHASES, true)) {
            $this->sendJsonResponse(400, 'phase must be developer, tester, or project');
            return;
        }
        if (strlen($title) < 3) {
            $this->sendJsonResponse(400, 'title must be at least 3 characters');
            return;
        }
        if (strlen($description) < 10) {
            $this->sendJsonResponse(400, 'description must be at least 10 characters');
            return;
        }

        try {
            $stmt = $this->conn->prepare(
                "UPDATE codo_common_rules
                 SET phase = ?, title = ?, subtitle = ?, description = ?, sort_order = ?, is_active = ?, updated_by = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $phase,
                $title,
                $subtitle !== '' ? $subtitle : null,
                $description,
                $sortOrder,
                $isActive,
                (string)$decoded->user_id,
                $id,
            ]);

            $fetch = $this->conn->prepare('SELECT * FROM codo_common_rules WHERE id = ? LIMIT 1');
            $fetch->execute([$id]);
            $out = $fetch->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(200, 'Rule updated', $out ? $this->formatRow($out) : null);
        } catch (Throwable $e) {
            error_log('CodoRulesController::update: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to update rule');
        }
    }

    public function delete($payload)
    {
        $decoded = $this->requireTeamAuth();
        if (!$decoded) {
            return;
        }
        $role = strtolower(trim((string)($decoded->role ?? '')));
        if ($role !== 'admin') {
            $this->sendJsonResponse(403, 'Only administrators can delete CODO rules');
            return;
        }
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(503, 'Common CODO is not set up. Run migration 022_codo_common_rules.sql.');
            return;
        }

        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        $hard = !empty($payload['hard']);
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }

        $existing = $this->conn->prepare('SELECT id FROM codo_common_rules WHERE id = ? LIMIT 1');
        $existing->execute([$id]);
        if (!$existing->fetch(PDO::FETCH_ASSOC)) {
            $this->sendJsonResponse(404, 'Rule not found');
            return;
        }

        try {
            if ($hard) {
                $stmt = $this->conn->prepare('DELETE FROM codo_common_rules WHERE id = ?');
                $stmt->execute([$id]);
                $this->sendJsonResponse(200, 'Rule deleted');
                return;
            }
            $stmt = $this->conn->prepare(
                "UPDATE codo_common_rules SET is_active = 0, updated_by = ? WHERE id = ?"
            );
            $stmt->execute([(string)$decoded->user_id, $id]);
            $this->sendJsonResponse(200, 'Rule deactivated');
        } catch (Throwable $e) {
            error_log('CodoRulesController::delete: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to delete rule');
        }
    }
}
