<?php
/**
 * Check existing indexes on user_activity_sessions table
 * This helps identify which indexes already exist
 */

require_once 'config/database.php';

echo "=== User Activity Sessions Index Check ===\n\n";

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    echo "✓ Database connection successful\n";
    echo "Database: " . $conn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";
    
    // Check if user_activity_sessions table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'user_activity_sessions'");
    if ($checkTable->rowCount() > 0) {
        echo "✓ user_activity_sessions table exists\n\n";
        
        // Get all indexes on the table
        echo "--- Existing Indexes ---\n";
        $indexes = $conn->query("SHOW INDEX FROM user_activity_sessions");
        $existingIndexes = [];
        
        while ($index = $indexes->fetch(PDO::FETCH_ASSOC)) {
            $indexName = $index['Key_name'];
            if (!in_array($indexName, $existingIndexes)) {
                $existingIndexes[] = $indexName;
                echo "- {$indexName} ({$index['Index_type']})\n";
            }
        }
        
        if (empty($existingIndexes)) {
            echo "No custom indexes found (only PRIMARY key)\n";
        }
        
        // Check for specific indexes we need
        echo "\n--- Required Indexes Check ---\n";
        $requiredIndexes = [
            'idx_user_activity_user_id',
            'idx_user_activity_session_start',
            'idx_user_activity_session_end',
            'idx_user_activity_user_date',
            'idx_user_activity_active'
        ];
        
        foreach ($requiredIndexes as $indexName) {
            $checkIndex = $conn->query("
                SELECT COUNT(*) as count 
                FROM information_schema.statistics 
                WHERE table_schema = DATABASE() 
                AND table_name = 'user_activity_sessions' 
                AND index_name = '{$indexName}'
            ");
            $exists = $checkIndex->fetch(PDO::FETCH_ASSOC)['count'] > 0;
            
            if ($exists) {
                echo "✓ {$indexName} - EXISTS\n";
            } else {
                echo "✗ {$indexName} - MISSING\n";
            }
        }
        
        // Generate SQL to add missing indexes
        echo "\n--- Missing Indexes SQL ---\n";
        $missingIndexes = [];
        foreach ($requiredIndexes as $indexName) {
            $checkIndex = $conn->query("
                SELECT COUNT(*) as count 
                FROM information_schema.statistics 
                WHERE table_schema = DATABASE() 
                AND table_name = 'user_activity_sessions' 
                AND index_name = '{$indexName}'
            ");
            $exists = $checkIndex->fetch(PDO::FETCH_ASSOC)['count'] > 0;
            
            if (!$exists) {
                switch ($indexName) {
                    case 'idx_user_activity_user_id':
                        $missingIndexes[] = "ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_user_id (user_id);";
                        break;
                    case 'idx_user_activity_session_start':
                        $missingIndexes[] = "ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_session_start (session_start);";
                        break;
                    case 'idx_user_activity_session_end':
                        $missingIndexes[] = "ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_session_end (session_end);";
                        break;
                    case 'idx_user_activity_user_date':
                        $missingIndexes[] = "ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_user_date (user_id, session_start);";
                        break;
                    case 'idx_user_activity_active':
                        $missingIndexes[] = "ALTER TABLE user_activity_sessions ADD INDEX idx_user_activity_active (is_active);";
                        break;
                }
            }
        }
        
        if (!empty($missingIndexes)) {
            echo "Run these SQL commands to add missing indexes:\n\n";
            foreach ($missingIndexes as $sql) {
                echo $sql . "\n";
            }
        } else {
            echo "All required indexes are already present!\n";
        }
        
    } else {
        echo "✗ user_activity_sessions table does not exist\n";
        echo "Run the table creation SQL first:\n";
        echo "backend/config/user_activity_tracking_simple.sql\n";
    }
    
} catch (Exception $e) {
    echo "✗ Check failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Check Complete ===\n";
?>
