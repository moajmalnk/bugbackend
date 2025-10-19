<?php
require_once __DIR__ . '/../BaseAPI.php';

class GetOnlineStatusAPI extends BaseAPI {
    
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
            
            $targetUserId = $_GET['user_id'] ?? null;
            
            if (!$targetUserId) {
                $this->sendJsonResponse(400, "user_id is required");
                return;
            }
            
            // Get online status
            $stmt = $this->conn->prepare("
                SELECT 
                    os.is_online,
                    os.last_seen,
                    u.username
                FROM online_status os
                JOIN users u ON os.user_id = u.id
                WHERE os.user_id = ?
            ");
            $stmt->execute([$targetUserId]);
            $status = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$status) {
                // User never set online status, assume offline
                $status = [
                    'is_online' => false,
                    'last_seen' => null,
                    'username' => 'Unknown'
                ];
            } else {
                $status['is_online'] = (bool)$status['is_online'];
            }
            
            $this->sendJsonResponse(200, "Online status retrieved successfully", $status);
            
        } catch (Exception $e) {
            error_log("Error getting online status: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to get online status: " . $e->getMessage());
        }
    }
}

$api = new GetOnlineStatusAPI();
$api->handle();

