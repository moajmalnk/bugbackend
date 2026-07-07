<?php
require_once __DIR__ . '/../BaseAPI.php';

class MarkVoicePlayedAPI extends BaseAPI {

    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $role = $decoded->role ?? 'user';

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

            if ($message['message_type'] !== 'voice') {
                $this->sendJsonResponse(400, "Only voice messages can be marked as played");
                return;
            }

            if ($message['sender_id'] === $userId) {
                $this->sendJsonResponse(200, "Cannot mark own voice message as played");
                return;
            }

            if (!$this->userCanAccessChatGroup($message['group_id'], $userId, $role)) {
                $this->sendJsonResponse(403, "Access denied");
                return;
            }

            if ($this->dbTableExists('message_voice_played')) {
                $checkStmt = $this->conn->prepare("
                    SELECT message_id FROM message_voice_played
                    WHERE message_id = ? AND user_id = ?
                ");
                $checkStmt->execute([$messageId, $userId]);

                if (!$checkStmt->fetch()) {
                    $stmt = $this->conn->prepare("
                        INSERT INTO message_voice_played (message_id, user_id)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([$messageId, $userId]);
                }
            }

            $this->recordDeliveryStatus($messageId, $userId, 'delivered');
            $this->recordMessageRead($messageId, $userId);

            $updateMemberStmt = $this->conn->prepare("
                UPDATE chat_group_members
                SET last_read_at = CURRENT_TIMESTAMP
                WHERE group_id = ? AND user_id = ?
            ");
            $updateMemberStmt->execute([$message['group_id'], $userId]);

            $this->sendJsonResponse(200, "Voice message marked as played");
        } catch (Exception $e) {
            error_log("Error marking voice as played: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to mark voice as played: " . $e->getMessage());
        }
    }

    private function recordDeliveryStatus($messageId, $userId, $status) {
        if (!$this->dbTableExists('message_delivery_status')) {
            return;
        }

        $checkStmt = $this->conn->prepare("
            SELECT id FROM message_delivery_status
            WHERE message_id = ? AND user_id = ?
        ");
        $checkStmt->execute([$messageId, $userId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($status === 'read') {
                $stmt = $this->conn->prepare("
                    UPDATE message_delivery_status
                    SET status = 'read', timestamp = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$existing['id']]);
            }
            return;
        }

        $deliveryId = $this->utils->generateUUID();
        $stmt = $this->conn->prepare("
            INSERT INTO message_delivery_status (id, message_id, user_id, status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$deliveryId, $messageId, $userId, $status === 'read' ? 'read' : 'delivered']);
    }

    private function recordMessageRead($messageId, $userId) {
        if (!$this->dbTableExists('message_read_status')) {
            return;
        }

        $checkStmt = $this->conn->prepare("
            SELECT message_id FROM message_read_status
            WHERE message_id = ? AND user_id = ?
        ");
        $checkStmt->execute([$messageId, $userId]);

        if ($checkStmt->fetch()) {
            return;
        }

        if ($this->dbColumnExists('message_read_status', 'id')) {
            $readId = $this->utils->generateUUID();
            $stmt = $this->conn->prepare("
                INSERT INTO message_read_status (id, message_id, user_id)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$readId, $messageId, $userId]);
            return;
        }

        $stmt = $this->conn->prepare("
            INSERT INTO message_read_status (message_id, user_id)
            VALUES (?, ?)
        ");
        $stmt->execute([$messageId, $userId]);
    }
}

$api = new MarkVoicePlayedAPI();
$api->handle();
