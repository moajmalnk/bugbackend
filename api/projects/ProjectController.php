<?php
require_once __DIR__ . '/../BaseAPI.php';

class ProjectController extends BaseAPI
{
    public function __construct()
    {
        parent::__construct();
    }

    public function handleError($status, $message)
    {
        $this->sendJsonResponse($status, $message);
    }

    public function getAll()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $this->sendJsonResponse(405, "Method not allowed");
        return;
    }

    try {
        $decoded = $this->validateToken();

        $query = "SELECT * FROM projects";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();

        $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add members array to each project
        foreach ($projects as &$project) {
            $stmt2 = $this->conn->prepare("SELECT user_id FROM project_members WHERE project_id = ?");
            $stmt2->execute([$project['id']]);
            $project['members'] = array_column($stmt2->fetchAll(PDO::FETCH_ASSOC), 'user_id');
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
            $stmt = $this->conn->prepare(
                "INSERT INTO projects (id, name, description, status, created_by) 
                 VALUES (?, ?, ?, ?, ?)"
            );

            $status = 'active';
            $stmt->execute([
                $id,
                $data['name'],
                $data['description'],
                $status,
                $decoded->user_id
            ]);

            $project = [
                'id' => $id,
                'name' => $data['name'],
                'description' => $data['description'],
                'status' => $status,
                'created_by' => $decoded->user_id,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

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
                $updateFields[] = "status = ?";
                $values[] = $data['status'];
            }

            if (empty($updateFields)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }

            $updateFields[] = "updated_at = CURRENT_TIMESTAMP()";

            $query = "UPDATE projects SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);

            $values[] = $id;

            if (!$stmt->execute($values)) {
                throw new Exception("Failed to update project");
            }

            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Project not found or no changes made");
                return;
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
}

// Handle the request
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