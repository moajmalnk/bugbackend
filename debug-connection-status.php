<?php
/**
 * Debug endpoint to check Google Docs connection status in detail
 */

require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/api/BaseAPI.php';
require_once __DIR__ . '/api/oauth/GoogleAuthService.php';

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
    
    // Check database connection
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Check if user has tokens in database
    $stmt = $conn->prepare("SELECT * FROM google_tokens WHERE bugricer_user_id = ?");
    $stmt->execute([$userId]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check using GoogleAuthService
    $authService = new GoogleAuthService();
    $isConnected = $authService->isUserConnected($userId);
    
    // Try to get client (this will test if tokens are valid)
    $clientValid = false;
    $clientError = null;
    try {
        $client = $authService->getClientForUser($userId);
        $clientValid = true;
    } catch (Exception $e) {
        $clientError = $e->getMessage();
    }
    
    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'tokens_in_db' => count($tokens),
        'tokens_data' => $tokens,
        'is_connected' => $isConnected,
        'client_valid' => $clientValid,
        'client_error' => $clientError,
        'all_tokens_count' => $conn->query("SELECT COUNT(*) as count FROM google_tokens")->fetch()['count']
    ]);
    
} catch (Exception $e) {
    error_log("Debug connection status error: " . $e->getMessage());
    
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
