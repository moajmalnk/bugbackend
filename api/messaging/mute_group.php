<?php
require_once __DIR__ . '/../BaseAPI.php';

class MuteGroupAPI extends BaseAPI {
    
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
            $duration = $input['duration'] ?? 86400; // Default 24 hours
            
            // Check if user is member of the group
            $role = $decoded->role;
            if (!$this->validateGroupAccess($groupId, $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            // Calculate mute until timestamp
            $muteUntil = date('Y-m-d H:i:s', time() + $duration);
            
            // Check if already muted
            $checkStmt = $this->conn->prepare("
                SELECT id FROM muted_groups 
                WHERE group_id = ? AND user_id = ?
            ");
            $checkStmt->execute([$groupId, $userId]);
            
            if ($existingMute = $checkStmt->fetch()) {
                // Update existing mute
                $updateStmt = $this->conn->prepare("
                    UPDATE muted_groups 
                    SET muted_until = ? 
                    WHERE id = ?
                ");
                $updateStmt->execute([$muteUntil, $existingMute['id']]);
            } else {
                // Create new mute
                $muteId = $this->utils->generateUUID();
                $stmt = $this->conn->prepare("
                    INSERT INTO muted_groups (id, group_id, user_id, muted_until)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$muteId, $groupId, $userId, $muteUntil]);
            }
            
            $this->sendJsonResponse(200, "Group muted successfully", [
                'muted_until' => $muteUntil
            ]);
            
        } catch (Exception $e) {
            error_log("Error muting group: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to mute group: " . $e->getMessage());
        }
    }
}

$api = new MuteGroupAPI();
$api->handle();

