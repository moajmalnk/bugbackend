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
    if (isset($_GET['user_id']) && !empty($_GET['user_id'])) {
        $bugricerUserId = $_GET['user_id'];
        error_log("DEBUG: Using user_id from query parameter: " . $bugricerUserId);
    } else {
        // Try to get from JWT token
        require_once __DIR__ . '/../BaseAPI.php';
        $api = new BaseAPI();
        $userData = $api->validateToken();
        if ($userData && isset($userData->user_id)) {
            $bugricerUserId = $userData->user_id;
            error_log("DEBUG: Using user_id from JWT token: " . $bugricerUserId);
        }
    }
    
    if (!$bugricerUserId) {
        throw new Exception('User ID required. Please provide user_id parameter or valid JWT token.');
    }
    
    // Validate that the user exists in the database
    require_once __DIR__ . '/../../config/database.php';
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
    $stmt->execute([$bugricerUserId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Invalid user ID. User not found in database.');
    }
    
    echo "Starting OAuth re-authorization for user: " . $user['username'] . " (ID: $bugricerUserId)\n\n";
    
    // Force production environment for OAuth
    $_SERVER['HTTP_HOST'] = 'bugbackend.bugricer.com';
    
    // Clear existing tokens to force fresh OAuth
    $stmt = $pdo->prepare('DELETE FROM google_tokens WHERE bugricer_user_id = ?');
    $stmt->execute([$bugricerUserId]);
    echo "✅ Cleared existing tokens to force fresh OAuth\n";
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // Create state with user ID
    $state = base64_encode(json_encode(['user_id' => $bugricerUserId]));
    
    // Get the authorization URL
    $authUrl = $oauthController->getAuthorizationUrl($state);
    
    // Debug: Extract redirect URI from the auth URL
    $parsedUrl = parse_url($authUrl);
    parse_str($parsedUrl['query'], $queryParams);
    $redirectUri = $queryParams['redirect_uri'] ?? 'not found';
    
    echo "Redirecting to Google OAuth with updated scopes...\n";
    echo "Generated redirect URI: " . $redirectUri . "\n";
    echo "Full auth URL: " . $authUrl . "\n\n";
    echo "After completing OAuth, your Google account will have the calendar scope for Meet integration.\n";
    
    // Redirect to Google's OAuth consent screen
    header('Location: ' . $authUrl);
    exit();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Please ensure you're logged in and have a valid user ID.\n";
}
?>
