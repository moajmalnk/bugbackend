<?php
/**
 * Verify Magic Link Token for Passwordless Authentication
 * POST /api/auth/verify_magic_link.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';

class MagicLinkVerificationAPI extends BaseAPI {
    private $db;
    
    public function __construct() {
        parent::__construct();
        $this->db = getDBConnection();
    }
    
    public function handleRequest() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendResponse(['success' => false, 'message' => 'Method not allowed'], 405);
            return;
        }
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['token']) || empty($input['token'])) {
                $this->sendResponse(['success' => false, 'message' => 'Magic link token is required'], 400);
                return;
            }
            
            $token = filter_var($input['token'], FILTER_SANITIZE_STRING);
            
            // Verify magic link token
            $stmt = $this->db->prepare("
                SELECT ml.*, u.id, u.username, u.email, u.role, u.status 
                FROM magic_links ml 
                JOIN users u ON ml.user_id = u.id 
                WHERE ml.token = ? AND ml.expires_at > NOW() AND ml.used_at IS NULL
            ");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $this->sendResponse(['success' => false, 'message' => 'Invalid or expired magic link'], 400);
                return;
            }
            
            $magic_link = $result->fetch_assoc();
            
            // Check if user account is active
            if ($magic_link['status'] !== 'active') {
                $this->sendResponse(['success' => false, 'message' => 'Account is not active'], 403);
                return;
            }
            
            // Mark magic link as used
            $stmt = $this->db->prepare("UPDATE magic_links SET used_at = NOW() WHERE token = ?");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            
            // Generate JWT token for the user
            $jwt_token = $this->generateJWTToken($magic_link);
            
            // Log successful magic link authentication
            $this->logActivity($magic_link['user_id'], 'magic_link_login', 'User signed in with magic link');
            
            $this->sendResponse([
                'success' => true,
                'message' => 'Magic link verified successfully',
                'token' => $jwt_token,
                'user' => [
                    'id' => $magic_link['id'],
                    'username' => $magic_link['username'],
                    'email' => $magic_link['email'],
                    'role' => $magic_link['role']
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Magic link verification error: " . $e->getMessage());
            $this->sendResponse(['success' => false, 'message' => 'Internal server error'], 500);
        }
    }
    
    private function generateJWTToken($user) {
        // Include JWT library
        require_once __DIR__ . '/../../vendor/autoload.php';
        
        $key = $_ENV['JWT_SECRET'] ?? 'your-secret-key';
        $payload = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            'iat' => time(),
            'exp' => time() + (24 * 60 * 60) // 24 hours
        ];
        
        return \Firebase\JWT\JWT::encode($payload, $key, 'HS256');
    }
    
    private function logActivity($user_id, $activity_type, $description) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO activity_log (user_id, activity_type, description, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->bind_param("iss", $user_id, $activity_type, $description);
            $stmt->execute();
        } catch (Exception $e) {
            error_log("Failed to log magic link activity: " . $e->getMessage());
        }
    }
}

// Handle the request
$api = new MagicLinkVerificationAPI();
$api->handleRequest();
?>
