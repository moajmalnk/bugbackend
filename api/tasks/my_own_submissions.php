<?php
require_once __DIR__ . '/WorkSubmissionController.php';

class OwnWorkSubmissionController extends WorkSubmissionController {
    public function myOwnSubmissions($q) {
        // Use the standard validateToken method which handles impersonation correctly
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        $from = $q['from'] ?? date('Y-m-01');
        $to = $q['to'] ?? date('Y-m-t');
        
        // Debug logging to verify user isolation and impersonation
        $impersonationInfo = isset($decoded->impersonated) && $decoded->impersonated ? " (IMPERSONATED)" : "";
        $adminInfo = isset($decoded->admin_id) ? " Admin: " . $decoded->admin_id : "";
        error_log("🔍 OwnWorkSubmissionController::myOwnSubmissions - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . $impersonationInfo . $adminInfo . ", Date range: $from to $to");

        $sql = "SELECT * FROM work_submissions WHERE user_id = ? AND submission_date BETWEEN ? AND ? ORDER BY submission_date DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId, $from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("🔍 OwnWorkSubmissionController::myOwnSubmissions - Found " . count($rows) . " submissions for user: " . $userId . $impersonationInfo);
        $this->sendJsonResponse(200, 'OK', $rows);
    }
}

$c = new OwnWorkSubmissionController();
$c->myOwnSubmissions($_GET);
?>
