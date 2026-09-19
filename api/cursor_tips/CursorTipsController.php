<?php
require_once __DIR__ . '/../BaseAPI.php';

/**
 * Why: Cursor Tips is a Common-CODO sibling catalog for Cursor operating craft.
 * Separate table and API keep CODO production law isolated from Tips operating craft.
 */
class CursorTipsController extends BaseAPI
{
    private const TEAM_ROLES = ['admin', 'developer', 'tester', 'creator'];
    private const PHASES = ['modes', 'commands', 'skills', 'workflow', 'review'];

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
            $this->sendJsonResponse(403, 'Access denied. Cursor Tips is available to admin, developer, tester, and creator.');
            return null;
        }
        return $decoded;
    }

    private function tablesReady(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $res = $this->conn->query("SHOW TABLES LIKE 'cursor_tips'");
            $ready = (bool)($res && $res->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {
            $ready = false;
        }
        return $ready;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        $v = trim((string)$value);
        return $v === '' ? null : $v;
    }

    private function formatRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'phase' => $row['phase'],
            'tip_key' => $row['tip_key'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'] ?? null,
            'description' => $row['description'],
            'analogy_en' => $row['analogy_en'] ?? null,
            'analogy_ml' => $row['analogy_ml'] ?? null,
            'when_to_use' => $row['when_to_use'] ?? null,
            'when_not_to_use' => $row['when_not_to_use'] ?? null,
            'example_bad' => $row['example_bad'] ?? null,
            'example_good' => $row['example_good'] ?? null,
            'example_language' => $row['example_language'] ?? 'Prompt',
            'sort_order' => (int)($row['sort_order'] ?? 0),
            'is_active' => (int)($row['is_active'] ?? 1) === 1,
            'created_by' => $row['created_by'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function slugifyKey(string $title, string $phase): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?: 'tip';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'tip';
        }
        $prefixMap = [
            'modes' => 'cursor_mode_',
            'commands' => 'cursor_cmd_',
            'skills' => 'cursor_skill_',
            'workflow' => 'cursor_wf_',
            'review' => 'cursor_rev_',
        ];
        $prefix = $prefixMap[$phase] ?? 'cursor_tip_';
        if (strpos($base, $prefix) !== 0) {
            $base = $prefix . $base;
        }
        $base = substr($base, 0, 50);
        $candidate = $base;
        $n = 1;
        while (true) {
            $stmt = $this->conn->prepare(
                'SELECT id FROM cursor_tips WHERE phase = ? AND tip_key = ? LIMIT 1'
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
            $this->sendJsonResponse(503, 'Cursor Tips is not set up. Run migration 103_cursor_tips.sql.');
            return;
        }

        $phase = isset($_GET['phase']) ? strtolower(trim((string)$_GET['phase'])) : '';
        $search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        $includeInactive = isset($_GET['include_inactive']) && (string)$_GET['include_inactive'] === '1';

        $sql = 'SELECT t.* FROM cursor_tips t WHERE t.deleted_at IS NULL';
        $params = [];
        if (!$includeInactive) {
            $sql .= ' AND t.is_active = 1';
        }
        if ($phase !== '' && in_array($phase, self::PHASES, true)) {
            $sql .= ' AND t.phase = ?';
            $params[] = $phase;
        }
        if ($search !== '') {
            $sql .= ' AND (
                t.title LIKE ? OR t.subtitle LIKE ? OR t.description LIKE ? OR t.tip_key LIKE ?
                OR t.analogy_en LIKE ? OR t.analogy_ml LIKE ?
                OR t.when_to_use LIKE ? OR t.when_not_to_use LIKE ?
                OR t.example_bad LIKE ? OR t.example_good LIKE ?
            )';
            $like = '%' . $search . '%';
            for ($i = 0; $i < 10; $i++) {
                $params[] = $like;
            }
        }
        $sql .= " ORDER BY FIELD(t.phase, 'modes', 'commands', 'skills', 'workflow', 'review'), t.sort_order ASC, t.id ASC";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $items = array_map([$this, 'formatRow'], $rows);

            $counts = [
                'all' => 0,
                'modes' => 0,
                'commands' => 0,
                'skills' => 0,
                'workflow' => 0,
                'review' => 0,
            ];
            $countSql = 'SELECT phase, COUNT(*) AS c FROM cursor_tips WHERE is_active = 1 AND deleted_at IS NULL GROUP BY phase';
            $cstmt = $this->conn->query($countSql);
            while ($cstmt && ($crow = $cstmt->fetch(PDO::FETCH_ASSOC))) {
                $p = $crow['phase'];
                $n = (int)$crow['c'];
                if (isset($counts[$p])) {
                    $counts[$p] = $n;
                }
                $counts['all'] += $n;
            }

            $this->sendJsonResponse(200, 'OK', [
                'tips' => $items,
                'counts' => $counts,
            ]);
        } catch (Throwable $e) {
            error_log('CursorTipsController::list: ' . $e->getMessage());
            $msg = $e->getMessage();
            if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'cursor_tips') !== false) {
                $this->sendJsonResponse(503, 'Cursor Tips is not set up. Run migration 103_cursor_tips.sql on the database.');
                return;
            }
            $this->sendJsonResponse(500, 'Failed to load Cursor Tips: ' . $msg);
        }
    }

    public function create($payload)
    {
        $decoded = $this->requireTeamAuth();
        if (!$decoded) {
            return;
        }
        $role = strtolower(trim((string)($decoded->role ?? '')));
        if ($role !== 'admin') {
            $this->sendJsonResponse(403, 'Only administrators can create Cursor Tips');
            return;
        }
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(503, 'Cursor Tips is not set up. Run migration 103_cursor_tips.sql.');
            return;
        }

        $phase = strtolower(trim((string)($payload['phase'] ?? 'modes')));
        $title = trim((string)($payload['title'] ?? ''));
        $subtitle = trim((string)($payload['subtitle'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $tipKey = trim((string)($payload['tip_key'] ?? ''));
        $sortOrder = isset($payload['sort_order']) ? (int)$payload['sort_order'] : null;
        $analogyEn = $this->nullIfEmpty($payload['analogy_en'] ?? null);
        $analogyMl = $this->nullIfEmpty($payload['analogy_ml'] ?? null);
        $whenToUse = $this->nullIfEmpty($payload['when_to_use'] ?? null);
        $whenNot = $this->nullIfEmpty($payload['when_not_to_use'] ?? null);
        $exampleBad = $this->nullIfEmpty($payload['example_bad'] ?? null);
        $exampleGood = $this->nullIfEmpty($payload['example_good'] ?? null);
        $exampleLanguage = $this->nullIfEmpty($payload['example_language'] ?? null) ?? 'Prompt';

        if (!in_array($phase, self::PHASES, true)) {
            $this->sendJsonResponse(400, 'phase must be modes, commands, skills, workflow, or review');
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

        if ($tipKey === '') {
            $tipKey = $this->slugifyKey($title, $phase);
        } else {
            $tipKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $tipKey);
            $tipKey = substr($tipKey, 0, 64);
            $chk = $this->conn->prepare(
                'SELECT id FROM cursor_tips WHERE phase = ? AND tip_key = ? LIMIT 1'
            );
            $chk->execute([$phase, $tipKey]);
            if ($chk->fetch(PDO::FETCH_ASSOC)) {
                $this->sendJsonResponse(409, 'tip_key already exists for this phase');
                return;
            }
        }

        if ($sortOrder === null) {
            $maxStmt = $this->conn->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) AS m FROM cursor_tips WHERE phase = ?'
            );
            $maxStmt->execute([$phase]);
            $sortOrder = (int)($maxStmt->fetch(PDO::FETCH_ASSOC)['m'] ?? 0) + 1;
        }

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO cursor_tips
                 (phase, tip_key, title, subtitle, description,
                  analogy_en, analogy_ml, when_to_use, when_not_to_use,
                  example_bad, example_good, example_language,
                  sort_order, is_active, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $uid = (string)$decoded->user_id;
            $stmt->execute([
                $phase,
                $tipKey,
                $title,
                $subtitle !== '' ? $subtitle : null,
                $description,
                $analogyEn,
                $analogyMl,
                $whenToUse,
                $whenNot,
                $exampleBad,
                $exampleGood,
                substr($exampleLanguage, 0, 40),
                $sortOrder,
                $uid,
                $uid,
            ]);
            $id = (int)$this->conn->lastInsertId();
            $fetch = $this->conn->prepare('SELECT * FROM cursor_tips WHERE id = ? LIMIT 1');
            $fetch->execute([$id]);
            $row = $fetch->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(201, 'Tip created', $row ? $this->formatRow($row) : ['id' => $id]);
        } catch (Throwable $e) {
            error_log('CursorTipsController::create: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to create tip: ' . $e->getMessage());
        }
    }

    public function update($payload)
    {
        $decoded = $this->requireTeamAuth();
        if (!$decoded) {
            return;
        }
        $role = strtolower(trim((string)($decoded->role ?? '')));
        if ($role !== 'admin') {
            $this->sendJsonResponse(403, 'Only administrators can update Cursor Tips');
            return;
        }
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(503, 'Cursor Tips is not set up. Run migration 103_cursor_tips.sql.');
            return;
        }

        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }

        $existing = $this->conn->prepare('SELECT * FROM cursor_tips WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $existing->execute([$id]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->sendJsonResponse(404, 'Tip not found');
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

        $analogyEn = array_key_exists('analogy_en', $payload)
            ? $this->nullIfEmpty($payload['analogy_en'])
            : ($row['analogy_en'] ?? null);
        $analogyMl = array_key_exists('analogy_ml', $payload)
            ? $this->nullIfEmpty($payload['analogy_ml'])
            : ($row['analogy_ml'] ?? null);
        $whenToUse = array_key_exists('when_to_use', $payload)
            ? $this->nullIfEmpty($payload['when_to_use'])
            : ($row['when_to_use'] ?? null);
        $whenNot = array_key_exists('when_not_to_use', $payload)
            ? $this->nullIfEmpty($payload['when_not_to_use'])
            : ($row['when_not_to_use'] ?? null);
        $exampleBad = array_key_exists('example_bad', $payload)
            ? $this->nullIfEmpty($payload['example_bad'])
            : ($row['example_bad'] ?? null);
        $exampleGood = array_key_exists('example_good', $payload)
            ? $this->nullIfEmpty($payload['example_good'])
            : ($row['example_good'] ?? null);
        $exampleLanguage = array_key_exists('example_language', $payload)
            ? ($this->nullIfEmpty($payload['example_language']) ?? 'Prompt')
            : ($row['example_language'] ?? 'Prompt');

        if (!in_array($phase, self::PHASES, true)) {
            $this->sendJsonResponse(400, 'phase must be modes, commands, skills, workflow, or review');
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
                'UPDATE cursor_tips
                 SET phase = ?, title = ?, subtitle = ?, description = ?,
                     analogy_en = ?, analogy_ml = ?, when_to_use = ?, when_not_to_use = ?,
                     example_bad = ?, example_good = ?, example_language = ?,
                     sort_order = ?, is_active = ?, updated_by = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                $phase,
                $title,
                $subtitle !== '' ? $subtitle : null,
                $description,
                $analogyEn,
                $analogyMl,
                $whenToUse,
                $whenNot,
                $exampleBad,
                $exampleGood,
                substr((string)$exampleLanguage, 0, 40),
                $sortOrder,
                $isActive,
                (string)$decoded->user_id,
                $id,
            ]);

            $fetch = $this->conn->prepare('SELECT * FROM cursor_tips WHERE id = ? LIMIT 1');
            $fetch->execute([$id]);
            $out = $fetch->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(200, 'Tip updated', $out ? $this->formatRow($out) : null);
        } catch (Throwable $e) {
            error_log('CursorTipsController::update: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to update tip');
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
            $this->sendJsonResponse(403, 'Only administrators can delete Cursor Tips');
            return;
        }
        if (!$this->tablesReady()) {
            $this->sendJsonResponse(503, 'Cursor Tips is not set up. Run migration 103_cursor_tips.sql.');
            return;
        }

        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        if ($id <= 0) {
            $this->sendJsonResponse(400, 'id is required');
            return;
        }

        $existing = $this->conn->prepare(
            'SELECT id, title, subtitle, phase FROM cursor_tips WHERE id = ? AND deleted_at IS NULL LIMIT 1'
        );
        $existing->execute([$id]);
        $tipRow = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$tipRow) {
            $this->sendJsonResponse(404, 'Tip not found');
            return;
        }

        try {
            require_once __DIR__ . '/../recycle_bin/RecycleBinService.php';
            $rb = new RecycleBinService($this->conn);
            $rb->softDelete('cursor_tip', (string)$id, (string)$decoded->user_id, [
                'title' => $tipRow['title'] ?? 'Cursor Tip',
                'subtitle' => $tipRow['subtitle'] ?? ($tipRow['phase'] ?? null),
            ]);
            $this->sendJsonResponse(200, 'Tip moved to recycle bin');
        } catch (Throwable $e) {
            error_log('CursorTipsController::delete: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to delete tip: ' . $e->getMessage());
        }
    }
}
