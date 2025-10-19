<?php
require_once __DIR__ . '/../BaseAPI.php';

class MarkReadAPI extends BaseAPI {
    
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
            
            if (!isset($input['message_id'])) {
                $this->sendJsonResponse(400, "message_id is required");
                return;
            }
            
            $messageId = $input['message_id'];
            
            // Verify message exists and user has access
            $messageStmt = $this->conn->prepare("
                SELECT cm.id, cm.group_id, cm.sender_id
                FROM chat_messages cm
                WHERE cm.id = ? AND cm.is_deleted = 0
            ");
            $messageStmt->execute([$messageId]);
            $message = $messageStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$message) {
                $this->sendJsonResponse(404, "Message not found");
                return;
            }
            
            // Don't mark sender's own message as read
            if ($message['sender_id'] === $userId) {
                $this->sendJsonResponse(200, "Cannot mark own message as read");
                return;
            }
            
            // Check group access
            $role = $decoded->role;
            if (!$this->validateGroupAccess($message['group_id'], $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied");
                return;
            }
            
            // Check if already marked as read
            $checkStmt = $this->conn->prepare("
                SELECT id FROM message_read_status 
                WHERE message_id = ? AND user_id = ?
            ");
            $checkStmt->execute([$messageId, $userId]);
            
            if (!$checkStmt->fetch()) {
                // Insert read status
                $readId = $this->utils->generateUUID();
                $stmt = $this->conn->prepare("
                    INSERT INTO message_read_status (id, message_id, user_id)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$readId, $messageId, $userId]);
            }
            
            // Update last_read_at for the user in the group
            $updateMemberStmt = $this->conn->prepare("
                UPDATE chat_group_members 
                SET last_read_at = CURRENT_TIMESTAMP
                WHERE group_id = ? AND user_id = ?
            ");
            $updateMemberStmt->execute([$message['group_id'], $userId]);
            
            $this->sendJsonResponse(200, "Message marked as read");
            
        } catch (Exception $e) {
            error_log("Error marking message as read: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to mark message as read: " . $e->getMessage());
        }
    }
}

$api = new MarkReadAPI();
$api->handle();

