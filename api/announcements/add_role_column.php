<?php
/**
 * Add role column to announcements table
 * Run this script once to add role-based access control to announcements
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $conn = getDBConnection();
    
    $executed = [];
    $errors = [];
    
    // Check if column already exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM announcements LIKE 'role'");
    $columnExists = $checkColumn->rowCount() > 0;
    
    if (!$columnExists) {
        // Add role column
        try {
            $sql = "ALTER TABLE `announcements` 
                    ADD COLUMN `role` VARCHAR(100) DEFAULT 'all' 
                    COMMENT 'Role access: all, admins, developers, testers (comma-separated for multiple)' 
                    AFTER `expiry_date`";
            $conn->exec($sql);
            $executed[] = 'Added role column';
            error_log("✅ Added role column to announcements table");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') !== false) {
                $executed[] = 'Role column (already exists)';
                error_log("ℹ️  Role column already exists");
            } else {
                $errors[] = [
                    'operation' => 'Add role column',
                    'error' => $e->getMessage()
                ];
                error_log("❌ Error adding role column: " . $e->getMessage());
            }
        }
    } else {
        $executed[] = 'Role column (already exists)';
        error_log("ℹ️  Role column already exists");
    }
    
    // Check if index exists
    $checkIndex = $conn->query("SHOW INDEX FROM announcements WHERE Key_name = 'idx_role'");
    $indexExists = $checkIndex->rowCount() > 0;
    
    if (!$indexExists) {
        // Add index for role filtering
        try {
            $sql = "ALTER TABLE `announcements` ADD INDEX `idx_role` (`role`)";
            $conn->exec($sql);
            $executed[] = 'Added role index';
            error_log("✅ Added role index to announcements table");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key') !== false) {
                $executed[] = 'Role index (already exists)';
                error_log("ℹ️  Role index already exists");
            } else {
                $errors[] = [
                    'operation' => 'Add role index',
                    'error' => $e->getMessage()
                ];
                error_log("❌ Error adding role index: " . $e->getMessage());
            }
        }
    } else {
        $executed[] = 'Role index (already exists)';
        error_log("ℹ️  Role index already exists");
    }
    
    // Verify the column exists
    $verifyColumn = $conn->query("SHOW COLUMNS FROM announcements LIKE 'role'");
    $columnVerified = $verifyColumn->rowCount() > 0;
    
    $response = [
        'success' => count($errors) === 0 && $columnVerified,
        'message' => count($errors) === 0 && $columnVerified
            ? 'Role column added successfully' 
            : 'Some operations may have failed',
        'executed' => $executed,
        'column_exists' => $columnVerified,
        'errors' => $errors
    ];
    
    if (count($errors) > 0) {
        http_response_code(500);
    } else {
        http_response_code(200);
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}

