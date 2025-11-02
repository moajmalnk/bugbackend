<?php
require_once __DIR__ . '/TaskController.php';

class OwnTaskController extends TaskController {
    public function listMyOwnTasks($filters) {
        // Use the standard validateToken method which handles impersonation correctly
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        
        // Debug logging to verify user isolation and impersonation
        $impersonationInfo = isset($decoded->impersonated) && $decoded->impersonated ? " (IMPERSONATED)" : "";
        $adminInfo = isset($decoded->admin_id) ? " Admin: " . $decoded->admin_id : "";
        error_log("🔍 OwnTaskController::listMyOwnTasks - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . $impersonationInfo . $adminInfo);
        
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
        
        error_log("🔍 OwnTaskController::listMyOwnTasks - Found " . count($rows) . " tasks for user: " . $userId . $impersonationInfo);
        $this->sendJsonResponse(200, 'OK', $rows);
    }
}

$c = new OwnTaskController();
$c->listMyOwnTasks($_GET);
?>
