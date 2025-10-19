<?php
require_once __DIR__ . '/../BaseAPI.php';

class GetMessageInfoAPI extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
            
            // Get message details
            $messageStmt = $this->conn->prepare("
                SELECT cm.*, u.username as sender_name, u.email as sender_email
                FROM chat_messages cm
                JOIN users u ON cm.sender_id = u.id
                WHERE cm.id = ? AND cm.is_deleted = 0
            ");
            $messageStmt->execute([$messageId]);
            $message = $messageStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$message) {
                $this->sendJsonResponse(404, "Message not found");
                return;
            }
            
            // Verify user has access to the message's group
            $groupId = $message['group_id'];
            $role = $decoded->role;
            
            if (!$this->validateGroupAccess($groupId, $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied");
                return;
            }
            
            // Get delivery status (users who received the message)
            $deliveryStmt = $this->conn->prepare("
                SELECT 
                    u.id, 
                    u.username, 
                    u.email,
                    'delivered' as status,
                    cm.created_at as delivered_at
                FROM chat_group_members cgm
                JOIN users u ON cgm.user_id = u.id
                JOIN chat_messages cm ON cm.group_id = cgm.group_id
                WHERE cm.id = ? 
                    AND cgm.user_id != ?
                    AND cgm.left_at IS NULL
                ORDER BY u.username ASC
            ");
            $deliveryStmt->execute([$messageId, $message['sender_id']]);
            $deliveryInfo = $deliveryStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get read receipts
            $readStmt = $this->conn->prepare("
                SELECT 
                    mrs.user_id,
                    u.username,
                    u.email,
                    mrs.read_at
                FROM message_read_status mrs
                JOIN users u ON mrs.user_id = u.id
                WHERE mrs.message_id = ?
                ORDER BY mrs.read_at DESC
            ");
            $readStmt->execute([$messageId]);
            $readReceipts = $readStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Merge delivery and read information
            $userStatuses = [];
            foreach ($deliveryInfo as $delivery) {
                $userStatuses[$delivery['id']] = [
                    'user_id' => $delivery['id'],
                    'username' => $delivery['username'],
                    'email' => $delivery['email'],
                    'status' => 'delivered',
                    'delivered_at' => $delivery['delivered_at'],
                    'read_at' => null
                ];
            }
            
            foreach ($readReceipts as $read) {
                if (isset($userStatuses[$read['user_id']])) {
                    $userStatuses[$read['user_id']]['status'] = 'read';
                    $userStatuses[$read['user_id']]['read_at'] = $read['read_at'];
                }
            }
            
            // Get group member count for statistics
            $memberStmt = $this->conn->prepare("
                SELECT COUNT(*) as total_members
                FROM chat_group_members
                WHERE group_id = ? AND left_at IS NULL AND user_id != ?
            ");
            $memberStmt->execute([$groupId, $message['sender_id']]);
            $memberCount = $memberStmt->fetch(PDO::FETCH_ASSOC)['total_members'];
            
            $readCount = count($readReceipts);
            $deliveredCount = count($deliveryInfo);
            
            $result = [
                'message_id' => $messageId,
                'sent_at' => $message['created_at'],
                'sender_name' => $message['sender_name'],
                'message_type' => $message['message_type'],
                'statistics' => [
                    'total_recipients' => (int)$memberCount,
                    'delivered_count' => (int)$deliveredCount,
                    'read_count' => (int)$readCount
                ],
                'recipients' => array_values($userStatuses)
            ];
            
            $this->sendJsonResponse(200, "Message info retrieved successfully", $result);
            
        } catch (Exception $e) {
            error_log("Error getting message info: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to get message info: " . $e->getMessage());
        }
    }
}

$api = new GetMessageInfoAPI();
$api->handle();

