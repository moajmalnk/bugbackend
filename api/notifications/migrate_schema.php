<?php
/**
 * Migration script to enhance notifications table and create user_notifications table
 * Run this once to update the database schema
 */

require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();
    
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
    
    $pdo->beginTransaction();
    
    // Step 1: Alter notifications table to add new columns if they don't exist
    $alterNotificationsSQL = "
        ALTER TABLE notifications
        ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity: bug, task, meet, doc, update, project',
        ADD COLUMN IF NOT EXISTS entity_id VARCHAR(36) DEFAULT NULL COMMENT 'ID of the related entity',
        ADD COLUMN IF NOT EXISTS project_id VARCHAR(36) DEFAULT NULL COMMENT 'Related project ID',
        MODIFY COLUMN bug_id VARCHAR(36) DEFAULT NULL COMMENT 'Bug ID (can be null for non-bug notifications)',
        MODIFY COLUMN bug_title VARCHAR(255) DEFAULT NULL COMMENT 'Bug title (can be null for non-bug notifications)'
    ";
    
    // Check if columns exist first (MySQL doesn't support IF NOT EXISTS for ADD COLUMN)
    try {
        $checkCols = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'entity_type'");
        if ($checkCols->rowCount() == 0) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN entity_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity: bug, task, meet, doc, update, project'");
        }
    } catch (Exception $e) {
        error_log("Error adding entity_type: " . $e->getMessage());
    }
    
    try {
        $checkCols = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'entity_id'");
        if ($checkCols->rowCount() == 0) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN entity_id VARCHAR(36) DEFAULT NULL COMMENT 'ID of the related entity'");
        }
    } catch (Exception $e) {
        error_log("Error adding entity_id: " . $e->getMessage());
    }
    
    try {
        $checkCols = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'project_id'");
        if ($checkCols->rowCount() == 0) {
            $pdo->exec("ALTER TABLE notifications ADD COLUMN project_id VARCHAR(36) DEFAULT NULL COMMENT 'Related project ID'");
        }
    } catch (Exception $e) {
        error_log("Error adding project_id: " . $e->getMessage());
    }
    
    // Modify bug_id and bug_title to allow NULL
    try {
        $pdo->exec("ALTER TABLE notifications MODIFY COLUMN bug_id VARCHAR(36) DEFAULT NULL");
    } catch (Exception $e) {
        error_log("Error modifying bug_id: " . $e->getMessage());
    }
    
    try {
        $pdo->exec("ALTER TABLE notifications MODIFY COLUMN bug_title VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {
        error_log("Error modifying bug_title: " . $e->getMessage());
    }
    
    // Step 2: Modify type enum to include new types
    // Note: MySQL doesn't support direct enum modification, so we need to recreate
    try {
        $pdo->exec("
            ALTER TABLE notifications 
            MODIFY COLUMN type ENUM(
                'new_bug', 
                'status_change', 
                'bug_created', 
                'bug_fixed', 
                'update_created', 
                'task_created', 
                'meet_created', 
                'doc_created', 
                'project_created',
                'task_assigned',
                'task_completed',
                'meeting_reminder'
            ) NOT NULL
        ");
    } catch (Exception $e) {
        error_log("Error modifying type enum: " . $e->getMessage());
    }
    
    // Step 3: Create user_notifications table if it doesn't exist
    $createUserNotificationsSQL = "
        CREATE TABLE IF NOT EXISTS user_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL,
            user_id VARCHAR(36) NOT NULL,
            `read` TINYINT(1) DEFAULT 0 NOT NULL,
            read_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_notification_id (notification_id),
            INDEX idx_read (`read`),
            INDEX idx_user_read (user_id, `read`),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_notification (user_id, notification_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";
    
    $pdo->exec($createUserNotificationsSQL);
    
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Database schema migrated successfully',
        'changes' => [
            'notifications table enhanced with entity_type, entity_id, project_id',
            'notifications.type enum expanded with new notification types',
            'user_notifications table created'
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed: ' . $e->getMessage()
    ]);
    error_log("Migration error: " . $e->getMessage());
}

