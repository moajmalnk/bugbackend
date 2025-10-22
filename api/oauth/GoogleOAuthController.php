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
        
        // Set required scopes for Google Docs, Drive, and Calendar (for Meet)
        $this->googleClient->addScope('https://www.googleapis.com/auth/documents');
        $this->googleClient->addScope('https://www.googleapis.com/auth/drive.file');
        $this->googleClient->addScope('https://www.googleapis.com/auth/userinfo.email');
        $this->googleClient->addScope('https://www.googleapis.com/auth/calendar');
        
        // Critical: Get refresh token
        $this->googleClient->setAccessType('offline');
        $this->googleClient->setPrompt('consent');
        
        error_log("Google Client initialized with redirect URI: " . $redirectUri);
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
            // Production URL - use the path that matches Google Cloud Console
            return 'https://' . $host . '/api/oauth/callback';
        }
    }
    
    public function getClient() {
        return $this->googleClient;
    }
    
    /**
     * Generate and return the authorization URL
     */
    public function getAuthorizationUrl($state = null) {
        if ($state) {
            $this->googleClient->setState($state);
        }
        $authUrl = $this->googleClient->createAuthUrl();
        error_log("Generated auth URL: " . $authUrl);
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
            // Check if token already exists
            $stmt = $this->conn->prepare(
                "SELECT google_user_id FROM google_tokens WHERE google_user_id = ? OR bugricer_user_id = ?"
            );
            $stmt->execute([$googleUserId, $bugricerUserId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing token
                $stmt = $this->conn->prepare(
                    "UPDATE google_tokens 
                     SET refresh_token = ?, access_token_expiry = ?, email = ?, updated_at = CURRENT_TIMESTAMP 
                     WHERE google_user_id = ?"
                );
                $stmt->execute([$refreshToken, $accessTokenExpiry, $email, $googleUserId]);
                error_log("Updated existing Google token for user: " . $bugricerUserId);
            } else {
                // Insert new token
                $stmt = $this->conn->prepare(
                    "INSERT INTO google_tokens (google_user_id, bugricer_user_id, refresh_token, access_token_expiry, email) 
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$googleUserId, $bugricerUserId, $refreshToken, $accessTokenExpiry, $email]);
                error_log("Saved new Google token for user: " . $bugricerUserId);
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
