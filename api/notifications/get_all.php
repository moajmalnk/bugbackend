<?php
/**
 * Get all notifications for the current user
 * GET /api/notifications/get_all.php?limit=50&offset=0
 */

// Handle CORS first - this will handle OPTIONS requests automatically
// MUST be called before any other includes or code
require_once __DIR__ . '/../../config/cors.php';

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../NotificationManager.php';

try {
    $api = new BaseAPI();
    
    // Validate authentication
    $userData = $api->validateToken();
    if (!$userData || !isset($userData->user_id)) {
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
    
    // Get notifications
    $notificationManager = NotificationManager::getInstance();
    $notifications = $notificationManager->getUserNotifications($userId, $limit, $offset);
    
    // Debug logging
    error_log("get_all.php - UserId: $userId, Notifications returned: " . count($notifications));
    if (empty($notifications)) {
        // Check if there are any notifications in the database at all
        $conn = $api->getConnection();
        $totalNotifications = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch(PDO::FETCH_ASSOC)['count'];
        $userNotificationsCount = $conn->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ?");
        $userNotificationsCount->execute([$userId]);
        $userNotificationCount = $userNotificationsCount->fetch(PDO::FETCH_ASSOC)['count'];
        error_log("get_all.php - DEBUG: Total notifications in DB: $totalNotifications, User notifications: $userNotificationCount");
    }
    
    // Format notifications for frontend
    $formattedNotifications = array_map(function($notification) {
        return [
            'id' => (int)$notification['id'],
            'type' => $notification['type'],
            'title' => $notification['title'],
            'message' => $notification['message'],
            'entity_type' => $notification['entity_type'],
            'entity_id' => $notification['entity_id'],
            'project_id' => $notification['project_id'],
            'bug_id' => $notification['bug_id'],
            'bug_title' => $notification['bug_title'],
            'status' => $notification['status'],
            'created_by' => $notification['created_by'],
            'createdAt' => $notification['created_at'],
            'read' => (bool)$notification['read'],
            'read_at' => $notification['read_at']
        ];
    }, $notifications);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'notifications' => $formattedNotifications,
            'count' => count($formattedNotifications),
            'limit' => $limit,
            'offset' => $offset
        ],
        'debug' => [
            'user_id' => $userId,
            'raw_count' => count($notifications)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_all.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}

