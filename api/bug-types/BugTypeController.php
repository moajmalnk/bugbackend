<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

class BugTypeController extends BaseAPI
{
    /** @var bool|null */
    private $tablesReady = null;

    private function bugTypesTablesExist(): bool
    {
        if ($this->tablesReady !== null) {
            return $this->tablesReady;
        }
        try {
            $types = $this->conn->query("SHOW TABLES LIKE 'bug_types'");
            $junction = $this->conn->query("SHOW TABLES LIKE 'bug_bug_types'");
            $this->tablesReady = $types && $types->rowCount() > 0
                && $junction && $junction->rowCount() > 0;
        } catch (Exception $e) {
            $this->tablesReady = false;
        }
        return $this->tablesReady;
    }

    private function requireTables(): void
    {
        if (!$this->bugTypesTablesExist()) {
            $this->sendJsonResponse(503, "Bug types are not available. Run migration 032_bug_types.sql.");
        }
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'type_' . substr(md5($name . microtime()), 0, 8);
        }
        return substr($slug, 0, 64);
    }

    private function uniqueSlug(string $base, ?string $excludeId = null): string
    {
        $slug = $this->slugify($base);
        $candidate = $slug;
        $i = 2;
        while (true) {
            $sql = "SELECT id FROM bug_types WHERE slug = ?";
            $params = [$candidate];
            if ($excludeId) {
                $sql .= " AND id <> ?";
                $params[] = $excludeId;
            }
            $stmt = $this->conn->prepare($sql . " LIMIT 1");
            $stmt->execute($params);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                return $candidate;
            }
            $candidate = substr($slug, 0, 60) . '_' . $i;
            $i++;
            if ($i > 50) {
                return $slug . '_' . substr(Utils::generateUUID(), 0, 8);
            }
        }
    }

    /** List types. ?include_inactive=1 for Settings (admin). */
    public function list()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $this->validateToken();
            if (!$this->bugTypesTablesExist()) {
                $this->sendJsonResponse(200, "Bug types retrieved", []);
                return;
            }

            $includeInactive = isset($_GET['include_inactive'])
                && ($_GET['include_inactive'] === '1' || $_GET['include_inactive'] === 'true');

            $sql = "SELECT id, name, slug, is_active, sort_order, created_at, updated_at
                    FROM bug_types";
            if (!$includeInactive) {
                $sql .= " WHERE is_active = 1";
            }
            $sql .= " ORDER BY sort_order ASC, name ASC";

            $stmt = $this->conn->query($sql);
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as &$row) {
                $row['is_active'] = (int) $row['is_active'] === 1;
                $row['sort_order'] = (int) $row['sort_order'];
            }
            unset($row);

            $this->sendJsonResponse(200, "Bug types retrieved", $rows);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Failed to list bug types: " . $e->getMessage());
        }
    }

    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            if (($decoded->role ?? '') !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can create bug types");
                return;
            }
            $this->requireTables();

            $data = $this->getRequestData();
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $this->sendJsonResponse(400, "Name is required");
                return;
            }
            if (strlen($name) > 100) {
                $this->sendJsonResponse(400, "Name must be 100 characters or fewer");
                return;
            }

            $sortOrder = isset($data['sort_order']) ? (int) $data['sort_order'] : 100;
            $isActive = array_key_exists('is_active', $data)
                ? ((int) (!!$data['is_active']))
                : 1;
            $id = Utils::generateUUID();
            $slug = $this->uniqueSlug($name);

            $stmt = $this->conn->prepare(
                "INSERT INTO bug_types (id, name, slug, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$id, $name, $slug, $isActive, $sortOrder]);

            $this->sendJsonResponse(200, "Bug type created", [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'is_active' => $isActive === 1,
                'sort_order' => $sortOrder,
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Failed to create bug type: " . $e->getMessage());
        }
    }

    public function update()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            if (($decoded->role ?? '') !== 'admin') {
                $this->sendJsonResponse(403, "Only admins can update bug types");
                return;
            }
            $this->requireTables();

            $data = $this->getRequestData();
            $id = trim((string) ($data['id'] ?? ''));
            if ($id === '') {
                $this->sendJsonResponse(400, "id is required");
                return;
            }

            $check = $this->conn->prepare("SELECT id, name, slug FROM bug_types WHERE id = ? LIMIT 1");
            $check->execute([$id]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $this->sendJsonResponse(404, "Bug type not found");
                return;
            }

            $fields = [];
            $params = [];

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    $this->sendJsonResponse(400, "Name cannot be empty");
                    return;
                }
                $fields[] = "name = ?";
                $params[] = $name;
                // Refresh slug when name changes
                $fields[] = "slug = ?";
                $params[] = $this->uniqueSlug($name, $id);
            }
            if (array_key_exists('is_active', $data)) {
                $fields[] = "is_active = ?";
                $params[] = (int) (!!$data['is_active']);
            }
            if (array_key_exists('sort_order', $data)) {
                $fields[] = "sort_order = ?";
                $params[] = (int) $data['sort_order'];
            }

            if (empty($fields)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }

            $params[] = $id;
            $sql = "UPDATE bug_types SET " . implode(', ', $fields) . " WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            $fetch = $this->conn->prepare(
                "SELECT id, name, slug, is_active, sort_order, created_at, updated_at FROM bug_types WHERE id = ?"
            );
            $fetch->execute([$id]);
            $row = $fetch->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['is_active'] = (int) $row['is_active'] === 1;
                $row['sort_order'] = (int) $row['sort_order'];
            }

            $this->sendJsonResponse(200, "Bug type updated", $row);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Failed to update bug type: " . $e->getMessage());
        }
    }
}
