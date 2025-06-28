<?php
require_once __DIR__ . '/../BaseAPI.php';

class ChatMessageController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Send a new message
     */
    public function send() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['group_id']) || !isset($input['message_type'])) {
                $this->sendJsonResponse(400, "group_id and message_type are required");
                return;
            }
            
            $groupId = $input['group_id'];
            $messageType = $input['message_type'];
            $content = $input['content'] ?? null;
            $replyToMessageId = $input['reply_to_message_id'] ?? null;
            
            // Validate message type
            if (!in_array($messageType, ['text', 'voice', 'reply'])) {
                $this->sendJsonResponse(400, "Invalid message type");
                return;
            }
            
            // Check if user has access to the group
            if (!$this->validateGroupAccess($groupId, $userId, $userRole)) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            // Validate content based on message type
            if ($messageType === 'text' && (empty($content) || strlen(trim($content)) === 0)) {
                $this->sendJsonResponse(400, "Text message content cannot be empty");
                return;
            }
            
            if ($messageType === 'voice' && empty($input['voice_file_path'])) {
                $this->sendJsonResponse(400, "Voice file path is required for voice messages");
                return;
            }
            
            // Validate reply message if provided
            if ($replyToMessageId) {
                if (!$this->validateReplyMessage($replyToMessageId, $groupId)) {
                    $this->sendJsonResponse(400, "Invalid reply message");
                    return;
                }
            }
            
            $messageId = $this->utils->generateUUID();
            
            $stmt = $this->conn->prepare("
                INSERT INTO chat_messages (
                    id, group_id, sender_id, message_type, content, 
                    voice_file_path, voice_duration, reply_to_message_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $messageId,
                $groupId,
                $userId,
                $messageType,
                $content,
                $input['voice_file_path'] ?? null,
                $input['voice_duration'] ?? null,
                $replyToMessageId
            ]);
            
            // Get the created message with sender details
            $message = $this->getMessageWithDetails($messageId);
            
            $this->sendJsonResponse(201, "Message sent successfully", $message);
            
        } catch (Exception $e) {
            error_log("Error sending message: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to send message: " . $e->getMessage());
        }
    }
    
    /**
     * Get messages for a group
     */
    public function getByGroup($groupId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Check if user has access to the group
            if (!$this->validateGroupAccess($groupId, $userId, $userRole)) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = ($page - 1) * $limit;
            
            // Get messages with sender details and reply information
            $query = "
                SELECT 
                    cm.*,
                    u.username as sender_name,
                    u.email as sender_email,
                    u.role as sender_role,
                    rm.content as reply_content,
                    rm.message_type as reply_type,
                    ru.username as reply_sender_name
                FROM chat_messages cm
                JOIN users u ON cm.sender_id = u.id
                LEFT JOIN chat_messages rm ON cm.reply_to_message_id = rm.id
                LEFT JOIN users ru ON rm.sender_id = ru.id
                WHERE cm.group_id = ? AND cm.is_deleted = 0
                ORDER BY cm.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$groupId, $limit, $offset]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count for pagination
            $countStmt = $this->conn->prepare("
                SELECT COUNT(*) as total 
                FROM chat_messages 
                WHERE group_id = ? AND is_deleted = 0
            ");
            $countStmt->execute([$groupId]);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Mark messages as read for this user
            $this->markMessagesAsRead($groupId, $userId, $messages);
            
            $this->sendJsonResponse(200, "Messages retrieved successfully", [
                'messages' => array_reverse($messages), // Return in chronological order
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Error fetching messages: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve messages: " . $e->getMessage());
        }
    }
    
    /**
     * Delete a message
     */
    public function delete($messageId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Get message details
            $message = $this->getMessageWithDetails($messageId);
            if (!$message) {
                $this->sendJsonResponse(404, "Message not found");
                return;
            }
            
            // Check if user can delete this message
            $canDelete = false;
            if ($userRole === 'admin') {
                $canDelete = true; // Admins can delete any message
            } elseif ($message['sender_id'] === $userId) {
                // Users can only delete their own messages within 1 hour
                $messageTime = strtotime($message['created_at']);
                $currentTime = time();
                $oneHour = 3600; // 1 hour in seconds
                
                if (($currentTime - $messageTime) <= $oneHour) {
                    $canDelete = true;
                } else {
                    $this->sendJsonResponse(403, "You can only delete your own messages within 1 hour of posting");
                    return;
                }
            }
            
            if (!$canDelete) {
                $this->sendJsonResponse(403, "You don't have permission to delete this message");
                return;
            }
            
            // Soft delete the message
            $stmt = $this->conn->prepare("
                UPDATE chat_messages 
                SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$messageId]);
            
            $this->sendJsonResponse(200, "Message deleted successfully");
            
        } catch (Exception $e) {
            error_log("Error deleting message: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to delete message: " . $e->getMessage());
        }
    }
    
    /**
     * Update typing indicator
     */
    public function updateTyping($groupId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Check if user has access to the group
            if (!$this->validateGroupAccess($groupId, $userId, 'user')) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $isTyping = $input['is_typing'] ?? false;
            
            $typingId = $this->utils->generateUUID();
            $expiresAt = date('Y-m-d H:i:s', time() + 30); // Expires in 30 seconds
            
            if ($isTyping) {
                // Remove existing typing indicator for this user
                $deleteStmt = $this->conn->prepare("DELETE FROM typing_indicators WHERE group_id = ? AND user_id = ?");
                $deleteStmt->execute([$groupId, $userId]);
                
                // Add new typing indicator
                $stmt = $this->conn->prepare("
                    INSERT INTO typing_indicators (id, group_id, user_id, is_typing, expires_at)
                    VALUES (?, ?, ?, 1, ?)
                ");
                $stmt->execute([$typingId, $groupId, $userId, $expiresAt]);
            } else {
                // Remove typing indicator
                $stmt = $this->conn->prepare("DELETE FROM typing_indicators WHERE group_id = ? AND user_id = ?");
                $stmt->execute([$groupId, $userId]);
            }
            
            $this->sendJsonResponse(200, "Typing indicator updated successfully");
            
        } catch (Exception $e) {
            error_log("Error updating typing indicator: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update typing indicator: " . $e->getMessage());
        }
    }
    
    /**
     * Get typing indicators for a group
     */
    public function getTyping($groupId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Check if user has access to the group
            if (!$this->validateGroupAccess($groupId, $userId, 'user')) {
                $this->sendJsonResponse(403, "Access denied to this chat group");
                return;
            }
            
            $query = "
                SELECT 
                    ti.user_id,
                    u.username as user_name
                FROM typing_indicators ti
                JOIN users u ON ti.user_id = u.id
                WHERE ti.group_id = ? AND ti.is_typing = 1 AND ti.expires_at > NOW()
                AND ti.user_id != ?
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$groupId, $userId]);
            $typingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendJsonResponse(200, "Typing indicators retrieved successfully", $typingUsers);
            
        } catch (Exception $e) {
            error_log("Error fetching typing indicators: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve typing indicators: " . $e->getMessage());
        }
    }
    
    /**
     * Helper methods
     */
    private function validateGroupAccess($groupId, $userId, $userRole) {
        if ($userRole === 'admin') {
            return true;
        }
        
        $query = "
            SELECT 1 FROM chat_group_members 
            WHERE group_id = ? AND user_id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$groupId, $userId]);
        return (bool) $stmt->fetch();
    }
    
    private function validateReplyMessage($replyMessageId, $groupId) {
        $query = "
            SELECT 1 FROM chat_messages 
            WHERE id = ? AND group_id = ? AND is_deleted = 0
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$replyMessageId, $groupId]);
        return (bool) $stmt->fetch();
    }
    
    private function getMessageWithDetails($messageId) {
        $query = "
            SELECT 
                cm.*,
                u.username as sender_name,
                u.email as sender_email,
                u.role as sender_role,
                rm.content as reply_content,
                rm.message_type as reply_type,
                ru.username as reply_sender_name
            FROM chat_messages cm
            JOIN users u ON cm.sender_id = u.id
            LEFT JOIN chat_messages rm ON cm.reply_to_message_id = rm.id
            LEFT JOIN users ru ON rm.sender_id = ru.id
            WHERE cm.id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$messageId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function markMessagesAsRead($groupId, $userId, $messages) {
        if (empty($messages)) {
            return;
        }
        
        $messageIds = array_column($messages, 'id');
        $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
        
        // Insert read status for messages that haven't been read yet
        $query = "
            INSERT IGNORE INTO message_read_status (message_id, user_id)
            SELECT id, ? FROM chat_messages 
            WHERE id IN ($placeholders) AND sender_id != ?
        ";
        
        $params = array_merge([$userId], $messageIds, [$userId]);
        $stmt = $this->conn->prepare($query);
        $stmt->execute($params);
        
        // Update last read timestamp for the group
        $updateStmt = $this->conn->prepare("
            UPDATE chat_group_members 
            SET last_read_at = CURRENT_TIMESTAMP
            WHERE group_id = ? AND user_id = ?
        ");
        $updateStmt->execute([$groupId, $userId]);
    }
} 