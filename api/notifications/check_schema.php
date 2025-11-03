<?php
/**
 * Check notification schema and ENUM values
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

try {
    $api = new BaseAPI();
    $conn = $api->getConnection();
    
    // Check notifications table structure
    $columns = $conn->query("SHOW COLUMNS FROM notifications")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get type ENUM values
    $typeColumn = null;
    foreach ($columns as $col) {
        if ($col['Field'] === 'type') {
            $typeColumn = $col;
            break;
        }
    }
    
    // Check if user_notifications table exists and get its structure
    $userNotificationsColumns = [];
    try {
        $userNotificationsColumns = $conn->query("SHOW COLUMNS FROM user_notifications")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $userNotificationsColumns = ['error' => $e->getMessage()];
    }
    
    // Try to insert a test record (will rollback)
    $testResult = null;
    try {
        $conn->beginTransaction();
        $testStmt = $conn->prepare("INSERT INTO notifications (type, title, message, created_at) VALUES (?, ?, ?, NOW())");
        $testResult = $testStmt->execute(['bug_created', 'Test', 'Test message']);
        $conn->rollBack();
    } catch (Exception $e) {
        $conn->rollBack();
        $testResult = ['error' => $e->getMessage(), 'code' => $e->getCode()];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'notifications_columns' => $columns,
            'type_column_info' => $typeColumn,
            'type_enum_values' => $typeColumn ? $typeColumn['Type'] : 'not found',
            'user_notifications_columns' => $userNotificationsColumns,
            'test_insert_result' => $testResult
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("Error in check_schema.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

