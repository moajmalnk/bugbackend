<?php
require_once __DIR__ . '/../BaseAPI.php';

class EditMessageAPI extends BaseAPI {
    
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
            
            if (!isset($input['message_id']) || !isset($input['content'])) {
                $this->sendJsonResponse(400, "message_id and content are required");
                return;
            }
            
            $messageId = $input['message_id'];
            $newContent = trim($input['content']);
            
            if (empty($newContent)) {
                $this->sendJsonResponse(400, "Content cannot be empty");
                return;
            }
            
            // Get message details
            $messageStmt = $this->conn->prepare("
                SELECT id, sender_id, message_type, created_at 
                FROM chat_messages 
                WHERE id = ? AND is_deleted = 0
            ");
            $messageStmt->execute([$messageId]);
            $message = $messageStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$message) {
                $this->sendJsonResponse(404, "Message not found");
                return;
            }
            
            // Verify user owns the message
            if ($message['sender_id'] !== $userId) {
                $this->sendJsonResponse(403, "You can only edit your own messages");
                return;
            }
            
            // Only text messages can be edited
            if ($message['message_type'] !== 'text') {
                $this->sendJsonResponse(400, "Only text messages can be edited");
                return;
            }
            
            // Check time limit (15 minutes)
            $messageTime = strtotime($message['created_at']);
            $currentTime = time();
            $timeLimit = 15 * 60; // 15 minutes in seconds
            
            if (($currentTime - $messageTime) > $timeLimit) {
                $this->sendJsonResponse(403, "Messages can only be edited within 15 minutes of sending");
                return;
            }
            
            // Update the message
            $stmt = $this->conn->prepare("
                UPDATE chat_messages 
                SET content = ?, 
                    is_edited = 1, 
                    edited_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$newContent, $messageId]);
            
            // Get updated message
            $updatedStmt = $this->conn->prepare("
                SELECT cm.*, u.username as sender_name
                FROM chat_messages cm
                JOIN users u ON cm.sender_id = u.id
                WHERE cm.id = ?
            ");
            $updatedStmt->execute([$messageId]);
            $updatedMessage = $updatedStmt->fetch(PDO::FETCH_ASSOC);
            
            $this->sendJsonResponse(200, "Message edited successfully", $updatedMessage);
            
        } catch (Exception $e) {
            error_log("Error editing message: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to edit message: " . $e->getMessage());
        }
    }
}

$api = new EditMessageAPI();
$api->handle();

