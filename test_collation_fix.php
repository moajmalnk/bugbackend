<?php
// Test collation fix
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Testing Collation Fix ===\n";
    
    // Check current collations
    echo "--- Current collations ---\n";
    
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
    $users_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Users table: " . $users_collation . "\n";
    
    $stmt = $pdo->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets'");
    $password_resets_collation = $stmt->fetch(PDO::FETCH_ASSOC)['TABLE_COLLATION'];
    echo "Password_resets table: " . $password_resets_collation . "\n";
    
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'");
    $users_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Users.id: " . $users_id_collation . "\n";
    
    $stmt = $pdo->query("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets' AND COLUMN_NAME = 'user_id'");
    $password_resets_user_id_collation = $stmt->fetch(PDO::FETCH_ASSOC)['COLLATION_NAME'];
    echo "Password_resets.user_id: " . $password_resets_user_id_collation . "\n";
    
    // Test the join query
    echo "\n--- Testing join query ---\n";
    
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.user_id, u.username, u.email 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        WHERE pr.email = ? 
        LIMIT 1
    ");
    
    $stmt->execute(['moajmalnk@gmail.com']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "✓ Join query successful!\n";
        echo "Token ID: " . $result['id'] . "\n";
        echo "User ID: " . $result['user_id'] . "\n";
        echo "Username: " . $result['username'] . "\n";
        echo "Email: " . $result['email'] . "\n";
    } else {
        echo "✗ Join query failed\n";
    }
    
    // Test password reset query
    echo "\n--- Testing password reset query ---\n";
    
    $stmt = $pdo->prepare("SELECT token FROM password_resets WHERE email = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['moajmalnk@gmail.com']);
    $test_token = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($test_token) {
        $token = $test_token['token'];
        echo "Testing with token: " . substr($token, 0, 20) . "...\n";
        
        $reset_stmt = $pdo->prepare("
            SELECT pr.*, u.id as user_id, u.username, u.email, u.role 
            FROM password_resets pr 
            LEFT JOIN users u ON pr.user_id = u.id 
            WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL
        ");
        
        $reset_stmt->execute([$token]);
        $reset_request = $reset_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reset_request) {
            echo "✓ Reset token lookup successful!\n";
            echo "User ID: " . $reset_request['user_id'] . "\n";
            echo "Username: " . $reset_request['username'] . "\n";
            echo "Email: " . $reset_request['email'] . "\n";
        } else {
            echo "✗ Reset token lookup failed\n";
        }
    } else {
        echo "No test token found\n";
    }
    
    echo "\n=== Test Complete ===\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
