<?php
/**
 * Google OAuth Callback Endpoint
 * This endpoint handles the OAuth callback from Google and stores the tokens
 */

require_once __DIR__ . '/GoogleOAuthController.php';

try {
    error_log("=== Google OAuth Callback Endpoint ===");
    
    // Check for error from Google
    if (isset($_GET['error'])) {
        throw new Exception('OAuth error: ' . $_GET['error']);
    }
    
    // Get authorization code
    if (!isset($_GET['code'])) {
        throw new Exception('No authorization code received');
    }
    
    $code = $_GET['code'];
    error_log("Received authorization code: " . substr($code, 0, 20) . "...");
    
    // Get state parameter (contains JWT token)
    $state = $_GET['state'] ?? null;
    if ($state) {
        error_log("Received state parameter: " . substr($state, 0, 20) . "...");
    }
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // Exchange code for tokens
    $token = $oauthController->exchangeCodeForTokens($code);
    
    if (!isset($token['refresh_token'])) {
        error_log("Warning: No refresh token received. Token response: " . json_encode($token));
        // This can happen if user has already authorized the app
        // Try to proceed anyway, but log the issue
    }
    
    // Get Google user information
    $userInfo = $oauthController->getGoogleUserInfo($token);
    error_log("Google user info: " . json_encode($userInfo));
    
    $googleUserId = $userInfo['google_user_id'];
    $email = $userInfo['email'];
    
    // Calculate access token expiry
    $expiresIn = $token['expires_in'] ?? 3600;
    $accessTokenExpiry = date('Y-m-d H:i:s', time() + $expiresIn);
    
    // Try to get the user ID from the state parameter
    if ($state) {
        error_log("Attempting to parse state parameter: " . substr($state, 0, 50) . "...");
        
        try {
            // First try to validate as JWT token (most common case)
            require_once __DIR__ . '/../BaseAPI.php';
            $baseAPI = new BaseAPI();
            
            // Temporarily set the token for validation
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $state;
            $userData = $baseAPI->validateToken();
            
            if ($userData && isset($userData->user_id)) {
                $bugricerUserId = $userData->user_id;
                error_log("User authenticated in callback via JWT: " . $bugricerUserId);
                
                // Check if we have a refresh token
                if (!empty($token['refresh_token'])) {
                    // Save tokens directly to database
                    $oauthController->saveTokens(
                        $googleUserId,
                        $bugricerUserId,
                        $token['refresh_token'],
                        $accessTokenExpiry,
                        $email
                    );
                    
                    error_log("Successfully linked Google account for user: " . $bugricerUserId);
                    
                    // Redirect to frontend success page - environment aware
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
                    
                    if ($isLocal) {
                        $frontendUrl = 'http://localhost:8080/docs-setup-success?linked=true&email=' . urlencode($email);
                    } else {
                        // Production - redirect to the main domain
                        $frontendUrl = 'https://bugs.bugricer.com/admin/meet?google_connected=true&email=' . urlencode($email);
                    }
                    
                    error_log("Redirecting to: " . $frontendUrl);
                    header('Location: ' . $frontendUrl);
                    exit();
                } else {
                    error_log("No refresh token received, storing in session for manual linking");
                }
            } else {
                // Fallback: Try to decode the state as JSON (for direct-auth)
                $stateData = json_decode(base64_decode($state), true);
                
                if ($stateData && isset($stateData['user_id'])) {
                    $bugricerUserId = $stateData['user_id'];
                    error_log("User ID from state JSON: " . $bugricerUserId);
                    
                    // Check if we have a refresh token
                    if (!empty($token['refresh_token'])) {
                        // Save tokens directly to database
                        $oauthController->saveTokens(
                            $googleUserId,
                            $bugricerUserId,
                            $token['refresh_token'],
                            $accessTokenExpiry,
                            $email
                        );
                        
                        error_log("Successfully linked Google account for user: " . $bugricerUserId);
                        
                        // Redirect to frontend success page - environment aware
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
                        
                        if ($isLocal) {
                            $frontendUrl = 'http://localhost:8080/docs-setup-success?linked=true&email=' . urlencode($email);
                        } else {
                            // Production - redirect to the main domain
                            $frontendUrl = 'https://bugs.bugricer.com/admin/meet?google_connected=true&email=' . urlencode($email);
                        }
                        
                        error_log("Redirecting to: " . $frontendUrl);
                        header('Location: ' . $frontendUrl);
                        exit();
                    } else {
                        error_log("No refresh token received, storing in session for manual linking");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("State parsing failed in callback: " . $e->getMessage());
        }
    }
    
    // Fallback: Store in session for manual linking
    session_start();
    
    // Store in session for temporary access
    $_SESSION['google_oauth_pending'] = [
        'google_user_id' => $googleUserId,
        'email' => $email,
        'refresh_token' => $token['refresh_token'] ?? null,
        'access_token_expiry' => $accessTokenExpiry,
        'timestamp' => time()
    ];
    
    error_log("OAuth callback successful. Stored in session. Redirecting to frontend...");
    
    // Redirect to frontend success page - environment aware
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
    
    if ($isLocal) {
        $frontendUrl = 'http://localhost:8080/docs-setup-success';
    } else {
        // Production - redirect to the main domain
        $frontendUrl = 'https://bugs.bugricer.com/admin/meet?google_connected=true';
    }
    
    error_log("OAuth callback redirecting to: " . $frontendUrl);
    header('Location: ' . $frontendUrl);
    exit();
    
} catch (Exception $e) {
    error_log("OAuth callback error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Redirect to frontend with error - environment aware
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
    
    if ($isLocal) {
        $frontendUrl = 'http://localhost:8080/docs-setup-error?error=' . urlencode($e->getMessage());
    } else {
        // Production - redirect to the main domain with error
        $frontendUrl = 'https://bugs.bugricer.com/admin/meet?google_error=' . urlencode($e->getMessage());
    }
    
    error_log("OAuth callback error redirecting to: " . $frontendUrl);
    header('Location: ' . $frontendUrl);
    exit();
}
?>

