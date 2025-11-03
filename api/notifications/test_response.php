<?php
/**
 * Test endpoint to check what's in the database and what the API returns
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../NotificationManager.php';

header('Content-Type: application/json');

try {
    $api = new BaseAPI();
    
    // Validate authentication
    $userData = $api->validateToken();
    if (!$userData || !isset($userData->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $userId = $userData->user_id;
    $conn = $api->getConnection();
    
    // Check total notifications
    $totalNotifications = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Check user_notifications
    $userNotificationsCount = $conn->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ?");
    $userNotificationsCount->execute([$userId]);
    $userNotificationCount = $userNotificationsCount->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get recent notifications for this user
    $recentNotifications = $conn->prepare("
        SELECT n.*, un.read, un.read_at, un.user_id
        FROM notifications n
        JOIN user_notifications un ON n.id = un.notification_id
        WHERE un.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 10
    ");
    $recentNotifications->execute([$userId]);
    $recent = $recentNotifications->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all notifications (without user filter)
    $allNotifications = $conn->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    
    // Check project members for debugging
    $projectMembers = $conn->query("
        SELECT pm.project_id, pm.user_id, u.username, u.role
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Test NotificationManager
    $notificationManager = NotificationManager::getInstance();
    $managerNotifications = $notificationManager->getUserNotifications($userId, 10, 0);
    
    echo json_encode([
        'success' => true,
        'debug' => [
            'current_user_id' => $userId,
            'current_username' => $userData->username ?? 'unknown',
            'total_notifications_in_db' => (int)$totalNotifications,
            'user_notifications_count' => (int)$userNotificationCount,
            'recent_user_notifications' => $recent,
            'all_recent_notifications' => $allNotifications,
            'project_members_sample' => $projectMembers,
            'notification_manager_result' => $managerNotifications,
            'manager_result_count' => count($managerNotifications)
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Error in test_response.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

