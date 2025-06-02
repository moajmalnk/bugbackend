<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/utils.php';

echo "Creating Admin User for BugRacer\n";
echo "================================\n\n";

try {
    // Connect to database
    $database = new Database();
    $conn = $database->getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    echo "✓ Database connected successfully!\n\n";
    
    // Check if admin user already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute(['admin']);
    
    if ($stmt->rowCount() > 0) {
        echo "Admin user already exists. Updating password...\n";
        
        // Update existing admin user
        $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
        $result = $stmt->execute([$hashed_password, 'admin']);
        
        if ($result) {
            echo "✓ Admin password updated successfully!\n";
        } else {
            echo "✗ Failed to update admin password\n";
        }
    } else {
        echo "Creating new admin user...\n";
        
        // Create new admin user
        $user_id = Utils::generateUUID();
        $username = 'admin';
        $email = 'ajmalpkottakkal@gmail.com';
        $password = 'admin123';
        $role = 'admin';
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare(
            "INSERT INTO users (id, username, email, password, role) VALUES (?, ?, ?, ?, ?)"
        );
        
        $result = $stmt->execute([
            $user_id,
            $username,
            $email,
            $hashed_password,
            $role
        ]);
        
        if ($result) {
            echo "✓ Admin user created successfully!\n";
        } else {
            echo "✗ Failed to create admin user\n";
        }
    }
    
    echo "\nLogin Credentials:\n";
    echo "  Username: admin\n";
    echo "  Password: admin123\n";
    echo "  Role: admin\n\n";
    
    // Also ensure Ajmal user has correct password for testing
    echo "Updating Ajmal user password for consistency...\n";
    $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
    $result = $stmt->execute([$hashed_password, 'Ajmal']);
    
    if ($result) {
        echo "✓ Ajmal password updated to 'admin123'\n";
    }
    
    echo "\nAll users can now login with password: admin123\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}
?> 