<?php
/**
 * Production Migration: Add Missing Columns to notifications table
 * Adds: entity_type, entity_id, project_id
 * Makes bug_id and bug_title nullable
 * 
 * This script is idempotent - safe to run multiple times
 * Run: https://bugbackend.bugricer.com/api/notifications/add_missing_columns.php
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
    
    $changes = [];
    $warnings = [];
    
    // Step 1: Add entity_type column if it doesn't exist
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'entity_type'");
        if ($checkCol->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE notifications 
                ADD COLUMN entity_type VARCHAR(50) DEFAULT NULL 
                COMMENT 'Type of entity: bug, task, meet, doc, update, project'
            ");
            $changes[] = "Added entity_type column";
        } else {
            $warnings[] = "entity_type column already exists";
        }
    } catch (Exception $e) {
        $warnings[] = "Could not add entity_type: " . $e->getMessage();
    }
    
    // Step 2: Add entity_id column if it doesn't exist
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'entity_id'");
        if ($checkCol->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE notifications 
                ADD COLUMN entity_id VARCHAR(36) DEFAULT NULL 
                COMMENT 'ID of the related entity'
            ");
            $changes[] = "Added entity_id column";
        } else {
            $warnings[] = "entity_id column already exists";
        }
    } catch (Exception $e) {
        $warnings[] = "Could not add entity_id: " . $e->getMessage();
    }
    
    // Step 3: Add project_id column if it doesn't exist
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'project_id'");
        if ($checkCol->rowCount() == 0) {
            $pdo->exec("
                ALTER TABLE notifications 
                ADD COLUMN project_id VARCHAR(36) DEFAULT NULL 
                COMMENT 'Related project ID'
            ");
            $changes[] = "Added project_id column";
        } else {
            $warnings[] = "project_id column already exists";
        }
    } catch (Exception $e) {
        $warnings[] = "Could not add project_id: " . $e->getMessage();
    }
    
    // Step 4: Make bug_id nullable if it isn't already
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'bug_id'");
        $colInfo = $checkCol->fetch(PDO::FETCH_ASSOC);
        if ($colInfo && $colInfo['Null'] === 'NO') {
            $pdo->exec("
                ALTER TABLE notifications 
                MODIFY COLUMN bug_id VARCHAR(36) DEFAULT NULL 
                COMMENT 'Bug ID (can be null for non-bug notifications)'
            ");
            $changes[] = "Made bug_id nullable";
        } else {
            $warnings[] = "bug_id column already nullable";
        }
    } catch (Exception $e) {
        $warnings[] = "Could not modify bug_id: " . $e->getMessage();
    }
    
    // Step 5: Make bug_title nullable if it isn't already
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM notifications WHERE Field = 'bug_title'");
        $colInfo = $checkCol->fetch(PDO::FETCH_ASSOC);
        if ($colInfo && $colInfo['Null'] === 'NO') {
            $pdo->exec("
                ALTER TABLE notifications 
                MODIFY COLUMN bug_title VARCHAR(255) DEFAULT NULL 
                COMMENT 'Bug title (can be null for non-bug notifications)'
            ");
            $changes[] = "Made bug_title nullable";
        } else {
            $warnings[] = "bug_title column already nullable";
        }
    } catch (Exception $e) {
        $warnings[] = "Could not modify bug_title: " . $e->getMessage();
    }
    
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    
    // Verify the changes
    $columns = [];
    $colCheck = $pdo->query("SHOW COLUMNS FROM notifications");
    while ($col = $colCheck->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = [
            'name' => $col['Field'],
            'type' => $col['Type'],
            'null' => $col['Null'],
            'default' => $col['Default']
        ];
    }
    
    // Check specifically for the new columns
    $entityTypeExists = false;
    $entityIdExists = false;
    $projectIdExists = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'entity_type') $entityTypeExists = true;
        if ($col['name'] === 'entity_id') $entityIdExists = true;
        if ($col['name'] === 'project_id') $projectIdExists = true;
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Migration completed successfully',
        'changes' => $changes,
        'warnings' => $warnings,
        'verification' => [
            'entity_type_exists' => $entityTypeExists,
            'entity_id_exists' => $entityIdExists,
            'project_id_exists' => $projectIdExists,
            'all_columns_added' => $entityTypeExists && $entityIdExists && $projectIdExists
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

