<?php
require_once __DIR__ . '/../BaseAPI.php';

class ForwardMessageAPI extends BaseAPI {
    
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
            
            if (!isset($input['message_id']) || !isset($input['target_group_ids'])) {
                $this->sendJsonResponse(400, "message_id and target_group_ids are required");
                return;
            }
            
            $messageId = $input['message_id'];
            $targetGroupIds = $input['target_group_ids'];
            
            if (!is_array($targetGroupIds) || empty($targetGroupIds)) {
                $this->sendJsonResponse(400, "target_group_ids must be a non-empty array");
                return;
            }
            
            // Get original message
            $messageStmt = $this->conn->prepare("
                SELECT * FROM chat_messages 
                WHERE id = ? AND is_deleted = 0
            ");
            $messageStmt->execute([$messageId]);
            $originalMessage = $messageStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$originalMessage) {
                $this->sendJsonResponse(404, "Message not found");
                return;
            }
            
            $this->conn->beginTransaction();
            
            $forwardedCount = 0;
            $errors = [];
            
            foreach ($targetGroupIds as $targetGroupId) {
                try {
                    // Check if user has access to target group
                    $accessStmt = $this->conn->prepare("
                        SELECT 1 FROM chat_group_members 
                        WHERE group_id = ? AND user_id = ?
                    ");
                    $accessStmt->execute([$targetGroupId, $userId]);
                    
                    if (!$accessStmt->fetch()) {
                        $errors[] = "No access to group: $targetGroupId";
                        continue;
                    }
                    
                    // Create forwarded message
                    $newMessageId = $this->utils->generateUUID();
                    $stmt = $this->conn->prepare("
                        INSERT INTO chat_messages (
                            id, group_id, sender_id, message_type, content,
                            media_type, media_file_path, media_file_name,
                            voice_file_path, voice_duration,
                            is_forwarded, original_message_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
                    ");
                    
                    $stmt->execute([
                        $newMessageId,
                        $targetGroupId,
                        $userId,
                        $originalMessage['message_type'],
                        $originalMessage['content'],
                        $originalMessage['media_type'],
                        $originalMessage['media_file_path'],
                        $originalMessage['media_file_name'],
                        $originalMessage['voice_file_path'],
                        $originalMessage['voice_duration'],
                        $originalMessage['id']
                    ]);
                    
                    $forwardedCount++;
                    
                } catch (Exception $e) {
                    $errors[] = "Failed to forward to group $targetGroupId: " . $e->getMessage();
                }
            }
            
            $this->conn->commit();
            
            $message = "Message forwarded to $forwardedCount group(s)";
            if (!empty($errors)) {
                $message .= ". Errors: " . implode(", ", $errors);
            }
            
            $this->sendJsonResponse(200, $message, [
                'forwarded_count' => $forwardedCount,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Error forwarding message: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to forward message: " . $e->getMessage());
        }
    }
}

$api = new ForwardMessageAPI();
$api->handle();

