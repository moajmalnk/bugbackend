<?php

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/../../config/utils.php';

class MeetingController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }

    private function generateMeetingCode($length = 10) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    public function createMeeting($requestUserId, $title) {
        $code = $this->generateMeetingCode(10);
        $stmt = $this->conn->prepare("INSERT INTO meetings (meeting_code, title, created_by) VALUES (?, ?, ?)");
        $ok = $stmt->execute([$code, $title, $requestUserId]);
        if (!$ok) {
            return $this->sendJsonResponse(500, 'Failed to create meeting');
        }
        $meetingId = $this->conn->lastInsertId();
        
        // Log meeting creation activity
        try {
            $logger = ActivityLogger::getInstance();
            $logger->logMeetingCreated(
                $requestUserId,
                null, // No specific project for meetings
                $meetingId,
                $title,
                [
                    'meeting_code' => $code,
                    'is_active' => true
                ]
            );
        } catch (Exception $e) {
            error_log("Failed to log meeting creation activity: " . $e->getMessage());
        }
        
        return [ 'success' => true, 'data' => ['id' => (int)$meetingId, 'code' => $code, 'title' => $title] ];
    }

    public function listMeetings($requestUserId) {
        $sql = "SELECT id, meeting_code AS code, title, created_by, is_active, created_at FROM meetings WHERE created_by = ? ORDER BY id DESC LIMIT 200";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$requestUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return [ 'success' => true, 'data' => ['meetings' => $rows] ];
    }

    public function getMeetingByCode($code) {
        $stmt = $this->conn->prepare("SELECT id, meeting_code AS code, title, created_by, is_active, created_at FROM meetings WHERE meeting_code = ? LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [ 'success' => true, 'data' => $row ];
        }
        return [ 'success' => false, 'message' => 'Meeting not found' ];
    }

    public function joinMeeting($meetingCode, $userId, $displayName) {
        $meeting = $this->getMeetingByCodeRaw($meetingCode);
        if (!$meeting) { return [ 'success' => false, 'message' => 'Meeting not found' ]; }
        $meetingId = $meeting['id'];
        $stmt = $this->conn->prepare("INSERT INTO meeting_participants (meeting_id, user_id, display_name, role, is_connected) VALUES (?, ?, ?, 'participant', 1)");
        $ok = $stmt->execute([$meetingId, $userId ?: null, $displayName]);
        if (!$ok) {
            return [ 'success' => false, 'message' => 'Failed to join' ];
        }
        return [ 'success' => true, 'data' => ['meeting_id' => (int)$meetingId] ];
    }

    public function leaveMeeting($meetingCode, $userId) {
        $meeting = $this->getMeetingByCodeRaw($meetingCode);
        if (!$meeting) { return [ 'success' => false, 'message' => 'Meeting not found' ]; }
        $meetingId = $meeting['id'];
        $stmt = $this->conn->prepare("UPDATE meeting_participants SET is_connected = 0, left_at = NOW() WHERE meeting_id = ? AND user_id = ?");
        $stmt->execute([$meetingId, $userId]);
        return [ 'success' => true, 'data' => ['left' => true] ];
    }

    public function sendMessage($meetingCode, $senderId, $senderName, $message) {
        $meeting = $this->getMeetingByCodeRaw($meetingCode);
        if (!$meeting) { return [ 'success' => false, 'message' => 'Meeting not found' ]; }
        $meetingId = $meeting['id'];
        $stmt = $this->conn->prepare("INSERT INTO meeting_messages (meeting_id, sender_id, sender_name, message) VALUES (?, ?, ?, ?)");
        $ok = $stmt->execute([$meetingId, $senderId ?: null, $senderName, $message]);
        if (!$ok) {
            return [ 'success' => false, 'message' => 'Failed to send' ];
        }
        return [ 'success' => true, 'data' => ['sent' => true] ];
    }

    public function getMessages($meetingCode, $limit = 100) {
        $meeting = $this->getMeetingByCodeRaw($meetingCode);
        if (!$meeting) { return [ 'success' => false, 'message' => 'Meeting not found' ]; }
        $meetingId = $meeting['id'];
        $limit = max(1, min(500, intval($limit)));
        $stmt = $this->conn->prepare("SELECT id, sender_id, sender_name, message, created_at FROM meeting_messages WHERE meeting_id = ? ORDER BY id DESC LIMIT $limit");
        $stmt->execute([$meetingId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = array_reverse($rows);
        return [ 'success' => true, 'data' => ['messages' => $rows] ];
    }

    private function getMeetingByCodeRaw($code) {
        $stmt = $this->conn->prepare("SELECT * FROM meetings WHERE meeting_code = ? LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

