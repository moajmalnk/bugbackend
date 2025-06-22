<?php
require_once __DIR__ . '/config/database.php';

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    // First, let's check the users table structure
    $stmt = $conn->prepare("DESCRIBE users");
    $stmt->execute();
    $usersColumns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Users table structure:\n";
    foreach ($usersColumns as $column) {
        echo "- {$column['Field']}: {$column['Type']}\n";
    }
    echo "\n";
    
    // Create admin audit log table without foreign keys first
    $sql = "
    CREATE TABLE IF NOT EXISTS admin_audit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id VARCHAR(36) NOT NULL,
        action VARCHAR(100) NOT NULL,
        target_user_id VARCHAR(36),
        details JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_id (admin_id),
        INDEX idx_action (action),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    $stmt = $conn->prepare($sql);
    $result = $stmt->execute();
    
    if ($result) {
        echo "✓ Admin audit log table created successfully!\n";
        
        // Now try to add foreign keys if they don't exist
        try {
            $fkSql = "
            ALTER TABLE admin_audit_log 
            ADD CONSTRAINT fk_admin_audit_log_admin_id 
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE;
            ";
            $stmt = $conn->prepare($fkSql);
            $stmt->execute();
            echo "✓ Foreign key for admin_id added successfully!\n";
        } catch (Exception $e) {
            echo "⚠ Could not add foreign key for admin_id: " . $e->getMessage() . "\n";
        }
        
        try {
            $fkSql2 = "
            ALTER TABLE admin_audit_log 
            ADD CONSTRAINT fk_admin_audit_log_target_user_id 
            FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE;
            ";
            $stmt = $conn->prepare($fkSql2);
            $stmt->execute();
            echo "✓ Foreign key for target_user_id added successfully!\n";
        } catch (Exception $e) {
            echo "⚠ Could not add foreign key for target_user_id: " . $e->getMessage() . "\n";
        }
        
    } else {
        echo "✗ Failed to create admin audit log table\n";
        print_r($stmt->errorInfo());
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
?> 