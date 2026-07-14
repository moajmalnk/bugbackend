<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/ProjectComplianceController.php';

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
        'start_date',
        'deadline_date',
        'expected_publish_date',
        'testing_start_date',
        'testing_end_date',
        'frontend_finish_date',
        'backend_finish_date',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    private function normalizeDateField($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $value;
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

        foreach ($members as $member) {
            if (!isset($member['user_id'], $member['role'])) {
                continue;
            }
            $role = $member['role'];
            if (!in_array($role, $allowedRoles, true)) {
                continue;
            }

            $check = $this->conn->prepare(
                "SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ? LIMIT 1"
            );
            $check->execute([$projectId, $member['user_id']]);
            if ($check->fetch()) {
                continue;
            }

            $stmt->execute([$projectId, $member['user_id'], $role]);
        }
    }

    private function getProjectAttachments($projectId)
    {
        $stmt = $this->conn->prepare(
            "SELECT id, project_id, file_name, file_path, file_type, uploaded_by, created_at
             FROM project_attachments WHERE project_id = ? ORDER BY created_at DESC"
        );
        $stmt->execute([$projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        $stmt = $this->conn->prepare(
            "SELECT pm.user_id, pm.role, u.username, u.email
             FROM project_members pm
             INNER JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ?"
        );
        $stmt->execute([$project['id']]);
        $project['members_detail'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $project['attachments'] = $this->getProjectAttachments($project['id']);

        $complianceController = new ProjectComplianceController();
        $summary = $complianceController->getSummaryForProject($project['id']);
        if ($summary) {
            $project['compliance'] = $summary;
        }
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
            $clientWhere = $clientIdFilter !== '' ? ' WHERE client_id = ?' : '';
            $clientParams = $clientIdFilter !== '' ? [$clientIdFilter] : [];

            if ($user_role_lower === 'admin' && !$is_impersonated) {
                $query = "SELECT * FROM projects" . $clientWhere;
                $stmt = $this->conn->prepare($query);
                $stmt->execute($clientParams);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($is_impersonated) {
                $query = "SELECT DISTINCT p.* FROM projects p
                          INNER JOIN project_members pm ON p.id = pm.project_id
                          WHERE pm.user_id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$user_id]);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Developer / tester: allow browsing all projects
                $query = "SELECT * FROM projects" . $clientWhere;
                $stmt = $this->conn->prepare($query);
                $stmt->execute($clientParams);
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Add members, client, and compliance summary to each project
            $complianceController = new ProjectComplianceController();
            foreach ($projects as &$project) {
                $stmt2 = $this->conn->prepare(
                    "SELECT pm.user_id, pm.role, u.username, u.email
                     FROM project_members pm
                     INNER JOIN users u ON u.id = pm.user_id
                     WHERE pm.project_id = ?"
                );
                $stmt2->execute([$project['id']]);
                $membersDetail = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

            $id = Utils::generateUUID();
            $status = isset($data['status']) ? $data['status'] : 'active';

            $columns = ['id', 'name', 'description', 'status', 'created_by'];
            $placeholders = ['?', '?', '?', '?', '?'];
            $values = [$id, $data['name'], $data['description'], $status, $decoded->user_id];

            foreach (self::$EXTENDED_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $columns[] = $field;
                    $placeholders[] = '?';
                    $values[] = $this->normalizeDateField($data[$field]);
                }
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

            $updateFields = [];
            $values = [];

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

                $updateFields[] = "status = ?";
                $values[] = $newStatus;
            }

            foreach (self::$EXTENDED_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $updateFields[] = "$field = ?";
                    $values[] = $this->normalizeDateField($data[$field]);
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

            $this->sendJsonResponse(200, "Project updated successfully");

        } catch (Exception $e) {
            error_log("Error updating project: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function delete($id, $forceDelete = false)
    {
        try {
            // Convert forceDelete to boolean and log it
            $forceDelete = (bool) $forceDelete;
            error_log("ProjectController::delete - ID: $id, Force Delete: " . ($forceDelete ? 'YES' : 'NO'));
            error_log("Raw forceDelete parameter value: " . var_export($forceDelete, true) . " (type: " . gettype($forceDelete) . ")");

            // Skip method check as it's already handled in delete.php
            $decoded = $this->validateToken();

            // Start transaction
            $this->conn->beginTransaction();
            error_log("Transaction started for project deletion");

            // Check if project exists
            $checkQuery = "SELECT id FROM projects WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();

            if (!$checkStmt->fetch()) {
                $this->conn->rollBack();
                error_log("Project not found: $id");
                $this->sendJsonResponse(404, "Project not found");
                return;
            }
            error_log("Project exists: $id");

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

            // If force delete is NOT enabled and there are related records, return error
            if (!$forceDelete && ($memberCount > 0 || $bugCount > 0)) {
                $this->conn->rollBack();

                $message = "Cannot delete project due to existing ";
                if ($memberCount > 0 && $bugCount > 0) {
                    $message .= "team members and bugs";
                } else if ($memberCount > 0) {
                    $message .= "team members";
                } else {
                    $message .= "bugs";
                }
                $message .= ". Please remove these relationships first.";

                error_log("Force delete not enabled, returning error: $message");
                $this->sendJsonResponse(400, $message);
                return;
            }

            // Process with force delete if enabled or no related records
            if ($forceDelete) {
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