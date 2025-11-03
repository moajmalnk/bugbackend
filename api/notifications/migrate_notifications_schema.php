<?php
/**
 * Production-Ready Migration Script
 * Updates notifications.type ENUM to include all notification types
 * Sets default value for created_by column
 * 
 * Run this ONCE on your production server:
 * https://yourdomain.com/backend/api/notifications/migrate_notifications_schema.php
 * 
 * This script is idempotent - safe to run multiple times
 */

/**
 * PRODUCTION MIGRATION SCRIPT
 * 
 * SECURITY: Uncomment the authentication section below for production use!
 */

// Handle CORS
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

// PRODUCTION SECURITY: Uncomment these lines for production
// This ensures only admins can run the migration
/*
require_once __DIR__ . '/../BaseAPI.php';
try {
    $api = new BaseAPI();
    $userData = $api->validateToken();
    if (!$userData || $userData->role !== 'admin') {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'message' => 'Admin access required. Please authenticate with an admin account.',
            'error' => 'FORBIDDEN'
        ]);
        exit();
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required. Please provide a valid admin token.',
        'error' => 'UNAUTHORIZED'
    ]);
    exit();
}
*/

try {
    $pdo = Database::getInstance()->getConnection();
    
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
    
    $pdo->beginTransaction();
    
    $changes = [];
    $warnings = [];
    
    // Step 1: Check current ENUM values
    $currentTypeCheck = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'type'");
    $currentTypeInfo = $currentTypeCheck->fetch(PDO::FETCH_ASSOC);
    $currentEnum = $currentTypeInfo['Type'] ?? '';
    
    // Step 2: Check if we need to update
    $requiredTypes = [
        'new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed',
        'update_created', 'task_created', 'meet_created', 'doc_created',
        'project_created', 'task_assigned', 'task_completed', 'meeting_reminder', 'info'
    ];
    
    $allTypesPresent = true;
    foreach ($requiredTypes as $type) {
        if (stripos($currentEnum, $type) === false) {
            $allTypesPresent = false;
            break;
        }
    }
    
    // Step 3: Update any existing invalid enum values to a valid one (only if needed)
    if (!$allTypesPresent) {
        try {
            $updated = $pdo->exec("
                UPDATE notifications 
                SET type = 'new_bug' 
                WHERE type NOT IN ('new_bug', 'status_change', 'new_update', 'bug_created', 'bug_fixed', 
                                   'update_created', 'task_created', 'meet_created', 'doc_created', 
                                   'project_created', 'task_assigned', 'task_completed', 'meeting_reminder', 'info')
                OR type IS NULL
            ");
            if ($updated > 0) {
                $changes[] = "Updated $updated notification(s) with invalid types to 'new_bug'";
            }
        } catch (Exception $e) {
            $warnings[] = "Could not update invalid types (may not exist): " . $e->getMessage();
        }
    } else {
        $warnings[] = "All notification types already present in ENUM, skipping type updates";
    }
    
    // Step 4: Alter the ENUM to include all notification types (only if needed)
    if (!$allTypesPresent) {
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
            $changes[] = "Successfully updated notifications.type ENUM with all notification types";
        } catch (Exception $e) {
            error_log("Error updating ENUM: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Step 5: Make sure created_by has a default value (idempotent)
    try {
        // Check current column definition
        $colCheck = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'created_by'");
        $colInfo = $colCheck->fetch(PDO::FETCH_ASSOC);
        
        // Check if default is already set
        $hasDefault = isset($colInfo['Default']) && $colInfo['Default'] !== null;
        
        if (!$hasDefault) {
            $pdo->exec("
                ALTER TABLE notifications 
                MODIFY COLUMN created_by VARCHAR(100) DEFAULT 'system'
            ");
            $changes[] = "Successfully set default value 'system' for created_by column";
        } else {
            $warnings[] = "created_by column already has a default value";
        }
    } catch (Exception $e) {
        $warnings[] = "Could not modify created_by (may already be correct): " . $e->getMessage();
    }
    
    // Step 6: Verify user_notifications table exists and has proper structure
    try {
        $tableExists = $pdo->query("SHOW TABLES LIKE 'user_notifications'")->rowCount() > 0;
        
        if (!$tableExists) {
            $pdo->exec("
                CREATE TABLE user_notifications (
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
            ");
            $changes[] = "Created user_notifications table";
        }
    } catch (Exception $e) {
        // Table might already exist or have different structure
        if (stripos($e->getMessage(), 'already exists') === false) {
            $warnings[] = "Could not create user_notifications table: " . $e->getMessage();
        }
    }
    
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    
    // Verify the changes
    $verify = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'type'");
    $typeInfo = $verify->fetch(PDO::FETCH_ASSOC);
    
    // Get counts for verification
    $notificationCount = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $userNotificationCount = $pdo->query("SELECT COUNT(*) FROM user_notifications")->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'message' => 'Database schema migration completed successfully',
        'changes' => $changes,
        'warnings' => $warnings,
        'verification' => [
            'new_type_enum' => $typeInfo['Type'] ?? 'not found',
            'total_notifications' => (int)$notificationCount,
            'total_user_notifications' => (int)$userNotificationCount
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    error_log("Migration error: " . $e->getMessage());
}

