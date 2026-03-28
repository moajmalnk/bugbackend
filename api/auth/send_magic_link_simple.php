<?php
/**
 * Send Magic Link for Passwordless Authentication (Simplified Version)
 * POST /api/auth/send_magic_link_simple.php
 */

header('Content-Type: application/json');

// Include CORS configuration
require_once __DIR__ . '/../../config/cors.php';

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';
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
    
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || !Utils::userRowIsAllowedLogin($result)) {
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
    
    // Prefer the browser Origin when it is a known frontend (correct port / domain in the email link)
    $magic_link = null;
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
    if ($origin !== '') {
        $parts = parse_url($origin);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $hostPart = isset($parts['host']) ? strtolower($parts['host']) : '';
        $allowed = ($scheme === 'http' || $scheme === 'https')
            && $hostPart !== ''
            && (
                preg_match('/^(localhost|127\.0\.0\.1)$/i', $hostPart)
                || substr($hostPart, -strlen('.bugricer.com')) === '.bugricer.com'
                || substr($hostPart, -strlen('.moajmalnk.in')) === '.moajmalnk.in'
                || $hostPart === 'bugricer.com'
                || $hostPart === 'bugs.bugricer.com'
            );
        if ($allowed) {
            $magic_link = rtrim($origin, '/') . '/login?magic_token=' . $token;
        }
    }
    
    $backendHost = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalBackend = (strpos($backendHost, 'localhost') !== false || strpos($backendHost, '127.0.0.1') !== false);
    if ($magic_link === null) {
        if ($isLocalBackend) {
            $magic_link = 'http://localhost:8080/login?magic_token=' . $token;
        } else {
            $magic_link = 'https://bugs.bugricer.com/login?magic_token=' . $token;
        }
    }
    
    error_log("Magic Link Simple: HTTP_HOST=$backendHost, used_origin=" . ($origin !== '' ? 'yes' : 'no') . ", magic_link: $magic_link");
    
    // Send magic link email
    $email_sent = sendMagicLinkEmail($user['email'], $user['username'], $magic_link);
    
    if (!$email_sent) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send magic link email. The server could not send mail—set SMTP_USER and SMTP_PASS in backend/.env (Gmail: use an App Password).',
        ]);
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
?>
