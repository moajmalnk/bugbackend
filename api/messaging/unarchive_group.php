<?php
require_once __DIR__ . '/../BaseAPI.php';

class UnarchiveGroupAPI extends BaseAPI {
    
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
            
            // Unarchive the group
            $stmt = $this->conn->prepare("
                DELETE FROM archived_groups 
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $userId]);
            
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Archived group not found");
                return;
            }
            
            $this->sendJsonResponse(200, "Group unarchived successfully");
            
        } catch (Exception $e) {
            error_log("Error unarchiving group: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to unarchive group: " . $e->getMessage());
        }
    }
}

$api = new UnarchiveGroupAPI();
$api->handle();

