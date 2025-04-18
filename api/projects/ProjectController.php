<?php
require_once __DIR__ . '/../BaseAPI.php';

class ProjectController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }
    
    public function handleError($status, $message) {
        $this->sendJsonResponse($status, $message);
    }
    
    public function getAll() {
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
            
            $this->sendJsonResponse(200, "Projects retrieved successfully", $projects);
            
        } catch (Exception $e) {
            error_log("Error fetching projects: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    
    public function create() {
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
    
    public function getById($id) {
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
    
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();
            
            $updateFields = [];
            $types = "";
            $values = [];
            
            if (isset($data['name'])) {
                $updateFields[] = "name = ?";
                $types .= "s";
                $values[] = $data['name'];
            }
            
            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $types .= "s";
                $values[] = $data['description'];
            }
            
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $types .= "s";
                $values[] = $data['status'];
            }
            
            if (empty($updateFields)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }
            
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP()";
            
            $query = "UPDATE projects SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            
            $types .= "s";
            $values[] = $id;
            
            $stmt->bind_param($types, ...$values);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update project: " . $stmt->error);
            }
            
            if ($stmt->affected_rows === 0) {
                $this->sendJsonResponse(404, "Project not found");
                return;
            }
            
            $this->sendJsonResponse(200, "Project updated successfully");
            
        } catch (Exception $e) {
            error_log("Error updating project: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function delete($id) {
        try {
            $decoded = $this->validateToken();
            $this->conn->beginTransaction();

            // Check if project exists
            $checkQuery = "SELECT id FROM projects WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();

            if (!$checkStmt->fetch()) {
                $this->conn->rollBack();
                $this->sendJsonResponse(404, "Project not found");
                return;
            }

            // Delete project (cascading will handle related records)
            $query = "DELETE FROM projects WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                $this->conn->commit();
                $this->sendJsonResponse(200, "Project deleted successfully");
                return;
            }

            $this->conn->rollBack();
            $this->sendJsonResponse(500, "Failed to delete project");
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
}

// Handle the request
$controller = new ProjectController();
$action = basename($_SERVER['PHP_SELF'], '.php');
$id = isset($_GET['id']) ? $_GET['id'] : null;

if ($id) {
    switch ($action) {
        case 'get':
            $controller->getById($id);
            break;
        case 'update':
            $controller->update($id);
            break;
        case 'delete':
            $controller->delete($id);
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