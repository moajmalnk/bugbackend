<?php
/**
 * Simple token scope checker
 */

require_once 'config/database.php';
require_once 'api/oauth/GoogleAuthService.php';

echo "Simple Token Scope Check\n";
echo "========================\n\n";

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Get all Google tokens
    $stmt = $pdo->query("SELECT * FROM google_tokens ORDER BY created_at DESC LIMIT 5");
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($tokens)) {
        echo "No Google tokens found.\n";
        exit;
    }
    
    echo "Recent Google tokens:\n";
    echo "--------------------\n";
    
    foreach ($tokens as $token) {
        echo "User ID: {$token['bugricer_user_id']}\n";
        echo "Google Email: {$token['email']}\n";
        echo "Created: {$token['created_at']}\n";
        
        // Check token scopes
        try {
            $googleAuthService = new GoogleAuthService();
            $client = $googleAuthService->getClientForUser($token['bugricer_user_id']);
            
            if ($client) {
                $accessToken = $client->getAccessToken();
                if ($accessToken && isset($accessToken['scope'])) {
                    $scopes = $accessToken['scope'];
                    $hasCalendarScope = in_array('https://www.googleapis.com/auth/calendar', $scopes);
                    
                    echo "Calendar scope: " . ($hasCalendarScope ? "✅ YES" : "❌ NO") . "\n";
                    echo "All scopes: " . implode(', ', $scopes) . "\n";
                    
                    if (!$hasCalendarScope) {
                        echo "⚠️  NEEDS RE-AUTH: This user needs to re-authorize for calendar access.\n";
                        echo "   Re-auth URL: https://bugbackend.bugricer.com/api/oauth/production-reauth.php?user_id={$token['bugricer_user_id']}\n";
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
