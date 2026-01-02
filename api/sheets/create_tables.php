<?php
/**
 * Create user_sheets and related tables
 * Run this script once to set up the database tables for BugSheets
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

try {
    $conn = getDBConnection();
    
    // Create user_sheets table
    $executed = [];
    $errors = [];
    
    $tables = [
        'user_sheets' => "
            CREATE TABLE IF NOT EXISTS `user_sheets` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `sheet_title` varchar(500) NOT NULL COMMENT 'Sheet title',
              `google_sheet_id` varchar(255) NOT NULL COMMENT 'Google Sheet ID',
              `google_sheet_url` text NOT NULL COMMENT 'Full Google Sheets edit URL',
              `creator_user_id` varchar(255) NOT NULL COMMENT 'BugRicer user ID (UUID)',
              `template_id` int(11) DEFAULT NULL COMMENT 'Reference to sheet_templates or doc_templates if created from template',
              `sheet_type` varchar(50) DEFAULT 'general' COMMENT 'Sheet type: general, meeting, notes, etc.',
              `is_archived` tinyint(1) DEFAULT 0 COMMENT 'Whether sheet is archived',
              `project_id` varchar(500) DEFAULT NULL COMMENT 'Reference to projects.id (comma-separated for multiple projects)',
              `role` varchar(100) DEFAULT 'all' COMMENT 'Role access: all, admins, developers, testers (comma-separated for multiple)',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              `last_accessed_at` timestamp NULL DEFAULT NULL COMMENT 'Last time sheet was opened',
              PRIMARY KEY (`id`),
              UNIQUE KEY `google_sheet_id` (`google_sheet_id`),
              KEY `idx_creator` (`creator_user_id`),
              KEY `idx_template` (`template_id`),
              KEY `idx_type` (`sheet_type`),
              KEY `idx_archived` (`is_archived`),
              KEY `idx_project` (`project_id`(255)),
              KEY `idx_role` (`role`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User-created general Google Sheets'
        ",
        'sheet_templates' => "
            CREATE TABLE IF NOT EXISTS `sheet_templates` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `template_name` varchar(255) NOT NULL COMMENT 'Template name',
              `google_sheet_id` varchar(255) NOT NULL COMMENT 'Google Sheet ID of the template',
              `category` varchar(100) DEFAULT NULL COMMENT 'Template category',
              `description` text DEFAULT NULL COMMENT 'Template description',
              `is_active` tinyint(1) DEFAULT 1 COMMENT 'Whether template is active',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `template_name` (`template_name`),
              KEY `idx_category` (`category`),
              KEY `idx_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Google Sheets templates'
        ",
        'bug_sheets' => "
            CREATE TABLE IF NOT EXISTS `bug_sheets` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `bug_id` varchar(255) NOT NULL COMMENT 'Reference to bugs table (UUID)',
              `google_sheet_id` varchar(255) NOT NULL COMMENT 'Google Sheet ID',
              `google_sheet_url` text NOT NULL COMMENT 'Full URL to the Google Sheet',
              `sheet_name` varchar(500) NOT NULL COMMENT 'Name of the sheet',
              `created_by` varchar(255) NOT NULL COMMENT 'User who created the sheet (UUID)',
              `template_id` int(11) DEFAULT NULL COMMENT 'Reference to sheet_templates or doc_templates if created from template',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              `last_accessed_at` timestamp NULL DEFAULT NULL COMMENT 'Last time sheet was opened',
              PRIMARY KEY (`id`),
              UNIQUE KEY `google_sheet_id` (`google_sheet_id`),
              KEY `idx_bug` (`bug_id`),
              KEY `idx_created_by` (`created_by`),
              KEY `idx_template` (`template_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sheets created for bugs'
        "
    ];
    
    foreach ($tables as $tableName => $sql) {
        try {
            $conn->exec($sql);
            $executed[] = $tableName;
            error_log("✅ Created table: {$tableName}");
        } catch (PDOException $e) {
            // If table already exists, that's okay
            if (strpos($e->getMessage(), 'already exists') !== false || 
                strpos($e->getMessage(), 'Duplicate table') !== false) {
                $executed[] = $tableName . ' (already exists)';
                error_log("ℹ️  Table already exists: {$tableName}");
            } else {
                $errors[] = [
                    'table' => $tableName,
                    'error' => $e->getMessage()
                ];
                error_log("❌ Error creating table {$tableName}: " . $e->getMessage());
            }
        }
    }
    
    // Verify tables exist
    $tablesToCheck = ['user_sheets', 'sheet_templates', 'bug_sheets'];
    $existingTables = [];
    $missingTables = [];
    
    foreach ($tablesToCheck as $table) {
        try {
            $stmt = $conn->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() > 0) {
                $existingTables[] = $table;
            } else {
                $missingTables[] = $table;
            }
        } catch (Exception $e) {
            $missingTables[] = $table;
        }
    }
    
    $response = [
        'success' => count($missingTables) === 0,
        'message' => count($missingTables) === 0 
            ? 'All tables created successfully' 
            : 'Some tables may not have been created',
        'executed' => $executed,
        'existing_tables' => $existingTables,
        'missing_tables' => $missingTables,
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

