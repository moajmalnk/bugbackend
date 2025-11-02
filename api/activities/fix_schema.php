<?php
require_once __DIR__ . '/../BaseAPI.php';

/**
 * Fix the project_activities table schema to allow NULL project_id
 */

try {
    $baseAPI = new BaseAPI();
    $conn = $baseAPI->getConnection();
    
    echo "Fixing project_activities table schema...\n";
    
    // Modify project_id column to allow NULL
    $sql = "ALTER TABLE project_activities MODIFY COLUMN project_id VARCHAR(36) NULL";
    $conn->exec($sql);
    echo "✓ Modified project_id column to allow NULL\n";
    
    // Add indexes for better performance
    $indexes = [
        "CREATE INDEX idx_project_activities_project_id ON project_activities(project_id)",
        "CREATE INDEX idx_project_activities_type ON project_activities(activity_type)",
        "CREATE INDEX idx_project_activities_created_at ON project_activities(created_at)",
        "CREATE INDEX idx_project_activities_user_id ON project_activities(user_id)"
    ];
    
    foreach ($indexes as $indexSql) {
        try {
            $conn->exec($indexSql);
            echo "✓ Created index successfully\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "ℹ Index already exists, skipping\n";
            } else {
                echo "⚠ Warning creating index: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\nSchema fix completed successfully!\n";
    echo "The project_activities table now allows NULL project_id values.\n";
    
} catch (Exception $e) {
    echo "Error fixing schema: " . $e->getMessage() . "\n";
}
?>
