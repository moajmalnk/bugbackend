<?php
/**
 * Disconnect Google Account Endpoint
 * This endpoint disconnects the user's Google account by revoking tokens and deleting from database
 */

require_once __DIR__ . '/GoogleAuthService.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

try {
    error_log("=== Google Account Disconnect Endpoint ===");
    
    // Initialize BaseAPI for authentication
    $baseAPI = new BaseAPI();
    
    // Validate user authentication
    $userData = $baseAPI->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    error_log("Disconnecting Google account for user: " . $userId);
    
    // Initialize GoogleAuthService
    $authService = new GoogleAuthService();
    
    // Try to revoke token (may fail if token already expired/invalid, but that's okay)
    try {
        $client = $authService->getClientForUser($userId);
        $client->revokeToken();
        error_log("Successfully revoked Google token for user: " . $userId);
    } catch (Exception $revokeException) {
        // If revocation fails (e.g., token already expired), continue to delete from database
        error_log("Token revocation failed (may already be expired): " . $revokeException->getMessage());
        error_log("Proceeding to delete tokens from database anyway");
    }
    
    // Delete tokens from database
    require_once __DIR__ . '/../../config/database.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("DELETE FROM google_tokens WHERE bugricer_user_id = ?");
    $stmt->execute([$userId]);
    $deletedRows = $stmt->rowCount();
    
    error_log("Deleted {$deletedRows} token record(s) from database for user: " . $userId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Google account disconnected successfully',
        'data' => [
            'deleted_tokens' => $deletedRows
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Disconnect error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

