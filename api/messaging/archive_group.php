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
            if (!$this->validateGroupAccess($groupId, $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            // Check if already archived
            $checkStmt = $this->conn->prepare("
                SELECT id FROM archived_groups 
                WHERE group_id = ? AND user_id = ?
            ");
            $checkStmt->execute([$groupId, $userId]);
            
            if ($checkStmt->fetch()) {
                $this->sendJsonResponse(409, "Group already archived");
                return;
            }
            
            // Archive the group
            $archiveId = $this->utils->generateUUID();
            $stmt = $this->conn->prepare("
                INSERT INTO archived_groups (id, group_id, user_id)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$archiveId, $groupId, $userId]);
            
            $this->sendJsonResponse(200, "Group archived successfully");
            
        } catch (Exception $e) {
            error_log("Error archiving group: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to archive group: " . $e->getMessage());
        }
    }
}

$api = new ArchiveGroupAPI();
$api->handle();

