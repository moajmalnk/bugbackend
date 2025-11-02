<?php
/**
 * Migration script to drop time tracking tables
 * 
 * This script removes the following tables:
 * - session_activities (has foreign key to work_sessions)
 * - session_pauses (has foreign key to work_sessions)
 * - work_sessions (parent table)
 * 
 * Run this script once to remove time tracking functionality from the database.
 * 
 * Usage:
 * php drop_time_tracking_tables.php
 * or access via browser: http://your-domain/backend/api/drop_time_tracking_tables.php
 */

require_once __DIR__ . '/../config/database.php';

try {
    $conn = getDatabaseConnection();
    
    echo "Starting time tracking tables removal...\n<br>";
    
    // Drop tables in order (child tables first due to foreign key constraints)
    $tables = [
        'session_activities',
        'session_pauses',
        'work_sessions'
    ];
    
    foreach ($tables as $table) {
        // Check if table exists
        $check = $conn->query("SHOW TABLES LIKE '{$table}'");
        
        if ($check->rowCount() > 0) {
            // Drop table
            $conn->exec("DROP TABLE IF EXISTS `{$table}`");
            echo "✓ Dropped table: {$table}\n<br>";
        } else {
            echo "⊘ Table {$table} does not exist, skipping...\n<br>";
        }
    }
    
    echo "\n<br>Time tracking tables removal completed successfully!\n<br>";
    echo "The following tables have been removed:\n<br>";
    echo "- work_sessions\n<br>";
    echo "- session_pauses\n<br>";
    echo "- session_activities\n<br>";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n<br>";
    http_response_code(500);
    exit(1);
} catch (Exception $e) {
    echo "Unexpected error: " . $e->getMessage() . "\n<br>";
    http_response_code(500);
    exit(1);
}
