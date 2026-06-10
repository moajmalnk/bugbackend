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
            if (!$this->userCanAccessChatGroup($groupId, $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            // Calculate mute until timestamp
            $muteUntil = $duration ? date('Y-m-d H:i:s', time() + (int)$duration) : null;

            if ($this->dbColumnExists('chat_group_members', 'is_muted')) {
                $stmt = $this->conn->prepare("
                    UPDATE chat_group_members
                    SET is_muted = 1, muted_until = ?
                    WHERE group_id = ? AND user_id = ?
                ");
                $stmt->execute([$muteUntil, $groupId, $userId]);
            } else {
                $this->sendJsonResponse(200, "Mute settings are not installed", [
                    'muted_until' => null
                ]);
                return;
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

