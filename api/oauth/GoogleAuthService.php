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
        $this->redirectUri = Environment::getGoogleRedirectUri();
        
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
                throw new Exception('User has not connected Google Docs. Please connect your Google account first.');
            }
            
            // Initialize Google Client
            $client = new Google\Client();
            $client->setClientId($this->clientId);
            $client->setClientSecret($this->clientSecret);
            $client->setRedirectUri($this->redirectUri);
            $client->setScopes([
                Google\Service\Docs::DOCUMENTS,
                Google\Service\Drive::DRIVE_FILE
            ]);
            $client->setAccessType('offline');
            
            // Set refresh token
            $client->refreshToken($tokenData['refresh_token']);
            
            // Get fresh access token
            $accessToken = $client->getAccessToken();
            
            if (!$accessToken || isset($accessToken['error'])) {
                throw new Exception('Failed to obtain access token. User may need to reconnect Google account.');
            }
            
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

