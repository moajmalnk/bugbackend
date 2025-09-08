<?php
require_once __DIR__ . '/../../api/BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';
use Firebase\JWT\JWT;

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
    
    // Validate token and check admin access
    $decoded = $api->validateToken();
    if ($decoded->role !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only admins can generate dashboard links']);
        exit();
    }
    
    $adminId = $decoded->user_id;
    $data = json_decode(file_get_contents('php://input'), true);
    $targetUserId = $data['userId'] ?? null;
    
    if (!$targetUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID is required']);
        exit();
    }
    
    // Validate target user exists
    $stmt = $api->getConnection()->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Target user not found']);
        exit();
    }
    
    // Generate a secure token for the dashboard link with custom payload
    $issuedAt = time();
    $role = $targetUser['role'];
    
    if ($role === 'admin') {
        $expiration = $issuedAt + (7 * 24 * 60 * 60); // 7 days
    } else {
        $expiration = $issuedAt + (7 * 24 * 60 * 60); // 7 days
    }
    
    // Use the existing JWT generation method but with custom payload
    $payload = [
        'iat' => $issuedAt,
        'exp' => $expiration,
        'user_id' => $targetUserId,
        'username' => $targetUser['username'],
        'role' => $targetUser['role'],
        'admin_id' => $adminId,
        'purpose' => 'dashboard_access'
    ];
    
    // Generate JWT manually since we need custom payload
    $secret = "local_jwt_secret_bugricer_2024"; // Default to local secret
    
    // Check if we're in production environment
    $httpHost = $_SERVER['HTTP_HOST'] ?? '';
    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    $isLocal = false;
    
    $localHosts = ['localhost', '127.0.0.1', '::1'];
    foreach ($localHosts as $localHost) {
        if (strpos($httpHost, $localHost) !== false || strpos($serverName, $localHost) !== false) {
            $isLocal = true;
            break;
        }
    }
    
    if (!$isLocal) {
        $secret = "prod_jwt_secret_bugricer_secure_key_2024";
    }
    
    $token = JWT::encode($payload, $secret, 'HS256');
    
    // Log the admin access for audit purposes
    $logStmt = $api->getConnection()->prepare(
        "INSERT INTO admin_audit_log (admin_id, action, target_user_id, details, created_at) 
         VALUES (?, ?, ?, ?, NOW())"
    );
    $logStmt->execute([
        $adminId,
        'generate_dashboard_link',
        $targetUserId,
        json_encode([
            'target_username' => $targetUser['username'],
            'target_role' => $targetUser['role'],
            'expires_at' => date('Y-m-d H:i:s', $expiration)
        ])
    ]);
    
    // Generate the dashboard URL - construct frontend URL based on current environment
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Determine if we're in development or production
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        // Development - use localhost with role-based routing
        $frontendUrl = 'http://localhost:8080';
    } else {
        // Production - use the bug tracker domain
        $frontendUrl = 'https://bugs.moajmalnk.in';
    }
    
    // Generate role-based dashboard URL
    $dashboardUrl = $frontendUrl . '/' . $targetUser['role'] . '/projects?token=' . urlencode($token);
    
    echo json_encode([
        'success' => true,
        'message' => 'Dashboard link generated successfully',
        'data' => [
            'url' => $dashboardUrl,
            'expires_at' => date('Y-m-d H:i:s', $expiration),
            'ttl_seconds' => 604800
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Error generating dashboard link: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?> 