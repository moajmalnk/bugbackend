<?php
// Manual password reset test
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Manual Password Reset Test ===\n";
    
    // Get user data
    $stmt = $pdo->prepare('SELECT id, username, email, password FROM users WHERE username = ?');
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "User not found\n";
        exit;
    }
    
    echo "User: " . $user['username'] . " (ID: " . $user['id'] . ")\n";
    echo "Current password hash: " . $user['password'] . "\n";
    
    // Test new password
    $new_password = 'TestPassword123!';
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    echo "\nNew password: " . $new_password . "\n";
    echo "New hash: " . $hashed_password . "\n";
    
    // Test direct update without transaction
    echo "\n--- Testing Direct Update ---\n";
    
    $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$hashed_password, $user['id']]);
    
    echo "Update result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
    echo "Rows affected: " . $stmt->rowCount() . "\n";
    
    if ($stmt->rowCount() > 0) {
        // Verify the update
        $verify_stmt = $pdo->prepare("SELECT password, password_changed_at FROM users WHERE id = ?");
        $verify_stmt->execute([$user['id']]);
        $updated_user = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($updated_user) {
            echo "\nVerification:\n";
            echo "Updated password hash: " . $updated_user['password'] . "\n";
            echo "Password changed at: " . $updated_user['password_changed_at'] . "\n";
            
            // Test password verification
            $verify_result = password_verify($new_password, $updated_user['password']);
            echo "New password verification: " . ($verify_result ? 'SUCCESS' : 'FAILED') . "\n";
            
            $old_verify = password_verify('Codo@8848', $updated_user['password']);
            echo "Old password verification: " . ($old_verify ? 'SUCCESS (ERROR!)' : 'FAILED (CORRECT)') . "\n";
        }
    } else {
        echo "ERROR: No rows were affected by the update!\n";
        
        // Check if user ID exists
        $check_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE id = ?");
        $check_stmt->execute([$user['id']]);
        $count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        echo "User count with ID " . $user['id'] . ": " . $count . "\n";
        
        // Try updating by username instead
        echo "\n--- Trying update by username ---\n";
        $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE username = ?");
        $result = $stmt->execute([$hashed_password, $user['username']]);
        
        echo "Update by username result: " . ($result ? 'SUCCESS' : 'FAILED') . "\n";
        echo "Rows affected: " . $stmt->rowCount() . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
