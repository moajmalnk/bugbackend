<?php
/**
 * Check Google connections for all users
 */

require_once 'config/database.php';
require_once 'api/oauth/GoogleAuthService.php';

echo "Google Connections Status\n";
echo "========================\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Get all users with Google connections
    $stmt = $pdo->query("
        SELECT u.id, u.username, u.email, gt.google_user_id, gt.email as google_email, gt.created_at
        FROM users u 
        INNER JOIN google_tokens gt ON u.id = gt.bugricer_user_id
        ORDER BY gt.created_at DESC
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "No users with Google connections found.\n";
        exit;
    }
    
    echo "Users with Google connections:\n";
    echo "-------------------------------\n";
    
    foreach ($users as $user) {
        echo "User: {$user['username']} ({$user['email']})\n";
        echo "Google: {$user['google_email']}\n";
        echo "Connected: {$user['created_at']}\n";
        echo "User ID: {$user['id']}\n";
        
        // Check token scopes
        try {
            $googleAuthService = new GoogleAuthService();
            $client = $googleAuthService->getClientForUser($user['id']);
            
            if ($client) {
                $accessToken = $client->getAccessToken();
                if ($accessToken && isset($accessToken['scope'])) {
                    $scopes = $accessToken['scope'];
                    $hasCalendarScope = in_array('https://www.googleapis.com/auth/calendar', $scopes);
                    
                    echo "Calendar scope: " . ($hasCalendarScope ? "✅ YES" : "❌ NO") . "\n";
                    
                    if (!$hasCalendarScope) {
                        echo "⚠️  NEEDS RE-AUTH: This user needs to re-authorize for calendar access.\n";
                        echo "   Re-auth URL: https://bugbackend.bugricer.com/api/oauth/production-reauth.php?user_id={$user['id']}\n";
                    }
                } else {
                    echo "❌ No access token available\n";
                }
            } else {
                echo "❌ Failed to create Google client\n";
            }
        } catch (Exception $e) {
            echo "❌ Error checking scopes: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
