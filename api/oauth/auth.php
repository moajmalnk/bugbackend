<?php
/**
 * Google OAuth Authorization Endpoint
 * This endpoint initiates the OAuth flow by redirecting users to Google's consent screen
 */

require_once __DIR__ . '/GoogleOAuthController.php';

try {
    error_log("=== Google OAuth Auth Endpoint ===");
    
    // Get state parameter (JWT token) from query string
    $state = $_GET['state'] ?? null;
    if ($state) {
        error_log("Received state parameter: " . substr($state, 0, 20) . "...");
    }
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // Generate authorization URL with state
    $authUrl = $oauthController->getAuthorizationUrl($state);
    
    error_log("Redirecting to Google OAuth: " . $authUrl);
    
    // Redirect user to Google's OAuth consent screen
    header('Location: ' . $authUrl);
    exit();
    
} catch (Exception $e) {
    error_log("OAuth auth error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Redirect to frontend with error
    $frontendUrl = 'http://localhost:8080/docs-setup-error?error=' . urlencode($e->getMessage());
    header('Location: ' . $frontendUrl);
    exit();
}
?>
