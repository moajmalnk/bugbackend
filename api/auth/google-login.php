<?php
/**
 * Google Sign-In Authentication Endpoint
 * Handles secure verification of Google ID tokens and user provisioning
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/cors.php';

// Set headers
header('Content-Type: application/json');

// Only allow POST method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
        exit;
    }
    
    // Validate required fields
    if (!isset($data['id_token']) || empty($data['id_token'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID token is required']);
        exit;
    }
    
    $idToken = $data['id_token'];
    
    // Load OAuth configuration from environment
    $clientId = Environment::getGoogleClientId();
    $clientSecret = Environment::getGoogleClientSecret();
    
    // Validate that environment variables are set
    if (empty($clientId) || empty($clientSecret)) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Google OAuth configuration not properly set. Please check environment variables.'
        ]);
        exit;
    }
    
    // Initialize Google Client
    $googleClient = new Google\Client();
    $googleClient->setClientId($clientId);
    $googleClient->setClientSecret($clientSecret);
    
    // Verify the ID token
    $payload = $googleClient->verifyIdToken($idToken);
    
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid ID token']);
        exit;
    }
    
    // Extract user information from the token payload
    $googleSub = $payload['sub'];
    $email = $payload['email'];
    $name = $payload['name'] ?? '';
    $picture = $payload['picture'] ?? '';
    $emailVerified = $payload['email_verified'] ?? false;
    
    // Validate email verification
    if (!$emailVerified) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Email not verified by Google']);
        exit;
    }
    
    // Get database connection
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Check if user exists by Google sub or email
    $user = findOrCreateUser($conn, $googleSub, $email, $name, $picture);
    
    if (!$user) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to create or retrieve user']);
        exit;
    }
    
    // Update last login time
    updateLastLogin($conn, $user['id']);
    
    // Generate JWT token for the user
    $token = Utils::generateJWT($user['id'], $user['username'], $user['role']);
    
    // Remove sensitive data from user object
    unset($user['password']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Authentication successful',
        'token' => $token,
        'user' => $user
    ]);
    
} catch (Exception $e) {
    error_log("Google authentication error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication failed: ' . $e->getMessage()
    ]);
}

/**
 * Find existing user or create new user
 */
function findOrCreateUser($conn, $googleSub, $email, $name, $picture) {
    try {
        // First, try to find user by Google sub
        $stmt = $conn->prepare(
            "SELECT * FROM users WHERE google_sub = ? LIMIT 1"
        );
        $stmt->execute([$googleSub]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Update profile picture if it has changed
            if ($user['profile_picture_url'] !== $picture) {
                $updateStmt = $conn->prepare(
                    "UPDATE users SET profile_picture_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
                );
                $updateStmt->execute([$picture, $user['id']]);
                $user['profile_picture_url'] = $picture;
            }
            
            return $user;
        }
        
        // Try to find user by email
        $stmt = $conn->prepare(
            "SELECT * FROM users WHERE email = ? LIMIT 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Link existing user with Google account
            $updateStmt = $conn->prepare(
                "UPDATE users SET google_sub = ?, profile_picture_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $updateStmt->execute([$googleSub, $picture, $user['id']]);
            
            $user['google_sub'] = $googleSub;
            $user['profile_picture_url'] = $picture;
            
            return $user;
        }
        
        // Create new user
        $userId = Utils::generateUUID();
        $username = generateUsername($conn, $name, $email);
        
        $stmt = $conn->prepare(
            "INSERT INTO users (id, username, email, password, role, google_sub, profile_picture_url, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        
        // Set a random password for Google users (they won't use it)
        $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $defaultRole = 'tester'; // Default role for new users
        
        $stmt->execute([
            $userId,
            $username,
            $email,
            $randomPassword,
            $defaultRole,
            $googleSub,
            $picture
        ]);
        
        // Return the newly created user
        return [
            'id' => $userId,
            'username' => $username,
            'email' => $email,
            'role' => $defaultRole,
            'google_sub' => $googleSub,
            'profile_picture_url' => $picture,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        error_log("Error finding or creating user: " . $e->getMessage());
        return null;
    }
}

/**
 * Generate a unique username from name and email
 */
function generateUsername($conn, $name, $email) {
    // Try to use name first
    if (!empty($name)) {
        $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        if (strlen($baseUsername) >= 3) {
            $username = $baseUsername;
            
            // Check if username is available
            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() == 0) {
                return $username;
            }
        }
    }
    
    // Fallback to email prefix
    $emailPrefix = strtolower(explode('@', $email)[0]);
    $emailPrefix = preg_replace('/[^a-zA-Z0-9]/', '', $emailPrefix);
    
    if (strlen($emailPrefix) >= 3) {
        $username = $emailPrefix;
        
        // Check if username is available
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() == 0) {
            return $username;
        }
    }
    
    // Generate unique username with timestamp
    $timestamp = substr(time(), -6);
    $username = 'user' . $timestamp;
    
    // Ensure uniqueness
    $counter = 1;
    while (true) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() == 0) {
            break;
        }
        $username = 'user' . $timestamp . $counter;
        $counter++;
    }
    
    return $username;
}

/**
 * Update last login time
 */
function updateLastLogin($conn, $userId) {
    try {
        $stmt = $conn->prepare(
            "UPDATE users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->execute([$userId]);
    } catch (Exception $e) {
        error_log("Error updating last login: " . $e->getMessage());
    }
}
?>
