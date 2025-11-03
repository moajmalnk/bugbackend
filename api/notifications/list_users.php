<?php
/**
 * List all users for testing purposes
 * GET /api/notifications/list_users.php
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

try {
    $api = new BaseAPI();
    $conn = $api->getConnection();
    
    // Get all users
    $stmt = $conn->query("SELECT id, username, email, role FROM users ORDER BY username");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total notifications
    $notificationsCount = $conn->query("SELECT COUNT(*) as count FROM notifications")->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get total user_notifications
    $userNotificationsCount = $conn->query("SELECT COUNT(*) as count FROM user_notifications")->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'users' => $users,
            'total_users' => count($users),
            'total_notifications' => (int)$notificationsCount,
            'total_user_notifications' => (int)$userNotificationsCount,
            'sample_user_ids' => array_slice(array_column($users, 'id'), 0, 5)
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Error in list_users.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}

