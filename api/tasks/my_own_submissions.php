<?php
require_once __DIR__ . '/WorkSubmissionController.php';

class OwnWorkSubmissionController extends WorkSubmissionController {
    public function myOwnSubmissions($q) {
        // Force disable impersonation for personal submissions
        $originalToken = $this->getBearerToken();
        
        try {
            // Validate token without impersonation
            $result = $this->utils->validateJWT($originalToken);
            
            if (!$result || !isset($result->user_id)) {
                $this->sendJsonResponse(401, 'Invalid token or user_id missing');
                return;
            }
            
            // For dashboard access tokens, use admin_id (the actual admin user)
            // For regular tokens, use user_id (the logged in user)
            $userId = null;
            if (isset($result->purpose) && $result->purpose === 'dashboard_access' && isset($result->admin_id)) {
                $userId = $result->admin_id; // Admin viewing their own submissions
                error_log("🔍 OwnWorkSubmissionController::myOwnSubmissions - Dashboard token detected, using admin_id: " . $userId);
            } else {
                $userId = $result->user_id; // Regular user token
                error_log("🔍 OwnWorkSubmissionController::myOwnSubmissions - Regular token, using user_id: " . $userId);
            }
            $from = $q['from'] ?? date('Y-m-01');
            $to = $q['to'] ?? date('Y-m-t');
            
            // Debug logging to verify no impersonation
            error_log("🔍 OwnWorkSubmissionController::myOwnSubmissions - Original User ID: " . $userId . ", Username: " . ($result->username ?? 'unknown') . " (NO IMPERSONATION), Date range: $from to $to");
            
            $sql = "SELECT * FROM work_submissions WHERE user_id = ? AND submission_date BETWEEN ? AND ? ORDER BY submission_date DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $from, $to]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("🔍 OwnWorkSubmissionController::myOwnSubmissions - Found " . count($rows) . " OWN submissions for user: " . $userId);
            $this->sendJsonResponse(200, 'OK', $rows);
            
        } catch (Exception $e) {
            error_log('OwnWorkSubmissionController error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to load own submissions');
        }
    }
}

$c = new OwnWorkSubmissionController();
$c->myOwnSubmissions($_GET);
?>
