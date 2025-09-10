<?php
/**
 * Test database setup for password reset system
 */

require_once 'config/database.php';

echo "<h2>BugRicer Database Setup Test</h2>\n";

try {
    // Get database connection
    $pdo = Database::getInstance()->getConnection();
    
    if (!$pdo) {
        throw new Exception("Database connection failed");
    }
    
    echo "<p style='color: green;'>✅ Database connection successful</p>\n";
    
    // Check if password_resets table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'password_resets'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "<p style='color: green;'>✅ password_resets table exists</p>\n";
        
        // Check table structure
        $stmt = $pdo->query("DESCRIBE password_resets");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>password_resets table structure:</h3>\n";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>\n";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p style='color: red;'>❌ password_resets table does not exist</p>\n";
        echo "<p>Please run the database setup script first:</p>\n";
        echo "<p><a href='setup_password_reset.php'>Run Database Setup</a></p>\n";
    }
    
    // Check if audit_logs table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
    $audit_table_exists = $stmt->fetch();
    
    if ($audit_table_exists) {
        echo "<p style='color: green;'>✅ audit_logs table exists</p>\n";
    } else {
        echo "<p style='color: orange;'>⚠️ audit_logs table does not exist (optional)</p>\n";
    }
    
    // Check if users table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $users_table_exists = $stmt->fetch();
    
    if ($users_table_exists) {
        echo "<p style='color: green;'>✅ users table exists</p>\n";
        
        // Check users table structure
        $stmt = $pdo->query("DESCRIBE users");
        $user_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h3>users table structure:</h3>\n";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>\n";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
        foreach ($user_columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p style='color: red;'>❌ users table does not exist</p>\n";
        echo "<p>This is required for the password reset system to work.</p>\n";
    }
    
    // Test a simple query
    if ($users_table_exists) {
        $stmt = $pdo->query("SELECT COUNT(*) as user_count FROM users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p><strong>Total users in database:</strong> " . $result['user_count'] . "</p>\n";
    }
    
    echo "<h3>Next Steps</h3>\n";
    if (!$table_exists) {
        echo "<ol>\n";
        echo "<li>Run the database setup: <a href='setup_password_reset.php'>Setup Password Reset Tables</a></li>\n";
        echo "<li>Test the API: <a href='test_forgot_password.php'>Test Forgot Password API</a></li>\n";
        echo "</ol>\n";
    } else {
        echo "<p style='color: green;'>✅ Database setup looks good! You can now test the forgot password functionality.</p>\n";
        echo "<p><a href='test_forgot_password.php'>Test Forgot Password API</a></p>\n";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>\n";
    echo "<p>Please check your database configuration in config/database.php</p>\n";
}
?>
