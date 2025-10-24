<?php
/**
 * Send Magic Link for Passwordless Authentication (Simplified Version)
 * POST /api/auth/send_magic_link_simple.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/email.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['email']) || empty($input['email'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email is required']);
        exit();
    }
    
    $email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }
    
    // Get database connection
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    // Check if user exists
    $stmt = $db->prepare("SELECT id, username, email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No account found with this email address']);
        exit();
    }
    
    $user = $result;
    
    // Generate magic link token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+15 minutes')); // 15 minutes expiry
    
    // Store magic link in database
    // First, delete any existing magic links for this user to prevent duplicates
    $delete_stmt = $db->prepare("DELETE FROM magic_links WHERE user_id = ?");
    $delete_stmt->execute([(int)$user['id']]);
    
    // Insert new magic link
    $stmt = $db->prepare("
        INSERT INTO magic_links (user_id, token, email, expires_at, created_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    
    if (!$stmt->execute([(int)$user['id'], $token, $email, $expires_at])) {
        throw new Exception("Failed to store magic link token");
    }
    
    // Generate magic link URL based on environment
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    
    // Determine if we're in local or production environment
    $isLocal = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);
    
    if ($isLocal) {
        // Local environment: use localhost:8080 for frontend
        $magic_link = 'http://localhost:8080/login?magic_token=' . $token;
    } else {
        // Production environment: use bugs.bugricer.com for frontend
        $magic_link = 'https://bugs.bugricer.com/login?magic_token=' . $token;
    }
    
    // Send magic link email
    $email_sent = sendMagicLinkEmail($user['email'], $user['username'], $magic_link);
    
    if (!$email_sent) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send magic link email']);
        exit();
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Magic link sent to your email address',
        'expires_in' => 15 // minutes
    ]);
    
} catch (Exception $e) {
    error_log("Magic link error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
}

function sendMagicLinkEmail($email, $username, $magic_link) {
    $subject = "Your Magic Link - BugRicer";
    
    $html_body = "
    <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
      <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
        
        <!-- Header -->
        <div style=\"background-color: #7c3aed; color: #ffffff; padding: 20px; text-align: center;\">
           <h1 style=\"margin: 0; font-size: 24px; display: flex; align-items: center; justify-content: center;\">
            <img src=\"https://fonts.gstatic.com/s/e/notoemoji/16.0/1f41e/32.png\" alt=\"Bug Ricer Logo\" style=\"width: 30px; height: 30px; margin-right: 10px; vertical-align: middle;\">
            BugRicer Magic Link
          </h1>
          <p style=\"margin: 5px 0 0 0; font-size: 16px;\">Passwordless Sign-In</p>
        </div>
        
        <!-- Body -->
        <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
          <h3 style=\"margin-top: 0; color: #1e293b; font-size: 18px;\">Hello $username,</h3>
          <p style=\"white-space: pre-line; margin-bottom: 15px; font-size: 14px;\">You requested a magic link to sign in to your BugRicer account. Click the button below to sign in instantly without a password:</p>
          
          <div style=\"margin: 20px 0; text-align: center;\">
            <a href=\"$magic_link\" style=\"background-color: #7c3aed; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: 500; font-size: 16px;\">✨ Sign In with Magic Link</a>
          </div>
          
          <div style=\"margin-bottom: 15px; padding: 12px; background-color: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;\">
            <p style=\"margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #92400e;\"><strong>⏰ Security Notice:</strong></p>
            <p style=\"margin: 0; font-size: 14px; color: #92400e;\">This magic link will expire in 15 minutes for your security. If you didn't request this link, please ignore this email.</p>
          </div>
          
          <p style=\"font-size: 14px; margin-bottom: 10px;\">If the button doesn't work, you can copy and paste this link into your browser:</p>
          <div style=\"background-color: #f3f4f6; padding: 12px; border-radius: 4px; text-align: center; margin: 15px 0; font-family: 'Courier New', monospace; font-size: 12px; word-break: break-all; color: #374151;\">$magic_link</div>
          
          <p style=\"font-size: 14px; margin-bottom: 0;\">If you have any questions or need assistance, please contact our support team.</p>
          <p style=\"font-size: 14px; margin-bottom: 0;\">Best regards,<br>The BugRicer Team</p>
        </div>
        
        <!-- Footer -->
        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
          <p style=\"margin: 0;\">This is an automated notification from Bug Ricer. Please do not reply to this email.</p>
          <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " Bug Ricer. All rights reserved.</p>
        </div>
        
      </div>
    </div>
    ";
    
    $text_body = "
Magic Link Sign-In - BugRicer

Hello $username,

You requested a magic link to sign in to your BugRicer account.

To sign in instantly, please visit the following link:
$magic_link

This link will expire in 15 minutes for your security.

If you didn't request this magic link, please ignore this email.

Best regards,
The BugRicer Team

© 2025 BugRicer. All rights reserved.
    ";
    
    return sendEmail($email, $subject, $html_body, $text_body);
}
?>
