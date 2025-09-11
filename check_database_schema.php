<?php
// Check database schema for password reset functionality
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Database Schema Check ===\n";
    
    // Check if password_resets table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'password_resets'");
    $table_exists = $stmt->fetch();
    
    if ($table_exists) {
        echo "✓ password_resets table exists\n";
        
        // Check table structure
        $stmt = $pdo->query("DESCRIBE password_resets");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\n--- password_resets table structure ---\n";
        foreach ($columns as $column) {
            echo $column['Field'] . " - " . $column['Type'] . " - " . $column['Null'] . " - " . $column['Key'] . "\n";
        }
        
        // Check if audit_logs table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
        $audit_exists = $stmt->fetch();
        
        if ($audit_exists) {
            echo "\n✓ audit_logs table exists\n";
        } else {
            echo "\n✗ audit_logs table missing\n";
        }
        
    } else {
        echo "✗ password_resets table does not exist\n";
        
        // Try to create the table
        echo "\n--- Creating password_resets table ---\n";
        $create_sql = "
            CREATE TABLE password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                email VARCHAR(100) NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_email (email),
                INDEX idx_token (token),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        try {
            $pdo->exec($create_sql);
            echo "✓ password_resets table created successfully\n";
        } catch (Exception $e) {
            echo "✗ Failed to create password_resets table: " . $e->getMessage() . "\n";
        }
    }
    
    // Check users table structure
    echo "\n--- users table structure ---\n";
    $stmt = $pdo->query("DESCRIBE users");
    $user_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($user_columns as $column) {
        echo $column['Field'] . " - " . $column['Type'] . " - " . $column['Null'] . " - " . $column['Key'] . "\n";
    }
    
    // Check if password_changed_at column exists
    $password_changed_exists = false;
    foreach ($user_columns as $column) {
        if ($column['Field'] === 'password_changed_at') {
            $password_changed_exists = true;
            break;
        }
    }
    
    if ($password_changed_exists) {
        echo "\n✓ password_changed_at column exists\n";
    } else {
        echo "\n✗ password_changed_at column missing\n";
        
        // Try to add the column
        echo "\n--- Adding password_changed_at column ---\n";
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMP NULL AFTER password");
            echo "✓ password_changed_at column added successfully\n";
        } catch (Exception $e) {
            echo "✗ Failed to add password_changed_at column: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
