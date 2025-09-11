<?php
// Debug script to check password reset tokens
require_once 'config/database.php';

try {
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugs.moajmalnk.in';
    
    $pdo = Database::getInstance()->getConnection();
    
    echo "=== Password Reset Tokens Debug ===\n";
    
    // Get user data
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = ?');
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "User not found\n";
        exit;
    }
    
    echo "User: " . $user['username'] . " (ID: " . $user['id'] . ")\n";
    
    // Check password reset tokens for this user
    $stmt = $pdo->prepare("
        SELECT pr.*, u.username, u.email 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        WHERE pr.user_id = ? 
        ORDER BY pr.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute([$user['id']]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n--- Recent Password Reset Tokens ---\n";
    if ($tokens) {
        foreach ($tokens as $token) {
            echo "Token ID: " . $token['id'] . "\n";
            echo "  User ID: " . $token['user_id'] . "\n";
            echo "  Username: " . ($token['username'] ?? 'NULL') . "\n";
            echo "  Email: " . $token['email'] . "\n";
            echo "  Token: " . substr($token['token'], 0, 20) . "...\n";
            echo "  Created: " . $token['created_at'] . "\n";
            echo "  Expires: " . $token['expires_at'] . "\n";
            echo "  Used: " . ($token['used_at'] ?? 'NOT USED') . "\n";
            echo "  ---\n";
        }
    } else {
        echo "No password reset tokens found for this user.\n";
    }
    
    // Check if there are any tokens with NULL user_id
    $stmt = $pdo->prepare("
        SELECT pr.*, u.username, u.email 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        WHERE pr.email = ? 
        ORDER BY pr.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user['email']]);
    $email_tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n--- Tokens by Email ---\n";
    if ($email_tokens) {
        foreach ($email_tokens as $token) {
            echo "Token ID: " . $token['id'] . "\n";
            echo "  User ID: " . ($token['user_id'] ?? 'NULL') . "\n";
            echo "  Username: " . ($token['username'] ?? 'NULL') . "\n";
            echo "  Email: " . $token['email'] . "\n";
            echo "  Token: " . substr($token['token'], 0, 20) . "...\n";
            echo "  Created: " . $token['created_at'] . "\n";
            echo "  ---\n";
        }
    } else {
        echo "No tokens found for email: " . $user['email'] . "\n";
    }
    
    // Test creating a new token
    echo "\n--- Testing Token Creation ---\n";
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
        echo "Test token created successfully\n";
        echo "Token: " . $test_token . "\n";
        
        // Now test the reset process with this token
        echo "\n--- Testing Reset Process with New Token ---\n";
        
        $reset_stmt = $pdo->prepare("
            SELECT pr.*, u.id as user_id, u.username, u.email, u.role 
            FROM password_resets pr 
            LEFT JOIN users u ON pr.user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci 
            WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL
        ");
        
        $reset_stmt->execute([$test_token]);
        $reset_request = $reset_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reset_request) {
            echo "Reset token found: " . $reset_request['username'] . "\n";
            echo "User ID: " . $reset_request['user_id'] . "\n";
        } else {
            echo "Reset token not found or expired\n";
        }
        
    } else {
        echo "Failed to create test token\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
