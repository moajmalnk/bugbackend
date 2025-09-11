<?php
// Test password reset after fixing the table
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Testing Password Reset After Fix ===\n";
    
    // Get user data
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = ?');
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "User not found\n";
        exit;
    }
    
    echo "User: " . $user['username'] . " (ID: " . $user['id'] . ")\n";
    
    // Create a test reset token
    $test_token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    echo "\n--- Creating test reset token ---\n";
    echo "Token: " . $test_token . "\n";
    echo "Expires: " . $expires_at . "\n";
    
    // Insert the token
    $stmt = $pdo->prepare("
        INSERT INTO password_resets (user_id, email, token, expires_at, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    
    $result = $stmt->execute([
        $user['id'],
        $user['email'],
        $test_token,
        $expires_at
    ]);
    
    if ($result) {
        echo "✓ Test token created successfully\n";
        
        // Now test the reset process
        echo "\n--- Testing reset token lookup ---\n";
        
        $reset_stmt = $pdo->prepare("
            SELECT pr.*, u.id as user_id, u.username, u.email, u.role 
            FROM password_resets pr 
            LEFT JOIN users u ON pr.user_id = u.id 
            WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL
        ");
        
        $reset_stmt->execute([$test_token]);
        $reset_request = $reset_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reset_request) {
            echo "✓ Reset token found!\n";
            echo "User ID: " . $reset_request['user_id'] . "\n";
            echo "Username: " . $reset_request['username'] . "\n";
            echo "Email: " . $reset_request['email'] . "\n";
            
            // Test password update
            echo "\n--- Testing password update ---\n";
            $new_password = 'NewTestPassword123!';
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $update_stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?");
            $update_result = $update_stmt->execute([$hashed_password, $reset_request['user_id']]);
            
            if ($update_result && $update_stmt->rowCount() > 0) {
                echo "✓ Password updated successfully\n";
                
                // Mark token as used
                $mark_stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = ?");
                $mark_stmt->execute([$test_token]);
                echo "✓ Token marked as used\n";
                
                // Test login
                echo "\n--- Testing login with new password ---\n";
                $login_stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
                $login_stmt->execute([$user['username']]);
                $login_user = $login_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($login_user) {
                    $login_verify = password_verify($new_password, $login_user['password']);
                    echo "Login with new password: " . ($login_verify ? 'SUCCESS' : 'FAILED') . "\n";
                    
                    $old_verify = password_verify('TestPassword123!', $login_user['password']);
                    echo "Login with old password: " . ($old_verify ? 'SUCCESS (ERROR!)' : 'FAILED (CORRECT)') . "\n";
                }
                
            } else {
                echo "✗ Password update failed\n";
            }
            
        } else {
            echo "✗ Reset token not found\n";
        }
        
    } else {
        echo "✗ Failed to create test token\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
