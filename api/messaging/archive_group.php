<?php
require_once __DIR__ . '/../BaseAPI.php';

class ArchiveGroupAPI extends BaseAPI {
    
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
            
            // Check if user is member of the group
            $role = $decoded->role;
            if (!$this->userCanAccessChatGroup($groupId, $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }

            if ($this->dbColumnExists('chat_groups', 'is_archived')) {
                $setClause = $this->dbColumnExists('chat_groups', 'archived_at')
                    ? "is_archived = 1, archived_at = CURRENT_TIMESTAMP"
                    : "is_archived = 1";
                $stmt = $this->conn->prepare("
                    UPDATE chat_groups
                    SET {$setClause}
                    WHERE id = ?
                ");
                $stmt->execute([$groupId]);
            }
            
            $this->sendJsonResponse(200, "Group archived successfully");
            
        } catch (Exception $e) {
            error_log("Error archiving group: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to archive group: " . $e->getMessage());
        }
    }
}

$api = new ArchiveGroupAPI();
$api->handle();

