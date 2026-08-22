<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/ProjectComplianceController.php';
require_once __DIR__ . '/projectTimelineHistoryHelper.php';

class ProjectController extends BaseAPI
{
    private static $EXTENDED_FIELDS = [
        'client_name',
        'client_location',
        'client_contact_name',
        'client_email',
        'client_phone',
        'client_account_status',
        'technology_stack',
        'reference_sites_or_themes',
        'frontend_domain',
        'backend_domain',
        'vercel_domain',
        'platforms',
        'project_categories',
        'app_publisher_meta',
        'category_asset_links',
        'app_url_ios',
        'app_url_android',
        'testflight_url',
        'github_frontend',
        'github_backend',
        'github_app',
        'start_date',
        'deadline_date',
        'expected_publish_date',
        'testing_start_date',
        'testing_end_date',
        'frontend_finish_date',
        'backend_finish_date',
        'tester_compliance_complete_date',
        'developer_compliance_complete_date',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->ensureProjectCategoryColumns();
        $this->ensureProjectMembersMultiRole();
    }

    private function ensureProjectCategoryColumns(): void
    {
        static $done = false;
        if ($done || !$this->conn) {
            return;
        }
        $done = true;
        try {
            $cols = [];
            $res = $this->conn->query('SHOW COLUMNS FROM projects');
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }
            if (!in_array('project_categories', $cols, true)) {
                $this->conn->exec(
                    "ALTER TABLE projects ADD COLUMN project_categories VARCHAR(100) DEFAULT NULL AFTER platforms"
                );
                $cols[] = 'project_categories';
            }
            if (!in_array('app_publisher_meta', $cols, true)) {
                $this->conn->exec(
                    "ALTER TABLE projects ADD COLUMN app_publisher_meta TEXT DEFAULT NULL AFTER project_categories"
                );
                $cols[] = 'app_publisher_meta';
            }
            if (!in_array('category_asset_links', $cols, true)) {
                $this->conn->exec(
                    "ALTER TABLE projects ADD COLUMN category_asset_links TEXT DEFAULT NULL AFTER app_publisher_meta"
                );
            }

            $attCols = [];
            $attRes = $this->conn->query('SHOW COLUMNS FROM project_attachments');
            if ($attRes) {
                while ($row = $attRes->fetch(PDO::FETCH_ASSOC)) {
                    $attCols[] = $row['Field'];
                }
            }
            if (!in_array('category', $attCols, true)) {
                $this->conn->exec(
                    "ALTER TABLE project_attachments ADD COLUMN category VARCHAR(32) DEFAULT NULL AFTER file_type"
                );
            }
            if (!in_array('folder', $attCols, true)) {
                $this->conn->exec(
                    "ALTER TABLE project_attachments ADD COLUMN folder VARCHAR(100) DEFAULT NULL AFTER category"
                );
            }
        } catch (Exception $e) {
            error_log('ensureProjectCategoryColumns: ' . $e->getMessage());
        }

        $this->ensureComplianceCompleteColumns();
    }

    /**
     * Why: Timeline date+time columns must exist as DATETIME before create/update or values are dropped.
     */
    private function listProjectColumns(): array
    {
        $cols = [];
        if (!$this->conn) {
            return $cols;
        }
        $res = $this->conn->query('SHOW COLUMNS FROM projects');
        if ($res) {
            while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
        }
        return $cols;
    }

    private function ensureComplianceCompleteColumns(): void
    {
        if (!$this->conn) {
            return;
        }
        $cols = $this->listProjectColumns();
        $needed = [
            'start_date' => 'technology_stack',
            'deadline_date' => 'start_date',
            'expected_publish_date' => 'deadline_date',
            'testing_start_date' => 'expected_publish_date',
            'testing_end_date' => 'testing_start_date',
            'frontend_finish_date' => 'testing_end_date',
            'backend_finish_date' => 'frontend_finish_date',
            'tester_compliance_complete_date' => 'backend_finish_date',
            'developer_compliance_complete_date' => 'tester_compliance_complete_date',
        ];
        foreach ($needed as $name => $after) {
            if (!in_array($name, $cols, true)) {
                try {
                    if (in_array($after, $cols, true)) {
                        $this->conn->exec(
                            "ALTER TABLE projects ADD COLUMN `{$name}` DATETIME DEFAULT NULL AFTER `{$after}`"
                        );
                    } else {
                        $this->conn->exec(
                            "ALTER TABLE projects ADD COLUMN `{$name}` DATETIME DEFAULT NULL"
                        );
                    }
                    $cols[] = $name;
                } catch (Exception $e) {
                    error_log("ensureComplianceCompleteColumns add {$name}: " . $e->getMessage());
                }
                continue;
            }
            try {
                $this->conn->exec("ALTER TABLE projects MODIFY COLUMN `{$name}` DATETIME DEFAULT NULL");
            } catch (Exception $e) {
                error_log("ensureComplianceCompleteColumns modify {$name}: " . $e->getMessage());
            }
        }
    }

    private function normalizeDateField($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $value;
    }

    /**
     * Why: Timeline fields store date + time; date-only values default to 09:00.
     */
    private function normalizeDateTimeField($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim(str_replace('T', ' ', (string) $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw . ' 09:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
            return $raw . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }
        $ts = strtotime($raw);
        if ($ts) {
            return date('Y-m-d H:i:s', $ts);
        }
        return $raw;
    }

    private function normalizeExtendedField(string $field, $value)
    {
        return $this->isDateTimeField($field)
            ? $this->normalizeDateTimeField($value)
            : $this->normalizeDateField($value);
    }

    private function isDateTimeField(string $field): bool
    {
        return substr($field, -5) === '_date';
    }

    /**
     * Why: A project lead can also be a developer; uniqueness is per role, not per user.
     */
    private function ensureProjectMembersMultiRole(): void
    {
        static $done = false;
        if ($done || !$this->conn) {
            return;
        }
        $done = true;
        try {
            $pkCols = [];
            $res = $this->conn->query("SHOW KEYS FROM project_members WHERE Key_name = 'PRIMARY'");
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $pkCols[] = $row['Column_name'];
                }
            }
            if (in_array('role', $pkCols, true)) {
                return;
            }
            $this->conn->exec(
                'ALTER TABLE project_members DROP PRIMARY KEY, ADD PRIMARY KEY (project_id, user_id, role)'
            );
        } catch (Exception $e) {
            error_log('ensureProjectMembersMultiRole: ' . $e->getMessage());
        }
    }

    private function addMembersFromPayload($projectId, $members)
    {
        if (!is_array($members)) {
            return;
        }

        $allowedRoles = ['manager', 'developer', 'tester'];
        $stmt = $this->conn->prepare(
            "INSERT INTO project_members (project_id, user_id, role, joined_at) VALUES (?, ?, ?, NOW())"
        );
        $check = $this->conn->prepare(
            "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? AND role = ? LIMIT 1"
        );

        foreach ($members as $member) {
            if (!isset($member['user_id'], $member['role'])) {
                continue;
            }
            $role = $member['role'];
            if (!in_array($role, $allowedRoles, true)) {
                continue;
            }

            $check->execute([$projectId, $member['user_id'], $role]);
            if ($check->fetch()) {
                continue;
            }

            $stmt->execute([$projectId, $member['user_id'], $role]);
        }
    }

    private function getProjectAttachments($projectId)
    {
        $stmt = $this->conn->prepare(
            "SELECT id, project_id, file_name, file_path, file_type, category, folder, uploaded_by, created_at
             FROM project_attachments WHERE project_id = ? ORDER BY created_at DESC"
        );
        try {
            $stmt->execute([$projectId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Older schema without category/folder
            $fallback = $this->conn->prepare(
                "SELECT id, project_id, file_name, file_path, file_type, uploaded_by, created_at
                 FROM project_attachments WHERE project_id = ? ORDER BY created_at DESC"
            );
            $fallback->execute([$projectId]);
            return $fallback->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    private function attachClientToProject(array &$project): void
    {
        if (!empty($project['client_id'])) {
            $stmt = $this->conn->prepare(
                "SELECT id, corporate_name, website, market_industry, commercial_status,
                        primary_contact_name, direct_email, direct_phone, hq_location
                 FROM clients WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$project['client_id']]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($client) {
                $project['client'] = $client;
            }
        }
    }

    private function enrichProjectRecord(&$project)
    {
        if (!isset($project['id'])) {
            return;
        }

        $this->attachClientToProject($project);

        require_once __DIR__ . '/../../utils/user_avatar.php';
        $userCols = [];
        $colRes = $this->conn->query('SHOW COLUMNS FROM users');
        if ($colRes) {
            while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
                $userCols[] = $row['Field'];
            }
        }
        $memberSelect = ['pm.user_id', 'pm.role', 'u.username', 'u.email'];
        foreach (['avatar', 'profile_picture', 'profile_picture_url'] as $col) {
            if (in_array($col, $userCols, true)) {
                $memberSelect[] = 'u.`' . $col . '`';
            }
        }
        $stmt = $this->conn->prepare(
            'SELECT ' . implode(', ', $memberSelect) . '
             FROM project_members pm
             INNER JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ?'
        );
        $stmt->execute([$project['id']]);
        $project['members_detail'] = array_map(
            'br_user_with_resolved_avatar',
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
        $project['attachments'] = $this->getProjectAttachments($project['id']);

        $complianceController = new ProjectComplianceController();
        $summary = $complianceController->getSummaryForProject($project['id']);
        if ($summary) {
            $project['compliance'] = $summary;
        }

        $project['timeline_history'] = ProjectTimelineHistoryHelper::fetchForProject(
            $this->conn,
            (string) $project['id']
        );
    }

    public function handleError($status, $message)
    {
        $this->sendJsonResponse($status, $message);
    }

    public function getAll()
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            $user_id = $decoded->user_id;
            $user_role = $decoded->role;
            
            // Check impersonation in multiple ways for robustness
            $is_impersonated = false;
            if (isset($decoded->impersonated)) {
                $is_impersonated = $decoded->impersonated === true || $decoded->impersonated === 'true' || $decoded->impersonated === 1;
            }
            // Also check if admin_id is set (indicating impersonation)
            if (!$is_impersonated && isset($decoded->admin_id) && !empty($decoded->admin_id)) {
                $is_impersonated = true;
            }

            $user_role_lower = strtolower(trim((string) $user_role));

            // Projects list behavior:
            // - Real admin: all projects
            // - Developer/tester: all projects (All Projects tab is read-only browse;
            //   Assigned Projects / check-in filter membership client-side)
            // - Admin impersonating another user: only that user's assigned projects
            //   so check-in matches their Assigned Projects view
            $clientIdFilter = isset($_GET['client_id']) ? trim((string) $_GET['client_id']) : '';
            $liveFilter = 'deleted_at IS NULL';
            $clientWhere = $clientIdFilter !== '' ? " WHERE {$liveFilter} AND client_id = ?" : " WHERE {$liveFilter}";
            $clientParams = $clientIdFilter !== '' ? [$clientIdFilter] : [];

            if ($user_role_lower === 'admin' && !$is_impersonated) {
                $query = "SELECT * FROM projects" . $clientWhere . " ORDER BY created_at DESC";
                $stmt = $this->conn->prepare($query);
                $stmt->execute($clientParams);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($is_impersonated) {
                $query = "SELECT DISTINCT p.* FROM projects p
                          INNER JOIN project_members pm ON p.id = pm.project_id
                          WHERE pm.user_id = ? AND p.deleted_at IS NULL
                          ORDER BY p.created_at DESC";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$user_id]);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Developer / tester: allow browsing all projects
                $query = "SELECT * FROM projects" . $clientWhere . " ORDER BY created_at DESC";
                $stmt = $this->conn->prepare($query);
                $stmt->execute($clientParams);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Add members, client, and compliance summary to each project
            require_once __DIR__ . '/../../utils/user_avatar.php';
            $userCols = [];
            $colRes = $this->conn->query('SHOW COLUMNS FROM users');
            if ($colRes) {
                while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
                    $userCols[] = $row['Field'];
                }
            }
            $memberSelect = ['pm.user_id', 'pm.role', 'u.username', 'u.email'];
            foreach (['avatar', 'profile_picture', 'profile_picture_url'] as $col) {
                if (in_array($col, $userCols, true)) {
                    $memberSelect[] = 'u.`' . $col . '`';
                }
            }
            $memberSql = 'SELECT ' . implode(', ', $memberSelect) . '
                     FROM project_members pm
                     INNER JOIN users u ON u.id = pm.user_id
                     WHERE pm.project_id = ?';
            $complianceController = new ProjectComplianceController();
            foreach ($projects as &$project) {
                $stmt2 = $this->conn->prepare($memberSql);
                $stmt2->execute([$project['id']]);
                $membersDetail = array_map(
                    'br_user_with_resolved_avatar',
                    $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: []
                );
                $project['members_detail'] = $membersDetail;
                $project['members'] = array_values(array_map(
                    static function ($row) {
                        return $row['user_id'];
                    },
                    $membersDetail
                ));

                $this->attachClientToProject($project);

                $summary = $complianceController->getSummaryForProject($project['id']);
                if ($summary) {
                    $project['compliance'] = $summary;
                }
            }

            $this->sendJsonResponse(200, "Projects retrieved successfully", $projects);

        } catch (Exception $e) {
            error_log("Error fetching projects: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
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
            $data = $this->getRequestData();

            if (!isset($data['name']) || !isset($data['description'])) {
                $this->sendJsonResponse(400, "Name and description are required");
                return;
            }

            $this->ensureComplianceCompleteColumns();

            $id = Utils::generateUUID();
            $status = isset($data['status']) ? $data['status'] : 'active';

            $columns = ['id', 'name', 'description', 'status', 'created_by'];
            $placeholders = ['?', '?', '?', '?', '?'];
            $values = [$id, $data['name'], $data['description'], $status, $decoded->user_id];
            $projectCols = $this->listProjectColumns();

            foreach (self::$EXTENDED_FIELDS as $field) {
                if (!array_key_exists($field, $data) || !in_array($field, $projectCols, true)) {
                    continue;
                }
                $columns[] = $field;
                $placeholders[] = '?';
                $values[] = $this->normalizeExtendedField($field, $data[$field]);
            }

            if (array_key_exists('client_id', $data)) {
                $columns[] = 'client_id';
                $placeholders[] = '?';
                $values[] = $data['client_id'] ?: null;
            }

            $query = "INSERT INTO projects (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($values);

            if (isset($data['members'])) {
                $this->addMembersFromPayload($id, $data['members']);
            }

            $fetchStmt = $this->conn->prepare("SELECT * FROM projects WHERE id = ?");
            $fetchStmt->execute([$id]);
            $project = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            $this->enrichProjectRecord($project);

            // Log activity
            try {
                $logger = ActivityLogger::getInstance();
                $logger->logProjectCreated(
                    $decoded->user_id,
                    $id,
                    $data['name'],
                    [
                        'description' => $data['description'],
                        'status' => $status
                    ]
                );
            } catch (Exception $e) {
                error_log("Failed to log project creation activity: " . $e->getMessage());
            }

            try {
                require_once __DIR__ . '/../NotificationManager.php';
                NotificationManager::getInstance()->notifyProjectCreated(
                    $id,
                    $data['name'],
                    $decoded->user_id
                );
            } catch (Throwable $e) {
                error_log("Failed to send project creation notification: " . $e->getMessage());
            }

            $this->sendJsonResponse(201, "Project created successfully", $project);

        } catch (Exception $e) {
            error_log("Error creating project: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getById($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();

            $stmt = $this->conn->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$id]);

            $project = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$project) {
                $this->sendJsonResponse(404, "Project not found");
                return;
            }

            $this->enrichProjectRecord($project);
            $this->sendJsonResponse(200, "Project retrieved successfully", $project);

        } catch (Exception $e) {
            error_log("Error fetching project: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function update($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            $this->ensureComplianceCompleteColumns();
            ProjectTimelineHistoryHelper::ensureTable($this->conn);

            $timelineKeys = array_keys(ProjectTimelineHistoryHelper::FIELD_LABELS);
            $timelineSelect = implode(', ', array_map(static function ($col) {
                return '`' . $col . '`';
            }, $timelineKeys));
            $beforeStmt = $this->conn->prepare("SELECT {$timelineSelect} FROM projects WHERE id = ?");
            $beforeStmt->execute([$id]);
            $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$beforeRow) {
                $this->sendJsonResponse(404, "Project not found");
                return;
            }

            $updateFields = [];
            $values = [];
            $afterTimeline = [];

            if (isset($data['name'])) {
                $updateFields[] = "name = ?";
                $values[] = $data['name'];
            }

            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $values[] = $data['description'];
            }

            if (isset($data['status'])) {
                $newStatus = $data['status'];
                $closedStatuses = ['completed', 'release_ready', 'archived'];
                if (in_array($newStatus, $closedStatuses, true)) {
                    $currentStmt = $this->conn->prepare("SELECT status FROM projects WHERE id = ?");
                    $currentStmt->execute([$id]);
                    $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
                    if ($current && $current['status'] !== $newStatus) {
                        $isRealAdmin = $this->isRealAdmin($decoded);
                        $adminArchiveBypass = $isRealAdmin && $newStatus === 'archived';
                        if (!$adminArchiveBypass) {
                            $complianceController = new ProjectComplianceController();
                            $gate = $complianceController->canCloseProject($id);
                            if (!$gate['allowed']) {
                                $this->sendJsonResponse(
                                    403,
                                    'Cannot change project to a closed status until CODO compliance is complete (Developer + QA checklists) or emergency bypass is authorized.'
                                );
                                return;
                            }
                        }
                    }
                }

                $updateFields[] = "status = ?";
                $values[] = $newStatus;
            }

            $projectCols = $this->listProjectColumns();
            foreach (self::$EXTENDED_FIELDS as $field) {
                if (!array_key_exists($field, $data) || !in_array($field, $projectCols, true)) {
                    continue;
                }
                $normalized = $this->normalizeExtendedField($field, $data[$field]);
                $updateFields[] = "$field = ?";
                $values[] = $normalized;
                if (isset(ProjectTimelineHistoryHelper::FIELD_LABELS[$field])) {
                    $afterTimeline[$field] = $normalized;
                }
            }

            if (array_key_exists('client_id', $data)) {
                $updateFields[] = 'client_id = ?';
                $values[] = $data['client_id'] ?: null;
            }

            if (isset($data['members']) && is_array($data['members'])) {
                $deleteStmt = $this->conn->prepare("DELETE FROM project_members WHERE project_id = ?");
                $deleteStmt->execute([$id]);
                $this->addMembersFromPayload($id, $data['members']);
            }

            if (empty($updateFields) && !isset($data['members'])) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }

            if (!empty($updateFields)) {
                $updateFields[] = "updated_at = CURRENT_TIMESTAMP()";
                $query = "UPDATE projects SET " . implode(", ", $updateFields) . " WHERE id = ?";
                $stmt = $this->conn->prepare($query);
                $values[] = $id;

                if (!$stmt->execute($values)) {
                    throw new Exception("Failed to update project");
                }

                if ($stmt->rowCount() === 0) {
                    $checkStmt = $this->conn->prepare("SELECT id FROM projects WHERE id = ?");
                    $checkStmt->execute([$id]);
                    if (!$checkStmt->fetch()) {
                        $this->sendJsonResponse(404, "Project not found");
                        return;
                    }
                }
            } else {
                $checkStmt = $this->conn->prepare("SELECT id FROM projects WHERE id = ?");
                $checkStmt->execute([$id]);
                if (!$checkStmt->fetch()) {
                    $this->sendJsonResponse(404, "Project not found");
                    return;
                }
            }

            $timelineChanges = [];
            if (!empty($afterTimeline)) {
                $timelineChanges = ProjectTimelineHistoryHelper::recordChanges(
                    $this->conn,
                    (string) $id,
                    (string) $decoded->user_id,
                    $beforeRow,
                    $afterTimeline
                );
            }

            if (!empty($timelineChanges)) {
                try {
                    $logger = ActivityLogger::getInstance();
                    $logger->logProjectTimelineUpdated(
                        $decoded->user_id,
                        $id,
                        $timelineChanges
                    );
                } catch (Exception $e) {
                    error_log("Failed to log timeline history activity: " . $e->getMessage());
                }
            }

            $this->sendJsonResponse(200, "Project updated successfully");

        } catch (Exception $e) {
            error_log("Error updating project: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function delete($id, $forceDelete = false, $permanent = false)
    {
        try {
            $forceDelete = (bool) $forceDelete;
            $permanent = (bool) $permanent;

            $decoded = $this->validateToken();

            $checkQuery = "SELECT id, name, status, deleted_at FROM projects WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();
            $project = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$project) {
                $this->sendJsonResponse(404, "Project not found");
                return;
            }

            if (!$permanent) {
                require_once __DIR__ . '/../recycle_bin/RecycleBinService.php';
                $rb = new RecycleBinService($this->conn);
                $rb->softDelete('project', $id, $decoded->user_id, [
                    'title' => $project['name'] ?? 'Project',
                    'subtitle' => $project['status'] ?? null,
                    'project_id' => $id,
                ]);
                $this->sendJsonResponse(200, "Project moved to recycle bin");
                return;
            }

            // Permanent purge (recycle bin only) — cascade when force enabled
            $this->conn->beginTransaction();

            // Check for project members
            $memberQuery = "SELECT COUNT(*) as member_count FROM project_members WHERE project_id = :id";
            $memberStmt = $this->conn->prepare($memberQuery);
            $memberStmt->bindParam(':id', $id);
            $memberStmt->execute();
            $memberCount = $memberStmt->fetch(PDO::FETCH_ASSOC)['member_count'];
            error_log("Project $id has $memberCount members");

            // Check for bugs
            $bugQuery = "SELECT COUNT(*) as bug_count FROM bugs WHERE project_id = :id";
            $bugStmt = $this->conn->prepare($bugQuery);
            $bugStmt->bindParam(':id', $id);
            $bugStmt->execute();
            $bugCount = $bugStmt->fetch(PDO::FETCH_ASSOC)['bug_count'];
            error_log("Project $id has $bugCount bugs");

            // Process with force delete if enabled or no related records
            if ($forceDelete || $permanent) {
                error_log("Force delete enabled, removing related records");

                // Delete team members
                $deleteMembersStmt = $this->conn->prepare("DELETE FROM project_members WHERE project_id = :id");
                    $deleteMembersStmt->bindParam(':id', $id);
                $deleteMembersStmt->execute();
                error_log("Deleted " . $deleteMembersStmt->rowCount() . " project members for project $id");

                // Delete updates linked to the project
                $deleteUpdatesStmt = $this->conn->prepare("DELETE FROM updates WHERE project_id = :id");
                $deleteUpdatesStmt->bindParam(':id', $id);
                $deleteUpdatesStmt->execute();
                error_log("Deleted " . $deleteUpdatesStmt->rowCount() . " updates for project $id");

                // Delete bugs
                $deleteBugsStmt = $this->conn->prepare("DELETE FROM bugs WHERE project_id = :id");
                    $deleteBugsStmt->bindParam(':id', $id);
                $deleteBugsStmt->execute();
                error_log("Deleted " . $deleteBugsStmt->rowCount() . " bugs for project $id");

                // Delete project activities
                $deleteActivitiesStmt = $this->conn->prepare("DELETE FROM project_activities WHERE project_id = :id");
                $deleteActivitiesStmt->bindParam(':id', $id);
                $deleteActivitiesStmt->execute();
                error_log("Deleted " . $deleteActivitiesStmt->rowCount() . " activities for project $id");
            }

            // Finally, delete the project
            $deleteProjectStmt = $this->conn->prepare("DELETE FROM projects WHERE id = :id");
            $deleteProjectStmt->bindParam(':id', $id);
            $deleteProjectStmt->execute();
            error_log("Deleted project $id, row count: " . $deleteProjectStmt->rowCount());

            // Commit transaction
                $this->conn->commit();
            error_log("Transaction committed for project deletion");

                $this->sendJsonResponse(200, "Project deleted successfully");

        } catch (Exception $e) {
            // Important: Rollback on any exception
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
                error_log("Transaction rolled back due to an exception during project deletion.");
            }
            error_log("Error deleting project: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    private function ensureProjectUpdatesColumn(): void
    {
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'project_updates'");
            if ($check && $check->rowCount() === 0) {
                $migration = realpath(__DIR__ . '/../../migrations/014_work_submission_project_updates.sql');
                if ($migration && is_readable($migration)) {
                    $this->conn->exec(file_get_contents($migration));
                } else {
                    $this->conn->exec(
                        "ALTER TABLE work_submissions ADD COLUMN project_updates JSON NULL DEFAULT NULL"
                    );
                }
            }
        } catch (Exception $e) {
            error_log('ensureProjectUpdatesColumn: ' . $e->getMessage());
        }
    }

    /** Real admin session — not impersonating another user. */
    private function isRealAdmin($decoded): bool
    {
        $userRole = strtolower(trim((string) ($decoded->role ?? '')));
        if ($userRole !== 'admin') {
            return false;
        }

        $isImpersonated = false;
        if (isset($decoded->impersonated)) {
            $isImpersonated = $decoded->impersonated === true
                || $decoded->impersonated === 'true'
                || $decoded->impersonated === 1;
        }
        if (!$isImpersonated && isset($decoded->admin_id) && !empty($decoded->admin_id)) {
            $isImpersonated = true;
        }

        return !$isImpersonated;
    }

    private function userCanViewProject($decoded, string $projectId): bool
    {
        $userId = $decoded->user_id ?? null;
        if (!$userId) {
            return false;
        }

        $userRole = strtolower(trim($decoded->role ?? ''));
        if ($userRole === 'admin') {
            return true;
        }

        $stmt = $this->conn->prepare(
            "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([$projectId, $userId]);
        return (bool) $stmt->fetch();
    }

    /**
     * Recent per-project checkout progress from work_submissions.project_updates JSON.
     */
    public function getWorkActivity(string $projectId, array $query = []): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, 'Method not allowed');
            return;
        }

        try {
            $decoded = $this->validateToken();
            $projectId = trim($projectId);
            if ($projectId === '') {
                $this->sendJsonResponse(400, 'project_id is required');
                return;
            }

            if (!$this->userCanViewProject($decoded, $projectId)) {
                $this->sendJsonResponse(403, 'Access denied to this project');
                return;
            }

            $exists = $this->conn->prepare('SELECT id FROM projects WHERE id = ? LIMIT 1');
            $exists->execute([$projectId]);
            if (!$exists->fetch()) {
                $this->sendJsonResponse(404, 'Project not found');
                return;
            }

            $from = $query['from'] ?? date('Y-m-01');
            $to = $query['to'] ?? date('Y-m-t');

            $this->ensureProjectUpdatesColumn();

            $sql = "SELECT ws.id, ws.user_id, ws.submission_date, ws.hours_today, ws.project_updates,
                           u.username, u.role
                    FROM work_submissions ws
                    INNER JOIN users u ON u.id = ws.user_id
                    WHERE ws.submission_date BETWEEN ? AND ?
                      AND ws.project_updates IS NOT NULL
                      AND JSON_LENGTH(ws.project_updates) > 0
                    ORDER BY ws.submission_date DESC, ws.updated_at DESC
                    LIMIT 200";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$from, $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $entries = [];
            foreach ($rows as $row) {
                $updates = json_decode($row['project_updates'] ?? '[]', true);
                if (!is_array($updates)) {
                    continue;
                }
                foreach ($updates as $update) {
                    if (!is_array($update) || (string) ($update['project_id'] ?? '') !== $projectId) {
                        continue;
                    }
                    $entries[] = [
                        'submission_id' => (int) $row['id'],
                        'submission_date' => $row['submission_date'],
                        'user_id' => $row['user_id'],
                        'username' => $row['username'],
                        'role' => $row['role'],
                        'hours_today' => (float) ($row['hours_today'] ?? 0),
                        'status' => (string) ($update['status'] ?? 'not_started'),
                        'progress_percentage' => max(0, min(100, (int) ($update['progress_percentage'] ?? 0))),
                        'notes' => trim((string) ($update['notes'] ?? '')),
                    ];
                }
            }

            $this->sendJsonResponse(200, 'OK', [
                'project_id' => $projectId,
                'from' => $from,
                'to' => $to,
                'entries' => $entries,
            ]);
        } catch (Exception $e) {
            $status = str_contains($e->getMessage(), 'token') ? 401 : 500;
            $this->sendJsonResponse($status, $e->getMessage());
        }
    }
}

// Route only when this file is the HTTP entry point (not when included by other endpoints).
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $controller = new ProjectController();
    $action = basename($_SERVER['PHP_SELF'], '.php');
    $id = isset($_GET['id']) ? $_GET['id'] : null;

    // Detect force_delete parameter
    $forceDelete = false;
    if (strpos($_SERVER['QUERY_STRING'] ?? '', 'force_delete=true') !== false) {
        $forceDelete = true;
    }
    if (isset($_GET['force_delete']) && $_GET['force_delete'] === 'true') {
        $forceDelete = true;
    }

    error_log("PROJECTCONTROLLER ROUTING - Force Delete: " . ($forceDelete ? 'YES' : 'NO'));
    error_log("PROJECTCONTROLLER ROUTING - Query String: " . ($_SERVER['QUERY_STRING'] ?? ''));

    if ($id) {
        switch ($action) {
            case 'get':
                $controller->getById($id);
                break;
            case 'update':
                $controller->update($id);
                break;
            case 'delete':
                $controller->delete($id, $forceDelete);
                break;
            default:
                $controller->handleError(404, "Endpoint not found");
        }
    } else {
        switch ($action) {
            case 'getAll':
                $controller->getAll();
                break;
            case 'create':
                $controller->create();
                break;
            default:
                $controller->handleError(404, "Endpoint not found");
        }
    }
}