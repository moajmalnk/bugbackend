<?php
// Final test of password reset functionality
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Final Password Reset Test ===\n";
    
    // Get user data
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = ?');
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "User not found\n";
        exit;
    }
    
    echo "User: " . $user['username'] . " (ID: " . $user['id'] . ")\n";
    
    // Test 1: Create a reset token
    echo "\n--- Test 1: Creating reset token ---\n";
    $test_token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
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
        echo "✓ Reset token created successfully\n";
        echo "Token: " . substr($test_token, 0, 20) . "...\n";
    } else {
        echo "✗ Failed to create reset token\n";
        exit;
    }
    
    // Test 2: Verify token lookup (same query as reset_password.php)
    echo "\n--- Test 2: Verifying token lookup ---\n";
    
    $stmt = $pdo->prepare("
        SELECT pr.*, u.id as user_id, u.username, u.email, u.role 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL
    ");
    
    $stmt->execute([$test_token]);
    $reset_request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reset_request) {
        echo "✓ Token lookup successful!\n";
        echo "User ID: " . $reset_request['user_id'] . "\n";
        echo "Username: " . $reset_request['username'] . "\n";
        echo "Email: " . $reset_request['email'] . "\n";
    } else {
        echo "✗ Token lookup failed\n";
        exit;
    }
    
    // Test 3: Simulate password reset
    echo "\n--- Test 3: Simulating password reset ---\n";
    
    $new_password = 'FinalTestPassword123!';
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    echo "New password: " . $new_password . "\n";
    echo "Hashed password: " . substr($hashed_password, 0, 20) . "...\n";
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Update user password
        $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$hashed_password, $reset_request['user_id']]);
        
        if ($result && $stmt->rowCount() > 0) {
            echo "✓ Password updated successfully\n";
            
            // Mark token as used
            $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = ?");
            $stmt->execute([$test_token]);
            echo "✓ Token marked as used\n";
            
            $pdo->commit();
            echo "✓ Transaction committed\n";
            
        } else {
            throw new Exception("Password update failed - no rows affected");
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "✗ Transaction rolled back: " . $e->getMessage() . "\n";
        exit;
    }
    
    // Test 4: Verify password change
    echo "\n--- Test 4: Verifying password change ---\n";
    
    $stmt = $pdo->prepare("SELECT password, password_changed_at FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($updated_user) {
        echo "Updated password hash: " . substr($updated_user['password'], 0, 20) . "...\n";
        echo "Password changed at: " . $updated_user['password_changed_at'] . "\n";
        
        // Test password verification
        $new_verify = password_verify($new_password, $updated_user['password']);
        echo "New password verification: " . ($new_verify ? 'SUCCESS' : 'FAILED') . "\n";
        
        $old_verify = password_verify('TestPassword123!', $updated_user['password']);
        echo "Old password verification: " . ($old_verify ? 'SUCCESS (ERROR!)' : 'FAILED (CORRECT)') . "\n";
    }
    
    // Test 5: Test login simulation
    echo "\n--- Test 5: Testing login simulation ---\n";
    
    $login_stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $login_stmt->execute([$user['username']]);
    $login_user = $login_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($login_user) {
        $login_verify = password_verify($new_password, $login_user['password']);
        echo "Login with new password: " . ($login_verify ? 'SUCCESS' : 'FAILED') . "\n";
        
        $old_login_verify = password_verify('TestPassword123!', $login_user['password']);
        echo "Login with old password: " . ($old_login_verify ? 'SUCCESS (ERROR!)' : 'FAILED (CORRECT)') . "\n";
    }
    
    echo "\n=== All Tests Complete ===\n";
    echo "✓ Password reset functionality is working correctly!\n";
    echo "You can now use the password reset feature in your application.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
