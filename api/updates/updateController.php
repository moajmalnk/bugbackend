<?php
require_once __DIR__ . '/../BaseAPI.php';

class UpdateController extends BaseAPI {
    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            $data = $this->getRequestData();

            if (!isset($data['title'], $data['type'], $data['description'])) {
                $this->sendJsonResponse(400, "Title, type, and description are required");
                return;
            }

            $id = Utils::generateUUID();
            $stmt = $this->conn->prepare(
                "INSERT INTO updates (id, title, type, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $id,
                $data['title'],
                $data['type'],
                $data['description'],
                $decoded->user_id
            ]);

            $this->sendJsonResponse(201, "Update created successfully", [
                'id' => $id,
                'title' => $data['title'],
                'type' => $data['type'],
                'description' => $data['description'],
                'created_by' => $decoded->user_id,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
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
            $stmt = $this->conn->prepare(
                "SELECT u.*, us.username as created_by_name
                 FROM updates u
                 LEFT JOIN users us ON u.created_by = us.id
                 WHERE u.id = ?"
            );
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }

            $this->sendJsonResponse(200, "Update retrieved successfully", [
                'id' => $update['id'],
                'title' => $update['title'],
                'type' => $update['type'],
                'description' => $update['description'],
                'created_by' => $update['created_by_name'] ?? $update['created_by'],
                'created_at' => $update['created_at'],
                'updated_at' => $update['updated_at'],
                'status' => $update['status']
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getAll() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            $stmt = $this->conn->prepare(
                "SELECT u.*, us.username as created_by_name
                 FROM updates u
                 LEFT JOIN users us ON u.created_by = us.id
                 ORDER BY u.created_at DESC"
            );
            $stmt->execute();
            $updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $result = array_map(function($update) {
                return [
                    'id' => $update['id'],
                    'title' => $update['title'],
                    'type' => $update['type'],
                    'description' => $update['description'],
                    'created_by' => $update['created_by_name'] ?? $update['created_by'],
                    'created_at' => $update['created_at'],
                    'updated_at' => $update['updated_at'],
                    'status' => $update['status']
                ];
            }, $updates);

            $this->sendJsonResponse(200, "Updates retrieved successfully", $result);
        } catch (Exception $e) {
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

    public function delete($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        try {
            $decoded = $this->validateToken();
            $user_id = $decoded->user_id;
            $user_role = $decoded->role;

            // Fetch update to check owner
            $stmt = $this->conn->prepare("SELECT created_by FROM updates WHERE id = ?");
            $stmt->execute([$id]);
            $update = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$update) {
                $this->sendJsonResponse(404, "Update not found");
                return;
            }

            // Only allow admin, or creator if tester/developer
            if (
                $user_role !== 'admin' &&
                !(
                    ($user_role === 'tester' || $user_role === 'developer') &&
                    $update['created_by'] == $user_id
                )
            ) {
                $this->sendJsonResponse(403, "You do not have permission to delete this update");
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

    public function approve($id) {
        $this->changeStatus($id, 'approved');
    }
    public function decline($id) {
        $this->changeStatus($id, 'declined');
    }
    private function changeStatus($id, $status) {
        try {
            $decoded = $this->validateToken();
            // Only admin can approve/decline
            if ($decoded->role !== 'admin') {
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
