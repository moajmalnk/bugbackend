<?php
require_once __DIR__ . '/../BaseAPI.php';

class GetGroupSettingsAPI extends BaseAPI {
    
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
            
            $groupId = $_GET['group_id'] ?? null;
            
            if (!$groupId) {
                $this->sendJsonResponse(400, "group_id is required");
                return;
            }
            
            // Check if user is member of the group
            $role = $decoded->role;
            if (!$this->validateGroupAccess($groupId, $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            // Get member settings
            $stmt = $this->conn->prepare("
                SELECT is_muted, muted_until, show_read_receipts
                FROM chat_group_members
                WHERE group_id = ? AND user_id = ?
            ");
            $stmt->execute([$groupId, $userId]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$settings) {
                $this->sendJsonResponse(404, "Group membership not found");
                return;
            }
            
            // Convert to boolean
            $settings['is_muted'] = (bool)$settings['is_muted'];
            $settings['show_read_receipts'] = (bool)$settings['show_read_receipts'];
            
            $this->sendJsonResponse(200, "Group settings retrieved successfully", $settings);
            
        } catch (Exception $e) {
            error_log("Error getting group settings: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to get group settings: " . $e->getMessage());
        }
    }
}

$api = new GetGroupSettingsAPI();
$api->handle();

