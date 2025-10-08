<?php
/**
 * Web-based Migration Runner
 * Run this via browser to execute the production migration
 */

require_once __DIR__ . '/../config/database.php';

// Simple authentication check (you can remove this if not needed)
$authToken = $_GET['token'] ?? '';
if ($authToken !== 'BugRicer2024Migration') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

try {
    // Get database connection
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Failed to connect to database");
    }
    
    $results = [];
    $results[] = "🚀 Starting Production Migration for Individual Completion Tracking...";
    
    // Check if the column already exists
    $checkColumn = $conn->query("SHOW COLUMNS FROM shared_task_assignees LIKE 'completed_at'");
    if ($checkColumn->rowCount() > 0) {
        $results[] = "⚠️ Column 'completed_at' already exists in shared_task_assignees table.";
        $results[] = "✅ Migration already applied!";
        echo json_encode([
            'success' => true, 
            'message' => 'Migration already applied',
            'results' => $results
        ]);
        exit;
    }
    
    $results[] = "🔧 Adding completed_at column to shared_task_assignees table...";
    
    // Add the completed_at column
    $sql = "ALTER TABLE shared_task_assignees ADD COLUMN completed_at DATETIME DEFAULT NULL";
    $conn->exec($sql);
    $results[] = "✅ Column 'completed_at' added successfully!";
    
    // Add index for better performance
    $results[] = "🔧 Adding index for better performance...";
    $indexSql = "CREATE INDEX idx_completed_at ON shared_task_assignees(completed_at)";
    $conn->exec($indexSql);
    $results[] = "✅ Index 'idx_completed_at' created successfully!";
    
    // Verify the migration
    $results[] = "🔍 Verifying migration...";
    $verify = $conn->query("DESCRIBE shared_task_assignees");
    $columns = $verify->fetchAll(PDO::FETCH_ASSOC);
    
    $results[] = "📋 Current shared_task_assignees table structure:";
    foreach ($columns as $column) {
        $results[] = "   - {$column['Field']}: {$column['Type']} ({$column['Null']}, {$column['Key']})";
    }
    
    // Test the new functionality
    $results[] = "🧪 Testing new functionality...";
    $testQuery = "SELECT COUNT(*) as total_assignees FROM shared_task_assignees";
    $result = $conn->query($testQuery);
    $count = $result->fetch(PDO::FETCH_ASSOC)['total_assignees'];
    $results[] = "📊 Total assignees in database: $count";
    
    if ($count > 0) {
        $testCompletionQuery = "SELECT COUNT(*) as completed_count FROM shared_task_assignees WHERE completed_at IS NOT NULL";
        $completionResult = $conn->query($testCompletionQuery);
        $completedCount = $completionResult->fetch(PDO::FETCH_ASSOC)['completed_count'];
        $results[] = "✅ Completed assignees: $completedCount";
    }
    
    $results[] = "🎉 Migration completed successfully!";
    $results[] = "✨ Individual completion tracking is now enabled in production!";
    
    echo json_encode([
        'success' => true,
        'message' => 'Migration completed successfully',
        'results' => $results
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Migration failed: ' . $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
?>
