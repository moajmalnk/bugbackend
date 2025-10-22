<?php
/**
 * Direct OAuth re-authorization for admin user
 */

require_once __DIR__ . '/GoogleOAuthController.php';

try {
    $adminUserId = '608dc9d1-26e0-441d-8144-45f74c53a846';
    
    echo "Starting OAuth re-authorization for admin user: $adminUserId\n\n";
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // Create state with admin user ID
    $state = base64_encode(json_encode(['user_id' => $adminUserId]));
    
    // Get the authorization URL
    $authUrl = $oauthController->getAuthorizationUrl($state);
    
    echo "Redirecting to Google OAuth...\n";
    echo "URL: " . $authUrl . "\n\n";
    echo "After completing OAuth, you can test the meeting creation.\n";
    
    // Redirect to Google's OAuth consent screen
    header('Location: ' . $authUrl);
    exit();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
