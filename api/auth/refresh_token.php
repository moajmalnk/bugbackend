<?php
require_once '../BaseAPI.php';
require_once '../../config/utils.php';

class TokenRefreshController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }

    public function refreshToken() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            // Validate current token
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid token");
                return;
            }

            // Get fresh user data from database
            $stmt = $this->conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
            $stmt->execute([$decoded->user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $this->sendJsonResponse(404, "User not found");
                return;
            }

            // Generate new JWT token with fresh role data
            $newToken = Utils::generateJWT($user['id'], $user['username'], $user['role']);

            error_log("Token refreshed for user: " . $user['username'] . " (ID: " . $user['id'] . ", Role: " . $user['role'] . ")");

            $this->sendJsonResponse(200, "Token refreshed successfully", [
                "token" => $newToken,
                "user" => [
                    "id" => $user['id'],
                    "username" => $user['username'],
                    "role" => $user['role']
                ]
            ]);

        } catch (Exception $e) {
            error_log("Token refresh error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to refresh token: " . $e->getMessage());
        }
    }
}

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$controller = new TokenRefreshController();
$controller->refreshToken();
?>
