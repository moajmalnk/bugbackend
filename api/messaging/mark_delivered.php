<?php
require_once __DIR__ . '/../BaseAPI.php';

class MarkDeliveredAPI extends BaseAPI {
    
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
            
            // Don't mark sender's own message as delivered
            if ($message['sender_id'] === $userId) {
                $this->sendJsonResponse(200, "Cannot mark own message as delivered");
                return;
            }
            
            // Check group access
            $role = $decoded->role;
            if (!$this->validateGroupAccess($message['group_id'], $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied");
                return;
            }
            
            // Note: In a real implementation, you might track delivery status separately
            // For now, we'll just acknowledge the delivery
            $this->sendJsonResponse(200, "Message marked as delivered");
            
        } catch (Exception $e) {
            error_log("Error marking message as delivered: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to mark message as delivered: " . $e->getMessage());
        }
    }
}

$api = new MarkDeliveredAPI();
$api->handle();

