<?php
require_once __DIR__ . '/TaskController.php';

class OwnTaskController extends TaskController {
    public function listMyOwnTasks($filters) {
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
                $userId = $result->admin_id; // Admin viewing their own tasks
                error_log("🔍 OwnTaskController::listMyOwnTasks - Dashboard token detected, using admin_id: " . $userId);
            } else {
                $userId = $result->user_id; // Regular user token
                error_log("🔍 OwnTaskController::listMyOwnTasks - Regular token, using user_id: " . $userId);
            }
            
            // Debug logging to verify no impersonation
            error_log("🔍 OwnTaskController::listMyOwnTasks - Original User ID: " . $userId . ", Username: " . ($result->username ?? 'unknown') . " (NO IMPERSONATION)");
            
            $conditions = ["user_id = ?"]; 
            $params = [$userId];
            if (!empty($filters['status'])) { 
                $conditions[] = "status = ?"; 
                $params[] = $filters['status']; 
            }
            if (!empty($filters['project_id'])) { 
                $conditions[] = "project_id = ?"; 
                $params[] = $filters['project_id']; 
            }
            $where = "WHERE " . implode(" AND ", $conditions);
            
            $sql = "SELECT * FROM user_tasks $where ORDER BY FIELD(status,'in_progress','todo','blocked','done'), due_date IS NULL, due_date ASC, updated_at DESC LIMIT 200";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("🔍 OwnTaskController::listMyOwnTasks - Found " . count($rows) . " OWN tasks for user: " . $userId);
            $this->sendJsonResponse(200, 'OK', $rows);
            
        } catch (Exception $e) {
            error_log('OwnTaskController error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to load own tasks');
        }
    }
}

$c = new OwnTaskController();
$c->listMyOwnTasks($_GET);
?>
