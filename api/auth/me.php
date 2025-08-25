<?php
require_once '../BaseAPI.php';

class MeController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }

    public function getMe() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid token or user_id missing");
                return;
            }
            
            // Get user data from database (include phone so frontend can show it on profile)
            $stmt = $this->conn->prepare("SELECT id, username, email, phone, role FROM users WHERE id = ?");
            $stmt->execute([$decoded->user_id]);
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                $this->sendJsonResponse(200, "User data retrieved successfully", $user);
            } else {
                $this->sendJsonResponse(404, "User not found");
            }
        } catch (Exception $e) {
            error_log("ME endpoint error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Error: " . $e->getMessage());
        }
    }
}

// Ensure no output before this point
if (ob_get_length()) ob_clean();

$controller = new MeController();
$controller->getMe();
?> 