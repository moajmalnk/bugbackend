<?php
require_once __DIR__ . '/../BaseAPI.php';

class StarMessageAPI extends BaseAPI {
    
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
            
            // Verify message exists
            $messageStmt = $this->conn->prepare("SELECT id, group_id FROM chat_messages WHERE id = ?");
            $messageStmt->execute([$messageId]);
            $message = $messageStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$message) {
                $this->sendJsonResponse(404, "Message not found");
                return;
            }
            
            // Check if already starred
            $checkStmt = $this->conn->prepare("SELECT id FROM starred_messages WHERE message_id = ? AND user_id = ?");
            $checkStmt->execute([$messageId, $userId]);
            if ($checkStmt->fetch()) {
                $this->sendJsonResponse(409, "Message already starred");
                return;
            }
            
            // Star the message
            $starId = $this->utils->generateUUID();
            $stmt = $this->conn->prepare("
                INSERT INTO starred_messages (id, message_id, user_id, group_id)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$starId, $messageId, $userId, $message['group_id']]);
            
            $this->sendJsonResponse(200, "Message starred successfully");
            
        } catch (Exception $e) {
            error_log("Error starring message: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to star message: " . $e->getMessage());
        }
    }
}

$api = new StarMessageAPI();
$api->handle();

