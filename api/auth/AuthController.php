<?php
require_once __DIR__ . '/../BaseAPI.php';

class AuthController extends BaseAPI {
    protected $pdo;

    public function __construct($pdo = null) {
        if ($pdo) {
            $this->pdo = $pdo;
        } elseif (property_exists($this, 'conn') && $this->conn) {
            $this->pdo = $this->conn;
        } else {
            // fallback for legacy code
            $this->pdo = null;
        }
        parent::__construct();
    }

    public function register() {
        // Handle CORS preflight
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->sendJsonResponse(200, "Preflight OK");
            return;
        }

        // Only allow POST method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $data = $this->getRequestData();
            
            // Validate required fields
            $required = ['username', 'email', 'password', 'role'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $this->sendJsonResponse(400, "Missing required field: {$field}");
                    return;
                }
            }
            
            // Check if username or email exists
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$data['username'], $data['email']]);
            
            if ($stmt->rowCount() > 0) {
                $this->sendJsonResponse(400, "Username or email already exists");
                return;
            }
            
            // Create new user
            $stmt = $this->conn->prepare(
                "INSERT INTO users (id, username, email, password, role) VALUES (?, ?, ?, ?, ?)"
            );
            
            $user_id = Utils::generateUUID();
            $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $stmt->execute([
                $user_id,
                $data['username'],
                $data['email'],
                $hashed_password,
                $data['role']
            ]);
            
            // Generate token
            $token = Utils::generateJWT($user_id, $data['username'], $data['role']);
            
            $this->sendJsonResponse(201, "User registered successfully", [
                "token" => $token,
                "user" => [
                    "id" => $user_id,
                    "username" => $data['username'],
                    "email" => $data['email'],
                    "role" => $data['role']
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error. Please try again later.");
        }
    }
    
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->sendJsonResponse(200, "Preflight OK");
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $data = $this->getRequestData();
            
            if (!isset($data['username']) || !isset($data['password'])) {
                $this->sendJsonResponse(400, "Username and password are required");
                return;
            }
            
            // Get user by username
            $stmt = $this->conn->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$data['username']]);
            
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(401, "Invalid credentials");
                return;
            }
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Debug log for password verification
            
            // Verify password
            if (!password_verify($data['password'], $user['password'])) {
                error_log("Password verification failed for user: " . $data['username']);
                $this->sendJsonResponse(401, "Invalid credentials");
                return;
            }
            
            // Generate JWT token
            $token = Utils::generateJWT($user['id'], $user['username'], $user['role']);
            
            // Remove password from user data
            unset($user['password']);
            
            $this->sendJsonResponse(200, "Login successful", [
                "token" => $token,
                "user" => $user
            ]);
            
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error. Please try again later.");
        }
    }
    
    public function me() {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->sendJsonResponse(200, "Preflight OK");
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid token");
                return;
            }

            // Include phone so clients receive full profile data
            $stmt = $this->conn->prepare("SELECT id, username, email, phone, role FROM users WHERE id = ?");
            $stmt->execute([$decoded->user_id]);

            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "User not found");
                return;
            }

            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(200, "User details retrieved successfully", $user);

        } catch (Exception $e) {
            $this->sendJsonResponse(401, "Authentication failed: " . $e->getMessage());
        }
    }

    public function loginWithIdentifier($identifier, $password) {
        if (is_array($identifier)) {
            $identifier = $identifier['username'] ?? $identifier['email'] ?? '';
        }
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $username = $user['username'];
            if (is_array($username)) {
                $username = $username[0]; // or handle as needed
            }
            if (password_verify(trim($password), $user['password'])) {
                unset($user['password']);
                $token = Utils::generateJWT($user['id'], $username, $user['role']);
                return [
                    'success' => true,
                    'user' => $user,
                    'token' => $token
                ];
            }
        }
        return ['success' => false, 'message' => 'Invalid credentials'];
    }
}

// Handle the request
// $controller = new AuthController();
// $action = basename($_SERVER['PHP_SELF'], '.php');
// switch($action) {
//     case 'register':
//         $controller->register();
//         break;
//     case 'login':
//         $controller->login();
//         break;
//     case 'me':
//         $controller->me();
//         break;
//     default:
//         Utils::sendResponse(404, "Endpoint not found");
// }
?> 