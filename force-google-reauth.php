<?php
/**
 * Force Google re-authorization for a specific user
 */

require_once 'config/database.php';
require_once 'api/oauth/GoogleOAuthController.php';

// Get user ID from query parameter
$userId = $_GET['user_id'] ?? null;

if (!$userId) {
    echo "Usage: force-google-reauth.php?user_id=USER_ID\n";
    echo "This will force the user to re-authorize with Google to get calendar scope.\n";
    exit;
}

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Get user info
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo "❌ User not found\n";
        exit;
    }
    
    echo "Forcing Google re-authorization for user: {$user['username']} ({$user['email']})\n";
    echo "User ID: {$userId}\n\n";
    
    // Force production environment
    $_SERVER['HTTP_HOST'] = 'bugbackend.bugricer.com';
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // Create state with user ID
    $state = base64_encode(json_encode(['user_id' => $userId]));
    
    // Get the authorization URL
    $authUrl = $oauthController->getAuthorizationUrl($state);
    
    echo "Redirecting to Google OAuth with calendar scope...\n";
    echo "URL: " . $authUrl . "\n\n";
    echo "After completing OAuth, the user will have calendar access for Meet integration.\n";
    
    // Redirect to Google's OAuth consent screen
    header('Location: ' . $authUrl);
    exit();
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
