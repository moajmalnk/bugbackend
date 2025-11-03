<?php
/**
 * Get unread notification count for the current user
 * GET /api/notifications/unread_count.php
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
    
    $notificationManager = NotificationManager::getInstance();
    $unreadCount = $notificationManager->getUnreadCount($userId);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'unread_count' => $unreadCount
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error in unread_count.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}

