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
            
            $cols = [];
            $colRes = $this->conn->query("SHOW COLUMNS FROM users");
            if ($colRes) {
                while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }
            $select = ['id', 'username', 'email', 'phone', 'role', 'role_id'];
            if (in_array('account_active', $cols, true)) {
                $select[] = 'account_active';
            }
            $stmt = $this->conn->prepare(
                'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ?'
            );
            $stmt->execute([$decoded->user_id]);
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (array_key_exists('account_active', $user) && (int) $user['account_active'] !== 1) {
                    $this->sendJsonResponse(403, "Account no longer available.", null, false, 'ACCOUNT_REVOKED');
                    return;
                }
                unset($user['account_active']);
                $this->sendJsonResponse(200, "User data retrieved successfully", $user);
            } else {
                $this->sendJsonResponse(403, "Account no longer available.", null, false, 'ACCOUNT_REVOKED');
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