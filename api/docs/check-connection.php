<?php
/**
 * Check Google Docs Connection Status
 * This endpoint checks if the current user has linked their Google account
 */

require_once __DIR__ . '/BugDocsController.php';

header('Content-Type: application/json');

try {
    error_log("=== Check Google Docs Connection Endpoint ===");
    
    // Initialize controller
    $docsController = new BugDocsController();
    
    // Validate user authentication
    $userData = $docsController->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Check if user has Google account linked using GoogleAuthService
    $authService = new GoogleAuthService();
    $hasAccount = $authService->isUserConnected($userId);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'connected' => $hasAccount
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Check connection error: " . $e->getMessage());
    
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

