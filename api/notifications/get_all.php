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
    
    // Enhanced debugging if no notifications returned
    if (empty($notifications)) {
        try {
            $conn = $api->getConnection();
            if ($conn) {
                // Check total notifications
                $totalNotifications = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch(PDO::FETCH_ASSOC)['count'];
                
                // Check user_notifications count with direct match
                $userNotificationsCount = $conn->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ?");
                $userNotificationsCount->execute([$userId]);
                $userNotificationCount = $userNotificationsCount->fetch(PDO::FETCH_ASSOC)['count'];
                
                // Check with CAST
                $userNotificationsCountCast = $conn->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE CAST(user_id AS CHAR) = CAST(? AS CHAR)");
                $userNotificationsCountCast->execute([$userId]);
                $userNotificationCountCast = $userNotificationsCountCast->fetch(PDO::FETCH_ASSOC)['count'];
                
                // Get sample user_ids from user_notifications
                $sampleUserIds = $conn->query("SELECT DISTINCT user_id FROM user_notifications LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
                
                // Try to get notifications with direct query
                $directQuery = $conn->prepare("
                    SELECT COUNT(*) as count 
                    FROM user_notifications un
                    JOIN notifications n ON un.notification_id = n.id
                    WHERE un.user_id = ?
                ");
                $directQuery->execute([$userId]);
                $directCount = $directQuery->fetch(PDO::FETCH_ASSOC)['count'];
                
                error_log("get_all.php - DEBUG DETAILS:");
                error_log("  - Total notifications in DB: $totalNotifications");
                error_log("  - User notifications (direct match): $userNotificationCount");
                error_log("  - User notifications (CAST match): $userNotificationCountCast");
                error_log("  - Direct JOIN query count: $directCount");
                error_log("  - Searching for user_id: $userId (type: " . gettype($userId) . ")");
                error_log("  - Sample user_ids in user_notifications: " . json_encode($sampleUserIds));
                
                // If we have notifications but JOIN fails, try to get them directly
                if ($userNotificationCount > 0 && $directCount == 0) {
                    error_log("get_all.php - WARNING: user_notifications exist but JOIN query returns 0. This indicates a JOIN issue.");
                    
                    // Try alternative query with LEFT JOIN (handles missing notifications)
                    $altQuery = $conn->prepare("
                        SELECT 
                            COALESCE(n.id, un.notification_id) as id,
                            COALESCE(n.type, 'info') as type,
                            COALESCE(n.title, 'Notification') as title,
                            COALESCE(n.message, '') as message,
                            n.entity_type,
                            n.entity_id,
                            n.project_id,
                            p.name as project_name,
                            n.bug_id,
                            n.bug_title,
                            n.status,
                            COALESCE(n.created_by, 'system') as created_by,
                            COALESCE(n.created_at, un.created_at) as created_at,
                            un.`read`,
                            un.read_at
                        FROM user_notifications un
                        LEFT JOIN notifications n ON un.notification_id = n.id
                        LEFT JOIN projects p ON n.project_id = p.id
                        WHERE un.user_id = ?
                        ORDER BY COALESCE(n.created_at, un.created_at) DESC
                        LIMIT ? OFFSET ?
                    ");
                    $altQuery->execute([$userId, $limit, $offset]);
                    $altResults = $altQuery->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($altResults)) {
                        error_log("get_all.php - SUCCESS: Alternative query returned " . count($altResults) . " notifications");
                        $notifications = $altResults;
                    } else {
                        // Last resort: Get just from user_notifications and try to match notifications
                        error_log("get_all.php - Trying last resort query...");
                        $lastResort = $conn->prepare("
                            SELECT 
                                un.notification_id as id,
                                'info' as type,
                                'Notification' as title,
                                '' as message,
                                NULL as entity_type,
                                NULL as entity_id,
                                NULL as project_id,
                                NULL as bug_id,
                                NULL as bug_title,
                                NULL as status,
                                'system' as created_by,
                                un.created_at,
                                un.`read`,
                                un.read_at
                            FROM user_notifications un
                            WHERE un.user_id = ?
                            ORDER BY un.created_at DESC
                            LIMIT ? OFFSET ?
                        ");
                        $lastResort->execute([$userId, $limit, $offset]);
                        $lastResortResults = $lastResort->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Now try to enrich with notification data
                        foreach ($lastResortResults as &$result) {
                            $notifId = $result['id'];
                            $notifQuery = $conn->prepare("SELECT * FROM notifications WHERE id = ?");
                            $notifQuery->execute([$notifId]);
                            $notifData = $notifQuery->fetch(PDO::FETCH_ASSOC);
                            if ($notifData) {
                                $result = array_merge($result, $notifData);
                                $result['id'] = (int)$notifId;
                            }
                        }
                        
                        if (!empty($lastResortResults)) {
                            error_log("get_all.php - Last resort query returned " . count($lastResortResults) . " notifications");
                            $notifications = $lastResortResults;
                        }
                    }
                }
            }
        } catch (Exception $debugEx) {
            error_log("get_all.php - Debug query failed: " . $debugEx->getMessage());
        }
    }
    
    // Format notifications for frontend - handle null/undefined values
    $formattedNotifications = [];
    
    foreach ($notifications as $notification) {
        // Ensure all required fields exist
        $formattedNotifications[] = [
            'id' => isset($notification['id']) ? (int)$notification['id'] : (isset($notification['notification_id']) ? (int)$notification['notification_id'] : 0),
            'type' => $notification['type'] ?? 'info',
            'title' => $notification['title'] ?? 'Notification',
            'message' => $notification['message'] ?? '',
            'entity_type' => $notification['entity_type'] ?? null,
            'entity_id' => $notification['entity_id'] ?? null,
            'project_id' => $notification['project_id'] ?? null,
            'project_name' => $notification['project_name'] ?? null,
            'bug_id' => $notification['bug_id'] ?? null,
            'bug_title' => $notification['bug_title'] ?? null,
            'status' => $notification['status'] ?? null,
            'created_by' => $notification['created_by'] ?? 'system',
            'createdAt' => $notification['created_at'] ?? ($notification['createdAt'] ?? date('Y-m-d H:i:s')),
            'created_at' => $notification['created_at'] ?? ($notification['createdAt'] ?? date('Y-m-d H:i:s')), // Include both for compatibility
            'read' => isset($notification['read']) ? (bool)(int)$notification['read'] : false,
            'read_at' => $notification['read_at'] ?? null
        ];
    }
    
    // Clean any output buffer before sending JSON
    ob_clean();
    
    $debugEnabled = isset($_GET['debug']) && $_GET['debug'] === '1';
    
    // Log what we're sending
    error_log("get_all.php - Sending response: " . count($formattedNotifications) . " notifications");
    if ($debugEnabled) {
        error_log("get_all.php - Sample notification: " . json_encode($formattedNotifications[0] ?? null));
    }
    
    header('Content-Type: application/json');
    $response = [
        'success' => true,
        'data' => [
            'notifications' => $formattedNotifications,
            'count' => count($formattedNotifications),
            'limit' => $limit,
            'offset' => $offset
        ]
    ];
    
    // only include debug if explicitly requested
    if ($debugEnabled) {
        $response['debug'] = [
            'user_id' => $userId,
            'raw_count' => count($notifications),
            'formatted_count' => count($formattedNotifications),
            'sample_notification' => $formattedNotifications[0] ?? null
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
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

