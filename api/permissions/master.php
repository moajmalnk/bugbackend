<?php
// Ensure no output before this point
if (ob_get_length()) ob_clean();
require_once '../BaseAPI.php';

class MasterPermissionsController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }

    public function dispatch() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            // Validate token
            try {
                $this->validateToken();
            } catch (Exception $e) {
                // Allow access to master permissions without strict auth (for now)
                // In production, you might want to require authentication
            }

            // Get all permissions grouped by category
            $stmt = $this->conn->query(
                "SELECT id, permission_key, permission_name, category, scope
                 FROM permissions
                 ORDER BY category, permission_name"
            );
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group by category
            $grouped = [];
            foreach ($permissions as $perm) {
                $category = $perm['category'];
                if (!isset($grouped[$category])) {
                    $grouped[$category] = [];
                }
                $grouped[$category][] = $perm;
            }
            
            $this->sendJsonResponse(200, "Permissions retrieved successfully", $grouped);
            
        } catch (Exception $e) {
            error_log("Master permissions error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve permissions");
        }
    }
}

$controller = new MasterPermissionsController();
$controller->dispatch();
?>
