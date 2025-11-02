<?php
require_once __DIR__ . '/WorkSubmissionController.php';

class OwnWorkSubmissionController extends WorkSubmissionController {
    public function submitOwnWork($payload) {
        // Use the standard validateToken method which handles impersonation correctly
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        
        // Debug logging to verify user isolation and impersonation
        $impersonationInfo = isset($decoded->impersonated) && $decoded->impersonated ? " (IMPERSONATED)" : "";
        $adminInfo = isset($decoded->admin_id) ? " Admin: " . $decoded->admin_id : "";
        error_log("🔍 OwnWorkSubmissionController::submitOwnWork - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . $impersonationInfo . $adminInfo . ", Date: " . ($payload['submission_date'] ?? 'no date'));
        
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

        // Auto-migrate: add overtime_hours column if missing
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'overtime_hours'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN overtime_hours DECIMAL(6,2) DEFAULT 0 AFTER hours_today");
            }
        } catch (Exception $e) {
            // ignore; migration may fail if no permissions
        }
        
        // Calculate overtime: if hours > 8, overtime = hours - 8, otherwise 0
        $overtime = $hours > 8 ? $hours - 8 : 0;
        
        $sql = "INSERT INTO work_submissions (user_id, submission_date, start_time, hours_today, overtime_hours, total_working_days, total_hours_cumulative, completed_tasks, pending_tasks, ongoing_tasks, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), hours_today=VALUES(hours_today), overtime_hours=VALUES(overtime_hours), total_working_days=VALUES(total_working_days),
                total_hours_cumulative=VALUES(total_hours_cumulative), completed_tasks=VALUES(completed_tasks), pending_tasks=VALUES(pending_tasks), ongoing_tasks=VALUES(ongoing_tasks), notes=VALUES(notes)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId, $date, $start, $hours, $overtime, $days, $cumulative, $completed, $pending, $ongoing, $notes]);
        
        error_log("🔍 OwnWorkSubmissionController::submitOwnWork - Saved submission for user: " . $userId . " on date: " . $date . $impersonationInfo);
        $this->sendJsonResponse(200, 'Submission saved');
    }
}

$c = new OwnWorkSubmissionController();
$data = $c->getRequestData();
$c->submitOwnWork($data);
?>
