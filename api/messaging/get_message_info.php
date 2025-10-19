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
            
            // Build user status map
            $userStatuses = [];
            foreach ($deliveryInfo as $delivery) {
                $userStatuses[$delivery['id']] = [
                    'user_id' => $delivery['id'],
                    'user_name' => $delivery['username'],
                    'email' => $delivery['email'],
                    'status' => 'delivered',
                    'delivered_at' => $delivery['delivered_at'],
                    'read_at' => null
                ];
            }
            
            // Update status for users who read the message
            foreach ($readReceipts as $read) {
                if (isset($userStatuses[$read['user_id']])) {
                    $userStatuses[$read['user_id']]['status'] = 'read';
                    $userStatuses[$read['user_id']]['read_at'] = $read['read_at'];
                }
            }
            
            // Separate users by status
            $read = [];
            $delivered = [];
            $pending = [];
            
            foreach ($userStatuses as $user) {
                if ($user['status'] === 'read') {
                    $read[] = [
                        'user_id' => $user['user_id'],
                        'user_name' => $user['user_name'],
                        'timestamp' => $user['read_at']
                    ];
                } else {
                    $delivered[] = [
                        'user_id' => $user['user_id'],
                        'user_name' => $user['user_name'],
                        'timestamp' => $user['delivered_at']
                    ];
                }
            }
            
            // Get all group members to find pending users
            $allMembersStmt = $this->conn->prepare("
                SELECT u.id, u.username
                FROM chat_group_members cgm
                JOIN users u ON cgm.user_id = u.id
                WHERE cgm.group_id = ? 
                    AND cgm.user_id != ?
                    AND cgm.left_at IS NULL
            ");
            $allMembersStmt->execute([$groupId, $message['sender_id']]);
            $allMembers = $allMembersStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Find pending users (those not in delivered or read)
            $deliveredUserIds = array_column($userStatuses, 'user_id');
            foreach ($allMembers as $member) {
                if (!in_array($member['id'], $deliveredUserIds)) {
                    $pending[] = [
                        'user_id' => $member['id'],
                        'user_name' => $member['username']
                    ];
                }
            }
            
            $result = [
                'read' => $read,
                'delivered' => $delivered,
                'pending' => $pending
            ];
            
            $this->sendJsonResponse(200, "Message info retrieved successfully", $result);
            
        } catch (Exception $e) {
            error_log("Error getting message info: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to get message info: " . $e->getMessage());
        }
    }
    
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
}

$api = new GetMessageInfoAPI();
$api->handle();

