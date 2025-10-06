<?php
require_once __DIR__ . '/../BaseAPI.php';

class TaskController extends BaseAPI {
    public function createTask($payload) {
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        
        // Debug logging to verify user isolation
        error_log("🔍 TaskController::createTask - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . ", Title: " . ($payload['title'] ?? 'no title'));

        $sql = "INSERT INTO user_tasks (user_id, title, description, project_id, priority, status, due_date, period, expected_hours) VALUES (?,?,?,?,?,?,?,?,?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            $userId,
            $payload['title'] ?? '',
            $payload['description'] ?? null,
            $payload['project_id'] ?? null,
            $payload['priority'] ?? 'medium',
            $payload['status'] ?? 'todo',
            $payload['due_date'] ?? null,
            $payload['period'] ?? 'daily',
            isset($payload['expected_hours']) ? (float)$payload['expected_hours'] : 0,
        ]);

        $newId = $this->conn->lastInsertId();
        error_log("🔍 TaskController::createTask - Created task ID: " . $newId . " for user: " . $userId);
        $this->sendJsonResponse(201, 'Task created', ['id' => $newId]);
    }

    public function listMyTasks($filters) {
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        
        // Debug logging to verify user isolation and impersonation
        $impersonationInfo = isset($decoded->impersonated) && $decoded->impersonated ? " (IMPERSONATED)" : "";
        $roleInfo = isset($decoded->role) ? " Role: " . $decoded->role : "";
        error_log("🔍 TaskController::listMyTasks - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . $impersonationInfo . $roleInfo);

        $conditions = ["user_id = ?"]; $params = [$userId];
        if (!empty($filters['status'])) { $conditions[] = "status = ?"; $params[] = $filters['status']; }
        if (!empty($filters['project_id'])) { $conditions[] = "project_id = ?"; $params[] = $filters['project_id']; }
        $where = "WHERE " . implode(" AND ", $conditions);

        $sql = "SELECT * FROM user_tasks $where ORDER BY FIELD(status,'in_progress','todo','blocked','done'), due_date IS NULL, due_date ASC, updated_at DESC LIMIT 200";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("🔍 TaskController::listMyTasks - Found " . count($rows) . " tasks for user: " . $userId . $impersonationInfo);
        $this->sendJsonResponse(200, 'OK', $rows);
    }

    public function updateTask($payload) {
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        $id = $payload['id'] ?? null;
        if (!$id) { $this->sendJsonResponse(400, 'Missing id'); }

        $fields = ['title','description','project_id','priority','status','due_date','period','expected_hours','spent_hours'];
        $sets = []; $params = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $payload)) { $sets[] = "$f = ?"; $params[] = $payload[$f]; }
        }
        if (!$sets) { $this->sendJsonResponse(400, 'Nothing to update'); }
        $params[] = $id; $params[] = $userId;

        $sql = "UPDATE user_tasks SET " . implode(',', $sets) . " WHERE id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        $this->sendJsonResponse(200, 'Task updated');
    }

    public function deleteTask($payload) {
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        $id = $payload['id'] ?? null;
        if (!$id) { $this->sendJsonResponse(400, 'Missing id'); }
        $sql = "DELETE FROM user_tasks WHERE id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id, $userId]);
        $this->sendJsonResponse(200, 'Task deleted');
    }
}
?>


