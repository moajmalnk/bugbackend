<?php
/**
 * Debug endpoint to check current user's Google tokens
 */

require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/api/BaseAPI.php';

header('Content-Type: application/json');

try {
    // Initialize BaseAPI to validate token
    $api = new BaseAPI();
    
    // Get the current user
    $userData = $api->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'user_data' => $userData
    ]);
    
} catch (Exception $e) {
    error_log("Debug user tokens error: " . $e->getMessage());
    
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>