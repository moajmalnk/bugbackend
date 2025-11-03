<?php
/**
 * Mark notification(s) as read
 * POST /api/notifications/mark_read.php
 * Body: { "notification_id": 123 } or { "notification_ids": [123, 456] }
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
    $data = $api->getRequestData();
    
    if (!$data) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid request data']);
        exit();
    }
    
    $notificationManager = NotificationManager::getInstance();
    $successCount = 0;
    
    // Handle single notification_id or array of notification_ids
    if (isset($data['notification_id'])) {
        // Single notification
        $notificationId = (int)$data['notification_id'];
        if ($notificationManager->markAsRead($userId, $notificationId)) {
            $successCount = 1;
        }
    } elseif (isset($data['notification_ids']) && is_array($data['notification_ids'])) {
        // Multiple notifications
        foreach ($data['notification_ids'] as $notificationId) {
            if ($notificationManager->markAsRead($userId, (int)$notificationId)) {
                $successCount++;
            }
        }
    } else {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'notification_id or notification_ids is required']);
        exit();
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Marked {$successCount} notification(s) as read",
        'data' => ['marked_count' => $successCount]
    ]);
    
} catch (Exception $e) {
    error_log("Error in mark_read.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}

