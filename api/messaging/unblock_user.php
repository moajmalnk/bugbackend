<?php
require_once __DIR__ . '/../BaseAPI.php';

class UnblockUserAPI extends BaseAPI {
    
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
            
            if (!isset($input['blocked_user_id'])) {
                $this->sendJsonResponse(400, "blocked_user_id is required");
                return;
            }
            
            $blockedUserId = $input['blocked_user_id'];
            
            // Unblock the user
            $stmt = $this->conn->prepare("
                DELETE FROM blocked_users 
                WHERE user_id = ? AND blocked_user_id = ?
            ");
            $stmt->execute([$userId, $blockedUserId]);
            
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "User was not blocked");
                return;
            }
            
            $this->sendJsonResponse(200, "User unblocked successfully");
            
        } catch (Exception $e) {
            error_log("Error unblocking user: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to unblock user: " . $e->getMessage());
        }
    }
}

$api = new UnblockUserAPI();
$api->handle();

