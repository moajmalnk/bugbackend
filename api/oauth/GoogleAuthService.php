<?php
/**
 * Google Auth Service
 * Centralized authentication and token management for Google APIs
 * Provides a reusable, authenticated Google Client for all API operations
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/environment.php';

class GoogleAuthService {
    private $conn;
    private $googleClient;
    
    // OAuth configuration - loaded from environment
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    
    public function __construct() {
        $db = Database::getInstance();
        $this->conn = $db->getConnection();
        
        // Load OAuth configuration from environment
        $this->clientId = Environment::getGoogleClientId();
        $this->clientSecret = Environment::getGoogleClientSecret();
        
        // CRITICAL: Determine redirect URI based on actual environment (not just config)
        // This must match what was used during authorization to avoid refresh token errors
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
            $this->redirectUri = 'http://localhost/BugRicer/backend/api/oauth/callback';
            error_log("GoogleAuthService: Using LOCAL redirect URI: " . $this->redirectUri);
        } else {
            $this->redirectUri = Environment::getGoogleRedirectUri();
            error_log("GoogleAuthService: Using redirect URI from environment: " . $this->redirectUri);
        }
        
        // Validate required configuration
        if (empty($this->clientId) || empty($this->clientSecret)) {
            error_log('Warning: Google OAuth credentials not properly configured. Using fallback values.');
        }
    }
    
    /**
     * Get an authenticated Google Client for a specific BugRicer user
     * This is the core method used by all API operations
     * 
     * @param string $bugricerUserId BugRicer user ID (UUID)
     * @return Google\Client Authenticated Google Client
     * @throws Exception if user has no tokens or refresh fails
     */
    public function getClientForUser($bugricerUserId) {
        try {
            error_log("GoogleAuthService::getClientForUser called for user: " . $bugricerUserId);
            
            // Retrieve user's refresh token from database
            $stmt = $this->conn->prepare(
                "SELECT google_user_id, refresh_token, access_token_expiry, email 
                 FROM google_tokens 
                 WHERE bugricer_user_id = ? 
                 LIMIT 1"
            );
            $stmt->execute([$bugricerUserId]);
            $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tokenData) {
                error_log("No token data found for user: " . $bugricerUserId);
                throw new Exception('User has not connected Google Docs. Please connect your Google account first.');
            }
            
            error_log("Found token data for user: " . $bugricerUserId . ", refresh_token (first 20): " . substr($tokenData['refresh_token'] ?? 'NULL', 0, 20));
            error_log("Using redirect URI: " . $this->redirectUri);
            
            // Initialize Google Client
            $client = new Google\Client();
            $client->setClientId($this->clientId);
            $client->setClientSecret($this->clientSecret);
            $client->setRedirectUri($this->redirectUri);
            $client->setScopes([
                Google\Service\Docs::DOCUMENTS,
                Google\Service\Sheets::SPREADSHEETS,
                Google\Service\Drive::DRIVE_FILE,
                'https://www.googleapis.com/auth/calendar'
            ]);
            $client->setAccessType('offline');
            
            // Set refresh token
            error_log("Attempting to refresh token...");
            error_log("Refresh token length: " . strlen($tokenData['refresh_token']));
            error_log("Refresh token (first 50 chars): " . substr($tokenData['refresh_token'], 0, 50));
            
            try {
                // refreshToken() returns the access token array or throws an exception
                $refreshResult = $client->refreshToken($tokenData['refresh_token']);
                error_log("refreshToken() returned: " . json_encode($refreshResult));
                
                if (is_array($refreshResult) && isset($refreshResult['error'])) {
                    error_log("ERROR in refreshToken response: " . $refreshResult['error']);
                    throw new Exception('Failed to refresh access token: ' . $refreshResult['error'] . '. User may need to reconnect Google account.');
                }
            } catch (Exception $refreshException) {
                error_log("ERROR during refreshToken exception: " . $refreshException->getMessage());
                error_log("Exception class: " . get_class($refreshException));
                error_log("Refresh token (first 50 chars): " . substr($tokenData['refresh_token'], 0, 50));
                throw new Exception('Failed to refresh access token: ' . $refreshException->getMessage() . '. User may need to reconnect Google account.');
            }
            
            // Get fresh access token
            $accessToken = $client->getAccessToken();
            error_log("getAccessToken() returned: " . json_encode($accessToken));
            error_log("Access token is null: " . ($accessToken === null ? 'YES' : 'NO'));
            error_log("Access token is array: " . (is_array($accessToken) ? 'YES' : 'NO'));
            
            if (!$accessToken) {
                error_log("ERROR: Access token is null or empty");
                error_log("Full access token response: " . var_export($accessToken, true));
                throw new Exception('Failed to obtain access token: Access token is null. The refresh token may be invalid. User may need to reconnect Google account.');
            }
            
            if (is_array($accessToken) && isset($accessToken['error'])) {
                $errorMsg = $accessToken['error'];
                $errorDescription = $accessToken['error_description'] ?? '';
                error_log("ERROR in access token: " . $errorMsg . " - " . $errorDescription);
                error_log("Full access token error response: " . json_encode($accessToken));
                throw new Exception('Failed to obtain access token: ' . $errorMsg . ($errorDescription ? ' - ' . $errorDescription : '') . '. User may need to reconnect Google account.');
            }
            
            // Validate access token structure
            if (!is_array($accessToken) || !isset($accessToken['access_token'])) {
                error_log("ERROR: Access token structure is invalid");
                error_log("Access token structure: " . json_encode($accessToken));
                throw new Exception('Failed to obtain access token: Invalid token structure. User may need to reconnect Google account.');
            }
            
            error_log("✓ Successfully obtained access token for user: " . $bugricerUserId);
            
            // Update token expiry in database for tracking
            $this->updateTokenExpiry($bugricerUserId, $accessToken);
            
            error_log("Successfully authenticated Google Client for user: " . $bugricerUserId);
            
            return $client;
            
        } catch (Exception $e) {
            error_log("GoogleAuthService::getClientForUser failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if a user has connected their Google account
     * 
     * @param string $bugricerUserId BugRicer user ID
     * @return bool True if user has valid tokens
     */
    public function isUserConnected($bugricerUserId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) as count FROM google_tokens WHERE bugricer_user_id = ?"
            );
            $stmt->execute([$bugricerUserId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] > 0;
        } catch (Exception $e) {
            error_log("Error checking user connection: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user's Google email
     * 
     * @param string $bugricerUserId BugRicer user ID
     * @return string|null Google email or null if not found
     */
    public function getUserGoogleEmail($bugricerUserId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT email FROM google_tokens WHERE bugricer_user_id = ? LIMIT 1"
            );
            $stmt->execute([$bugricerUserId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['email'] : null;
        } catch (Exception $e) {
            error_log("Error getting user email: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update access token expiry in database
     * 
     * @param string $bugricerUserId BugRicer user ID
     * @param array $accessToken Google access token data
     */
    private function updateTokenExpiry($bugricerUserId, $accessToken) {
        try {
            if (isset($accessToken['expires_in'])) {
                $expiryTime = date('Y-m-d H:i:s', time() + $accessToken['expires_in']);
                
                $stmt = $this->conn->prepare(
                    "UPDATE google_tokens 
                     SET access_token_expiry = ?, updated_at = CURRENT_TIMESTAMP 
                     WHERE bugricer_user_id = ?"
                );
                $stmt->execute([$expiryTime, $bugricerUserId]);
            }
        } catch (Exception $e) {
            error_log("Error updating token expiry: " . $e->getMessage());
            // Non-critical error, don't throw
        }
    }
    
    /**
     * Revoke user's Google access (disconnect)
     * 
     * @param string $bugricerUserId BugRicer user ID
     * @return bool Success status
     */
    public function disconnectUser($bugricerUserId) {
        try {
            // Get client to revoke token
            $client = $this->getClientForUser($bugricerUserId);
            $client->revokeToken();
            
            // Delete tokens from database
            $stmt = $this->conn->prepare(
                "DELETE FROM google_tokens WHERE bugricer_user_id = ?"
            );
            $stmt->execute([$bugricerUserId]);
            
            error_log("Successfully disconnected Google account for user: " . $bugricerUserId);
            return true;
            
        } catch (Exception $e) {
            error_log("Error disconnecting user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get Google Docs service instance for a user
     * 
     * @param string $bugricerUserId BugRicer user ID
     * @return Google\Service\Docs Docs service instance
     */
    public function getDocsService($bugricerUserId) {
        $client = $this->getClientForUser($bugricerUserId);
        return new Google\Service\Docs($client);
    }
    
    /**
     * Get Google Drive service instance for a user
     * 
     * @param string $bugricerUserId BugRicer user ID
     * @return Google\Service\Drive Drive service instance
     */
    public function getDriveService($bugricerUserId) {
        $client = $this->getClientForUser($bugricerUserId);
        return new Google\Service\Drive($client);
    }
}

