<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

/**
 * Why: Shared Technology Stack options for all projects, with on-the-fly custom create.
 */
class TechnologyController extends BaseAPI
{
    private function ensureTable(): bool
    {
        static $ready = false;
        if ($ready) {
            return true;
        }
        try {
            $probe = $this->conn->query("SHOW TABLES LIKE 'project_technologies'");
            if ($probe && $probe->fetch(PDO::FETCH_NUM)) {
                $ready = true;
                $this->seedFromProjects();
                return true;
            }
            $this->conn->exec(
                "CREATE TABLE IF NOT EXISTS `project_technologies` (
                  `id` varchar(36) NOT NULL,
                  `name` varchar(100) NOT NULL,
                  `slug` varchar(64) NOT NULL,
                  `is_active` tinyint(1) NOT NULL DEFAULT 1,
                  `sort_order` int NOT NULL DEFAULT 0,
                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_project_technologies_slug` (`slug`),
                  KEY `idx_project_technologies_active_sort` (`is_active`, `sort_order`),
                  KEY `idx_project_technologies_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $this->seedDefaults();
            $this->seedFromProjects();
            $ready = true;
            return true;
        } catch (Exception $e) {
            error_log('TechnologyController::ensureTable: ' . $e->getMessage());
            return false;
        }
    }

    private function seedDefaults(): void
    {
        $defaults = [
            ['React', 10],
            ['TypeScript', 20],
            ['JavaScript', 30],
            ['PHP', 40],
            ['Laravel', 50],
            ['MySQL', 60],
            ['Node.js', 70],
            ['Vue', 80],
            ['Angular', 90],
            ['Python', 100],
            ['Tailwind CSS', 110],
            ['Next.js', 120],
            ['Express', 130],
            ['PostgreSQL', 140],
            ['MongoDB', 150],
            ['Redis', 160],
            ['Docker', 170],
            ['AWS', 180],
            ['Firebase', 190],
            ['Flutter', 200],
        ];
        foreach ($defaults as [$name, $sort]) {
            $this->upsertByName($name, $sort);
        }
    }

    /**
     * Why: Surface technologies already used on projects so they appear in every dropdown.
     */
    private function seedFromProjects(): void
    {
        try {
            $stmt = $this->conn->query(
                "SELECT technology_stack FROM projects
                 WHERE technology_stack IS NOT NULL AND technology_stack <> ''"
            );
            if (!$stmt) {
                return;
            }
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $raw = (string) ($row['technology_stack'] ?? '');
                foreach (explode(',', $raw) as $part) {
                    $name = trim($part);
                    if ($name !== '') {
                        $this->upsertByName($name, 500);
                    }
                }
            }
        } catch (Exception $e) {
            error_log('TechnologyController::seedFromProjects: ' . $e->getMessage());
        }
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'tech_' . substr(md5($name . microtime()), 0, 8);
        }
        return substr($slug, 0, 64);
    }

    private function findByName(string $name): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, name, slug, is_active, sort_order, created_at, updated_at
             FROM project_technologies
             WHERE LOWER(name) = LOWER(?)
             LIMIT 1"
        );
        $stmt->execute([trim($name)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function upsertByName(string $name, int $sortOrder = 500): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (mb_strlen($name) > 100) {
            $name = mb_substr($name, 0, 100);
        }
        $existing = $this->findByName($name);
        if ($existing) {
            return $existing;
        }
        $id = Utils::generateUUID();
        $slug = $this->slugify($name);
        $candidate = $slug;
        $i = 2;
        while (true) {
            $check = $this->conn->prepare("SELECT id FROM project_technologies WHERE slug = ? LIMIT 1");
            $check->execute([$candidate]);
            if (!$check->fetch(PDO::FETCH_ASSOC)) {
                break;
            }
            $candidate = substr($slug, 0, 60) . '_' . $i;
            $i++;
            if ($i > 50) {
                $candidate = $slug . '_' . substr(Utils::generateUUID(), 0, 8);
                break;
            }
        }
        $ins = $this->conn->prepare(
            "INSERT INTO project_technologies (id, name, slug, is_active, sort_order)
             VALUES (?, ?, ?, 1, ?)"
        );
        $ins->execute([$id, $name, $candidate, $sortOrder]);
        return $this->findByName($name);
    }

    private function formatRow(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'is_active' => (int) ($row['is_active'] ?? 1) === 1,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public function list()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $this->validateToken();
            if (!$this->ensureTable()) {
                $this->sendJsonResponse(200, "Technologies retrieved", []);
                return;
            }

            $stmt = $this->conn->query(
                "SELECT id, name, slug, is_active, sort_order, created_at, updated_at
                 FROM project_technologies
                 WHERE is_active = 1
                 ORDER BY sort_order ASC, name ASC"
            );
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $data = array_map([$this, 'formatRow'], $rows);
            $this->sendJsonResponse(200, "Technologies retrieved", $data);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Failed to list technologies: " . $e->getMessage());
        }
    }

    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $this->validateToken();
            if (!$this->ensureTable()) {
                $this->sendJsonResponse(503, "Technologies catalog is not available. Run migration 083_project_technologies.sql.");
                return;
            }

            $data = $this->getRequestData();
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                $this->sendJsonResponse(400, "Name is required");
                return;
            }
            if (mb_strlen($name) > 100) {
                $this->sendJsonResponse(400, "Name must be 100 characters or fewer");
                return;
            }

            $existing = $this->findByName($name);
            if ($existing) {
                $this->sendJsonResponse(200, "Technology already exists", $this->formatRow($existing));
                return;
            }

            $row = $this->upsertByName($name, 500);
            if (!$row) {
                $this->sendJsonResponse(500, "Failed to create technology");
                return;
            }
            $this->sendJsonResponse(201, "Technology created", $this->formatRow($row));
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Failed to create technology: " . $e->getMessage());
        }
    }
}
