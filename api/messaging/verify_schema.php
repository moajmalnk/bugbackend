<?php
/**
 * WhatsApp Schema Verification
 * Checks which tables and columns have been successfully installed
 */

require_once '../../config/database.php';
header('Content-Type: application/json');

try {
    $conn = Database::getInstance()->getConnection();
    
    $results = [
        'timestamp' => date('Y-m-d H:i:s'),
        'database' => $conn->query("SELECT DATABASE()")->fetchColumn(),
        'tables' => [],
        'summary' => []
    ];
    
    // List of expected tables
    $expectedTables = [
        'chat_messages' => ['media_type', 'media_file_path', 'is_starred', 'is_forwarded', 'is_edited', 'delivery_status'],
        'users' => ['is_online', 'last_seen', 'profile_picture', 'status_message'],
        'chat_groups' => ['group_picture', 'is_archived'],
        'starred_messages' => ['id', 'message_id', 'user_id'],
        'message_delivery_status' => ['id', 'message_id', 'status'],
        'user_status' => ['id', 'user_id', 'media_type', 'expires_at'],
        'status_views' => ['id', 'status_id', 'viewer_id'],
        'broadcast_lists' => ['id', 'name', 'created_by'],
        'broadcast_recipients' => ['broadcast_id', 'user_id'],
        'disappearing_messages_settings' => ['group_id', 'enabled'],
        'blocked_users' => ['id', 'blocker_id', 'blocked_id'],
        'group_admins' => ['group_id', 'user_id'],
        'call_logs' => ['id', 'call_type', 'caller_id'],
        'call_participants' => ['call_id', 'user_id'],
        'message_polls' => ['id', 'message_id', 'question'],
        'poll_options' => ['id', 'poll_id', 'option_text'],
        'poll_votes' => ['id', 'poll_id', 'option_id', 'user_id']
    ];
    
    foreach ($expectedTables as $tableName => $expectedColumns) {
        // Check if table exists
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);
        $tableExists = $stmt->fetchColumn() > 0;
        
        if ($tableExists) {
            // Check which columns exist
            $stmt = $conn->prepare("
                SELECT COLUMN_NAME 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ?
            ");
            $stmt->execute([$tableName]);
            $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $missingColumns = array_diff($expectedColumns, $existingColumns);
            
            $results['tables'][$tableName] = [
                'exists' => true,
                'total_columns' => count($existingColumns),
                'expected_key_columns' => $expectedColumns,
                'missing_columns' => array_values($missingColumns),
                'status' => empty($missingColumns) ? 'complete' : 'incomplete'
            ];
        } else {
            $results['tables'][$tableName] = [
                'exists' => false,
                'status' => 'missing'
            ];
        }
    }
    
    // Generate summary
    $totalTables = count($expectedTables);
    $existingTables = count(array_filter($results['tables'], fn($t) => $t['exists']));
    $completeTables = count(array_filter($results['tables'], fn($t) => ($t['status'] ?? '') === 'complete'));
    
    $results['summary'] = [
        'total_expected_tables' => $totalTables,
        'existing_tables' => $existingTables,
        'complete_tables' => $completeTables,
        'missing_tables' => $totalTables - $existingTables,
        'incomplete_tables' => $existingTables - $completeTables,
        'installation_status' => $existingTables === $totalTables ? 'Complete' : 'Partial',
        'percentage_complete' => round(($completeTables / $totalTables) * 100, 1) . '%'
    ];
    
    // Check for views
    $stmt = $conn->query("
        SELECT TABLE_NAME 
        FROM INFORMATION_SCHEMA.VIEWS 
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'unread_message_counts'
    ");
    $results['views'] = [
        'unread_message_counts' => $stmt->rowCount() > 0 ? 'exists' : 'missing'
    ];
    
    echo json_encode($results, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
?>

