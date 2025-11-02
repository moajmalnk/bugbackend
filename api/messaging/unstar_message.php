<?php
require_once __DIR__ . '/../BaseAPI.php';

class UnstarMessageAPI extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            $messageId = $_GET['message_id'] ?? null;
            
            if (!$messageId) {
                $this->sendJsonResponse(400, "message_id is required");
                return;
            }
            
            // Delete starred message
            $stmt = $this->conn->prepare("
                DELETE FROM starred_messages 
                WHERE message_id = ? AND user_id = ?
            ");
            $stmt->execute([$messageId, $userId]);
            
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Starred message not found");
                return;
            }
            
            $this->sendJsonResponse(200, "Message unstarred successfully");
            
        } catch (Exception $e) {
            error_log("Error unstarring message: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to unstar message: " . $e->getMessage());
        }
    }
}

$api = new UnstarMessageAPI();
$api->handle();

