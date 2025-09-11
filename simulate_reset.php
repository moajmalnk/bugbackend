<?php
// Simulate the exact password reset flow
require_once 'config/database.php';
require_once 'utils/validation.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Simulating Password Reset Flow ===\n";
    
    // Step 1: Get user data
    $stmt = $pdo->prepare('SELECT id, username, email, password FROM users WHERE username = ?');
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "User not found\n";
        exit;
    }
    
    echo "Step 1 - User found: " . $user['username'] . "\n";
    echo "Current password hash: " . $user['password'] . "\n";
    
    // Step 2: Simulate password reset input
    $new_password = 'NewPassword123!';
    $confirm_password = 'NewPassword123!';
    
    echo "\nStep 2 - Password reset input:\n";
    echo "New password: " . $new_password . "\n";
    echo "Confirm password: " . $confirm_password . "\n";
    
    // Step 3: Validate inputs (same as reset_password.php)
    if (empty($new_password)) {
        throw new Exception('New password is required');
    }
    
    if (empty($confirm_password)) {
        throw new Exception('Password confirmation is required');
    }
    
    if ($new_password !== $confirm_password) {
        throw new Exception('Passwords do not match');
    }
    
    if (!validatePassword($new_password)) {
        throw new Exception('Password must be at least 8 characters long and contain at least one uppercase letter, one lowercase letter, and one number');
    }
    
    echo "Step 3 - Validation passed\n";
    
    // Step 4: Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    echo "\nStep 4 - Password hashed:\n";
    echo "New hash: " . $hashed_password . "\n";
    echo "Hash length: " . strlen($hashed_password) . "\n";
    
    // Step 5: Start transaction and update password
    $pdo->beginTransaction();
    
    try {
        echo "\nStep 5 - Updating password in database...\n";
        
        $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$hashed_password, $user['id']]);
        
        echo "Update result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Rows affected: " . $stmt->rowCount() . "\n";
        
        // Step 6: Verify the update
        $verify_stmt = $pdo->prepare("SELECT password, password_changed_at FROM users WHERE id = ?");
        $verify_stmt->execute([$user['id']]);
        $updated_user = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($updated_user) {
            echo "\nStep 6 - Verification:\n";
            echo "Updated password hash: " . $updated_user['password'] . "\n";
            echo "Password changed at: " . $updated_user['password_changed_at'] . "\n";
            
            // Step 7: Test password verification
            echo "\nStep 7 - Password verification tests:\n";
            
            $new_verify = password_verify($new_password, $updated_user['password']);
            echo "New password verification: " . ($new_verify ? 'SUCCESS' : 'FAILED') . "\n";
            
            $old_verify = password_verify('Codo@8848', $updated_user['password']);
            echo "Old password verification: " . ($old_verify ? 'SUCCESS (ERROR!)' : 'FAILED (CORRECT)') . "\n";
            
            // Step 8: Test login simulation
            echo "\nStep 8 - Login simulation:\n";
            
            // Simulate the login process
            $login_stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $login_stmt->execute(['moajmalnk']);
            $login_user = $login_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($login_user) {
                $login_verify = password_verify($new_password, $login_user['password']);
                echo "Login with new password: " . ($login_verify ? 'SUCCESS' : 'FAILED') . "\n";
                
                $old_login_verify = password_verify('Codo@8848', $login_user['password']);
                echo "Login with old password: " . ($old_login_verify ? 'SUCCESS (ERROR!)' : 'FAILED (CORRECT)') . "\n";
            }
        }
        
        $pdo->commit();
        echo "\nTransaction committed successfully\n";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Transaction rolled back: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
