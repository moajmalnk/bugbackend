<?php
/**
 * Debug script to check token scopes for a specific user
 */

require_once 'config/database.php';
require_once 'api/oauth/GoogleAuthService.php';

// Get user ID from query parameter
$userId = $_GET['user_id'] ?? null;

if (!$userId) {
    echo "Usage: debug-token-scopes.php?user_id=USER_ID\n";
    echo "Available users:\n";
    
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    $stmt = $pdo->query("SELECT id, username, email FROM users ORDER BY id DESC LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $user) {
        echo "- {$user['id']} ({$user['username']}) - {$user['email']}\n";
    }
    exit;
}

echo "Checking token scopes for user: $userId\n";
echo "=====================================\n\n";

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
    
    echo "User: {$user['username']} ({$user['email']})\n\n";
    
    // Check Google connection
    $stmt = $pdo->prepare('SELECT * FROM google_tokens WHERE bugricer_user_id = ?');
    $stmt->execute([$userId]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tokenData) {
        echo "❌ User is not connected to Google?\n";
        exit;
    }
    
    echo "✅ User is connected to Google\n";
    echo "✅ Access token found\n\n";
    
    // Get Google client for user
    $googleAuthService = new GoogleAuthService();
    $client = $googleAuthService->getClientForUser($userId);
    
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
    echo "Scopes: " . implode(' ', $accessToken['scope'] ?? []) . "\n";
    echo "Expires in: " . ($accessToken['expires_in'] ?? 'unknown') . " seconds\n";
    echo "Audience: " . ($accessToken['audience'] ?? 'unknown') . "\n\n";
    
    // Check for calendar scope
    $scopes = $accessToken['scope'] ?? [];
    $hasCalendarScope = in_array('https://www.googleapis.com/auth/calendar', $scopes);
    
    echo "Scope Analysis:\n";
    echo "Has calendar scope: " . ($hasCalendarScope ? "✅ YES" : "❌ NO") . "\n";
    
    if (!$hasCalendarScope) {
        echo "\n⚠️  WARNING: User's token does not have calendar scope!\n";
        echo "This user needs to re-authorize with the updated scopes.\n";
        echo "Use the 'Re-authorize Google Account' button in the frontend.\n";
    } else {
        echo "\n✅ User's token has the required calendar scope.\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
