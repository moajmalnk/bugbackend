<?php
/**
 * Production Migration Script for Individual Completion Tracking
 * This script will run the database migration for the production environment
 */

require_once __DIR__ . '/database.php';

echo "🚀 Starting Production Migration for Individual Completion Tracking...\n\n";

try {
    // Get database connection
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Failed to connect to database");
    }
    
    echo "✅ Database connection successful!\n";
    echo "📊 Database: " . ($_SERVER['HTTP_HOST'] ?? 'CLI') . "\n\n";
    
    // Check if the column already exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM shared_task_assignees LIKE 'completed_at'");
    if ($checkColumn->rowCount() > 0) {
        echo "⚠️  Column 'completed_at' already exists in shared_task_assignees table.\n";
        echo "✅ Migration already applied!\n";
        exit(0);
    }
    
    echo "🔧 Adding completed_at column to shared_task_assignees table...\n";
    
    // Add the completed_at column
    $sql = "ALTER TABLE shared_task_assignees ADD COLUMN completed_at DATETIME DEFAULT NULL";
    $conn->exec($sql);
    echo "✅ Column 'completed_at' added successfully!\n";
    
    // Add index for better performance
    echo "🔧 Adding index for better performance...\n";
    $indexSql = "CREATE INDEX idx_completed_at ON shared_task_assignees(completed_at)";
    $conn->exec($indexSql);
    echo "✅ Index 'idx_completed_at' created successfully!\n";
    
    // Verify the migration
    echo "\n🔍 Verifying migration...\n";
    $verify = $conn->query("DESCRIBE shared_task_assignees");
    $columns = $verify->fetchAll(PDO::FETCH_ASSOC);
    
    echo "📋 Current shared_task_assignees table structure:\n";
    echo "┌─────────────────────┬─────────────────┬──────┬─────┬────────┬───────┐\n";
    echo "│ Field               │ Type            │ Null │ Key │ Default│ Extra │\n";
    echo "├─────────────────────┼─────────────────┼──────┼─────┼────────┼───────┤\n";
    
    foreach ($columns as $column) {
        printf("│ %-19s │ %-15s │ %-4s │ %-3s │ %-6s │ %-5s │\n",
            $column['Field'],
            $column['Type'],
            $column['Null'],
            $column['Key'],
            $column['Default'] ?? 'NULL',
            $column['Extra']
        );
    }
    echo "└─────────────────────┴─────────────────┴──────┴─────┴────────┴───────┘\n";
    
    echo "\n🎉 Migration completed successfully!\n";
    echo "✨ Individual completion tracking is now enabled in production!\n\n";
    
    // Test the new functionality
    echo "🧪 Testing new functionality...\n";
    $testQuery = "SELECT COUNT(*) as total_assignees FROM shared_task_assignees";
    $result = $conn->query($testQuery);
    $count = $result->fetch(PDO::FETCH_ASSOC)['total_assignees'];
    echo "📊 Total assignees in database: $count\n";
    
    if ($count > 0) {
        $testCompletionQuery = "SELECT COUNT(*) as completed_count FROM shared_task_assignees WHERE completed_at IS NOT NULL";
        $completionResult = $conn->query($testCompletionQuery);
        $completedCount = $completionResult->fetch(PDO::FETCH_ASSOC)['completed_count'];
        echo "✅ Completed assignees: $completedCount\n";
    }
    
    echo "\n🚀 Production is ready for individual completion tracking!\n";
    
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "🔧 Please check your database connection and try again.\n";
    exit(1);
}
?>
