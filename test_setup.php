<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/utils.php';

echo "Testing Database Connection and User Setup\n";
echo "==========================================\n\n";

try {
    // Test database connection
    echo "1. Testing database connection...\n";
    $database = new Database();
    $conn = $database->getConnection();
    
    if ($conn) {
        echo "✓ Database connected successfully!\n\n";
        
        // Check if users table exists
        echo "2. Checking if users table exists...\n";
        $stmt = $conn->query("SHOW TABLES LIKE 'users'");
        
        if ($stmt->rowCount() > 0) {
            echo "✓ Users table exists!\n\n";
            
            // Check existing users
            echo "3. Checking existing users...\n";
            $stmt = $conn->query("SELECT username, email, role FROM users");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($users) > 0) {
                echo "Existing users:\n";
                foreach ($users as $user) {
                    echo "  - {$user['username']} ({$user['email']}) - {$user['role']}\n";
                }
            } else {
                echo "No users found. Creating test user...\n";
                
                // Create test user
                $username = 'admin';
                $email = 'admin@bugricer.com';
                $password = 'admin123';
                $role = 'admin';
                
                $user_id = Utils::generateUUID();
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
                    echo "✓ Test user created successfully!\n";
                    echo "  Username: admin\n";
                    echo "  Password: admin123\n";
                    echo "  Email: admin@bugricer.com\n";
                    echo "  Role: admin\n";
                } else {
                    echo "✗ Failed to create test user\n";
                }
            }
            
        } else {
            echo "✗ Users table does not exist. Please run the database setup script.\n";
        }
        
    } else {
        echo "✗ Database connection failed!\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nTest completed.\n";
?> 