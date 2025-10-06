<?php
require_once __DIR__ . '/TaskController.php';

class OwnTaskController extends TaskController {
    public function createOwnTask($payload) {
        // Force disable impersonation for personal tasks
        $originalToken = $this->getBearerToken();
        
        try {
            // Validate token without impersonation
            $result = $this->utils->validateJWT($originalToken);
            
            if (!$result || !isset($result->user_id)) {
                $this->sendJsonResponse(401, 'Invalid token or user_id missing');
                return;
            }
            
            // For dashboard access tokens, use admin_id (the actual admin user)
            // For regular tokens, use user_id (the logged in user)
            $userId = null;
            if (isset($result->purpose) && $result->purpose === 'dashboard_access' && isset($result->admin_id)) {
                $userId = $result->admin_id; // Admin creating their own tasks
                error_log("🔍 OwnTaskController::createOwnTask - Dashboard token detected, using admin_id: " . $userId);
            } else {
                $userId = $result->user_id; // Regular user token
                error_log("🔍 OwnTaskController::createOwnTask - Regular token, using user_id: " . $userId);
            }
            
            // Debug logging to verify no impersonation
            error_log("🔍 OwnTaskController::createOwnTask - Original User ID: " . $userId . ", Username: " . ($result->username ?? 'unknown') . " (NO IMPERSONATION), Title: " . ($payload['title'] ?? 'no title'));
            
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
            error_log("🔍 OwnTaskController::createOwnTask - Created OWN task ID: " . $newId . " for user: " . $userId);
            $this->sendJsonResponse(201, 'Task created', ['id' => $newId]);
            
        } catch (Exception $e) {
            error_log('OwnTaskController create error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to create own task');
        }
    }
}

$c = new OwnTaskController();
$data = $c->getRequestData();
$c->createOwnTask($data);
?>
