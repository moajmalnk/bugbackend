<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/MessageReceiptHelper.php';

class MarkReadAPI extends BaseAPI {
    use MessageReceiptHelper;

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

            $messageStmt = $this->conn->prepare("
                SELECT cm.id, cm.group_id, cm.sender_id, cm.message_type
                FROM chat_messages cm
                WHERE cm.id = ? AND cm.is_deleted = 0
            ");
            $messageStmt->execute([$messageId]);
            $message = $messageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$message) {
                $this->sendJsonResponse(404, "Message not found");
                return;
            }

            if ($message['sender_id'] === $userId) {
                $this->sendJsonResponse(200, "Cannot mark own message as read");
                return;
            }

            $role = $decoded->role;
            if (!$this->userCanAccessChatGroup($message['group_id'], $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied");
                return;
            }

            if (($message['message_type'] ?? '') === 'voice') {
                $this->sendJsonResponse(400, "Voice messages are marked as played when listened to");
                return;
            }

            $this->recordDeliveryStatus($messageId, $userId, 'delivered');
            $this->recordMessageRead($messageId, $userId);
            $this->recordDeliveryStatus($messageId, $userId, 'read');

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
