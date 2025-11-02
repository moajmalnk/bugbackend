<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

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
            
            // Try multiple date formats to be more flexible
            $dateFormats = [
                DateTime::ATOM,                    // 2025-01-01T00:00:00+00:00
                'Y-m-d\TH:i:s.v\Z',               // 2025-01-01T00:00:00.000Z (with milliseconds)
                'Y-m-d\TH:i:s\Z',                 // 2025-01-01T00:00:00Z (without milliseconds)
                'Y-m-d\TH:i:sP',                  // 2025-01-01T00:00:00+00:00
                'Y-m-d H:i:s',                    // 2025-01-01 00:00:00
                'Y-m-d',                          // 2025-01-01
            ];
            
            $sinceDateTime = null;
            foreach ($dateFormats as $format) {
                $sinceDateTime = DateTime::createFromFormat($format, $since);
                if ($sinceDateTime !== false) {
                    break;
                }
            }
            
            if (!$sinceDateTime) {
                $this->sendJsonResponse(400, 'Invalid date format. Please use ISO 8601 format like: 2025-01-01T00:00:00Z');
                return;
            }
            
            // Check database connection
            if (!$this->conn) {
                $this->sendJsonResponse(500, 'Database connection failed');
                return;
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
            // Temporarily allow self-notifications for testing
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
                ORDER BY created_at DESC 
                LIMIT 50
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $this->sendJsonResponse(500, 'Database query preparation failed');
                return;
            }
            
            $username = $userData->username ?? $userData->user_id ?? 'Unknown';
            
            $success = $stmt->execute([
                $sinceDateTime->format('Y-m-d H:i:s')
                // Removed username filter temporarily for testing
            ]);
            
            if (!$success) {
                $this->sendJsonResponse(500, 'Database query execution failed');
                return;
            }
            
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