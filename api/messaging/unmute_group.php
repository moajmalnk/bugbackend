<?php
require_once __DIR__ . '/../BaseAPI.php';

class UnmuteGroupAPI extends BaseAPI {
    
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
            
            if (!isset($input['group_id'])) {
                $this->sendJsonResponse(400, "group_id is required");
                return;
            }
            
            $groupId = $input['group_id'];
            
            // Unmute the group
            $stmt = $this->conn->prepare("
                DELETE FROM muted_groups 
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $userId]);
            
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Muted group not found");
                return;
            }
            
            $this->sendJsonResponse(200, "Group unmuted successfully");
            
        } catch (Exception $e) {
            error_log("Error unmuting group: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to unmute group: " . $e->getMessage());
        }
    }
}

$api = new UnmuteGroupAPI();
$api->handle();

