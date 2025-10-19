<?php
require_once __DIR__ . '/../BaseAPI.php';

class BlockUserAPI extends BaseAPI {
    
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
            
            // Cannot block yourself
            if ($blockedUserId === $userId) {
                $this->sendJsonResponse(400, "Cannot block yourself");
                return;
            }
            
            // Check if user exists
            $userStmt = $this->conn->prepare("SELECT id FROM users WHERE id = ?");
            $userStmt->execute([$blockedUserId]);
            if (!$userStmt->fetch()) {
                $this->sendJsonResponse(404, "User not found");
                return;
            }
            
            // Check if already blocked
            $checkStmt = $this->conn->prepare("
                SELECT id FROM blocked_users 
                WHERE user_id = ? AND blocked_user_id = ?
            ");
            $checkStmt->execute([$userId, $blockedUserId]);
            
            if ($checkStmt->fetch()) {
                $this->sendJsonResponse(409, "User already blocked");
                return;
            }
            
            // Block the user
            $blockId = $this->utils->generateUUID();
            $stmt = $this->conn->prepare("
                INSERT INTO blocked_users (id, user_id, blocked_user_id)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$blockId, $userId, $blockedUserId]);
            
            $this->sendJsonResponse(200, "User blocked successfully");
            
        } catch (Exception $e) {
            error_log("Error blocking user: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to block user: " . $e->getMessage());
        }
    }
}

$api = new BlockUserAPI();
$api->handle();

