<?php
/**
 * Test endpoint to manually create a notification
 * POST /api/notifications/test_create.php
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
    $notificationManager = NotificationManager::getInstance();
    
    // Create a test notification for the current user
    $result = $notificationManager->createNotification(
        'info',
        'Test Notification',
        'This is a test notification to verify the system is working',
        [$userId], // Notify current user
        [
            'entity_type' => 'test',
            'created_by' => 'System'
        ]
    );
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Test notification created successfully',
            'data' => ['notification_id' => $result]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create test notification'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error in test_create.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
}

