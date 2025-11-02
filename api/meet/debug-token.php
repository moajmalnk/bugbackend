<?php
/**
 * Debug endpoint to check JWT token and regenerate if needed
 */

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../oauth/GoogleAuthService.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Initialize the API controller
    $api = new BaseAPI();
    
    // Debug: Log the Authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT_FOUND';
    error_log("DEBUG: Authorization header: " . substr($authHeader, 0, 50) . "...");
    
    // Validate JWT token and get user data
    $userData = $api->validateToken();
    if (!$userData || !isset($userData->user_id)) {
        error_log("DEBUG: JWT validation failed - userData: " . json_encode($userData));
        
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or missing authentication token',
            'debug' => [
                'auth_header' => substr($authHeader, 0, 50) . '...',
                'user_data' => $userData
            ]
        ]);
        exit();
    }
    
    $bugricerUserId = $userData->user_id;
    error_log("DEBUG: Authenticated user ID: " . $bugricerUserId);
    
    // Check Google connection
    $googleAuth = new GoogleAuthService();
    $isConnected = $googleAuth->isUserConnected($bugricerUserId);
    
    echo json_encode([
        'success' => true,
        'user_id' => $bugricerUserId,
        'username' => $userData->username ?? 'unknown',
        'role' => $userData->role ?? 'unknown',
        'google_connected' => $isConnected,
        'debug' => [
            'auth_header' => substr($authHeader, 0, 50) . '...',
            'environment' => $_SERVER['HTTP_HOST'] ?? 'unknown'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("DEBUG: Exception in debug-token.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'auth_header' => substr($_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT_FOUND', 0, 50) . '...',
            'environment' => $_SERVER['HTTP_HOST'] ?? 'unknown'
        ]
    ]);
}
?>
