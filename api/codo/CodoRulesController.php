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

    private const ACK_STATUSES = ['acknowledged', 'doubt', 'not_required'];

    private function hasAckStatusColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $res = $this->conn->query("SHOW COLUMNS FROM codo_rule_acknowledgements LIKE 'status'");
            $has = (bool)($res && $res->fetch(PDO::FETCH_ASSOC));
        } catch (Throwable $e) {
            $has = false;
        }
        return $has;
    }

    /**
     * @param array<string, array{status:string,acknowledged_at:?string}> $ackMap
     */
    private function summarizeFromAckMap(array $required, array $ackMap, string $currentUserId): array
    {
        $acknowledged = [];
        $doubt = [];
        $notRequired = [];
        $pending = [];

        foreach ($required as $u) {
            if (!isset($ackMap[$u['id']])) {
                $pending[] = [
                    'id' => $u['id'],
                    'username' => $u['username'],
                    'role' => $u['role'],
                ];
                continue;
            }
            $entry = [
                'id' => $u['id'],
                'username' => $u['username'],
                'role' => $u['role'],
                'status' => $ackMap[$u['id']]['status'],
                'acknowledged_at' => $ackMap[$u['id']]['acknowledged_at'],
            ];
            $st = $ackMap[$u['id']]['status'];
            if ($st === 'doubt') {
                $doubt[] = $entry;
            } elseif ($st === 'not_required') {
                $notRequired[] = $entry;
            } else {
                $acknowledged[] = $entry;
            }
        }

        $requiredIds = array_column($required, 'id');
        $mustRespond = in_array($currentUserId, $requiredIds, true);
        $currentStatus = isset($ackMap[$currentUserId]) ? $ackMap[$currentUserId]['status'] : null;
        $currentAt = isset($ackMap[$currentUserId]) ? ($ackMap[$currentUserId]['acknowledged_at'] ?? null) : null;
        $responded = count($acknowledged) + count($doubt) + count($notRequired);

        return [
            'required_total' => count($required),
            'responded_count' => $responded,
            'acknowledged_count' => count($acknowledged),
            'doubt_count' => count($doubt),
            'not_required_count' => count($notRequired),
            'pending_count' => count($pending),
            'current_user_status' => $currentStatus,
            'current_user_acknowledged_at' => $currentAt,
            'current_user_acknowledged' => $currentStatus === 'acknowledged',
            'current_user_must_acknowledge' => $mustRespond && $currentStatus === null,
            'acknowledged' => $acknowledged,
            'doubt' => $doubt,
            'not_required' => $notRequired,
            'pending' => $pending,
        ];
    }

    private function buildAckSummary(int $ruleId, string $phase, string $currentUserId): array
    {
        $required = $this->requiredUsersForPhase($phase);
        $requiredIds = array_column($required, 'id');
        $ackMap = [];
        if ($this->ackTableReady() && !empty($requiredIds)) {
            $placeholders = implode(',', array_fill(0, count($requiredIds), '?'));
            $statusSelect = $this->hasAckStatusColumn() ? 'status' : "'acknowledged' AS status";
            $stmt = $this->conn->prepare(
                "SELECT user_id, $statusSelect, acknowledged_at FROM codo_rule_acknowledgements
                 WHERE rule_id = ? AND user_id IN ($placeholders)"
            );
            $stmt->execute(array_merge([$ruleId], $requiredIds));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $st = strtolower((string)($row['status'] ?? 'acknowledged'));
                if (!in_array($st, self::ACK_STATUSES, true)) {
                    $st = 'acknowledged';
                }
                $ackMap[(string)$row['user_id']] = [
                    'status' => $st,
                    'acknowledged_at' => $row['acknowledged_at'] ?? null,
                ];
            }
        }

        return $this->summarizeFromAckMap($required, $ackMap, $currentUserId);
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

        $empty = $this->summarizeFromAckMap([], [], $currentUserId);

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
            $statusSelect = $this->hasAckStatusColumn() ? 'status' : "'acknowledged' AS status";
            $stmt = $this->conn->prepare(
                "SELECT rule_id, user_id, $statusSelect, acknowledged_at
                 FROM codo_rule_acknowledgements
                 WHERE rule_id IN ($placeholders)"
            );
            $stmt->execute($ruleIds);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rid = (int)$row['rule_id'];
                $st = strtolower((string)($row['status'] ?? 'acknowledged'));
                if (!in_array($st, self::ACK_STATUSES, true)) {
                    $st = 'acknowledged';
                }
                if (!isset($ackByRule[$rid])) {
                    $ackByRule[$rid] = [];
                }
                $ackByRule[$rid][(string)$row['user_id']] = [
                    'status' => $st,
                    'acknowledged_at' => $row['acknowledged_at'] ?? null,
                ];
            }
        }

        foreach ($items as &$item) {
            $phase = (string)$item['phase'];
            $required = $usersByPhase[$phase] ?? [];
            $ackMap = $ackByRule[(int)$item['id']] ?? [];
            $item['acknowledgements'] = $this->summarizeFromAckMap($required, $ackMap, $currentUserId);
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

        $status = strtolower(trim((string)($payload['status'] ?? 'acknowledged')));
        if (!in_array($status, self::ACK_STATUSES, true)) {
            $this->sendJsonResponse(400, 'status must be acknowledged, doubt, or not_required');
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
                'Only ' . implode(' / ', $requiredRoles) . ' roles can respond to this rule'
            );
            return;
        }

        try {
            if ($this->hasAckStatusColumn()) {
                $stmt = $this->conn->prepare(
                    "INSERT INTO codo_rule_acknowledgements (rule_id, user_id, status, acknowledged_at)
                     VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE status = VALUES(status), acknowledged_at = NOW()"
                );
                $stmt->execute([$ruleId, $userId, $status]);
            } else {
                if ($status !== 'acknowledged') {
                    $this->sendJsonResponse(503, 'Run migration 024_codo_ack_status.sql to enable Doubt / Not Required.');
                    return;
                }
                $stmt = $this->conn->prepare(
                    "INSERT INTO codo_rule_acknowledgements (rule_id, user_id, acknowledged_at)
                     VALUES (?, ?, NOW())
                     ON DUPLICATE KEY UPDATE acknowledged_at = NOW()"
                );
                $stmt->execute([$ruleId, $userId]);
            }

            $summary = $this->buildAckSummary($ruleId, (string)$rule['phase'], $userId);
            if ($role !== 'admin') {
                $summary['acknowledged'] = [];
                $summary['doubt'] = [];
                $summary['not_required'] = [];
                $summary['pending'] = [];
            }
            $labels = [
                'acknowledged' => 'Rule acknowledged',
                'doubt' => 'Marked as doubt',
                'not_required' => 'Marked as not required',
            ];
            $this->sendJsonResponse(200, $labels[$status] ?? 'Response saved', [
                'rule_id' => $ruleId,
                'status' => $status,
                'acknowledgements' => $summary,
            ]);
        } catch (Throwable $e) {
            error_log('CodoRulesController::acknowledge: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to save response: ' . $e->getMessage());
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
            'project_ids' => [],
            'projects' => [],
        ];
    }

    private function ruleProjectsTableReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $res = $this->conn->query("SHOW TABLES LIKE 'codo_rule_projects'");
            $ready = (bool)($res && $res->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    /**
     * @param mixed $raw
     * @return string[]
     */
    private function normalizeProjectIds($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            $id = trim((string)$id);
            if ($id !== '' && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Validate project IDs exist. Returns cleaned list or null on failure (response already sent).
     * @param string[] $projectIds
     * @return string[]|null
     */
    private function validateProjectIds(array $projectIds): ?array
    {
        if (empty($projectIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $this->conn->prepare("SELECT id FROM projects WHERE id IN ($placeholders)");
        $stmt->execute($projectIds);
        $found = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $found[] = (string)$row['id'];
        }
        if (count($found) !== count($projectIds)) {
            $this->sendJsonResponse(400, 'One or more selected projects were not found');
            return null;
        }
        return $found;
    }

    /**
     * @param string[] $projectIds
     */
    private function syncRuleProjects(int $ruleId, string $phase, array $projectIds): void
    {
        if (!$this->ruleProjectsTableReady()) {
            return;
        }
        $del = $this->conn->prepare('DELETE FROM codo_rule_projects WHERE rule_id = ?');
        $del->execute([$ruleId]);
        if ($phase !== 'project' || empty($projectIds)) {
            return;
        }
        $ins = $this->conn->prepare(
            'INSERT INTO codo_rule_projects (rule_id, project_id) VALUES (?, ?)'
        );
        foreach ($projectIds as $pid) {
            $ins->execute([$ruleId, $pid]);
        }
    }

    /**
     * @param array<int, array> $items
     * @return array<int, array>
     */
    private function attachRuleProjects(array $items): array
    {
        if (empty($items) || !$this->ruleProjectsTableReady()) {
            return $items;
        }
        $ruleIds = array_values(array_unique(array_map(static fn($r) => (int)$r['id'], $items)));
        if (empty($ruleIds)) {
            return $items;
        }
        $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT rp.rule_id, rp.project_id, p.name AS project_name
             FROM codo_rule_projects rp
             LEFT JOIN projects p ON p.id = rp.project_id
             WHERE rp.rule_id IN ($placeholders)
             ORDER BY p.name ASC"
        );
        $stmt->execute($ruleIds);
        $byRule = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$row['rule_id'];
            if (!isset($byRule[$rid])) {
                $byRule[$rid] = ['ids' => [], 'projects' => []];
            }
            $pid = (string)$row['project_id'];
            $byRule[$rid]['ids'][] = $pid;
            $byRule[$rid]['projects'][] = [
                'id' => $pid,
                'name' => (string)($row['project_name'] ?? $pid),
            ];
        }
        foreach ($items as &$item) {
            $rid = (int)$item['id'];
            $item['project_ids'] = $byRule[$rid]['ids'] ?? [];
            $item['projects'] = $byRule[$rid]['projects'] ?? [];
        }
        unset($item);
        return $items;
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
            $items = $this->attachRuleProjects($items);
            $items = $this->attachAckSummaries($items, (string)$decoded->user_id);
            $viewerRole = strtolower(trim((string)($decoded->role ?? '')));
            if ($viewerRole !== 'admin') {
                foreach ($items as &$item) {
                    if (!isset($item['acknowledgements']) || !is_array($item['acknowledgements'])) {
                        continue;
                    }
                    $item['acknowledgements']['acknowledged'] = [];
                    $item['acknowledgements']['doubt'] = [];
                    $item['acknowledgements']['not_required'] = [];
                    $item['acknowledgements']['pending'] = [];
                }
                unset($item);
            }

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
        $projectIds = $this->normalizeProjectIds($payload['project_ids'] ?? []);

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
        if ($phase === 'project') {
            if (empty($projectIds)) {
                $this->sendJsonResponse(400, 'Select at least one project for project-phase rules');
                return;
            }
            if (!$this->ruleProjectsTableReady()) {
                $this->sendJsonResponse(503, 'Run migration 025_codo_rule_projects.sql to link projects.');
                return;
            }
            $projectIds = $this->validateProjectIds($projectIds);
            if ($projectIds === null) {
                return;
            }
        } else {
            $projectIds = [];
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
            $this->syncRuleProjects($id, $phase, $projectIds);
            $fetch = $this->conn->prepare('SELECT * FROM codo_common_rules WHERE id = ? LIMIT 1');
            $fetch->execute([$id]);
            $row = $fetch->fetch(PDO::FETCH_ASSOC);
            $out = $row ? $this->formatRow($row) : ['id' => $id];
            $attached = $this->attachRuleProjects([$out]);
            $this->sendJsonResponse(201, 'Rule created', $attached[0] ?? $out);
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

        $projectIds = null;
        if ($phase !== 'project') {
            $projectIds = [];
        } elseif (array_key_exists('project_ids', $payload)) {
            $projectIds = $this->normalizeProjectIds($payload['project_ids']);
            if (empty($projectIds)) {
                $this->sendJsonResponse(400, 'Select at least one project for project-phase rules');
                return;
            }
            if (!$this->ruleProjectsTableReady()) {
                $this->sendJsonResponse(503, 'Run migration 025_codo_rule_projects.sql to link projects.');
                return;
            }
            $projectIds = $this->validateProjectIds($projectIds);
            if ($projectIds === null) {
                return;
            }
        }

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

            if ($projectIds !== null) {
                $this->syncRuleProjects($id, $phase, $projectIds);
            }

            $fetch = $this->conn->prepare('SELECT * FROM codo_common_rules WHERE id = ? LIMIT 1');
            $fetch->execute([$id]);
            $out = $fetch->fetch(PDO::FETCH_ASSOC);
            $formatted = $out ? $this->formatRow($out) : null;
            if ($formatted) {
                $attached = $this->attachRuleProjects([$formatted]);
                $formatted = $attached[0] ?? $formatted;
            }
            $this->sendJsonResponse(200, 'Rule updated', $formatted);
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
