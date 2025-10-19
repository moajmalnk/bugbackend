<?php
/**
 * Messaging Cleanup Tasks
 * 
 * This script handles automated cleanup tasks that would normally be done by MySQL events.
 * Run this script via cron job or manually when needed.
 * 
 * Recommended cron schedule:
 * - Or call via HTTP: https://yoursite.com/api/messaging/cleanup_tasks.php
 */

require_once '../../config/database.php';
header('Content-Type: application/json');

// Security: Only allow execution from CLI or with a secret token
$CLEANUP_SECRET = 'your_secret_token_here_change_this'; // Change this!

if (php_sapi_name() !== 'cli') {
    // Running via HTTP - check for secret token
    $token = $_GET['token'] ?? '';
    if ($token !== $CLEANUP_SECRET) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized access'
        ]);
        exit;
    }
}

$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'tasks' => []
];

try {
    $conn = Database::getInstance()->getConnection();
    
    // Task 1: Clean up expired user status/stories (after 24 hours)
    $stmt = $conn->prepare("DELETE FROM user_status WHERE expires_at < NOW()");
    $stmt->execute();
    $expiredStatusCount = $stmt->rowCount();
    
    $results['tasks'][] = [
        'task' => 'cleanup_expired_status',
        'deleted_count' => $expiredStatusCount,
        'status' => 'success'
    ];
    
    // Task 2: Update offline users (inactive for more than 5 minutes)
    $stmt = $conn->prepare("
        UPDATE users 
        SET is_online = 0 
        WHERE is_online = 1 
        AND last_seen < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute();
    $offlineUsersCount = $stmt->rowCount();
    
    $results['tasks'][] = [
        'task' => 'update_offline_users',
        'updated_count' => $offlineUsersCount,
        'status' => 'success'
    ];
    
    // Task 3: Clean up old message delivery status (older than 30 days)
    // This is optional - keeps database size manageable
    $stmt = $conn->prepare("
        DELETE FROM message_delivery_status 
        WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $oldDeliveryStatusCount = $stmt->rowCount();
    
    $results['tasks'][] = [
        'task' => 'cleanup_old_delivery_status',
        'deleted_count' => $oldDeliveryStatusCount,
        'status' => 'success'
    ];
    
    // Task 4: Clean up old call logs (older than 90 days)
    // This is optional - adjust retention period as needed
    $stmt = $conn->prepare("
        DELETE FROM call_logs 
        WHERE started_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    $stmt->execute();
    $oldCallLogsCount = $stmt->rowCount();
    
    $results['tasks'][] = [
        'task' => 'cleanup_old_call_logs',
        'deleted_count' => $oldCallLogsCount,
        'status' => 'success'
    ];
    
    $results['success'] = true;
    $results['message'] = 'All cleanup tasks completed successfully';
    
} catch (PDOException $e) {
    $results['success'] = false;
    $results['error'] = 'Database error: ' . $e->getMessage();
    http_response_code(500);
}

// Output results
echo json_encode($results, JSON_PRETTY_PRINT);

// Log results to file (optional)
$logFile = __DIR__ . '/../../logs/cleanup_tasks.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}
file_put_contents(
    $logFile, 
    date('Y-m-d H:i:s') . ' - ' . json_encode($results) . PHP_EOL, 
    FILE_APPEND
);

?>

