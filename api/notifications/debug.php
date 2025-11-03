<?php
/**
 * Debug endpoint to check notification system
 * Shows recent notifications and user_notifications
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

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
    
    $conn = $api->getConnection();
    
    // Get recent notifications
    $notificationsStmt = $conn->prepare("
        SELECT n.*, COUNT(un.id) as user_count
        FROM notifications n
        LEFT JOIN user_notifications un ON n.id = un.notification_id
        GROUP BY n.id
        ORDER BY n.created_at DESC
        LIMIT 10
    ");
    $notificationsStmt->execute();
    $notifications = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user notifications for current user
    $userNotificationsStmt = $conn->prepare("
        SELECT un.*, n.type, n.title, n.message
        FROM user_notifications un
        JOIN notifications n ON un.notification_id = n.id
        WHERE un.user_id = ?
        ORDER BY un.created_at DESC
        LIMIT 10
    ");
    $userNotificationsStmt->execute([$userData->user_id]);
    $userNotifications = $userNotificationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get project members for debugging
    $projectMembersStmt = $conn->prepare("
        SELECT pm.project_id, pm.user_id, u.username, u.role
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        ORDER BY pm.project_id
        LIMIT 20
    ");
    $projectMembersStmt->execute();
    $projectMembers = $projectMembersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'current_user_id' => $userData->user_id,
            'recent_notifications' => $notifications,
            'user_notifications' => $userNotifications,
            'project_members_sample' => $projectMembers,
            'total_notifications' => count($notifications),
            'user_notification_count' => count($userNotifications)
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Error in debug.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}

