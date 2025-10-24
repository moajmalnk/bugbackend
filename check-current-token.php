<?php
/**
 * Check current token scopes for admin user
 */

require_once 'config/database.php';
require_once 'api/oauth/GoogleAuthService.php';

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
    
    echo "Checking token scopes for admin user: {$admin['username']} (ID: {$admin['id']})\n";
    echo "================================================================\n\n";
    
    // Check if user has Google tokens
    $stmt = $pdo->prepare('SELECT * FROM google_tokens WHERE bugricer_user_id = ?');
    $stmt->execute([$admin['id']]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tokenData) {
        echo "❌ Admin user has NO Google tokens - needs fresh OAuth\n";
        echo "Fresh OAuth URL: https://bugbackend.bugricer.com/force-fresh-oauth.php\n";
        exit;
    }
    
    echo "✅ Admin user has Google tokens\n";
    echo "Google Email: {$tokenData['email']}\n";
    echo "Created: {$tokenData['created_at']}\n\n";
    
    // Get Google client and check scopes
    $googleAuthService = new GoogleAuthService();
    $client = $googleAuthService->getClientForUser($admin['id']);
    
    if (!$client) {
        echo "❌ Failed to create Google client\n";
        exit;
    }
    
    // Get access token
    $accessToken = $client->getAccessToken();
    
    if (!$accessToken) {
        echo "❌ No access token available\n";
        exit;
    }
    
    echo "Token Information:\n";
    echo "------------------\n";
    echo "Scopes: " . implode(', ', $accessToken['scope'] ?? []) . "\n";
    echo "Expires in: " . ($accessToken['expires_in'] ?? 'unknown') . " seconds\n";
    echo "Audience: " . ($accessToken['audience'] ?? 'unknown') . "\n\n";
    
    // Check for calendar scope
    $scopes = $accessToken['scope'] ?? [];
    $hasCalendarScope = in_array('https://www.googleapis.com/auth/calendar', $scopes);
    
    echo "Scope Analysis:\n";
    echo "---------------\n";
    echo "Has calendar scope: " . ($hasCalendarScope ? "✅ YES" : "❌ NO") . "\n";
    
    if (!$hasCalendarScope) {
        echo "\n⚠️  PROBLEM IDENTIFIED: Admin's token does NOT have calendar scope!\n";
        echo "This is why you're getting 'ACCESS_TOKEN_SCOPE_INSUFFICIENT' errors.\n\n";
        echo "SOLUTION: Complete fresh OAuth with calendar scope\n";
        echo "URL: https://bugbackend.bugricer.com/force-fresh-oauth.php\n";
    } else {
        echo "\n✅ Admin's token HAS the required calendar scope.\n";
        echo "If you're still getting errors, there might be another issue.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
