<?php
// Test endpoint for password reset debugging
require_once '../../config/cors.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getInstance()->getConnection();
    
    // Get user data
    $stmt = $pdo->prepare('SELECT id, username, email, password, password_changed_at FROM users WHERE username = ?');
    $stmt->execute(['moajmalnk']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    // Test password verification
    $old_password = 'Codo@8848';
    $new_password = 'NewPassword123!';
    
    $old_verify = password_verify($old_password, $user['password']);
    $new_verify = password_verify($new_password, $user['password']);
    
    echo json_encode([
        'success' => true,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'password_changed_at' => $user['password_changed_at']
        ],
        'password_tests' => [
            'old_password_works' => $old_verify,
            'new_password_works' => $new_verify,
            'hash_length' => strlen($user['password']),
            'hash_starts_with' => substr($user['password'], 0, 10)
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
