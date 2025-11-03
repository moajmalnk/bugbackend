<?php
/**
 * Direct test to create a notification without auth - for debugging
 * POST /api/notifications/create_test_notification.php
 * Body: { "user_id": "xxx", "project_id": "xxx" }
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../NotificationManager.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id is required']);
        exit();
    }
    
    $userId = (string)$data['user_id'];
    $projectId = $data['project_id'] ?? null;
    
    $notificationManager = NotificationManager::getInstance();
    
    // Verify connection exists
    try {
        $conn = $notificationManager->getConnection();
        if (!$conn) {
            throw new Exception("NotificationManager connection is null");
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to get database connection: ' . $e->getMessage()
        ]);
        exit();
    }
    
    // Create a test notification directly for this user
    // Use 'new_bug' type since it's definitely in the ENUM (fallback compatibility)
    error_log("create_test_notification.php - Attempting to create notification for userId: $userId");
    
    $result = $notificationManager->createNotification(
        'new_bug',  // Use 'new_bug' as it's definitely in the current ENUM
        'Test Notification',
        'This is a test notification to verify the system is working',
        [$userId],
        [
            'entity_type' => 'test',
            'project_id' => $projectId,
            'created_by' => 'System Test'
        ]
    );
    
    error_log("create_test_notification.php - Result from createNotification: " . ($result ? "Success (ID: $result)" : "Failed"));
    
    if ($result) {
        // Verify it was created - use Database singleton
        require_once __DIR__ . '/../../config/database.php';
        $database = Database::getInstance();
        $conn = $database->getConnection();
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM user_notifications 
            WHERE user_id = ? AND notification_id = ?
        ");
        $checkStmt->execute([$userId, $result]);
        $check = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Test notification created successfully',
            'data' => [
                'notification_id' => $result,
                'user_id' => $userId,
                'verified_in_db' => (int)$check['count'] > 0
            ]
        ]);
    } else {
        // Get last error from PHP error log or try to get more info
        $errorDetails = [];
        try {
            require_once __DIR__ . '/../../config/database.php';
            $database = Database::getInstance();
            $conn = $database->getConnection();
            
            // Check if notifications table exists
            $tableCheck = $conn->query("SHOW TABLES LIKE 'notifications'");
            $errorDetails['notifications_table_exists'] = $tableCheck->rowCount() > 0;
            
            // Check if user_notifications table exists
            $tableCheck2 = $conn->query("SHOW TABLES LIKE 'user_notifications'");
            $errorDetails['user_notifications_table_exists'] = $tableCheck2->rowCount() > 0;
            
            // Check if user exists
            $userCheck = $conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
            $userCheck->execute([$userId]);
            $user = $userCheck->fetch(PDO::FETCH_ASSOC);
            $errorDetails['user_exists'] = $user !== false;
            $errorDetails['user_data'] = $user;
            
            // Check notification type ENUM
            $typeCheck = $conn->query("SHOW COLUMNS FROM notifications WHERE Field = 'type'");
            $typeInfo = $typeCheck->fetch(PDO::FETCH_ASSOC);
            $errorDetails['type_enum'] = $typeInfo['Type'] ?? 'not found';
            
            // Try a direct SQL insert to see the actual error (use 'new_bug' if 'bug_created' not in enum)
            try {
                $conn->beginTransaction();
                // Use 'new_bug' if 'bug_created' is not in the enum
                $testType = (strpos($errorDetails['type_enum'], 'bug_created') !== false) ? 'bug_created' : 'new_bug';
                $testStmt = $conn->prepare("INSERT INTO notifications (type, title, message, created_by, created_at) VALUES (?, ?, ?, ?, NOW())");
                $testResult = $testStmt->execute([$testType, 'Direct Test', 'Direct insert test', 'System Test']);
                if ($testResult) {
                    $testId = $conn->lastInsertId();
                    // Try user_notification insert
                    $testUserStmt = $conn->prepare("INSERT INTO user_notifications (notification_id, user_id, `read`, created_at) VALUES (?, ?, 0, NOW())");
                    $testUserResult = $testUserStmt->execute([$testId, $userId]);
                    $errorDetails['direct_insert'] = [
                        'notification_insert' => $testResult,
                        'notification_id' => $testId,
                        'type_used' => $testType,
                        'user_notification_insert' => $testUserResult,
                        'note' => 'Direct SQL insert works! Issue is likely in NotificationManager code.'
                    ];
                    $conn->rollBack();
                } else {
                    $errorDetails['direct_insert'] = [
                        'error' => $testStmt->errorInfo(),
                        'type_tried' => $testType
                    ];
                    $conn->rollBack();
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $errorDetails['direct_insert_error'] = $e->getMessage();
                $errorDetails['direct_insert_code'] = $e->getCode();
            }
            
        } catch (Exception $e) {
            $errorDetails['check_error'] = $e->getMessage();
        }
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create test notification. Check PHP error logs for details.',
            'debug' => $errorDetails,
            'user_id_provided' => $userId,
            'project_id_provided' => $projectId ?? null,
            'note' => 'Check server error logs for NotificationManager::createNotification messages'
        ], JSON_PRETTY_PRINT);
    }
    
} catch (Exception $e) {
    error_log("Error in create_test_notification.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

