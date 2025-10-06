<?php
require_once __DIR__ . '/WorkSubmissionController.php';

class OwnWorkSubmissionController extends WorkSubmissionController {
    public function submitOwnWork($payload) {
        // Force disable impersonation for personal work submissions
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
                $userId = $result->admin_id; // Admin submitting their own work
                error_log("🔍 OwnWorkSubmissionController::submitOwnWork - Dashboard token detected, using admin_id: " . $userId);
            } else {
                $userId = $result->user_id; // Regular user token
                error_log("🔍 OwnWorkSubmissionController::submitOwnWork - Regular token, using user_id: " . $userId);
            }
            
            // Debug logging to verify no impersonation
            error_log("🔍 OwnWorkSubmissionController::submitOwnWork - Original User ID: " . $userId . ", Username: " . ($result->username ?? 'unknown') . " (NO IMPERSONATION), Date: " . ($payload['submission_date'] ?? 'no date'));
            
            $date = $payload['submission_date'] ?? date('Y-m-d');
            // Do not allow future dates
            if (strtotime($date) > strtotime(date('Y-m-d'))) {
                return $this->sendJsonResponse(400, 'Future dates are not allowed');
            }
            
            $start = isset($payload['start_time']) && trim($payload['start_time']) !== '' ? $payload['start_time'] : null;
            $hours = isset($payload['hours_today']) ? (float)$payload['hours_today'] : 0;
            $days = isset($payload['total_working_days']) ? (int)$payload['total_working_days'] : null;
            $cumulative = isset($payload['total_hours_cumulative']) ? (float)$payload['total_hours_cumulative'] : null;
            $completed = $payload['completed_tasks'] ?? null;
            $pending = $payload['pending_tasks'] ?? null;
            $notes = $payload['notes'] ?? null;
            $ongoing = $payload['ongoing_tasks'] ?? null;
            
            // Auto-migrate: add ongoing_tasks column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'ongoing_tasks'");
                if ($check->rowCount() === 0) {
                    $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN ongoing_tasks MEDIUMTEXT AFTER pending_tasks");
                }
            } catch (Exception $e) {
                // ignore; migration may fail if no permissions
            }
            
            $sql = "INSERT INTO work_submissions (user_id, submission_date, start_time, hours_today, total_working_days, total_hours_cumulative, completed_tasks, pending_tasks, ongoing_tasks, notes)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), hours_today=VALUES(hours_today), total_working_days=VALUES(total_working_days),
                    total_hours_cumulative=VALUES(total_hours_cumulative), completed_tasks=VALUES(completed_tasks), pending_tasks=VALUES(pending_tasks), ongoing_tasks=VALUES(ongoing_tasks), notes=VALUES(notes)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $date, $start, $hours, $days, $cumulative, $completed, $pending, $ongoing, $notes]);
            
            error_log("🔍 OwnWorkSubmissionController::submitOwnWork - Saved OWN submission for user: " . $userId . " on date: " . $date);
            $this->sendJsonResponse(200, 'Submission saved');
            
        } catch (Exception $e) {
            error_log('OwnWorkSubmissionController submit error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to save own submission');
        }
    }
}

$c = new OwnWorkSubmissionController();
$data = $c->getRequestData();
$c->submitOwnWork($data);
?>
