<?php
/**
 * Mark all notifications as read for the current user
 * POST /api/notifications/mark_all_read.php
 */

// Handle CORS first - this will handle OPTIONS requests automatically
// MUST be called before any other includes or code
require_once __DIR__ . '/../../config/cors.php';

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../NotificationManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

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
    
    $userId = $userData->user_id;
    
    $notificationManager = NotificationManager::getInstance();
    $success = $notificationManager->markAllAsRead($userId);
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'All notifications marked as read'
        ]);
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Failed to mark all notifications as read'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error in mark_all_read.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}

