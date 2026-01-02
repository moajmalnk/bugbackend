<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/environment.php';

class GoogleOAuthController extends BaseAPI {
    private $googleClient;
    
    public function __construct() {
        parent::__construct();
        $this->initializeGoogleClient();
    }
    
    private function initializeGoogleClient() {
        $this->googleClient = new Google\Client();
        
        // Load OAuth credentials from environment
        $clientId = Environment::getGoogleClientId();
        $clientSecret = Environment::getGoogleClientSecret();
        
        // Validate required configuration
        if (empty($clientId) || empty($clientSecret)) {
            throw new Exception('Google OAuth credentials not configured. Please set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET environment variables.');
        }
        
        // Set OAuth credentials
        $this->googleClient->setClientId($clientId);
        $this->googleClient->setClientSecret($clientSecret);
        
        // Determine redirect URI based on environment
        $redirectUri = $this->getRedirectUri();
        $this->googleClient->setRedirectUri($redirectUri);
        
        // Set required scopes for Google Docs, Sheets, Drive, and Calendar (for Meet)
        $this->googleClient->addScope('https://www.googleapis.com/auth/documents');
        $this->googleClient->addScope('https://www.googleapis.com/auth/spreadsheets');
        $this->googleClient->addScope('https://www.googleapis.com/auth/drive.file');
        $this->googleClient->addScope('https://www.googleapis.com/auth/userinfo.email');
        $this->googleClient->addScope('https://www.googleapis.com/auth/calendar');
        
        // Critical: Get refresh token and force consent
        $this->googleClient->setAccessType('offline');
        $this->googleClient->setPrompt('consent');
        $this->googleClient->setIncludeGrantedScopes(true);
        
        error_log("Google Client initialized with redirect URI: " . $redirectUri);
        error_log("Current host: " . ($_SERVER['HTTP_HOST'] ?? 'unknown'));
        error_log("Environment redirect URI: " . ($envRedirectUri ?? 'not set'));
    }
    
    private function getRedirectUri() {
        // Use environment variable if available, otherwise auto-detect
        $envRedirectUri = Environment::getGoogleRedirectUri();
        if (!empty($envRedirectUri)) {
            return $envRedirectUri;
        }
        
        // Fallback: Check if we're in local development or production
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
            return 'http://localhost/BugRicer/backend/api/oauth/callback';
        } else {
            // Production URL - use the backend domain for OAuth callback
            return 'https://bugbackend.bugricer.com/api/oauth/callback';
        }
    }
    
    /**
     * Override redirect URI (useful for forcing local redirect in admin-reauth)
     */
    public function setRedirectUri($redirectUri) {
        // Set the redirect URI on the Google Client
        $this->googleClient->setRedirectUri($redirectUri);
        
        // Verify it was set correctly
        $actualUri = $this->googleClient->getRedirectUri();
        if ($actualUri !== $redirectUri) {
            error_log("WARNING: Redirect URI mismatch after setting. Expected: " . $redirectUri . ", Got: " . $actualUri);
            // Try setting it again - sometimes the Google Client needs it set multiple times
            $this->googleClient->setRedirectUri($redirectUri);
            $actualUri = $this->googleClient->getRedirectUri();
            error_log("After second attempt, redirect URI: " . $actualUri);
        }
        
        error_log("Redirect URI overridden to: " . $redirectUri);
        error_log("Verifying redirect URI after override: " . $this->googleClient->getRedirectUri());
    }
    
    public function getClient() {
        return $this->googleClient;
    }
    
    /**
     * Generate and return the authorization URL
     */
    public function getAuthorizationUrl($state = null) {
        // Log redirect URI before creating auth URL
        $redirectUriBefore = $this->googleClient->getRedirectUri();
        error_log("Redirect URI BEFORE creating auth URL: " . $redirectUriBefore);
        
        if ($state) {
            $this->googleClient->setState($state);
        }
        $authUrl = $this->googleClient->createAuthUrl();
        
        // Parse the redirect_uri from the auth URL to verify it's correct
        $parsedUrl = parse_url($authUrl);
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $params);
            $redirectUriInUrl = urldecode($params['redirect_uri'] ?? 'not found');
            error_log("Redirect URI IN the auth URL: " . $redirectUriInUrl);
            if ($redirectUriInUrl !== $redirectUriBefore) {
                error_log("ERROR: Redirect URI mismatch! Client has: " . $redirectUriBefore . ", but URL contains: " . $redirectUriInUrl);
            }
        }
        
        error_log("Generated auth URL: " . $authUrl);
        error_log("Redirect URI in Google Client AFTER creating auth URL: " . $this->googleClient->getRedirectUri());
        return $authUrl;
    }
    
    /**
     * Exchange authorization code for tokens
     */
    public function exchangeCodeForTokens($code) {
        try {
            error_log("Exchanging code for tokens...");
            
            // Fetch access token with auth code
            $token = $this->googleClient->fetchAccessTokenWithAuthCode($code);
            
            if (isset($token['error'])) {
                throw new Exception('Error fetching access token: ' . $token['error']);
            }
            
            error_log("Token exchange successful");
            error_log("Token response: " . json_encode($token));
            
            return $token;
        } catch (Exception $e) {
            error_log("Token exchange failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get Google user information
     */
    public function getGoogleUserInfo($accessToken = null) {
        try {
            if ($accessToken) {
                $this->googleClient->setAccessToken($accessToken);
            }
            
            $oauth2 = new Google\Service\Oauth2($this->googleClient);
            $userInfo = $oauth2->userinfo->get();
            
            return [
                'google_user_id' => $userInfo->id,
                'email' => $userInfo->email,
                'name' => $userInfo->name,
                'picture' => $userInfo->picture
            ];
        } catch (Exception $e) {
            error_log("Failed to get user info: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Save or update Google tokens in database
     */
    public function saveTokens($googleUserId, $bugricerUserId, $refreshToken, $accessTokenExpiry, $email) {
        try {
            error_log("saveTokens called - googleUserId: " . $googleUserId . ", bugricerUserId: " . $bugricerUserId);
            error_log("refreshToken length: " . strlen($refreshToken) . ", first 30 chars: " . substr($refreshToken, 0, 30));
            
            // FIRST: Delete any existing tokens for this user to avoid conflicts
            // Google invalidates old refresh tokens when a new one is issued
            $deleteStmt = $this->conn->prepare(
                "DELETE FROM google_tokens WHERE bugricer_user_id = ? OR google_user_id = ?"
            );
            $deleteStmt->execute([$bugricerUserId, $googleUserId]);
            $deletedRows = $deleteStmt->rowCount();
            if ($deletedRows > 0) {
                error_log("Deleted " . $deletedRows . " old token(s) for user: " . $bugricerUserId);
            }
            
            // Now insert the new token
            $stmt = $this->conn->prepare(
                "INSERT INTO google_tokens (google_user_id, bugricer_user_id, refresh_token, access_token_expiry, email) 
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                    refresh_token = VALUES(refresh_token),
                    access_token_expiry = VALUES(access_token_expiry),
                    email = VALUES(email),
                    updated_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([$googleUserId, $bugricerUserId, $refreshToken, $accessTokenExpiry, $email]);
            
            // Verify the token was saved
            $verifyStmt = $this->conn->prepare(
                "SELECT refresh_token FROM google_tokens WHERE bugricer_user_id = ? LIMIT 1"
            );
            $verifyStmt->execute([$bugricerUserId]);
            $savedToken = $verifyStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($savedToken && $savedToken['refresh_token'] === $refreshToken) {
                error_log("✓✓✓ Verified: New refresh token successfully saved for user: " . $bugricerUserId);
            } else {
                error_log("✗✗✗ WARNING: Token save verification failed for user: " . $bugricerUserId);
            }
            
            // Clear any cached token data
            $this->clearCache('google_token_' . $bugricerUserId);
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to save tokens: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get refresh token for a BugRicer user
     */
    public function getRefreshToken($bugricerUserId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT refresh_token, google_user_id, email FROM google_tokens WHERE bugricer_user_id = ? LIMIT 1"
            );
            $stmt->execute([$bugricerUserId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return null;
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("Failed to get refresh token: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get a fresh access token using refresh token
     */
    public function getFreshAccessToken($refreshToken) {
        try {
            // Set the refresh token and fetch new access token
            $this->googleClient->refreshToken($refreshToken);
            
            $accessToken = $this->googleClient->getAccessToken();
            
            if (isset($accessToken['error'])) {
                throw new Exception('Error refreshing access token: ' . $accessToken['error']);
            }
            
            return $accessToken;
        } catch (Exception $e) {
            error_log("Failed to refresh access token: " . $e->getMessage());
            throw $e;
        }
    }
}
