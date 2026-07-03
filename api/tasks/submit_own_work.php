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
        $requestedExtraHours = isset($payload['requested_extra_hours']) ? (float)$payload['requested_extra_hours'] : 0.0;
        $approvalReason = isset($payload['approval_reason']) ? trim((string)$payload['approval_reason']) : null;
        $breakEntriesPayload = isset($payload['break_entries']) && is_array($payload['break_entries']) ? $payload['break_entries'] : [];
        $breakEntries = array_values(array_filter(array_map(function ($entry) {
            return trim((string)$entry);
        }, $breakEntriesPayload), function ($entry) {
            return $entry !== '';
        }));
        $totalBreakMinutes = isset($payload['total_break_minutes']) ? (int)$payload['total_break_minutes'] : 0;
        if ($totalBreakMinutes < 0) {
            $totalBreakMinutes = 0;
        }
        if ($totalBreakMinutes === 0 && !empty($breakEntries)) {
            $computedMins = 0;
            foreach ($breakEntries as $entry) {
                if (preg_match('/\((\d+)\s*min\)/i', $entry, $matches)) {
                    $computedMins += (int)$matches[1];
                }
            }
            $totalBreakMinutes = $computedMins;
        }
        if ($requestedExtraHours < 0) {
            $requestedExtraHours = 0.0;
        }

        require_once __DIR__ . '/../../utils/work_period.php';
        $monthTotals = br_compute_calendar_month_totals($this->conn, $userId, $date);
        if ($days === null) {
            $days = $monthTotals['days'];
        }
        if ($cumulative === null) {
            $cumulative = $monthTotals['hours'];
        }
        
        // Auto-migrate: add ongoing_tasks column if missing
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'ongoing_tasks'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN ongoing_tasks MEDIUMTEXT AFTER pending_tasks");
            }
        } catch (Exception $e) {
            // ignore; migration may fail if no permissions
        }

        // Auto-migrate: add break_entries column if missing
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'break_entries'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN break_entries JSON NULL DEFAULT NULL AFTER approval_reason");
            }
        } catch (Exception $e) {
            // ignore; migration may fail if no permissions
        }

        // Auto-migrate: add total_break_minutes column if missing
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'total_break_minutes'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN total_break_minutes INT DEFAULT 0 AFTER break_entries");
            }
        } catch (Exception $e) {
            // ignore; migration may fail if no permissions
        }

        // Auto-migrate: add requested_extra_hours column if missing
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'requested_extra_hours'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN requested_extra_hours DECIMAL(6,2) DEFAULT 0 AFTER overtime_hours");
            }
        } catch (Exception $e) {
            // ignore; migration may fail if no permissions
        }

        // Auto-migrate: add approval_reason column if missing
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'approval_reason'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN approval_reason TEXT NULL AFTER requested_extra_hours");
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
        
        // Keep overtime aligned with explicit extra-hours requests.
        $overtime = max(($hours > 8 ? $hours - 8 : 0), $requestedExtraHours);
        
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

        // Persist OT request fields if these columns exist.
        $columnsCheck = $this->conn->query("SHOW COLUMNS FROM work_submissions");
        $columns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
        $hasRequestedExtraHours = in_array('requested_extra_hours', $columns);
        $hasApprovalReason = in_array('approval_reason', $columns);
        $hasBreakEntries = in_array('break_entries', $columns);
        $hasTotalBreakMinutes = in_array('total_break_minutes', $columns);
        if ($hasRequestedExtraHours || $hasApprovalReason || $hasBreakEntries || $hasTotalBreakMinutes) {
            $extraUpdateParts = [];
            $extraUpdateValues = [];
            if ($hasRequestedExtraHours) {
                $extraUpdateParts[] = "requested_extra_hours = ?";
                $extraUpdateValues[] = $requestedExtraHours;
            }
            if ($hasApprovalReason) {
                $extraUpdateParts[] = "approval_reason = ?";
                $extraUpdateValues[] = $approvalReason;
            }
            if ($hasBreakEntries) {
                $extraUpdateParts[] = "break_entries = ?";
                $extraUpdateValues[] = !empty($breakEntries) ? json_encode($breakEntries) : null;
            }
            if ($hasTotalBreakMinutes) {
                $extraUpdateParts[] = "total_break_minutes = ?";
                $extraUpdateValues[] = $totalBreakMinutes;
            }
            if (!empty($extraUpdateParts)) {
                $extraUpdateValues[] = $userId;
                $extraUpdateValues[] = $date;
                $extraUpdateSql = "UPDATE work_submissions SET " . implode(", ", $extraUpdateParts) . " WHERE user_id = ? AND submission_date = ?";
                $extraUpdateStmt = $this->conn->prepare($extraUpdateSql);
                $extraUpdateStmt->execute($extraUpdateValues);
            }
        }

        $this->updateOvertimeApprovalOnSubmit($userId, $date, $requestedExtraHours, $approvalReason);
        
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
            'requested_extra_hours' => $requestedExtraHours,
            'approval_reason' => $approvalReason,
            'break_entries' => $breakEntries,
            'total_break_minutes' => $totalBreakMinutes,
            'completed_tasks' => $completed,
            'pending_tasks' => $pending,
            'ongoing_tasks' => $ongoing,
            'notes' => $notes,
            'is_update' => $isUpdate
        ];
        
        // Send response immediately (non-blocking) for faster user experience
        $this->sendJsonResponse(200, 'Submission saved');
        
        // Send notifications asynchronously (non-blocking) after response is sent
        // This makes the save feel instant while notifications happen in background
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // Flush response to client immediately
        }
        
        // Now send notifications in background (won't block user)
        // Send notifications to admins for BOTH new submissions AND updates
        // This ensures admins are notified whenever developers or admins submit/update their work
        $updateStatus = $isUpdate ? 'UPDATE' : 'NEW SUBMISSION';
        error_log("📢 NOTIFICATION: Sending admin notifications for work $updateStatus by $userName ($userEmail)");
        
        // Send email notification to admins
        error_log("EMAIL_NOTIFICATION: Starting async email notification process");
        try {
            $emailPath = __DIR__ . '/../../utils/email.php';
            require_once $emailPath;
            
            error_log("📧 Starting daily work $updateStatus email notification process...");
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
                error_log("📧 Calling sendDailyWorkUpdateEmailToAdmins for $updateStatus with data: " . json_encode([
                    'admin_emails_count' => count($adminEmails),
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                    'submission_date' => $date,
                    'is_update' => $isUpdate
                ]));
                
                $emailResults = sendDailyWorkUpdateEmailToAdmins($adminEmails, $userName, $userEmail, $submissionData);
                error_log("📧 Daily work $updateStatus emails sent to admins. Results: " . json_encode($emailResults));
            }
        } catch (Exception $e) {
            // Don't fail the submission if email fails
            error_log("⚠️ Failed to send daily work $updateStatus email notification: " . $e->getMessage());
            error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        }
        
        // Send WhatsApp notification to admins
        error_log("📱 Starting daily work $updateStatus WhatsApp notification process...");
        try {
            $whatsappPath = __DIR__ . '/../../utils/whatsapp.php';
            require_once $whatsappPath;
            
            if (empty($userEmail)) {
                error_log("⚠️ User email is empty - skipping WhatsApp notification");
            } else {
                error_log("📱 Calling sendDailyWorkUpdateWhatsAppToAdmins for $updateStatus");
                $whatsappResult = sendDailyWorkUpdateWhatsAppToAdmins($userName, $userEmail, $submissionData);
                if ($whatsappResult) {
                    error_log("✅ Daily work $updateStatus WhatsApp sent to admins successfully");
                } else {
                    error_log("❌ Failed to send daily work $updateStatus WhatsApp to admins");
                }
            }
        } catch (Exception $e) {
            // Don't fail the submission if WhatsApp fails
            error_log("⚠️ Failed to send daily work $updateStatus WhatsApp notification: " . $e->getMessage());
            error_log("⚠️ Exception trace: " . $e->getTraceAsString());
        }
    }
}

$c = new OwnWorkSubmissionController();
$data = $c->getRequestData();
$c->submitOwnWork($data);
?>
