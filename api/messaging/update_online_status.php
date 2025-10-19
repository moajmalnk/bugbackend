<?php
require_once __DIR__ . '/../BaseAPI.php';

class UpdateOnlineStatusAPI extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            $isOnline = $input['is_online'] ?? true;
            
            // Check if record exists
            $checkStmt = $this->conn->prepare("
                SELECT id FROM online_status WHERE user_id = ?
            ");
            $checkStmt->execute([$userId]);
            
            if ($existing = $checkStmt->fetch()) {
                // Update existing status
                $stmt = $this->conn->prepare("
                    UPDATE online_status 
                    SET is_online = ?, 
                        last_seen = CURRENT_TIMESTAMP 
                    WHERE user_id = ?
                ");
                $stmt->execute([$isOnline ? 1 : 0, $userId]);
            } else {
                // Insert new status
                $statusId = $this->utils->generateUUID();
                $stmt = $this->conn->prepare("
                    INSERT INTO online_status (id, user_id, is_online)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$statusId, $userId, $isOnline ? 1 : 0]);
            }
            
            $this->sendJsonResponse(200, "Online status updated successfully");
            
        } catch (Exception $e) {
            error_log("Error updating online status: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update online status: " . $e->getMessage());
        }
    }
}

$api = new UpdateOnlineStatusAPI();
$api->handle();

