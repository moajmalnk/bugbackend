<?php
/**
 * Password Reset System Setup Script
 * Run this script to set up the password reset system
 */

require_once 'config/database.php';

echo "<h2>BugRicer Password Reset System Setup</h2>\n";

try {
    // Read and execute the simplified SQL schema
    $sql_file = __DIR__ . '/sql/password_reset_simple.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("SQL file not found: $sql_file");
    }
    
    $sql_content = file_get_contents($sql_file);
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql_content)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt) && !preg_match('/^\/\*/', $stmt);
        }
    );
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) continue;
        
        try {
            $pdo->exec($statement);
            $success_count++;
            echo "<p style='color: green;'>✓ Executed: " . substr($statement, 0, 50) . "...</p>\n";
        } catch (PDOException $e) {
            $error_count++;
            echo "<p style='color: red;'>✗ Error: " . $e->getMessage() . "</p>\n";
            echo "<p style='color: orange;'>Statement: " . substr($statement, 0, 100) . "...</p>\n";
        }
    }
    
    echo "<h3>Setup Summary</h3>\n";
    echo "<p>Successful statements: $success_count</p>\n";
    echo "<p>Failed statements: $error_count</p>\n";
    
    if ($error_count === 0) {
        echo "<p style='color: green; font-weight: bold;'>✅ Password reset system setup completed successfully!</p>\n";
    } else {
        echo "<p style='color: orange; font-weight: bold;'>⚠️ Setup completed with some errors. Please review the errors above.</p>\n";
    }
    
    // Test the tables
    echo "<h3>Table Verification</h3>\n";
    
    $tables_to_check = ['password_resets', 'audit_logs'];
    
    foreach ($tables_to_check as $table) {
        try {
            $stmt = $pdo->query("DESCRIBE $table");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            echo "<p style='color: green;'>✓ Table '$table' exists with " . count($columns) . " columns</p>\n";
        } catch (PDOException $e) {
            echo "<p style='color: red;'>✗ Table '$table' not found: " . $e->getMessage() . "</p>\n";
        }
    }
    
    // Test API endpoints
    echo "<h3>API Endpoint Test</h3>\n";
    $endpoints = [
        'forgot_password.php',
        'verify_reset_token.php', 
        'reset_password.php'
    ];
    
    foreach ($endpoints as $endpoint) {
        $file_path = __DIR__ . "/api/$endpoint";
        if (file_exists($file_path)) {
            echo "<p style='color: green;'>✓ API endpoint '$endpoint' exists</p>\n";
        } else {
            echo "<p style='color: red;'>✗ API endpoint '$endpoint' not found</p>\n";
        }
    }
    
    echo "<h3>Next Steps</h3>\n";
    echo "<ol>\n";
    echo "<li>Test the forgot password functionality from the frontend</li>\n";
    echo "<li>Configure email settings in utils/email.php</li>\n";
    echo "<li>Set up a cron job to run cleanup queries daily</li>\n";
    echo "<li>Monitor the audit_logs table for security events</li>\n";
    echo "</ol>\n";
    
    echo "<h3>Test Commands</h3>\n";
    echo "<p>You can test the system with these SQL queries:</p>\n";
    echo "<pre>\n";
    echo "-- Check if tables exist\n";
    echo "SHOW TABLES LIKE 'password_resets';\n";
    echo "SHOW TABLES LIKE 'audit_logs';\n\n";
    echo "-- Check table structure\n";
    echo "DESCRIBE password_resets;\n";
    echo "DESCRIBE audit_logs;\n\n";
    echo "-- Test insert (replace with actual user_id)\n";
    echo "INSERT INTO password_resets (user_id, email, token, expires_at) \n";
    echo "VALUES (1, 'test@example.com', 'test_token_123', DATE_ADD(NOW(), INTERVAL 1 HOUR));\n\n";
    echo "-- Check the insert\n";
    echo "SELECT * FROM password_resets WHERE email = 'test@example.com';\n";
    echo "</pre>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red; font-weight: bold;'>❌ Setup failed: " . $e->getMessage() . "</p>\n";
    echo "<p>Please check your database connection and try again.</p>\n";
}
?>
