<?php
/**
 * Production OAuth Re-authorization Endpoint
 * Forces re-authorization with updated scopes including calendar
 */

require_once __DIR__ . '/GoogleOAuthController.php';

try {
    // Get user ID from query parameter or JWT token
    $bugricerUserId = null;
    
    // Try to get from query parameter first
    if (isset($_GET['user_id'])) {
        $bugricerUserId = $_GET['user_id'];
    } else {
        // Try to get from JWT token
        require_once __DIR__ . '/../BaseAPI.php';
        $api = new BaseAPI();
        $userData = $api->validateToken();
        if ($userData && isset($userData->user_id)) {
            $bugricerUserId = $userData->user_id;
        }
    }
    
    if (!$bugricerUserId) {
        throw new Exception('User ID required. Please provide user_id parameter or valid JWT token.');
    }
    
    echo "Starting OAuth re-authorization for user: $bugricerUserId\n\n";
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // Create state with user ID
    $state = base64_encode(json_encode(['user_id' => $bugricerUserId]));
    
    // Get the authorization URL
    $authUrl = $oauthController->getAuthorizationUrl($state);
    
    echo "Redirecting to Google OAuth with updated scopes...\n";
    echo "URL: " . $authUrl . "\n\n";
    echo "After completing OAuth, your Google account will have the calendar scope for Meet integration.\n";
    
    // Redirect to Google's OAuth consent screen
    header('Location: ' . $authUrl);
    exit();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Please ensure you're logged in and have a valid user ID.\n";
}
?>
