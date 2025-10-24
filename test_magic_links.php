<?php
/**
 * Test Magic Links Setup
 * This script tests if the magic_links table exists and is properly configured
 */

require_once __DIR__ . '/config/database.php';

echo "🧪 Testing Magic Links Setup...\n\n";

try {
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    echo "✅ Database connection successful\n";
    
    // Check if magic_links table exists
    $result = $db->query("SHOW TABLES LIKE 'magic_links'");
    if ($result && $result->rowCount() > 0) {
        echo "✅ magic_links table exists\n";
        
        // Check table structure
        $result = $db->query("DESCRIBE magic_links");
        if ($result) {
            echo "✅ Table structure:\n";
            while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                echo "   - {$row['Field']}: {$row['Type']}\n";
            }
        }
        
        // Test insert (will be cleaned up)
        $test_token = bin2hex(random_bytes(32));
        $test_user_id = 1; // Assuming user ID 1 exists
        $test_email = 'test@example.com';
        $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        $stmt = $db->prepare("INSERT INTO magic_links (user_id, token, email, expires_at, created_at) VALUES (?, ?, ?, ?, NOW())");
        
        if ($stmt->execute([$test_user_id, $test_token, $test_email, $expires_at])) {
            echo "✅ Test insert successful\n";
            
            // Clean up test data
            $delete_stmt = $db->prepare("DELETE FROM magic_links WHERE token = ?");
            $delete_stmt->execute([$test_token]);
            echo "✅ Test data cleaned up\n";
        } else {
            echo "❌ Test insert failed\n";
        }
        
    } else {
        echo "❌ magic_links table does not exist\n";
        echo "💡 Run the migration script: php run_magic_links_migration_simple.php\n";
    }
    
    // Check if users table exists
    $result = $db->query("SHOW TABLES LIKE 'users'");
    if ($result && $result->rowCount() > 0) {
        echo "✅ users table exists\n";
        
        // Check if there are any users
        $result = $db->query("SELECT COUNT(*) as count FROM users");
        if ($result) {
            $row = $result->fetch(PDO::FETCH_ASSOC);
            echo "✅ Found {$row['count']} users in database\n";
        }
    } else {
        echo "❌ users table does not exist\n";
    }
    
    echo "\n🎉 Magic Links setup test completed!\n";
    
} catch (Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
