<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/../NotificationManager.php';
require_once __DIR__ . '/../../utils/send_email.php';
require_once __DIR__ . '/../../services/FirebaseMessagingService.php';

class AnnouncementController extends BaseAPI {

    public function __construct() {
        parent::__construct();
    }

    public function getLatestActive() {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $decoded = $this->validateToken(); // All authenticated users can see announcements
            $userRole = $decoded->role ?? 'user';
            
            // Determine user's role for filtering
            $roleMap = ['admin' => 'admins', 'developer' => 'developers', 'tester' => 'testers'];
            $userRoleFilter = $roleMap[$userRole] ?? null;

            // Build role filter condition
            // Announcements are visible if:
            // 1. role is NULL or 'all' (legacy support)
            // 2. role exactly matches user's role (e.g., 'admins')
            // 3. role contains user's role in comma-separated list (e.g., 'admins,developers')
            $roleCondition = "AND (role IS NULL OR role = '' OR role = 'all'";
            $params = [];
            
            if ($userRoleFilter) {
                // Check for exact match
                $roleCondition .= " OR role = ?";
                $params[] = $userRoleFilter;
                
                // Check for comma-separated values (role starts with userRole, role ends with userRole, or role contains ,userRole,)
                $roleCondition .= " OR role LIKE ? OR role LIKE ? OR role LIKE ?";
                $params[] = "{$userRoleFilter},%";  // Starts with role
                $params[] = "%,{$userRoleFilter}";  // Ends with role
                $params[] = "%,{$userRoleFilter},%"; // Contains role in middle
            }
            
            $roleCondition .= ")";

            $query = "SELECT id, title, content, is_active, expiry_date, role, created_at, last_broadcast_at FROM announcements 
                      WHERE is_active = 1 
                      AND (expiry_date IS NULL OR expiry_date > NOW())
                      {$roleCondition}
                      ORDER BY created_at DESC 
                      LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$announcement) {
                return $this->sendJsonResponse(200, "No active announcements.", null);
            }

            $this->sendJsonResponse(200, "Latest announcement retrieved successfully", $announcement);

        } catch (Exception $e) {
            error_log("Error fetching latest announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getAll() {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized to perform this action.");
            }

            $query = "SELECT id, title, content, is_active, expiry_date, role, created_at, updated_at, last_broadcast_at FROM announcements ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->sendJsonResponse(200, "Announcements retrieved successfully", $announcements);

        } catch (Exception $e) {
            error_log("Error fetching announcements: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function create() {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized to perform this action.");
            }

            $data = $this->getRequestData();

            if (!isset($data['title']) || !isset($data['content'])) {
                return $this->sendJsonResponse(400, "Title and content are required");
            }

            $query = "INSERT INTO announcements (title, content, is_active, expiry_date, role) VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);

            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 0;
            $expiryDate = isset($data['expiry_date']) ? $data['expiry_date'] : null;
            $role = isset($data['role']) ? $data['role'] : 'all';
            $recipientUserIds = isset($data['recipient_user_ids']) && is_array($data['recipient_user_ids'])
                ? array_values(array_unique(array_filter(array_map('strval', $data['recipient_user_ids']))))
                : [];

            $stmt->execute([
                $data['title'],
                $data['content'],
                $isActive,
                $expiryDate,
                $role
            ]);
            
            $lastInsertId = $this->conn->lastInsertId();
            $announcement = [
                'id' => $lastInsertId,
                'title' => $data['title'],
                'content' => $data['content'],
                'is_active' => $isActive,
                'expiry_date' => $expiryDate,
                'role' => $role,
            ];

            // Log announcement creation activity
            try {
                $logger = ActivityLogger::getInstance();
                $logger->logAnnouncementCreated(
                    $decoded->user_id,
                    null, // No specific project for announcements
                    $lastInsertId,
                    $data['title'],
                    [
                        'is_active' => $isActive,
                        'has_expiry' => !empty($expiryDate)
                    ]
                );
            } catch (Exception $e) {
                error_log("Failed to log announcement creation activity: " . $e->getMessage());
            }

            try {
                $this->notifyAnnouncementRecipients(
                    (string)$data['title'],
                    (string)$data['content'],
                    $role,
                    $recipientUserIds,
                    (string)$decoded->user_id
                );
            } catch (Exception $e) {
                error_log("Failed to notify announcement recipients: " . $e->getMessage());
            }

            $this->sendJsonResponse(201, "Announcement created successfully", $announcement);

        } catch (Exception $e) {
            error_log("Error creating announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    
    public function update($id) {
        if (!isset($_SERVER['REQUEST_METHOD']) || ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST')) { // Allow POST for updates
            return $this->sendJsonResponse(405, "Method not allowed");
        }
    
        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized.");
            }
    
            $data = $this->getRequestData();
    
            $updateFields = [];
            $params = [];
    
            if (isset($data['title'])) {
                $updateFields[] = "title = ?";
                $params[] = $data['title'];
            }
            if (isset($data['content'])) {
                $updateFields[] = "content = ?";
                $params[] = $data['content'];
            }
            if (isset($data['is_active'])) {
                $updateFields[] = "is_active = ?";
                $params[] = (int)$data['is_active'];
            }
            if (array_key_exists('expiry_date', $data)) { // Allow setting expiry_date to null
                $updateFields[] = "expiry_date = ?";
                $params[] = $data['expiry_date'];
            }
            if (isset($data['role'])) {
                $updateFields[] = "role = ?";
                $params[] = $data['role'];
            }
    
            if (empty($updateFields)) {
                return $this->sendJsonResponse(400, "No fields to update.");
            }
    
            $query = "UPDATE announcements SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $params[] = $id;
    
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
    
            if ($stmt->rowCount() === 0) {
                return $this->sendJsonResponse(404, "Announcement not found.");
            }

            // Fetch the announcement to get title/content
            $stmt = $this->conn->prepare("SELECT title, content FROM announcements WHERE id = ?");
            $stmt->execute([$id]);
            $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$announcement) {
                return $this->sendJsonResponse(404, "Announcement not found after update.");
            }

            // After successful database update, trigger the FCM message
            $this->triggerFCMBroadcast($announcement['title'], $announcement['content']);

            $this->sendJsonResponse(200, "Announcement broadcast scheduled successfully.");

        } catch (Exception $e) {
            error_log("Error updating announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    

    public function delete($id) {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized.");
            }

            $query = "DELETE FROM announcements WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                return $this->sendJsonResponse(404, "Announcement not found.");
            }

            $this->sendJsonResponse(200, "Announcement deleted successfully.");

        } catch (Exception $e) {
            error_log("Error deleting announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function broadcast($id) {
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized.");
            }

            $query = "UPDATE announcements SET last_broadcast_at = NOW() WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                return $this->sendJsonResponse(404, "Announcement not found.");
            }

            // Fetch the announcement to get title/content
            $stmt = $this->conn->prepare("SELECT title, content FROM announcements WHERE id = ?");
            $stmt->execute([$id]);
            $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$announcement) {
                return $this->sendJsonResponse(404, "Announcement not found after update.");
            }

            // After successful database update, trigger the FCM message
            $this->triggerFCMBroadcast($announcement['title'], $announcement['content']);

            $this->sendJsonResponse(200, "Announcement broadcast scheduled successfully.");

        } catch (Exception $e) {
            error_log("Error broadcasting announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    private function triggerFCMBroadcast($title, $content) {
        $url = 'http://' . $_SERVER['SERVER_NAME'] . '/BugRicer/backend/api/send-fcm-message.php';

        $payload = json_encode([
            'title' => $title,
            'body' => $content,
            'data' => [
                'type' => 'announcement_broadcast',
                'click_action' => '/notifications',
                'unread_count' => '1',
            ]
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        // Set a timeout to prevent the main request from hanging
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        // Execute in a non-blocking way
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 200);


        curl_exec($ch);
        curl_close($ch);
    }

    private function notifyAnnouncementRecipients($title, $content, $role, array $recipientUserIds, $actorUserId) {
        $userIds = $this->resolveAnnouncementRecipientUserIds($role, $recipientUserIds);
        if (empty($userIds)) {
            error_log('Announcement notifications skipped: no active recipients.');
            return;
        }

        try {
            $notificationManager = NotificationManager::getInstance();
            $notificationManager->createNotification(
                'new_update',
                'New Announcement',
                $content,
                $userIds,
                [
                    'entity_type' => 'announcement',
                    'entity_id' => null,
                    'created_by' => (string)$actorUserId,
                ]
            );
        } catch (Exception $e) {
            error_log('Announcement in-app notification failed: ' . $e->getMessage());
        }

        try {
            $messaging = new FirebaseMessagingService($this->conn);
            $messaging->sendToUsers($userIds, 'New Announcement', $content, [
                'type' => 'announcement_broadcast',
                'entity_type' => 'announcement',
                'url' => '/notifications',
                'click_action' => '/notifications',
            ]);
        } catch (Exception $e) {
            error_log('Announcement push notification failed: ' . $e->getMessage());
        }

        $emails = $this->getRecipientEmailsByUserIds($userIds);
        if (!empty($emails)) {
            $body = $this->buildAnnouncementEmailBody($title, $content);
            sendBugNotification($emails, '📣 New Announcement: ' . $title, $body, []);
        }
    }

    private function resolveAnnouncementRecipientUserIds($role, array $recipientUserIds) {
        if (!empty($recipientUserIds)) {
            return $this->filterActiveUserIds($recipientUserIds);
        }

        $roles = [];
        $role = trim((string)$role);
        if ($role === '' || strtolower($role) === 'all') {
            return $this->getAllActiveUserIds();
        }

        foreach (explode(',', $role) as $roleValue) {
            $normalized = strtolower(trim($roleValue));
            if ($normalized === 'admins') {
                $roles[] = 'admin';
            } elseif ($normalized === 'developers') {
                $roles[] = 'developer';
            } elseif ($normalized === 'testers') {
                $roles[] = 'tester';
            }
        }

        if (empty($roles)) {
            return $this->getAllActiveUserIds();
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE account_active = 1 AND role IN ($placeholders)");
        $stmt->execute($roles);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_unique(array_map('strval', $ids)));
    }

    private function filterActiveUserIds(array $userIds) {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if (empty($userIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE account_active = 1 AND id IN ($placeholders)");
        $stmt->execute($userIds);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_unique(array_map('strval', $ids)));
    }

    private function getAllActiveUserIds() {
        $stmt = $this->conn->query("SELECT id FROM users WHERE account_active = 1");
        $ids = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        return array_values(array_unique(array_map('strval', $ids)));
    }

    private function getRecipientEmailsByUserIds(array $userIds) {
        if (empty($userIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT email FROM users WHERE account_active = 1 AND id IN ($placeholders) AND email IS NOT NULL AND TRIM(email) <> ''"
        );
        $stmt->execute($userIds);
        $emails = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $emails))));
    }

    private function buildAnnouncementEmailBody($title, $content) {
        $safeTitle = htmlspecialchars((string)$title);
        $safeContent = nl2br(htmlspecialchars((string)$content));
        return '<div style="font-family: Segoe UI, Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;">'
            . '<div style="max-width: 620px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">'
            . '<div style="background: linear-gradient(135deg, #4f46e5 0%, #9333ea 100%); color: #ffffff; padding: 20px; text-align: center;">'
            . '<h1 style="margin: 0; font-size: 22px;">New Announcement</h1>'
            . '<p style="margin: 8px 0 0 0; font-size: 14px; opacity: 0.95;">BugRicer Broadcast</p></div>'
            . '<div style="padding: 24px;">'
            . '<h2 style="margin: 0 0 14px 0; color: #1e293b; font-size: 18px;">' . $safeTitle . '</h2>'
            . '<div style="background: #f8fafc; border-radius: 6px; padding: 14px; font-size: 14px;">' . $safeContent . '</div>'
            . '<p style="margin-top: 18px; color: #64748b; font-size: 13px;">Open BugRicer to view complete announcement details.</p>'
            . '</div><div style="background: #f8fafc; color: #64748b; padding: 14px; text-align: center; font-size: 12px;">'
            . '&copy; ' . date('Y') . ' BugRicer. Automated notification.</div></div></div>';
    }
}