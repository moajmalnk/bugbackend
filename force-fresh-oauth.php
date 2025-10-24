<?php
/**
 * Force fresh OAuth for admin user - completely clear tokens and force re-auth
 */

require_once 'config/database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Get admin user ID
    $stmt = $pdo->prepare('SELECT id, username FROM users WHERE username = ? AND role = ?');
    $stmt->execute(['moajmalnk', 'admin']);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        echo "Admin user not found\n";
        exit;
    }
    
    echo "Found admin user: {$admin['username']} (ID: {$admin['id']})\n";
    
    // Completely clear existing Google tokens for admin
    $stmt = $pdo->prepare('DELETE FROM google_tokens WHERE bugricer_user_id = ?');
    $result = $stmt->execute([$admin['id']]);
    
    if ($result) {
        echo "✅ Cleared ALL existing Google tokens for admin user\n";
        echo "Admin user will now need to complete fresh OAuth with calendar scope.\n\n";
        
        // Generate fresh OAuth URL
        require_once 'api/oauth/GoogleOAuthController.php';
        
        // Force production environment
        $_SERVER['HTTP_HOST'] = 'bugbackend.bugricer.com';
        
        $oauthController = new GoogleOAuthController();
        $state = base64_encode(json_encode(['user_id' => $admin['id']]));
        $authUrl = $oauthController->getAuthorizationUrl($state);
        
        echo "Fresh OAuth URL with calendar scope:\n";
        echo "=====================================\n";
        echo $authUrl . "\n\n";
        
        echo "Click this URL to complete fresh OAuth with calendar scope.\n";
        echo "This will request ALL required permissions including calendar access.\n";
        
    } else {
        echo "❌ Failed to clear tokens\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
