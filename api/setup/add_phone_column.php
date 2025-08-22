<?php
require_once __DIR__ . '/../../config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Check if phone column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'phone'");
    $phoneColumnExists = $stmt->rowCount() > 0;
    
    if (!$phoneColumnExists) {
        // Add phone column
        $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20) DEFAULT NULL");
        echo "Phone column added successfully to users table.\n";
        
        // Add index
        $pdo->exec("CREATE INDEX idx_users_phone ON users(phone)");
        echo "Phone index created successfully.\n";
    } else {
        echo "Phone column already exists in users table.\n";
    }
    
    // Show current table structure
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nCurrent users table structure:\n";
    foreach ($columns as $column) {
        echo "- {$column['Field']}: {$column['Type']}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?> 