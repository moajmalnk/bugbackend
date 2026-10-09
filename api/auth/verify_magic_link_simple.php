<?php
/**
 * Verify Magic Link Token for Passwordless Authentication (Simplified Version)
 * POST /api/auth/verify_magic_link_simple.php
 */

header('Content-Type: application/json');

// Include CORS configuration
require_once __DIR__ . '/../../config/cors.php';

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../utils/magic_links.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $token = isset($input['token']) && is_string($input['token']) ? trim($input['token']) : '';
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Magic link token is required']);
        exit();
    }
    // Tokens are bin2hex(random_bytes(32)); anything else can never match.
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired magic link']);
        exit();
    }
    
    // Get database connection
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    br_ensure_magic_links_schema($db);
    
    $stmt = $db->prepare("
        SELECT user_id FROM magic_links
        WHERE token = ? AND expires_at > NOW() AND used_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $magic_link = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$magic_link) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired magic link']);
        exit();
    }

    $userId = (string) $magic_link['user_id'];
    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$userRow) {
        // Links issued before the UUID fix point at no user; ask for a fresh one.
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This magic link is no longer valid. Please request a new one.']);
        exit();
    }
    if (!Utils::userRowIsAllowedLogin($userRow)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This account is no longer active',
            'error_code' => 'ACCOUNT_REVOKED',
        ]);
        exit();
    }
    
    // Claim atomically so a double-click or a replayed link signs in only once.
    $update_stmt = $db->prepare("UPDATE magic_links SET used_at = NOW() WHERE token = ? AND used_at IS NULL");
    $update_stmt->execute([$token]);
    if ($update_stmt->rowCount() !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired magic link']);
        exit();
    }
    
    $jwt_token = Utils::generateJWT($userRow['id'], $userRow['username'], $userRow['role']);
    
    logActivity($userRow['id'], 'magic_link_login', 'User signed in with magic link');
    
    echo json_encode([
        'success' => true,
        'message' => 'Magic link verified successfully',
        'token' => $jwt_token,
        'user' => [
            'id' => $userRow['id'],
            'username' => $userRow['username'],
            'email' => $userRow['email'],
            'role' => $userRow['role']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Magic link verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not verify the magic link. Please try again.']);
}


function logActivity($user_id, $activity_type, $description) {
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("
            INSERT INTO activity_log (user_id, activity_type, description, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $activity_type, $description]);
    } catch (Exception $e) {
        error_log("Failed to log magic link activity: " . $e->getMessage());
    }
}
?>
