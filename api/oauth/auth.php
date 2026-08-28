<?php
/**
 * Google OAuth Authorization Endpoint
 * This endpoint initiates the OAuth flow by redirecting users to Google's consent screen
 */

require_once __DIR__ . '/GoogleOAuthController.php';
require_once __DIR__ . '/../../config/database.php';

try {
    error_log("=== Google OAuth Auth Endpoint ===");
    
    // Get state parameter (JWT token) from query string
    $state = $_GET['state'] ?? null;
    if ($state) {
        error_log("Received state parameter: " . substr($state, 0, 20) . "...");
    }

    $forceReauth = isset($_GET['force_reauth']) && $_GET['force_reauth'] === '1';
    if ($forceReauth && $state) {
        $jwtToken = $state;
        try {
            $decoded = @json_decode(@base64_decode($state), true);
            if (is_array($decoded) && !empty($decoded['jwt_token'])) {
                $jwtToken = $decoded['jwt_token'];
            }
        } catch (Throwable $e) {
            // state may be plain JWT
        }

        require_once __DIR__ . '/../BaseAPI.php';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwtToken;
        $baseAPI = new BaseAPI();
        try {
            $userData = $baseAPI->validateToken();
            if ($userData && !empty($userData->user_id)) {
                $db = Database::getInstance()->getConnection();
                $del = $db->prepare('DELETE FROM google_tokens WHERE bugricer_user_id = ?');
                $del->execute([(string)$userData->user_id]);
                error_log('Force reauth: cleared google_tokens for user ' . $userData->user_id);
            }
        } catch (Throwable $e) {
            error_log('Force reauth token clear skipped: ' . $e->getMessage());
        }
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
