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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['token']) || empty($input['token'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Magic link token is required']);
        exit();
    }
    
    $token = filter_var($input['token'], FILTER_SANITIZE_STRING);
    
    // Get database connection
    $db = getDBConnection();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    // Verify magic link token
    $stmt = $db->prepare("
        SELECT ml.*, u.id, u.username, u.email, u.role 
        FROM magic_links ml 
        JOIN users u ON ml.user_id = u.id 
        WHERE ml.token = ? AND ml.expires_at > NOW() AND ml.used_at IS NULL
    ");
    $stmt->execute([$token]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired magic link']);
        exit();
    }
    
    $magic_link = $result;
    
    // Mark magic link as used
    $update_stmt = $db->prepare("UPDATE magic_links SET used_at = NOW() WHERE token = ?");
    $update_stmt->execute([$token]);
    
    // Generate JWT token for the user using the proper Utils class
    $jwt_token = Utils::generateJWT($magic_link['user_id'], $magic_link['username'], $magic_link['role']);
    
    // Log successful magic link authentication
    logActivity($magic_link['user_id'], 'magic_link_login', 'User signed in with magic link');
    
    echo json_encode([
        'success' => true,
        'message' => 'Magic link verified successfully',
        'token' => $jwt_token,
        'user' => [
            'id' => $magic_link['id'],
            'username' => $magic_link['username'],
            'email' => $magic_link['email'],
            'role' => $magic_link['role']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Magic link verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error: ' . $e->getMessage()]);
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
