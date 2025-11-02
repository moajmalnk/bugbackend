<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/email.php';
require_once __DIR__ . '/../../utils/validation.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get database connection
$pdo = Database::getInstance()->getConnection();

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $email = trim($input['email'] ?? '');
    
    // Validate email
    if (empty($email)) {
        throw new Exception('Email is required');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    $email = strtolower($email);
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // For security, don't reveal if email exists or not
        echo json_encode([
            'success' => true,
            'message' => 'If an account with this email exists, you will receive a password reset link shortly.'
        ]);
        exit();
    }
    
    // Generate secure reset token
    $reset_token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour
    
    // Store reset token in database
    $stmt = $pdo->prepare("
        INSERT INTO password_resets (user_id, email, token, expires_at, created_at) 
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
        token = VALUES(token), 
        expires_at = VALUES(expires_at), 
        created_at = NOW()
    ");
    
    $stmt->execute([
        $user['id'],
        $email,
        $reset_token,
        $expires_at
    ]);
    
    // Generate reset link based on environment
    $host = $_SERVER['HTTP_HOST'] ?? '';
    
    // Determine if we're in local or production environment
    // Check if backend is running on localhost or production domain
    $isLocal = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);
    
    if ($isLocal) {
        // Local environment: use localhost:8080 for frontend
        $reset_link = 'http://localhost:8080/reset-password?token=' . $reset_token;
    } else {
        // Production environment: use bugs.bugricer.com for frontend
        $reset_link = 'https://bugs.bugricer.com/reset-password?token=' . $reset_token;
    }
    
    error_log("Password Reset: Host detected: $host, isLocal: " . ($isLocal ? 'true' : 'false') . ", reset_link: $reset_link");
    
    // Send email
    $email_sent = sendPasswordResetEmail($user['email'], $user['username'], $reset_link);
    
    if (!$email_sent) {
        error_log("Forgot Password: Email sending failed for {$user['email']}");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send reset email. Please try again later.'
        ]);
        exit();
    }
    
    // Log the password reset request
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, details, ip_address, user_agent, created_at) 
        VALUES (?, 'password_reset_requested', ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $user['id'],
        json_encode(['email' => $email, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'If an account with this email exists, you will receive a password reset link shortly.'
    ]);
    
} catch (Exception $e) {
    error_log("Forgot Password Error: " . $e->getMessage());
    
    // Use 400 for client errors (validation), 500 for server errors
    $isClientError = in_array($e->getMessage(), ['Email is required', 'Invalid email format', 'Invalid JSON input']);
    http_response_code($isClientError ? 400 : 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
