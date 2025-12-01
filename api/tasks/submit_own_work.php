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
        
        // Check if this is an update before inserting
        $checkStmt = $this->conn->prepare("SELECT COUNT(*) as cnt FROM work_submissions WHERE user_id = ? AND submission_date = ?");
        $checkStmt->execute([$userId, $date]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $isUpdate = ($existing['cnt'] ?? 0) > 0;
        
        $sql = "INSERT INTO work_submissions (user_id, submission_date, start_time, hours_today, overtime_hours, total_working_days, total_hours_cumulative, completed_tasks, pending_tasks, ongoing_tasks, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), hours_today=VALUES(hours_today), overtime_hours=VALUES(overtime_hours), total_working_days=VALUES(total_working_days),
                total_hours_cumulative=VALUES(total_hours_cumulative), completed_tasks=VALUES(completed_tasks), pending_tasks=VALUES(pending_tasks), ongoing_tasks=VALUES(ongoing_tasks), notes=VALUES(notes)";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId, $date, $start, $hours, $overtime, $days, $cumulative, $completed, $pending, $ongoing, $notes]);
        
        error_log("🔍 OwnWorkSubmissionController::submitOwnWork - Saved submission for user: " . $userId . " on date: " . $date . $impersonationInfo);
        
        // Prepare notification data (shared for email and WhatsApp)
        $userStmt = $this->conn->prepare("SELECT username, email FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        $userName = $user['username'] ?? 'User';
        $userEmail = $user['email'] ?? '';
        
        $submissionData = [
            'submission_date' => $date,
            'start_time' => $start,
            'hours_today' => $hours,
            'overtime_hours' => $overtime,
            'completed_tasks' => $completed,
            'pending_tasks' => $pending,
            'ongoing_tasks' => $ongoing,
            'notes' => $notes,
            'is_update' => $isUpdate
        ];
        
        // Send email notification to admins
        error_log("EMAIL_NOTIFICATION: About to start email notification process");
        try {
            $emailPath = __DIR__ . '/../../utils/email.php';
            error_log("EMAIL_NOTIFICATION: Requiring email.php from: " . $emailPath);
            require_once $emailPath;
            error_log("EMAIL_NOTIFICATION: email.php required successfully");
            
            error_log("📧 Starting daily work update email notification process...");
            error_log("📧 User info - Name: $userName, Email: " . ($userEmail ?: 'EMPTY'));
            
            // Get admin emails
            $adminStmt = $this->conn->prepare("SELECT email FROM users WHERE role = 'admin'");
            $adminStmt->execute();
            $adminRows = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
            $adminEmails = array_column($adminRows, 'email');
            
            error_log("📧 Found " . count($adminEmails) . " admin emails: " . json_encode($adminEmails));
            
            if (empty($adminEmails)) {
                error_log("⚠️ No admin emails found - skipping email notification");
            } elseif (empty($userEmail)) {
                error_log("⚠️ User email is empty - skipping email notification");
            } else {
                error_log("📧 Calling sendDailyWorkUpdateEmailToAdmins with data: " . json_encode([
                    'admin_emails_count' => count($adminEmails),
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                    'submission_date' => $date
                ]));
                
                $emailResults = sendDailyWorkUpdateEmailToAdmins($adminEmails, $userName, $userEmail, $submissionData);
                error_log("📧 Daily work update emails sent to admins. Results: " . json_encode($emailResults));
            }
        } catch (Exception $e) {
            // Don't fail the submission if email fails
            error_log("⚠️ Failed to send daily work update email notification: " . $e->getMessage());
            error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        }
        
        // Send WhatsApp notification to admins
        error_log("📱 Starting daily work update WhatsApp notification process...");
        try {
            $whatsappPath = __DIR__ . '/../../utils/whatsapp.php';
            error_log("📱 Requiring whatsapp.php from: " . $whatsappPath);
            require_once $whatsappPath;
            error_log("📱 whatsapp.php required successfully");
            
            if (empty($userEmail)) {
                error_log("⚠️ User email is empty - skipping WhatsApp notification");
            } else {
                error_log("📱 Calling sendDailyWorkUpdateWhatsAppToAdmins");
                $whatsappResult = sendDailyWorkUpdateWhatsAppToAdmins($userName, $userEmail, $submissionData);
                if ($whatsappResult) {
                    error_log("✅ Daily work update WhatsApp sent to admins successfully");
                } else {
                    error_log("❌ Failed to send daily work update WhatsApp to admins");
                }
            }
        } catch (Exception $e) {
            // Don't fail the submission if WhatsApp fails
            error_log("⚠️ Failed to send daily work update WhatsApp notification: " . $e->getMessage());
            error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        }
        
        $this->sendJsonResponse(200, 'Submission saved');
    }
}

$c = new OwnWorkSubmissionController();
$data = $c->getRequestData();
$c->submitOwnWork($data);
?>
