<?php
// Test script to check production password reset functionality
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Production Password Reset Test ===\n";
    echo "Environment: Production\n";
    echo "Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";
    
    // Get user data
    $stmt = $pdo->prepare('SELECT id, username, email, password, password_changed_at, updated_at FROM users WHERE username = ?');
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "\n--- User Data ---\n";
        echo "User ID: " . $user['id'] . "\n";
        echo "Username: " . $user['username'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        echo "Password hash: " . $user['password'] . "\n";
        echo "Hash length: " . strlen($user['password']) . "\n";
        echo "Password changed at: " . ($user['password_changed_at'] ?? 'NULL') . "\n";
        echo "Updated at: " . $user['updated_at'] . "\n";
        
        // Test password verification
        echo "\n--- Password Verification Tests ---\n";
        
        // Test old password
        $old_password = 'Codo@8848';
        $old_verify = password_verify($old_password, $user['password']);
        echo "Old password ('$old_password') verification: " . ($old_verify ? 'SUCCESS' : 'FAILED') . "\n";
        
        // Test new password
        $new_password = 'NewPassword123!';
        $new_verify = password_verify($new_password, $user['password']);
        echo "New password ('$new_password') verification: " . ($new_verify ? 'SUCCESS' : 'FAILED') . "\n";
        
        // Check recent password resets
        echo "\n--- Recent Password Resets ---\n";
        $stmt = $pdo->prepare("
            SELECT pr.*, u.username 
            FROM password_resets pr 
            LEFT JOIN users u ON pr.user_id = u.id 
            WHERE pr.user_id = ? 
            ORDER BY pr.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$user['id']]);
        $resets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($resets) {
            foreach ($resets as $reset) {
                echo "Reset ID: " . $reset['id'] . "\n";
                echo "  Email: " . $reset['email'] . "\n";
                echo "  Created: " . $reset['created_at'] . "\n";
                echo "  Expires: " . $reset['expires_at'] . "\n";
                echo "  Used: " . ($reset['used_at'] ?? 'NOT USED') . "\n";
                echo "  Token: " . substr($reset['token'], 0, 10) . "...\n";
                echo "  ---\n";
            }
        } else {
            echo "No password resets found for this user.\n";
        }
        
        // Check audit logs
        echo "\n--- Recent Audit Logs ---\n";
        $stmt = $pdo->prepare("
            SELECT action, details, created_at 
            FROM audit_logs 
            WHERE user_id = ? 
            AND (action LIKE '%password%' OR action LIKE '%reset%')
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$user['id']]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($logs) {
            foreach ($logs as $log) {
                echo "Action: " . $log['action'] . "\n";
                echo "Details: " . $log['details'] . "\n";
                echo "Created: " . $log['created_at'] . "\n";
                echo "---\n";
            }
        } else {
            echo "No relevant audit logs found.\n";
        }
        
    } else {
        echo "User 'moajmalnk' not found in database.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
?>
