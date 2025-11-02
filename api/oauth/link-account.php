<?php
/**
 * Link Google Account to BugRicer User
 * This endpoint links the Google OAuth tokens to the currently logged-in BugRicer user
 */

require_once __DIR__ . '/GoogleOAuthController.php';

header('Content-Type: application/json');

try {
    error_log("=== Link Google Account Endpoint ===");
    
    session_start();
    
    // Check if we have pending OAuth data
    if (!isset($_SESSION['google_oauth_pending'])) {
        throw new Exception('No pending OAuth data found. Please authorize with Google first.');
    }
    
    $oauthData = $_SESSION['google_oauth_pending'];
    
    // Check if data is not too old (5 minutes)
    if (time() - $oauthData['timestamp'] > 300) {
        unset($_SESSION['google_oauth_pending']);
        throw new Exception('OAuth data expired. Please authorize with Google again.');
    }
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // Validate the user's JWT token
    $userData = $oauthController->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated. Please log in first.');
    }
    
    $bugricerUserId = $userData->user_id;
    
    // Check if we have a refresh token
    if (empty($oauthData['refresh_token'])) {
        throw new Exception('No refresh token available. Please re-authorize with Google.');
    }
    
    // Save tokens to database
    $oauthController->saveTokens(
        $oauthData['google_user_id'],
        $bugricerUserId,
        $oauthData['refresh_token'],
        $oauthData['access_token_expiry'],
        $oauthData['email']
    );
    
    // Clear session data
    unset($_SESSION['google_oauth_pending']);
    
    error_log("Successfully linked Google account for user: " . $bugricerUserId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Google account linked successfully',
        'data' => [
            'email' => $oauthData['email'],
            'google_user_id' => $oauthData['google_user_id']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Link account error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
