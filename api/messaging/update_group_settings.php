<?php
require_once __DIR__ . '/../BaseAPI.php';

class UpdateGroupSettingsAPI extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
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
            
            // Check if user is member of the group
            $role = $decoded->role;
            if (!$this->validateGroupAccess($groupId, $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            // Update member settings
            $updates = [];
            $params = [];
            
            if (isset($input['is_muted'])) {
                $updates[] = "is_muted = ?";
                $params[] = $input['is_muted'] ? 1 : 0;
            }
            
            if (isset($input['show_read_receipts'])) {
                $updates[] = "show_read_receipts = ?";
                $params[] = $input['show_read_receipts'] ? 1 : 0;
            }
            
            if (empty($updates)) {
                $this->sendJsonResponse(400, "No settings to update");
                return;
            }
            
            $params[] = $groupId;
            $params[] = $userId;
            
            $sql = "UPDATE chat_group_members SET " . implode(", ", $updates) . " WHERE group_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            $this->sendJsonResponse(200, "Group settings updated successfully");
            
        } catch (Exception $e) {
            error_log("Error updating group settings: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update group settings: " . $e->getMessage());
        }
    }
}

$api = new UpdateGroupSettingsAPI();
$api->handle();

