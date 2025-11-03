<?php
/**
 * Get all notifications for the current user
 * GET /api/notifications/get_all.php?limit=50&offset=0
 */

// Enable error reporting for debugging (disable in production if needed)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors, but log them
ini_set('log_errors', 1);

// Start output buffering to catch any accidental output
ob_start();

// Handle CORS first - this will handle OPTIONS requests automatically
// MUST be called before any other includes or code
require_once __DIR__ . '/../../config/cors.php';

// Ensure we have clean output for JSON
ob_clean();

try {
    // Include required files with error handling
    if (!file_exists(__DIR__ . '/../BaseAPI.php')) {
        throw new Exception("BaseAPI.php not found");
    }
    if (!file_exists(__DIR__ . '/../NotificationManager.php')) {
        throw new Exception("NotificationManager.php not found");
    }
    
    require_once __DIR__ . '/../BaseAPI.php';
    require_once __DIR__ . '/../NotificationManager.php';
    
    // Create BaseAPI instance - it may set headers, but CORS is already handled
    try {
        $api = new BaseAPI();
        
        // Check if database connection succeeded
        $conn = $api->getConnection();
        if (!$conn) {
            throw new Exception("Database connection is null");
        }
        
        // Verify user_notifications table exists
        try {
            $tableCheck = $conn->query("SHOW TABLES LIKE 'user_notifications'");
            if ($tableCheck->rowCount() === 0) {
                error_log("get_all.php - WARNING: user_notifications table does not exist");
                // Don't throw - just log it, we'll handle empty results
            }
        } catch (Exception $tableEx) {
            error_log("get_all.php - Error checking user_notifications table: " . $tableEx->getMessage());
        }
        
    } catch (Exception $e) {
        error_log("get_all.php - Error creating BaseAPI: " . $e->getMessage());
        throw $e; // Re-throw to be caught by outer catch
    } catch (Error $e) {
        error_log("get_all.php - Fatal error creating BaseAPI: " . $e->getMessage());
        throw $e; // Re-throw to be caught by outer catch
    }
    
    // Ensure we still have clean output after BaseAPI constructor
    ob_clean();
    
    // Validate authentication (handle token errors gracefully)
    try {
        $userData = $api->validateToken();
    } catch (Exception $e) {
        // In production this often causes 500 if unhandled; return 401 instead
        error_log("get_all.php - Token validation error: " . $e->getMessage());
        ob_clean();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized: invalid token']);
        exit();
    } catch (Error $e) {
        // Catch fatal errors too
        error_log("get_all.php - Fatal error in token validation: " . $e->getMessage());
        ob_clean();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    if (!$userData || !isset($userData->user_id)) {
        ob_clean();
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $userId = (string)$userData->user_id; // Ensure consistent type
    
    // Get pagination parameters
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    
    // Limit max results
    $limit = min(max(1, $limit), 100);
    $offset = max(0, $offset);
    
    // Get notifications - handle potential errors
    try {
        $notificationManager = NotificationManager::getInstance();
        if (!$notificationManager) {
            throw new Exception("Failed to get NotificationManager instance");
        }
        $notifications = $notificationManager->getUserNotifications($userId, $limit, $offset);
        if (!is_array($notifications)) {
            error_log("get_all.php - WARNING: getUserNotifications did not return an array");
            $notifications = [];
        }
    } catch (Exception $e) {
        error_log("get_all.php - Error getting notifications: " . $e->getMessage());
        $notifications = [];
    } catch (Error $e) {
        error_log("get_all.php - Fatal error getting notifications: " . $e->getMessage());
        $notifications = [];
    }
    
    // Debug logging
    error_log("get_all.php - UserId: $userId, Notifications returned: " . count($notifications));
    if (empty($notifications)) {
        // Check if there are any notifications in the database at all (optional debug)
        try {
            $conn = $api->getConnection();
            if ($conn) {
                $totalNotifications = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch(PDO::FETCH_ASSOC)['count'];
                $userNotificationsCount = $conn->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ?");
                $userNotificationsCount->execute([$userId]);
                $userNotificationCount = $userNotificationsCount->fetch(PDO::FETCH_ASSOC)['count'];
                error_log("get_all.php - DEBUG: Total notifications in DB: $totalNotifications, User notifications: $userNotificationCount");
            }
        } catch (Exception $debugEx) {
            // Don't fail if debug query fails
            error_log("get_all.php - Debug query failed: " . $debugEx->getMessage());
        }
    }
    
    // Format notifications for frontend - handle null/undefined values
    $formattedNotifications = array_map(function($notification) {
        return [
            'id' => isset($notification['id']) ? (int)$notification['id'] : 0,
            'type' => $notification['type'] ?? 'info',
            'title' => $notification['title'] ?? '',
            'message' => $notification['message'] ?? '',
            'entity_type' => $notification['entity_type'] ?? null,
            'entity_id' => $notification['entity_id'] ?? null,
            'project_id' => $notification['project_id'] ?? null,
            'bug_id' => $notification['bug_id'] ?? null,
            'bug_title' => $notification['bug_title'] ?? null,
            'status' => $notification['status'] ?? null,
            'created_by' => $notification['created_by'] ?? 'system',
            'createdAt' => $notification['created_at'] ?? date('Y-m-d H:i:s'),
            'read' => isset($notification['read']) ? (bool)$notification['read'] : false,
            'read_at' => $notification['read_at'] ?? null
        ];
    }, $notifications);
    
    // Clean any output buffer before sending JSON
    ob_clean();
    
    $debugEnabled = isset($_GET['debug']) && $_GET['debug'] === '1';
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => [
            'notifications' => $formattedNotifications,
            'count' => count($formattedNotifications),
            'limit' => $limit,
            'offset' => $offset
        ],
        // only include debug if explicitly requested
        'debug' => $debugEnabled ? [
            'user_id' => $userId,
            'raw_count' => count($notifications)
        ] : null
    ]);
    
} catch (Exception $e) {
    ob_clean();
    error_log("get_all.php - EXCEPTION: " . $e->getMessage());
    error_log("get_all.php - File: " . $e->getFile() . " Line: " . $e->getLine());
    if (method_exists($e, 'getTraceAsString')) {
        error_log("get_all.php - TRACE: " . $e->getTraceAsString());
    }
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => (isset($_GET['debug']) && $_GET['debug'] === '1') ? $e->getMessage() : null
    ]);
} catch (Error $e) {
    ob_clean();
    error_log("get_all.php - FATAL ERROR: " . $e->getMessage());
    error_log("get_all.php - File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("get_all.php - TRACE: " . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error' => (isset($_GET['debug']) && $_GET['debug'] === '1') ? $e->getMessage() : null
    ]);
}

