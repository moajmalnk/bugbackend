<?php
require_once __DIR__ . '/../BaseAPI.php';

class GetBlockedUsersAPI extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Get blocked users
            $stmt = $this->conn->prepare("
                SELECT 
                    bu.id,
                    bu.blocked_user_id,
                    u.username,
                    u.email,
                    u.avatar,
                    bu.created_at as blocked_at
                FROM blocked_users bu
                JOIN users u ON bu.blocked_user_id = u.id
                WHERE bu.user_id = ?
                ORDER BY bu.created_at DESC
            ");
            $stmt->execute([$userId]);
            $blockedUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendJsonResponse(200, "Blocked users retrieved successfully", $blockedUsers);
            
        } catch (Exception $e) {
            error_log("Error getting blocked users: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to get blocked users: " . $e->getMessage());
        }
    }
}

$api = new GetBlockedUsersAPI();
$api->handle();

