<?php
require_once '../../config/cors.php';
require_once '../../config/database.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}
require_once '../../utils/validation.php';

// Get database connection
$pdo = Database::getInstance()->getConnection();

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $token = trim($input['token'] ?? '');
    $new_password = trim($input['password'] ?? '');
    $confirm_password = trim($input['confirm_password'] ?? '');
    
    // Validate inputs
    if (empty($token)) {
        throw new Exception('Reset token is required');
    }
    
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
    
    // Check if token exists and is valid
    $stmt = $pdo->prepare("
        SELECT pr.*, u.id as user_id, u.username, u.email, u.role 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL
    ");
    
    $stmt->execute([$token]);
    $reset_request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reset_request) {
        throw new Exception('Invalid or expired reset token');
    }
    
    // Additional validation - check if user actually exists
    $user_check_stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id = ?");
    $user_check_stmt->execute([$reset_request['user_id']]);
    $user_exists = $user_check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_exists) {
        error_log("Password Reset Debug - User not found for ID: " . $reset_request['user_id']);
        throw new Exception('User not found for reset token');
    }
    
    error_log("Password Reset Debug - User found: " . $user_exists['username'] . " (ID: " . $user_exists['id'] . ")");
    
    // Hash the new password
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    // Debug logging
    error_log("Password Reset Debug - User ID: " . $reset_request['user_id']);
    error_log("Password Reset Debug - New password: " . $new_password);
    error_log("Password Reset Debug - New hash: " . $hashed_password);
    error_log("Password Reset Debug - Hash length: " . strlen($hashed_password));
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Update user password
        $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$hashed_password, $reset_request['user_id']]);
        
        // Debug logging for password update
        error_log("Password Reset Debug - Update result: " . ($result ? 'SUCCESS' : 'FAILED'));
        error_log("Password Reset Debug - Rows affected: " . $stmt->rowCount());
        error_log("Password Reset Debug - User ID being updated: " . $reset_request['user_id']);
        error_log("Password Reset Debug - New password hash: " . $hashed_password);
        
        // Check if the update actually worked
        if ($stmt->rowCount() === 0) {
            throw new Exception("Password update failed - no rows were affected. User ID: " . $reset_request['user_id']);
        }
        
        // Verify the password was actually updated
        $verify_stmt = $pdo->prepare("SELECT password, password_changed_at FROM users WHERE id = ?");
        $verify_stmt->execute([$reset_request['user_id']]);
        $updated_user = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($updated_user) {
            error_log("Password Reset Debug - Updated password hash: " . $updated_user['password']);
            error_log("Password Reset Debug - Password changed at: " . $updated_user['password_changed_at']);
            
            // Test if the new password works
            $test_verify = password_verify($new_password, $updated_user['password']);
            error_log("Password Reset Debug - New password verification test: " . ($test_verify ? 'SUCCESS' : 'FAILED'));
        } else {
            error_log("Password Reset Debug - ERROR: Could not verify password update");
        }
        
        // Mark reset token as used
        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = ?");
        $stmt->execute([$token]);
        
        // Log the password reset
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at) 
            VALUES (?, 'password_reset_completed', ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $reset_request['user_id'],
            json_encode(['email' => $reset_request['email'], 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Password has been reset successfully. You can now log in with your new password.'
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Reset Password Error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
