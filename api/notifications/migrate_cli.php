<?php
/**
 * CLI Migration Script for Notifications Schema
 * 
 * Run this via command line (SSH) if you can't access via browser:
 * php migrate_cli.php
 * 
 * Or run with specific PHP path:
 * /usr/bin/php migrate_cli.php
 */

// CLI check
if (php_sapi_name() !== 'cli' && !isset($_SERVER['HTTP_HOST'])) {
    // Allow both CLI and web access
}

require_once __DIR__ . '/../../config/database.php';

echo "========================================\n";
echo "Notifications Schema Migration\n";
echo "========================================\n\n";

try {
    $pdo = Database::getInstance()->getConnection();
    
    if (!$pdo) {
        throw new Exception('Database connection failed');
    }
    
    echo "✓ Database connection established\n\n";
    
    $pdo->beginTransaction();
    echo "Transaction started...\n\n";
    
    $changes = [];
    $warnings = [];
    
    // Step 1: Check current ENUM values
    echo "Step 1: Checking current schema...\n";
    $currentTypeCheck = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'type'");
    $currentTypeInfo = $currentTypeCheck->fetch(PDO::FETCH_ASSOC);
    $currentEnum = $currentTypeInfo['Type'] ?? '';
    echo "  Current type enum: $currentEnum\n\n";
    
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
    echo "Step 2: Checking for invalid notification types...\n";
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
                echo "  ✓ Updated $updated notification(s) with invalid types\n";
            } else {
                echo "  ✓ No invalid types found\n";
            }
        } catch (Exception $e) {
            $warnings[] = "Could not update invalid types (may not exist): " . $e->getMessage();
            echo "  ⚠ Could not update invalid types: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  ✓ All notification types already present in ENUM\n";
        $warnings[] = "All notification types already present in ENUM, skipping type updates";
    }
    
    // Step 4: Alter the ENUM to include all notification types (only if needed)
    echo "\nStep 3: Updating type ENUM...\n";
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
            echo "  ✓ ENUM updated successfully\n";
        } catch (Exception $e) {
            error_log("Error updating ENUM: " . $e->getMessage());
            throw $e;
        }
    } else {
        echo "  ✓ ENUM already up to date\n";
    }
    
    // Step 5: Make sure created_by has a default value (idempotent)
    echo "\nStep 4: Checking created_by column...\n";
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
            echo "  ✓ Default value set for created_by\n";
        } else {
            echo "  ✓ created_by already has default value: {$colInfo['Default']}\n";
            $warnings[] = "created_by column already has a default value";
        }
    } catch (Exception $e) {
        $warnings[] = "Could not modify created_by (may already be correct): " . $e->getMessage();
        echo "  ⚠ Could not modify created_by: " . $e->getMessage() . "\n";
    }
    
    // Step 6: Verify user_notifications table exists and has proper structure
    echo "\nStep 5: Checking user_notifications table...\n";
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
            echo "  ✓ Created user_notifications table\n";
        } else {
            echo "  ✓ user_notifications table already exists\n";
        }
    } catch (Exception $e) {
        // Table might already exist or have different structure
        if (stripos($e->getMessage(), 'already exists') === false) {
            $warnings[] = "Could not create user_notifications table: " . $e->getMessage();
            echo "  ⚠ Could not create user_notifications table: " . $e->getMessage() . "\n";
        } else {
            echo "  ✓ Table already exists\n";
        }
    }
    
    if ($pdo->inTransaction()) {
        $pdo->commit();
        echo "\n✓ Transaction committed\n\n";
    }
    
    // Verify the changes
    echo "========================================\n";
    echo "Verification\n";
    echo "========================================\n";
    
    $verify = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'type'");
    $typeInfo = $verify->fetch(PDO::FETCH_ASSOC);
    
    // Get counts for verification
    $notificationCount = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $userNotificationCount = $pdo->query("SELECT COUNT(*) FROM user_notifications")->fetchColumn();
    
    echo "New type ENUM: " . ($typeInfo['Type'] ?? 'not found') . "\n";
    echo "Total notifications: $notificationCount\n";
    echo "Total user_notifications: $userNotificationCount\n\n";
    
    // Summary
    echo "========================================\n";
    echo "Summary\n";
    echo "========================================\n";
    echo "Status: SUCCESS ✓\n";
    
    if (!empty($changes)) {
        echo "\nChanges made:\n";
        foreach ($changes as $change) {
            echo "  • $change\n";
        }
    }
    
    if (!empty($warnings)) {
        echo "\nWarnings:\n";
        foreach ($warnings as $warning) {
            echo "  ⚠ $warning\n";
        }
    }
    
    if (empty($changes) && empty($warnings)) {
        echo "\nNo changes needed - schema is already up to date.\n";
    }
    
    echo "\n========================================\n";
    echo "Migration completed successfully!\n";
    echo "========================================\n";
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
        echo "\n✗ Transaction rolled back\n";
    }
    echo "\n========================================\n";
    echo "ERROR: Migration failed\n";
    echo "========================================\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTrace:\n" . $e->getTraceAsString() . "\n";
    error_log("Migration error: " . $e->getMessage());
    exit(1);
}

