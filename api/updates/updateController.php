<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';
class UpdateController extends BaseAPI
{
    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $data = $this->getRequestData();

            if (
                !isset($data['title'], $data['type'], $data['description'], $data['project_id'])
                || empty($data['title']) || empty($data['type']) || empty($data['description']) || empty($data['project_id'])
            ) {
                $this->sendJsonResponse(400, "All fields are required");
                return;
            }

            $userId = $decoded->user_id;
            $projectId = $data['project_id'];

            // Use ProjectMemberController for access check (admins, testers, developers assigned)
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }

            $id = Utils::generateUUID();
            $stmt = $this->conn->prepare("INSERT INTO updates (id, project_id, title, type, description, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $success = $stmt->execute([
                $id,
                $projectId,
                $data['title'],
                $data['type'],
                $data['description'],
                $userId
            ]);

            if ($success) {
                // Also fetch creator username for convenience
                $username = null;
                try {
                    $stmtUser = $this->conn->prepare("SELECT username FROM users WHERE id = ?");
                    $stmtUser->execute([$userId]);
                    $username = $stmtUser->fetchColumn();
                } catch (Exception $e) {}

                $this->sendJsonResponse(201, "Update created successfully", [
                    'id' => $id,
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'description' => $data['description'],
                    'created_by_id' => $userId,
                    'created_by' => $username ?? $userId,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->sendJsonResponse(500, "Failed to create update");
            }
        } catch (Exception $e) {
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
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            // Fetch update to get project_id
            $stmt = $this->conn->prepare(
                "SELECT u.*, us.username as created_by_name, p.name as project_name
                 FROM updates u
                 LEFT JOIN users us ON u.created_by = us.id
                 LEFT JOIN projects p ON u.project_id = p.id
                 WHERE u.id = ?"
            );
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            $this->sendJsonResponse(200, "Update retrieved successfully", [
                'id' => $update['id'],
                'title' => $update['title'],
                'type' => $update['type'],
                'description' => $update['description'],
                'created_by_id' => $update['created_by'],
                'created_by' => $update['created_by_name'] ?? $update['created_by'],
                'created_at' => $update['created_at'],
                'updated_at' => $update['updated_at'],
                'status' => $update['status'],
                'project_id' => $update['project_id'],
                'project_name' => $update['project_name'] ?? null
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getAll()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            $pmc = new ProjectMemberController();
            // Admin: get all updates
            if ($userRole === 'admin') {
                $stmt = $this->conn->prepare("SELECT u.*, p.name as project_name, us.username as created_by_name FROM updates u JOIN projects p ON u.project_id = p.id LEFT JOIN users us ON u.created_by = us.id ORDER BY u.created_at DESC");
                $stmt->execute();
                $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Get all project IDs the user is a member of
                $stmt = $this->conn->prepare("SELECT project_id FROM project_members WHERE user_id = ?");
                $stmt->execute([$userId]);
                $projectIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (empty($projectIds)) {
                    $this->sendJsonResponse(200, "No updates found", []);
                    return;
                }
                $in = str_repeat('?,', count($projectIds) - 1) . '?';
                $stmt = $this->conn->prepare("SELECT u.*, p.name as project_name, us.username as created_by_name FROM updates u JOIN projects p ON u.project_id = p.id LEFT JOIN users us ON u.created_by = us.id WHERE u.project_id IN ($in) ORDER BY u.created_at DESC");
                $stmt->execute($projectIds);
                $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $result = array_map(function ($update) {
                return [
                    'id' => $update['id'],
                    'title' => $update['title'],
                    'type' => $update['type'],
                    'description' => $update['description'],
                    'created_by_id' => $update['created_by'],
                    'created_by' => $update['created_by_name'] ?? $update['created_by'],
                    'created_at' => $update['created_at'],
                    'updated_at' => $update['updated_at'],
                    'status' => $update['status'],
                    'project_name' => $update['project_name']
                ];
            }, $updates);
            $this->sendJsonResponse(200, "Updates retrieved successfully", $result);
        } catch (Exception $e) {
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
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            $data = $this->getRequestData();
            // Fetch update to get project_id and created_by
            $stmt = $this->conn->prepare("SELECT * FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            // Only allow admin or creator to update
            if ($userRole !== 'admin' && $update['created_by'] != $userId) {
                $this->sendJsonResponse(403, 'You do not have permission to update this update');
                return;
            }
            $fields = [];
            $values = [];
            if (isset($data['title'])) {
                $fields[] = "title = ?";
                $values[] = $data['title'];
            }
            if (isset($data['type'])) {
                $fields[] = "type = ?";
                $values[] = $data['type'];
            }
            if (isset($data['description'])) {
                $fields[] = "description = ?";
                $values[] = $data['description'];
            }
            if (isset($data['status'])) {
                $fields[] = "status = ?";
                $values[] = $data['status'];
            }
            if (empty($fields)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }
            $fields[] = "updated_at = NOW()";
            $values[] = $id;
            $query = "UPDATE updates SET " . implode(", ", $fields) . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($values);
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Update not found or no changes made");
                return;
            }
            $this->sendJsonResponse(200, "Update updated successfully");
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function delete($id)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            // Fetch update to get project_id and created_by
            $stmt = $this->conn->prepare("SELECT * FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            // Only allow admin or creator to delete
            if ($userRole !== 'admin' && $update['created_by'] != $userId) {
                $this->sendJsonResponse(403, 'You do not have permission to delete this update');
                return;
            }
            $stmt = $this->conn->prepare("DELETE FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $this->sendJsonResponse(200, "Update deleted successfully");
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function approve($id)
    {
        $this->changeStatus($id, 'approved');
    }
    public function decline($id)
    {
        $this->changeStatus($id, 'declined');
    }
    private function changeStatus($id, $status)
    {
        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Unauthorized: Invalid or missing token");
                return;
            }
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            // Fetch update to get project_id
            $stmt = $this->conn->prepare("SELECT * FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }
            $projectId = $update['project_id'];
            $pmc = new ProjectMemberController();
            if (!$pmc->hasProjectAccess($userId, $projectId)) {
                $this->sendJsonResponse(403, 'You are not a member of this project');
                return;
            }
            // Only admin can approve/decline
            if ($userRole !== 'admin') {
                $this->sendJsonResponse(403, "Only admin can approve or decline updates");
                return;
            }
            $stmt = $this->conn->prepare("UPDATE updates SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            $this->sendJsonResponse(200, "Update $status successfully");
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
}
