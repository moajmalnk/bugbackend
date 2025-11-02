<?php
/**
 * OAuth re-authorization endpoint for local development
 * Accepts user_id as query parameter and redirects back to local frontend
 */

require_once __DIR__ . '/GoogleOAuthController.php';
require_once __DIR__ . '/../BaseAPI.php';

try {
    error_log("=== Admin Re-auth Endpoint (Local) ===");
    
    // Get user_id from query parameter
    $userId = $_GET['user_id'] ?? null;
    
    if (!$userId) {
        // If no user_id provided, try to get from JWT token in Authorization header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.*)/', $authHeader, $matches)) {
            $token = $matches[1];
            try {
                $api = new BaseAPI();
                $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
                $userData = $api->validateToken();
                if ($userData && isset($userData->user_id)) {
                    $userId = $userData->user_id;
                }
            } catch (Exception $e) {
                error_log("Failed to validate token: " . $e->getMessage());
            }
        }
    }
    
    if (!$userId) {
        throw new Exception('User ID is required. Please provide user_id parameter or valid JWT token.');
    }
    
    error_log("Re-authorizing Google account for user: " . $userId);
    error_log("Current HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'not set'));
    error_log("Current REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set'));
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // CRITICAL: Force local redirect URI for admin-reauth endpoint
    // This ensures Google redirects to local callback even if HTTP_HOST is wrong
    $localRedirectUri = 'http://localhost/BugRicer/backend/api/oauth/callback';
    $oauthController->setRedirectUri($localRedirectUri);
    error_log("FORCED redirect URI to local: " . $localRedirectUri);
    
    // Verify the redirect URI was actually set
    $actualRedirectUri = $oauthController->getClient()->getRedirectUri();
    error_log("VERIFIED redirect URI after setting: " . $actualRedirectUri);
    if ($actualRedirectUri !== $localRedirectUri) {
        error_log("ERROR: Redirect URI mismatch! Expected: " . $localRedirectUri . ", Got: " . $actualRedirectUri);
    }
    
    // Get JWT token to pass as state (for user authentication after OAuth)
    $token = $_GET['token'] ?? null;
    if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        preg_match('/Bearer\s+(.*)/', $_SERVER['HTTP_AUTHORIZATION'], $matches);
        $token = $matches[1] ?? null;
    }
    
    // Get return_url from query parameter, or construct based on user role
    $returnUrl = $_GET['return_url'] ?? null;
    if (!$returnUrl) {
        // Determine user role to construct return_url
        $userRole = 'admin'; // default
        try {
            require_once __DIR__ . '/../../config/database.php';
            $db = Database::getInstance();
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($userRow && isset($userRow['role'])) {
                $userRole = $userRow['role'];
            }
        } catch (Exception $e) {
            error_log("Failed to get user role: " . $e->getMessage());
        }
        
        // Construct return_url based on role
        $rolePath = 'admin';
        if ($userRole === 'admin') {
            $rolePath = 'admin';
        } elseif ($userRole === 'tester') {
            $rolePath = 'tester';
        } elseif ($userRole === 'developer') {
            $rolePath = 'developer';
        }
        $returnUrl = "http://localhost:8080/{$rolePath}/meet";
        error_log("Constructed return_url based on role ({$userRole}): " . $returnUrl);
    }
    
    // Use JWT token as state if available, otherwise encode user_id with return URL
    if ($token) {
        // Encode both JWT token and return_url in state
        $state = base64_encode(json_encode([
            'jwt_token' => $token,
            'user_id' => $userId,
            'return_url' => $returnUrl
        ]));
        error_log("Using JWT token with return_url in state parameter: " . $returnUrl);
    } else {
        // Fallback: encode user_id in state
        $state = base64_encode(json_encode(['user_id' => $userId, 'return_url' => $returnUrl]));
        error_log("Using encoded user_id as state parameter with return_url: " . $returnUrl);
    }
    
    // Get the authorization URL
    $authUrl = $oauthController->getAuthorizationUrl($state);
    
    error_log("Redirecting to Google OAuth: " . $authUrl);
    
    // Redirect to Google's OAuth consent screen
    header('Location: ' . $authUrl);
    exit();
    
} catch (Exception $e) {
    error_log("Admin re-auth error: " . $e->getMessage());
    
    // Redirect to frontend with error - always use local URL
    $frontendUrl = 'http://localhost:8080/admin/meet?error=' . urlencode($e->getMessage());
    header('Location: ' . $frontendUrl);
    exit();
}
?>
