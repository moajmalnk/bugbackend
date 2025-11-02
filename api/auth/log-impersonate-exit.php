<?php
require_once __DIR__ . '/../../api/BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $api = new BaseAPI();
    
    // Get the token from Authorization header
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No token provided']);
        exit();
    }
    
    $token = $matches[1];
    
    // Decode the token to get admin_id
    $payload = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $token)[1]))), true);
    
    if (!$payload || $payload['purpose'] !== 'dashboard_access' || !isset($payload['admin_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid impersonation token']);
        exit();
    }
    
    $adminId = $payload['admin_id'];
    $targetUserId = $payload['user_id'];
    $targetUsername = $payload['username'];
    
    // Log the impersonation exit
    $logStmt = $api->getConnection()->prepare(
        "INSERT INTO admin_audit_log (admin_id, action, target_user_id, details, created_at) 
         VALUES (?, ?, ?, ?, NOW())"
    );
    $logStmt->execute([
        $adminId,
        'exit_impersonate_mode',
        $targetUserId,
        json_encode([
            'target_username' => $targetUsername,
            'target_role' => $payload['role'],
            'exited_at' => date('Y-m-d H:i:s')
        ])
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Impersonation exit logged successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Error logging impersonation exit: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?>
