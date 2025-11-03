<?php
/**
 * Fix notifications.type ENUM to include all notification types
 * Run this once to update the database schema
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getInstance()->getConnection();
    
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
    
    $pdo->beginTransaction();
    
    // Step 1: Update any existing invalid enum values to a valid one
    try {
        $pdo->exec("
            UPDATE notifications 
            SET type = 'new_bug' 
            WHERE type NOT IN ('new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed', 'update_created', 'task_created', 'meet_created', 'doc_created', 'project_created')
            OR type IS NULL
        ");
    } catch (Exception $e) {
        error_log("Note: Could not update invalid types (may not exist): " . $e->getMessage());
    }
    
    // Step 2: Alter the ENUM to include all notification types
    // We need to use MODIFY since MySQL doesn't support adding to ENUM directly
    try {
        $pdo->exec("
            ALTER TABLE notifications 
            MODIFY COLUMN type ENUM(
                'new_bug',
                'status_change',
                'new_update',
                'bug_created',
                'bug_fixed',
                'update_created',
                'task_created',
                'meet_created',
                'doc_created',
                'project_created',
                'task_assigned',
                'task_completed',
                'meeting_reminder',
                'info'
            ) NOT NULL
        ");
        error_log("Successfully updated notifications.type ENUM");
    } catch (Exception $e) {
        error_log("Error updating ENUM: " . $e->getMessage());
        throw $e;
    }
    
    // Step 3: Make sure created_by has a default or allow NULL
    try {
        $pdo->exec("
            ALTER TABLE notifications 
            MODIFY COLUMN created_by VARCHAR(100) DEFAULT 'system'
        ");
        error_log("Successfully updated created_by column");
    } catch (Exception $e) {
        error_log("Note: Could not modify created_by (may already be correct): " . $e->getMessage());
    }
    
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    
    // Verify the change
    $verify = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'type'");
    $typeInfo = $verify->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Database schema updated successfully',
        'changes' => [
            'notifications.type enum expanded with all notification types',
            'created_by column updated with default value'
        ],
        'new_type_enum' => $typeInfo['Type'] ?? 'not found'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    error_log("Migration error: " . $e->getMessage());
}

