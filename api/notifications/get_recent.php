<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class NotificationAPI extends BaseAPI {
    public function getRecentNotifications() {
        try {
            // Validate authentication
            $userData = $this->validateToken();
            if (!$userData) {
                $this->sendJsonResponse(401, 'Invalid token');
                return;
            }
            
            // Get request body
            $data = $this->getRequestData();
            
            if (!$data || !isset($data['since'])) {
                $this->sendJsonResponse(400, 'Missing since parameter');
                return;
            }
            
            $since = $data['since'];
            
            // Validate date format
            $sinceDateTime = DateTime::createFromFormat(DateTime::ATOM, $since);
            if (!$sinceDateTime) {
                // Try alternative format
                $sinceDateTime = DateTime::createFromFormat('Y-m-d H:i:s', $since);
                if (!$sinceDateTime) {
                    $this->sendJsonResponse(400, 'Invalid date format');
                    return;
                }
            }
            
            // Check if notifications table exists
            $tableExistsSQL = "SHOW TABLES LIKE 'notifications'";
            $result = $this->conn->query($tableExistsSQL);
            
            if ($result->rowCount() == 0) {
                // Table doesn't exist yet, return empty notifications
                $this->sendJsonResponse(200, 'No notifications found', [
                    'notifications' => [],
                    'count' => 0
                ]);
                return;
            }
            
            // Get notifications since the specified time
            // Exclude notifications created by the current user to avoid self-notifications
            $sql = "
                SELECT 
                    id,
                    type,
                    title,
                    message,
                    bug_id as bugId,
                    bug_title as bugTitle,
                    status,
                    created_by as createdBy,
                    created_at as createdAt
                FROM notifications 
                WHERE created_at > ? 
                AND created_by != ?
                ORDER BY created_at DESC 
                LIMIT 50
            ";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                $sinceDateTime->format('Y-m-d H:i:s'),
                $userData['username'] ?? $userData['name'] ?? 'Unknown'
            ]);
            
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert to proper format
            $formattedNotifications = array_map(function($notification) {
                return [
                    'id' => $notification['id'],
                    'type' => $notification['type'],
                    'title' => $notification['title'],
                    'message' => $notification['message'],
                    'bugId' => $notification['bugId'],
                    'bugTitle' => $notification['bugTitle'],
                    'status' => $notification['status'],
                    'createdBy' => $notification['createdBy'],
                    'createdAt' => $notification['createdAt']
                ];
            }, $notifications);
            
            $this->sendJsonResponse(200, 'Notifications retrieved successfully', [
                'notifications' => $formattedNotifications,
                'count' => count($formattedNotifications),
                'since' => $since
            ]);
            
        } catch (Exception $e) {
            error_log('Error in getRecentNotifications: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }
}

// Create instance and handle request
$api = new NotificationAPI();
$api->getRecentNotifications(); 