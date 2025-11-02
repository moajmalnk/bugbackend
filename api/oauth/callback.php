<?php
/**
 * Google OAuth Callback Endpoint
 * This endpoint handles the OAuth callback from Google and stores the tokens
 */

require_once __DIR__ . '/GoogleOAuthController.php';

try {
    error_log("=== Google OAuth Callback Endpoint ===");
    error_log("HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'not set'));
    error_log("REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set'));
    error_log("GET params: " . json_encode($_GET));
    
    // Check for error from Google
    if (isset($_GET['error'])) {
        $error = $_GET['error'];
        $errorDescription = $_GET['error_description'] ?? '';
        error_log("Google OAuth error: " . $error . " - " . $errorDescription);
        
        // Common error: redirect_uri_mismatch
        if ($error === 'redirect_uri_mismatch') {
            throw new Exception('Redirect URI mismatch. Please ensure http://localhost/BugRicer/backend/api/oauth/callback is configured in Google Cloud Console as an authorized redirect URI.');
        }
        
        throw new Exception('OAuth error: ' . $error . ($errorDescription ? ' - ' . $errorDescription : ''));
    }
    
    // Get authorization code
    if (!isset($_GET['code'])) {
        error_log("ERROR: No authorization code received. GET params: " . json_encode($_GET));
        error_log("This usually means:");
        error_log("1. The redirect URI is not configured in Google Cloud Console");
        error_log("2. The redirect URI in the request doesn't match what's configured");
        error_log("3. The user denied access (but should have 'error' param in that case)");
        throw new Exception('No authorization code received. Please ensure http://localhost/BugRicer/backend/api/oauth/callback is configured in Google Cloud Console.');
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
    
    // CRITICAL: Force redirect URI to match what was used in authorization request
    // This prevents redirect_uri_mismatch errors during token exchange
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        $localRedirectUri = 'http://localhost/BugRicer/backend/api/oauth/callback';
        $oauthController->setRedirectUri($localRedirectUri);
        error_log("FORCED redirect URI in callback to local: " . $localRedirectUri);
    } else {
        // Production
        $prodRedirectUri = 'https://bugbackend.bugricer.com/api/oauth/callback';
        $oauthController->setRedirectUri($prodRedirectUri);
        error_log("FORCED redirect URI in callback to production: " . $prodRedirectUri);
    }
    
    // Exchange code for tokens
    error_log("About to exchange code for tokens. Redirect URI: " . $oauthController->getClient()->getRedirectUri());
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
            // First try to decode state as JSON (may contain JWT token + return_url)
            $stateData = null;
            $jwtToken = $state;
            $returnUrl = null;
            
            // Try to decode state as JSON first
            try {
                $decoded = @json_decode(@base64_decode($state), true);
                if ($decoded && is_array($decoded)) {
                    $stateData = $decoded;
                    error_log("State decoded as JSON: " . json_encode($decoded));
                    
                    // Extract return_url first (important for local redirects)
                    $returnUrl = $decoded['return_url'] ?? null;
                    if ($returnUrl) {
                        error_log("✓ Found return_url in state: " . $returnUrl);
                    }
                    
                    // Extract JWT token if present
                    if (isset($decoded['jwt_token'])) {
                        $jwtToken = $decoded['jwt_token'];
                        error_log("State contains encoded JWT token with return_url");
                    } elseif (isset($decoded['user_id'])) {
                        // State might just have user_id and return_url (no JWT)
                        error_log("State contains user_id and return_url (no JWT)");
                    }
                } else {
                    error_log("State is not valid JSON, treating as plain JWT token");
                }
            } catch (Exception $e) {
                // State is plain JWT token, use as-is
                error_log("State decode exception: " . $e->getMessage() . " - treating as plain JWT token");
            }
            
            // Store return_url before validation (in case validation fails but we still want to redirect)
            $savedReturnUrl = $returnUrl;
            
            // Validate JWT token
            require_once __DIR__ . '/../BaseAPI.php';
            $baseAPI = new BaseAPI();
            
            // Temporarily set the token for validation
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwtToken;
            $userData = null;
            try {
                $userData = $baseAPI->validateToken();
            } catch (Exception $e) {
                error_log("JWT validation failed: " . $e->getMessage());
                // If we have return_url, we should still try to redirect there
                if ($savedReturnUrl) {
                    error_log("⚠ JWT validation failed but return_url present: " . $savedReturnUrl);
                    // We'll handle this in the fallback section below
                }
            }
            
            if ($userData && isset($userData->user_id)) {
                $bugricerUserId = $userData->user_id;
                error_log("User authenticated in callback via JWT: " . $bugricerUserId);
                
                // Check if we have a refresh token
                if (!empty($token['refresh_token'])) {
                    error_log("✓ Refresh token received, saving to database for user: " . $bugricerUserId);
                    error_log("✓ Refresh token (first 20 chars): " . substr($token['refresh_token'], 0, 20) . "...");
                    
                    // Save tokens directly to database
                    try {
                        $oauthController->saveTokens(
                            $googleUserId,
                            $bugricerUserId,
                            $token['refresh_token'],
                            $accessTokenExpiry,
                            $email
                        );
                        error_log("✓✓✓ SUCCESS: Tokens saved to database for user: " . $bugricerUserId);
                    } catch (Exception $saveException) {
                        error_log("✗✗✗ ERROR saving tokens: " . $saveException->getMessage());
                        throw $saveException;
                    }
                    
                    error_log("Successfully linked Google account for user: " . $bugricerUserId);
                    error_log("DEBUG - returnUrl from state: " . ($returnUrl ?? 'NULL'));
                    error_log("DEBUG - savedReturnUrl: " . ($savedReturnUrl ?? 'NULL'));
                    error_log("DEBUG - HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT_SET'));
                    error_log("DEBUG - REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT_SET'));
                    
                    // CRITICAL: ALWAYS use return_url from state if present (regardless of where callback runs)
                    // This ensures local requests redirect back to local, even if callback is on production
                    $redirectUrl = $returnUrl ?? $savedReturnUrl;
                    if ($redirectUrl) {
                        // Double-check: if return_url contains localhost, FORCE local redirect
                        if (strpos($redirectUrl, 'localhost') !== false || strpos($redirectUrl, '127.0.0.1') !== false) {
                            $frontendUrl = $redirectUrl . '?google_connected=true&email=' . urlencode($email);
                            error_log("✓✓✓ FORCING LOCAL redirect using return_url from state: " . $frontendUrl);
                            header('Location: ' . $frontendUrl);
                            exit();
                        }
                        // If return_url is production, use it
                        $frontendUrl = $redirectUrl . '?google_connected=true&email=' . urlencode($email);
                        error_log("✓ Using return_url from state: " . $frontendUrl);
                        header('Location: ' . $frontendUrl);
                        exit();
                    }
                    
                    // Fallback: ALWAYS check for localhost indicators first
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
                    
                    // If callback URL contains localhost or /BugRicer/, it's definitely local
                    $isDefinitelyLocal = strpos($requestUri, '/BugRicer/') !== false || 
                                         strpos($host, 'localhost') !== false || 
                                         strpos($host, '127.0.0.1') !== false || 
                                         strpos($host, 'local') !== false;
                    
                    // Determine redirect path based on user role
                    $userRole = $userData->role ?? 'developer';
                    $rolePath = 'admin'; // default
                    if ($userRole === 'admin') {
                        $rolePath = 'admin';
                    } elseif ($userRole === 'tester') {
                        $rolePath = 'tester';
                    } elseif ($userRole === 'developer') {
                        $rolePath = 'developer';
                    }
                    
                    if ($isDefinitelyLocal) {
                        $frontendUrl = "http://localhost:8080/{$rolePath}/meet?google_connected=true&email=" . urlencode($email);
                        error_log("⚠⚠⚠ FORCING LOCAL redirect (fallback detected local, role: {$userRole}): " . $frontendUrl);
                        header('Location: ' . $frontendUrl);
                        exit();
                    } else {
                        $frontendUrl = "https://bugs.bugricer.com/{$rolePath}/meet?google_connected=true&email=" . urlencode($email);
                        error_log("⚠ Fallback: Detected production environment (role: {$userRole}), redirecting to: " . $frontendUrl);
                        header('Location: ' . $frontendUrl);
                        exit();
                    }
                } else {
                    error_log("No refresh token received, storing in session for manual linking");
                }
            } else {
                // JWT validation failed - but check if we have return_url to redirect anyway
                if ($savedReturnUrl) {
                    error_log("⚠ JWT validation failed but redirecting to return_url: " . $savedReturnUrl);
                    $frontendUrl = $savedReturnUrl . '?google_connected=false&error=' . urlencode('Authentication failed');
                    header('Location: ' . $frontendUrl);
                    exit();
                }
                
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
                        $returnUrlFromState = $stateData['return_url'] ?? null;
                        error_log("DEBUG - returnUrlFromState from stateData: " . ($returnUrlFromState ?? 'NULL'));
                        
                        // CRITICAL: ALWAYS use return_url from state if present
                        if ($returnUrlFromState) {
                            // Double-check: if return_url contains localhost, FORCE local redirect
                            if (strpos($returnUrlFromState, 'localhost') !== false || strpos($returnUrlFromState, '127.0.0.1') !== false) {
                                $frontendUrl = $returnUrlFromState . '?google_connected=true&email=' . urlencode($email);
                                error_log("✓✓✓ FORCING LOCAL redirect using return_url from stateData: " . $frontendUrl);
                                header('Location: ' . $frontendUrl);
                                exit();
                            }
                            $frontendUrl = $returnUrlFromState . '?google_connected=true&email=' . urlencode($email);
                            error_log("✓ Using return_url from stateData: " . $frontendUrl);
                            header('Location: ' . $frontendUrl);
                            exit();
                        }
                        
                        // Fallback: ALWAYS check for localhost indicators first
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
                        
                        // If callback URL contains localhost or /BugRicer/, it's definitely local
                        $isDefinitelyLocal = strpos($requestUri, '/BugRicer/') !== false || 
                                             strpos($host, 'localhost') !== false || 
                                             strpos($host, '127.0.0.1') !== false || 
                                             strpos($host, 'local') !== false;
                        
                        // Get user role from database if we have user_id
                        $userRole = 'developer'; // default
                        if (isset($bugricerUserId)) {
                            try {
                                require_once __DIR__ . '/../../config/database.php';
                                $db = Database::getInstance();
                                $pdo = $db->getConnection();
                                $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
                                $stmt->execute([$bugricerUserId]);
                                $userRow = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($userRow && isset($userRow['role'])) {
                                    $userRole = $userRow['role'];
                                }
                            } catch (Exception $e) {
                                error_log("Failed to get user role: " . $e->getMessage());
                            }
                        }
                        
                        // Determine redirect path based on user role
                        $rolePath = 'admin'; // default
                        if ($userRole === 'admin') {
                            $rolePath = 'admin';
                        } elseif ($userRole === 'tester') {
                            $rolePath = 'tester';
                        } elseif ($userRole === 'developer') {
                            $rolePath = 'developer';
                        }
                        
                        if ($isDefinitelyLocal) {
                            $frontendUrl = "http://localhost:8080/{$rolePath}/meet?google_connected=true&email=" . urlencode($email);
                            error_log("⚠⚠⚠ FORCING LOCAL redirect (fallback 2 detected local, role: {$userRole}): " . $frontendUrl);
                            header('Location: ' . $frontendUrl);
                            exit();
                        } else {
                            $frontendUrl = "https://bugs.bugricer.com/{$rolePath}/meet?google_connected=true&email=" . urlencode($email);
                            error_log("⚠ Fallback: Detected production environment (role: {$userRole}), redirecting to: " . $frontendUrl);
                            header('Location: ' . $frontendUrl);
                            exit();
                        }
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
    
    // Redirect to frontend success page - check for return_url in query params or state
    $returnUrlFromState = null;
    $returnUrlFromQuery = $_GET['return_url'] ?? null;
    
    // First check query parameter (can be passed explicitly)
    if ($returnUrlFromQuery) {
        // Double-check: if return_url contains localhost, FORCE local redirect
        if (strpos($returnUrlFromQuery, 'localhost') !== false || strpos($returnUrlFromQuery, '127.0.0.1') !== false) {
            $frontendUrl = $returnUrlFromQuery . '?google_connected=true';
            error_log("✓✓✓ FORCING LOCAL redirect using return_url from query: " . $frontendUrl);
            header('Location: ' . $frontendUrl);
            exit();
        }
        $frontendUrl = $returnUrlFromQuery . '?google_connected=true';
        error_log("Using return_url from query parameter: " . $frontendUrl);
        header('Location: ' . $frontendUrl);
        exit();
    }
    
    // Then check state parameter
    if ($state) {
        try {
            $stateData = json_decode(base64_decode($state), true);
            if ($stateData && isset($stateData['return_url'])) {
                $returnUrlFromState = $stateData['return_url'];
                error_log("Found return_url in state: " . $returnUrlFromState);
            }
        } catch (Exception $e) {
            // State is JWT token or invalid, ignore
            error_log("State decode failed: " . $e->getMessage());
        }
    }
    
    // ALWAYS use return_url from state if present (this ensures local requests stay local)
    if ($returnUrlFromState) {
        // Double-check: if return_url contains localhost, FORCE local redirect
        if (strpos($returnUrlFromState, 'localhost') !== false || strpos($returnUrlFromState, '127.0.0.1') !== false) {
            $frontendUrl = $returnUrlFromState . '?google_connected=true';
            error_log("✓✓✓ FORCING LOCAL redirect using return_url from state (final fallback): " . $frontendUrl);
            header('Location: ' . $frontendUrl);
            exit();
        }
        $frontendUrl = $returnUrlFromState . '?google_connected=true';
        error_log("Using return_url from state (fallback): " . $frontendUrl);
        header('Location: ' . $frontendUrl);
        exit();
    } else {
        // Fallback: ALWAYS check for localhost indicators first
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        // If callback URL contains localhost or /BugRicer/, it's definitely local
        $isDefinitelyLocal = strpos($requestUri, '/BugRicer/') !== false || 
                             strpos($host, 'localhost') !== false || 
                             strpos($host, '127.0.0.1') !== false || 
                             strpos($host, 'local') !== false;
        
        // Try to get user role from JWT token in state if available
        $userRole = 'admin'; // default to admin if we can't determine
        if ($state) {
            try {
                // Try to decode JWT from state
                $jwtToken = $state;
                // Check if state is base64 encoded JSON
                $decoded = @json_decode(@base64_decode($state), true);
                if ($decoded && isset($decoded['jwt_token'])) {
                    $jwtToken = $decoded['jwt_token'];
                }
                
                // Decode JWT token to get role (only payload, no verification needed for role extraction)
                $parts = explode('.', $jwtToken);
                if (count($parts) === 3) {
                    $payload = json_decode(base64_decode($parts[1]), true);
                    if ($payload && isset($payload['role'])) {
                        $userRole = $payload['role'];
                    }
                }
            } catch (Exception $e) {
                error_log("Failed to extract role from JWT: " . $e->getMessage());
            }
        }
        
        // Determine redirect path based on user role
        $rolePath = 'admin'; // default
        if ($userRole === 'admin') {
            $rolePath = 'admin';
        } elseif ($userRole === 'tester') {
            $rolePath = 'tester';
        } elseif ($userRole === 'developer') {
            $rolePath = 'developer';
        }
        
        if ($isDefinitelyLocal) {
            $frontendUrl = "http://localhost:8080/{$rolePath}/meet?google_connected=true";
            error_log("⚠⚠⚠ FORCING LOCAL redirect (final fallback detected local, role: {$userRole}): " . $frontendUrl);
            header('Location: ' . $frontendUrl);
            exit();
        } else {
            // Production - redirect to the main domain
            $frontendUrl = "https://bugs.bugricer.com/{$rolePath}/meet?google_connected=true";
            error_log("Detected production environment (final fallback, role: {$userRole}), redirecting to: " . $frontendUrl);
            header('Location: ' . $frontendUrl);
            exit();
        }
    }
    
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

