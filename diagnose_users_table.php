<?php
/**
 * Diagnostic script to check users table structure
 * This helps identify why foreign key constraints are failing
 */

require_once 'config/database.php';

echo "=== Users Table Structure Diagnostic ===\n\n";

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    echo "✓ Database connection successful\n";
    echo "Database: " . $conn->query("SELECT DATABASE()")->fetchColumn() . "\n\n";
    
    // Check if users table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'users'");
    if ($checkTable->rowCount() > 0) {
        echo "✓ Users table exists\n\n";
        
        // Get users table structure
        echo "--- Users Table Structure ---\n";
        $structure = $conn->query("DESCRIBE users");
        while ($column = $structure->fetch(PDO::FETCH_ASSOC)) {
            echo "- {$column['Field']}: {$column['Type']} " . 
                 ($column['Null'] === 'NO' ? 'NOT NULL' : 'NULL') . 
                 ($column['Key'] ? " ({$column['Key']})" : '') . 
                 ($column['Default'] ? " DEFAULT {$column['Default']}" : '') . "\n";
        }
        
        // Check if id column exists and its type
        echo "\n--- ID Column Analysis ---\n";
        $idColumn = $conn->query("SHOW COLUMNS FROM users LIKE 'id'");
        if ($idColumn->rowCount() > 0) {
            $idInfo = $idColumn->fetch(PDO::FETCH_ASSOC);
            echo "✓ ID column found: {$idInfo['Type']}\n";
            echo "  - Null: {$idInfo['Null']}\n";
            echo "  - Key: {$idInfo['Key']}\n";
            echo "  - Default: {$idInfo['Default']}\n";
        } else {
            echo "✗ ID column not found\n";
        }
        
        // Check table engine
        echo "\n--- Table Engine ---\n";
        $engine = $conn->query("SHOW TABLE STATUS LIKE 'users'");
        $engineInfo = $engine->fetch(PDO::FETCH_ASSOC);
        echo "Engine: {$engineInfo['Engine']}\n";
        echo "Collation: {$engineInfo['Collation']}\n";
        
        // Check existing foreign keys
        echo "\n--- Existing Foreign Keys ---\n";
        $foreignKeys = $conn->query("
            SELECT 
                CONSTRAINT_NAME,
                COLUMN_NAME,
                REFERENCED_TABLE_NAME,
                REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'users' 
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");
        
        if ($foreignKeys->rowCount() > 0) {
            while ($fk = $foreignKeys->fetch(PDO::FETCH_ASSOC)) {
                echo "- {$fk['CONSTRAINT_NAME']}: {$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}\n";
            }
        } else {
            echo "No existing foreign keys found\n";
        }
        
        // Test creating a simple table without foreign key
        echo "\n--- Testing Table Creation ---\n";
        try {
            $testTable = "CREATE TABLE IF NOT EXISTS test_foreign_key (
                id VARCHAR(36) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL
            ) ENGINE=InnoDB";
            
            $conn->exec($testTable);
            echo "✓ Simple table creation works\n";
            
            // Try to add foreign key
            try {
                $conn->exec("ALTER TABLE test_foreign_key ADD CONSTRAINT fk_test FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
                echo "✓ Foreign key constraint works\n";
                $conn->exec("DROP TABLE test_foreign_key");
            } catch (Exception $e) {
                echo "✗ Foreign key constraint failed: " . $e->getMessage() . "\n";
                $conn->exec("DROP TABLE IF EXISTS test_foreign_key");
            }
            
        } catch (Exception $e) {
            echo "✗ Table creation failed: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "✗ Users table does not exist\n";
        echo "Available tables:\n";
        $tables = $conn->query("SHOW TABLES");
        while ($table = $tables->fetch(PDO::FETCH_NUM)) {
            echo "- {$table[0]}\n";
        }
    }
    
    echo "\n--- Recommendations ---\n";
    echo "1. If foreign key constraints fail, use the simple version:\n";
    echo "   backend/config/user_activity_tracking_simple.sql\n";
    echo "2. Make sure the users table has an 'id' column of type VARCHAR(36)\n";
    echo "3. Ensure the table engine is InnoDB for foreign key support\n";
    echo "4. Check that the database user has ALTER privileges\n";
    
} catch (Exception $e) {
    echo "✗ Diagnostic failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Diagnostic Complete ===\n";
?>
