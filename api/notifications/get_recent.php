<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/notifications_error.log');

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
            error_log("=== Starting getRecentNotifications ===");
            
            // Validate authentication
            error_log("Attempting to validate token...");
            $userData = $this->validateToken();
            error_log("Token validation result: " . json_encode($userData));
            
            if (!$userData) {
                error_log("Token validation failed");
                $this->sendJsonResponse(401, 'Invalid token');
                return;
            }
            
            // Get request body
            error_log("Getting request data...");
            $data = $this->getRequestData();
            error_log("Request data: " . json_encode($data));
            
            if (!$data || !isset($data['since'])) {
                error_log("Missing since parameter");
                $this->sendJsonResponse(400, 'Missing since parameter');
                return;
            }
            
            $since = $data['since'];
            error_log("Processing since date: " . $since);
            
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
                    error_log("Successfully parsed date with format: " . $format);
                    break;
                }
            }
            
            if (!$sinceDateTime) {
                error_log("All date format attempts failed for: " . $since);
                $this->sendJsonResponse(400, 'Invalid date format. Please use ISO 8601 format like: 2025-01-01T00:00:00Z');
                return;
            }
            
            // Check database connection
            if (!$this->conn) {
                error_log("Database connection is null");
                $this->sendJsonResponse(500, 'Database connection failed');
                return;
            }
            
            // Check if notifications table exists
            error_log("Checking if notifications table exists...");
            $tableExistsSQL = "SHOW TABLES LIKE 'notifications'";
            $result = $this->conn->query($tableExistsSQL);
            
            if ($result->rowCount() == 0) {
                error_log("Notifications table does not exist");
                // Table doesn't exist yet, return empty notifications
                $this->sendJsonResponse(200, 'No notifications found', [
                    'notifications' => [],
                    'count' => 0
                ]);
                return;
            }
            
            error_log("Notifications table exists, querying...");
            
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
            if (!$stmt) {
                error_log("Failed to prepare SQL statement");
                $this->sendJsonResponse(500, 'Database query preparation failed');
                return;
            }
            
            $username = $userData->username ?? $userData->name ?? 'Unknown';
            error_log("Executing query with date: " . $sinceDateTime->format('Y-m-d H:i:s') . " and username: " . $username);
            
            $success = $stmt->execute([
                $sinceDateTime->format('Y-m-d H:i:s'),
                $username
            ]);
            
            if (!$success) {
                error_log("Failed to execute SQL statement: " . json_encode($stmt->errorInfo()));
                $this->sendJsonResponse(500, 'Database query execution failed');
                return;
            }
            
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            error_log("Found " . count($notifications) . " notifications");
            
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
            
            error_log("Sending successful response with " . count($formattedNotifications) . " notifications");
            
            $this->sendJsonResponse(200, 'Notifications retrieved successfully', [
                'notifications' => $formattedNotifications,
                'count' => count($formattedNotifications),
                'since' => $since
            ]);
            
        } catch (Exception $e) {
            error_log('EXCEPTION in getRecentNotifications: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }
}

// Create instance and handle request
try {
    error_log("Creating NotificationAPI instance...");
    $api = new NotificationAPI();
    error_log("Calling getRecentNotifications...");
    $api->getRecentNotifications();
} catch (Exception $e) {
    error_log("Fatal error creating API: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Fatal error: ' . $e->getMessage()
    ]);
}