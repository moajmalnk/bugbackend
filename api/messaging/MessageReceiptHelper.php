<?php

trait MessageReceiptHelper {

    protected function recordDeliveryStatus($messageId, $userId, $status = 'delivered') {
        if (!$this->dbTableExists('message_delivery_status')) {
            return;
        }

        $checkStmt = $this->conn->prepare("
            SELECT id, status FROM message_delivery_status
            WHERE message_id = ? AND user_id = ?
        ");
        $checkStmt->execute([$messageId, $userId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($status === 'read' && $existing['status'] !== 'read') {
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
        $stmt->execute([
            $deliveryId,
            $messageId,
            $userId,
            $status === 'read' ? 'read' : 'delivered',
        ]);
    }

    protected function recordMessageRead($messageId, $userId) {
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

    protected function getGroupRecipientCount($groupId, $excludeUserId) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) AS total
            FROM chat_group_members
            WHERE group_id = ? AND user_id != ?
        ");
        $stmt->execute([$groupId, $excludeUserId]);
        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    }

    protected function enrichMessagesWithReceipts(array $messages, $groupId, $userId) {
        if (empty($messages)) {
            return $messages;
        }

        $ownMessageIds = [];
        foreach ($messages as $message) {
            if (($message['sender_id'] ?? null) === $userId) {
                $ownMessageIds[] = $message['id'];
            }
        }

        if (empty($ownMessageIds)) {
            return $messages;
        }

        $recipientCount = $this->getGroupRecipientCount($groupId, $userId);
        $placeholders = implode(',', array_fill(0, count($ownMessageIds), '?'));

        $deliveryCounts = [];
        if ($this->dbTableExists('message_delivery_status')) {
            $deliveryStmt = $this->conn->prepare("
                SELECT message_id, COUNT(*) AS total
                FROM message_delivery_status
                WHERE message_id IN ($placeholders)
                GROUP BY message_id
            ");
            $deliveryStmt->execute($ownMessageIds);
            foreach ($deliveryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $deliveryCounts[$row['message_id']] = (int) $row['total'];
            }
        }

        $readCounts = [];
        if ($this->dbTableExists('message_read_status')) {
            $readStmt = $this->conn->prepare("
                SELECT message_id, COUNT(*) AS total
                FROM message_read_status
                WHERE message_id IN ($placeholders)
                GROUP BY message_id
            ");
            $readStmt->execute($ownMessageIds);
            foreach ($readStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $readCounts[$row['message_id']] = (int) $row['total'];
            }
        }

        $playedCounts = [];
        if ($this->dbTableExists('message_voice_played')) {
            $playedStmt = $this->conn->prepare("
                SELECT message_id, COUNT(*) AS total
                FROM message_voice_played
                WHERE message_id IN ($placeholders)
                GROUP BY message_id
            ");
            $playedStmt->execute($ownMessageIds);
            foreach ($playedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $playedCounts[$row['message_id']] = (int) $row['total'];
            }
        }

        $readDetails = [];
        if ($this->dbTableExists('message_read_status')) {
            $detailStmt = $this->conn->prepare("
                SELECT mrs.message_id, mrs.user_id, mrs.read_at,
                       COALESCE(u.username, u.email, 'User') AS user_name
                FROM message_read_status mrs
                JOIN users u ON u.id = mrs.user_id
                WHERE mrs.message_id IN ($placeholders)
                ORDER BY mrs.read_at DESC
            ");
            $detailStmt->execute($ownMessageIds);
            foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $readDetails[$row['message_id']][] = [
                    'message_id' => $row['message_id'],
                    'user_id' => $row['user_id'],
                    'user_name' => $row['user_name'],
                    'read_at' => $row['read_at'],
                ];
            }
        }

        $playedDetails = [];
        if ($this->dbTableExists('message_voice_played')) {
            $playedDetailStmt = $this->conn->prepare("
                SELECT mvp.message_id, mvp.user_id, mvp.played_at,
                       COALESCE(u.username, u.email, 'User') AS user_name
                FROM message_voice_played mvp
                JOIN users u ON u.id = mvp.user_id
                WHERE mvp.message_id IN ($placeholders)
                ORDER BY mvp.played_at DESC
            ");
            $playedDetailStmt->execute($ownMessageIds);
            foreach ($playedDetailStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $playedDetails[$row['message_id']][] = [
                    'message_id' => $row['message_id'],
                    'user_id' => $row['user_id'],
                    'user_name' => $row['user_name'],
                    'read_at' => $row['played_at'],
                    'played_at' => $row['played_at'],
                ];
            }
        }

        foreach ($messages as &$message) {
            if (($message['sender_id'] ?? null) !== $userId) {
                continue;
            }

            $messageId = $message['id'];
            $isVoice = ($message['message_type'] ?? '') === 'voice';
            $deliveredCount = $deliveryCounts[$messageId] ?? 0;
            $ackCount = $isVoice
                ? ($playedCounts[$messageId] ?? 0)
                : ($readCounts[$messageId] ?? 0);

            if ($recipientCount === 0) {
                $message['delivery_status'] = 'sent';
            } elseif ($ackCount >= $recipientCount) {
                $message['delivery_status'] = 'read';
            } elseif ($deliveredCount > 0 || $ackCount > 0) {
                $message['delivery_status'] = 'delivered';
            } else {
                $message['delivery_status'] = 'sent';
            }

            $message['read_status'] = $isVoice
                ? ($playedDetails[$messageId] ?? [])
                : ($readDetails[$messageId] ?? []);

            if ($isVoice) {
                $message['voice_played_count'] = $playedCounts[$messageId] ?? 0;
            }
        }
        unset($message);

        return $messages;
    }

    protected function markIncomingMessagesReceipts($groupId, $userId, array $messages) {
        if (empty($messages)) {
            return;
        }

        foreach ($messages as $message) {
            if (($message['sender_id'] ?? null) === $userId) {
                continue;
            }

            $messageId = $message['id'];
            $messageType = $message['message_type'] ?? 'text';

            $this->recordDeliveryStatus($messageId, $userId, 'delivered');

            if ($messageType !== 'voice') {
                $this->recordMessageRead($messageId, $userId);
                $this->recordDeliveryStatus($messageId, $userId, 'read');
            }
        }

        $updateStmt = $this->conn->prepare("
            UPDATE chat_group_members
            SET last_read_at = CURRENT_TIMESTAMP
            WHERE group_id = ? AND user_id = ?
        ");
        $updateStmt->execute([$groupId, $userId]);
    }
}
