<?php
require_once '../../config/cors.php';
require_once '../../config/database.php';

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
    
    $token = trim($input['token'] ?? '');
    
    if (empty($token)) {
        throw new Exception('Reset token is required');
    }
    
    // Check if token exists and is valid
    $stmt = $pdo->prepare("
        SELECT pr.*, u.username, u.email, u.role 
        FROM password_resets pr 
        LEFT JOIN users u ON pr.user_id = u.id 
        WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL
    ");
    
    $stmt->execute([$token]);
    $reset_request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reset_request) {
        throw new Exception('Invalid or expired reset token');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Reset token is valid',
        'user' => [
            'username' => $reset_request['username'],
            'email' => $reset_request['email'],
            'role' => $reset_request['role']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Verify Reset Token Error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
