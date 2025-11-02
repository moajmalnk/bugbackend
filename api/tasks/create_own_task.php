<?php
require_once __DIR__ . '/TaskController.php';

class OwnTaskController extends TaskController {
    public function createOwnTask($payload) {
        // Use the standard validateToken method which handles impersonation correctly
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        
        // Debug logging to verify user isolation and impersonation
        $impersonationInfo = isset($decoded->impersonated) && $decoded->impersonated ? " (IMPERSONATED)" : "";
        $adminInfo = isset($decoded->admin_id) ? " Admin: " . $decoded->admin_id : "";
        error_log("🔍 OwnTaskController::createOwnTask - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . $impersonationInfo . $adminInfo . ", Title: " . ($payload['title'] ?? 'no title'));
        
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
        error_log("🔍 OwnTaskController::createOwnTask - Created task ID: " . $newId . " for user: " . $userId . $impersonationInfo);
        $this->sendJsonResponse(201, 'Task created', ['id' => $newId]);
    }
}

$c = new OwnTaskController();
$data = $c->getRequestData();
$c->createOwnTask($data);
?>
