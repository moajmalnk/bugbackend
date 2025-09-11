<?php
// Debug script to test password reset functionality
require_once 'config/database.php';

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Get user data
    $stmt = $pdo->prepare('SELECT id, username, email, password, password_changed_at FROM users WHERE username = ?');
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "User found: " . $user['username'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        echo "Password hash: " . $user['password'] . "\n";
        echo "Password changed at: " . ($user['password_changed_at'] ?? 'NULL') . "\n";
        echo "Hash length: " . strlen($user['password']) . "\n";
        echo "Hash starts with: " . substr($user['password'], 0, 10) . "\n";
        
        // Test password verification with old password
        $old_password = 'Codo@8848';
        $new_password = 'NewPassword123!';
        
        echo "\n--- Testing Password Verification ---\n";
        echo "Testing old password: " . $old_password . "\n";
        $old_verify = password_verify($old_password, $user['password']);
        echo "Old password verification: " . ($old_verify ? 'SUCCESS' : 'FAILED') . "\n";
        
        echo "\nTesting new password: " . $new_password . "\n";
        $new_verify = password_verify($new_password, $user['password']);
        echo "New password verification: " . ($new_verify ? 'SUCCESS' : 'FAILED') . "\n";
        
        // Test creating a new hash
        echo "\n--- Testing Hash Creation ---\n";
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        echo "New hash created: " . $new_hash . "\n";
        echo "New hash length: " . strlen($new_hash) . "\n";
        
        $new_hash_verify = password_verify($new_password, $new_hash);
        echo "New hash verification: " . ($new_hash_verify ? 'SUCCESS' : 'FAILED') . "\n";
        
        // Check if password was recently changed
        if ($user['password_changed_at']) {
            $changed_time = new DateTime($user['password_changed_at']);
            $now = new DateTime();
            $diff = $now->diff($changed_time);
            echo "\nPassword was changed " . $diff->format('%i minutes ago') . "\n";
        }
        
    } else {
        echo "User not found\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
Ajmalnk