<?php
require_once __DIR__ . '/../BaseAPI.php';

class WorkSubmissionController extends BaseAPI {
    public function submit($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Debug logging to verify user isolation
            error_log("🔍 WorkSubmissionController::submit - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . ", Date: " . ($payload['submission_date'] ?? 'no date'));

            $date = $payload['submission_date'] ?? date('Y-m-d');
            // Do not allow future dates
            if (strtotime($date) > strtotime(date('Y-m-d'))) {
                return $this->sendJsonResponse(400, 'Future dates are not allowed');
            }
            $start = isset($payload['start_time']) && trim($payload['start_time']) !== '' ? $payload['start_time'] : null; // empty string -> NULL for TIME column
            $hours = isset($payload['hours_today']) ? (float)$payload['hours_today'] : 0;
            $days = isset($payload['total_working_days']) ? (int)$payload['total_working_days'] : null;
            $cumulative = isset($payload['total_hours_cumulative']) ? (float)$payload['total_hours_cumulative'] : null;
            $completed = $payload['completed_tasks'] ?? null;
            $pending = $payload['pending_tasks'] ?? null;
            $notes = $payload['notes'] ?? null;
            $ongoing = $payload['ongoing_tasks'] ?? null;
            $plannedProjects = isset($payload['planned_projects']) && is_array($payload['planned_projects']) ? json_encode($payload['planned_projects']) : null;
            $plannedWork = $payload['planned_work'] ?? null;
            $plannedWorkStatus = $payload['planned_work_status'] ?? 'not_started';
            $plannedWorkNotes = $payload['planned_work_notes'] ?? null;

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

            // Auto-migrate: add check_in_time column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'check_in_time'");
                if ($check->rowCount() === 0) {
                    $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN check_in_time TIMESTAMP NULL DEFAULT NULL AFTER start_time");
                }
            } catch (Exception $e) {
                // ignore; migration may fail if no permissions
            }

            // Auto-migrate: add planned_projects column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'planned_projects'");
                if ($check->rowCount() === 0) {
                    $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN planned_projects JSON NULL DEFAULT NULL AFTER check_in_time");
                }
            } catch (Exception $e) {
                // ignore; migration may fail if no permissions
            }

            // Auto-migrate: add planned_work column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'planned_work'");
                if ($check->rowCount() === 0) {
                    $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN planned_work TEXT NULL DEFAULT NULL AFTER planned_projects");
                }
            } catch (Exception $e) {
                // ignore; migration may fail if no permissions
            }

            // Auto-migrate: add planned_work_status column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'planned_work_status'");
                if ($check->rowCount() === 0) {
                    $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN planned_work_status ENUM('not_started', 'in_progress', 'completed', 'blocked', 'cancelled') NULL DEFAULT 'not_started' AFTER planned_work");
                }
            } catch (Exception $e) {
                // ignore; migration may fail if no permissions
            }

            // Auto-migrate: add planned_work_notes column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'planned_work_notes'");
                if ($check->rowCount() === 0) {
                    $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN planned_work_notes TEXT NULL DEFAULT NULL AFTER planned_work_status");
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

            // Check if planned_projects, planned_work, planned_work_status, and planned_work_notes columns exist
            $columnsCheck = $this->conn->query("SHOW COLUMNS FROM work_submissions");
            $columns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
            $hasPlannedProjects = in_array('planned_projects', $columns);
            $hasPlannedWork = in_array('planned_work', $columns);
            $hasPlannedWorkStatus = in_array('planned_work_status', $columns);
            $hasPlannedWorkNotes = in_array('planned_work_notes', $columns);
            
            if ($hasPlannedProjects && $hasPlannedWork && $hasPlannedWorkStatus && $hasPlannedWorkNotes) {
                $sql = "INSERT INTO work_submissions (user_id, submission_date, start_time, hours_today, overtime_hours, total_working_days, total_hours_cumulative, completed_tasks, pending_tasks, ongoing_tasks, notes, planned_projects, planned_work, planned_work_status, planned_work_notes)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), hours_today=VALUES(hours_today), overtime_hours=VALUES(overtime_hours), total_working_days=VALUES(total_working_days),
                        total_hours_cumulative=VALUES(total_hours_cumulative), completed_tasks=VALUES(completed_tasks), pending_tasks=VALUES(pending_tasks), ongoing_tasks=VALUES(ongoing_tasks), notes=VALUES(notes),
                        planned_projects=VALUES(planned_projects), planned_work=VALUES(planned_work), planned_work_status=VALUES(planned_work_status), planned_work_notes=VALUES(planned_work_notes)";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$userId, $date, $start, $hours, $overtime, $days, $cumulative, $completed, $pending, $ongoing, $notes, $plannedProjects, $plannedWork, $plannedWorkStatus, $plannedWorkNotes]);
            } elseif ($hasPlannedProjects && $hasPlannedWork && $hasPlannedWorkStatus) {
                $sql = "INSERT INTO work_submissions (user_id, submission_date, start_time, hours_today, overtime_hours, total_working_days, total_hours_cumulative, completed_tasks, pending_tasks, ongoing_tasks, notes, planned_projects, planned_work, planned_work_status)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), hours_today=VALUES(hours_today), overtime_hours=VALUES(overtime_hours), total_working_days=VALUES(total_working_days),
                        total_hours_cumulative=VALUES(total_hours_cumulative), completed_tasks=VALUES(completed_tasks), pending_tasks=VALUES(pending_tasks), ongoing_tasks=VALUES(ongoing_tasks), notes=VALUES(notes),
                        planned_projects=VALUES(planned_projects), planned_work=VALUES(planned_work), planned_work_status=VALUES(planned_work_status)";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$userId, $date, $start, $hours, $overtime, $days, $cumulative, $completed, $pending, $ongoing, $notes, $plannedProjects, $plannedWork, $plannedWorkStatus]);
            } elseif ($hasPlannedProjects && $hasPlannedWork) {
                $sql = "INSERT INTO work_submissions (user_id, submission_date, start_time, hours_today, overtime_hours, total_working_days, total_hours_cumulative, completed_tasks, pending_tasks, ongoing_tasks, notes, planned_projects, planned_work)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), hours_today=VALUES(hours_today), overtime_hours=VALUES(overtime_hours), total_working_days=VALUES(total_working_days),
                        total_hours_cumulative=VALUES(total_hours_cumulative), completed_tasks=VALUES(completed_tasks), pending_tasks=VALUES(pending_tasks), ongoing_tasks=VALUES(ongoing_tasks), notes=VALUES(notes),
                        planned_projects=VALUES(planned_projects), planned_work=VALUES(planned_work)";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$userId, $date, $start, $hours, $overtime, $days, $cumulative, $completed, $pending, $ongoing, $notes, $plannedProjects, $plannedWork]);
            } else {
                $sql = "INSERT INTO work_submissions (user_id, submission_date, start_time, hours_today, overtime_hours, total_working_days, total_hours_cumulative, completed_tasks, pending_tasks, ongoing_tasks, notes)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE start_time=VALUES(start_time), hours_today=VALUES(hours_today), overtime_hours=VALUES(overtime_hours), total_working_days=VALUES(total_working_days),
                        total_hours_cumulative=VALUES(total_hours_cumulative), completed_tasks=VALUES(completed_tasks), pending_tasks=VALUES(pending_tasks), ongoing_tasks=VALUES(ongoing_tasks), notes=VALUES(notes)";
                $stmt = $this->conn->prepare($sql);
                $stmt->execute([$userId, $date, $start, $hours, $overtime, $days, $cumulative, $completed, $pending, $ongoing, $notes]);
            }

            error_log("🔍 WorkSubmissionController::submit - Saved submission for user: " . $userId . " on date: " . $date);
            
            // Prepare notification data (shared for email and WhatsApp)
            $userStmt = $this->conn->prepare("SELECT username, email FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            $userName = $user['username'] ?? 'User';
            $userEmail = $user['email'] ?? '';
            
            // Fetch planned_projects, planned_work, planned_work_status, and planned_work_notes from database if they exist
            $plannedProjectsData = null;
            $plannedWorkData = null;
            $plannedWorkStatusData = 'not_started';
            $plannedWorkNotesData = null;
            if ($hasPlannedProjects && $hasPlannedWork) {
                $selectFields = "planned_projects, planned_work";
                if ($hasPlannedWorkStatus) {
                    $selectFields .= ", planned_work_status";
                }
                if ($hasPlannedWorkNotes) {
                    $selectFields .= ", planned_work_notes";
                }
                $fetchStmt = $this->conn->prepare("SELECT $selectFields FROM work_submissions WHERE user_id = ? AND submission_date = ? LIMIT 1");
                $fetchStmt->execute([$userId, $date]);
                $plannedData = $fetchStmt->fetch(PDO::FETCH_ASSOC);
                if ($plannedData) {
                    $plannedProjectsData = $plannedData['planned_projects'] ? json_decode($plannedData['planned_projects'], true) : null;
                    $plannedWorkData = $plannedData['planned_work'];
                    $plannedWorkStatusData = $plannedData['planned_work_status'] ?? 'not_started';
                    $plannedWorkNotesData = $plannedData['planned_work_notes'] ?? null;
                }
            }
            
            // Fetch total_working_days and total_hours_cumulative from database
            $totalWorkingDays = null;
            $totalHoursCumulative = null;
            try {
                $totalStmt = $this->conn->prepare("SELECT total_working_days, total_hours_cumulative FROM work_submissions WHERE user_id = ? AND submission_date = ? LIMIT 1");
                $totalStmt->execute([$userId, $date]);
                $totalData = $totalStmt->fetch(PDO::FETCH_ASSOC);
                if ($totalData) {
                    $totalWorkingDays = $totalData['total_working_days'] ?? 0;
                    $totalHoursCumulative = $totalData['total_hours_cumulative'] ?? 0;
                }
            } catch (Exception $e) {
                error_log("⚠️ Could not fetch total working days/hours: " . $e->getMessage());
            }
            
            $submissionData = [
                'submission_date' => $date,
                'start_time' => $start,
                'check_in_time' => null, // Will be fetched from DB if exists
                'hours_today' => $hours,
                'overtime_hours' => $overtime,
                'total_working_days' => $totalWorkingDays ?? 0,
                'total_hours_cumulative' => $totalHoursCumulative ?? 0,
                'completed_tasks' => $completed,
                'pending_tasks' => $pending,
                'ongoing_tasks' => $ongoing,
                'notes' => $notes,
                'planned_projects' => $plannedProjectsData,
                'planned_work' => $plannedWorkData,
                'planned_work_status' => $plannedWorkStatusData,
                'planned_work_notes' => $plannedWorkNotesData,
                'is_update' => $isUpdate,
                '_db_conn' => $this->conn // Pass connection for project name lookup
            ];
            
            // Fetch check_in_time if it exists
            if ($hasPlannedProjects) {
                $checkInStmt = $this->conn->prepare("SELECT check_in_time FROM work_submissions WHERE user_id = ? AND submission_date = ? LIMIT 1");
                $checkInStmt->execute([$userId, $date]);
                $checkInData = $checkInStmt->fetch(PDO::FETCH_ASSOC);
                if ($checkInData && $checkInData['check_in_time']) {
                    $submissionData['check_in_time'] = $checkInData['check_in_time'];
                }
            }
            
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
        } catch (Exception $e) {
            error_log('WorkSubmission submit error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to save submission');
        }
    }

    public function mySubmissions($q) {
        $requestId = uniqid('req_', true);
        error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - Starting");
        
        $decoded = $this->validateToken();
        $userId = $decoded->user_id;
        $from = $q['from'] ?? date('Y-m-01');
        $to = $q['to'] ?? date('Y-m-t');
        
        // Debug logging to verify user isolation and impersonation
        $impersonationInfo = isset($decoded->impersonated) && $decoded->impersonated ? " (IMPERSONATED)" : "";
        $roleInfo = isset($decoded->role) ? " Role: " . $decoded->role : "";
        $adminInfo = isset($decoded->admin_id) ? " Admin ID: " . $decoded->admin_id : "";
        error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . $impersonationInfo . $roleInfo . $adminInfo . ", Date range: $from to $to");
        
        // Additional debugging - log the full decoded token
        error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - Full decoded token: " . json_encode($decoded));
        
        // Force clear any cached token validation to ensure fresh processing
        $this->clearCache();

        $sql = "SELECT * FROM work_submissions WHERE user_id = ? AND submission_date BETWEEN ? AND ? ORDER BY submission_date DESC";
        error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - SQL Query: " . $sql);
        error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - Query Parameters: userId=$userId, from=$from, to=$to");
        
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId, $from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - Found " . count($rows) . " submissions for user: " . $userId . $impersonationInfo);
        
        // Log the first few rows to see what user_ids are being returned
        if (count($rows) > 0) {
            $firstRow = $rows[0];
            error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - First row user_id: " . ($firstRow['user_id'] ?? 'not set'));
            error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - First row submission_date: " . ($firstRow['submission_date'] ?? 'not set'));
        } else {
            error_log("🔍 WorkSubmissionController::mySubmissions - Request ID: $requestId - No rows returned for user: " . $userId);
        }
        
        $this->sendJsonResponse(200, 'OK', $rows);
    }

    public function deleteSubmission($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $id = $payload['id'] ?? null;
            $date = $payload['submission_date'] ?? null;

            if (!$id && !$date) {
                return $this->sendJsonResponse(400, 'id or submission_date is required');
            }

            if ($id) {
                $stmt = $this->conn->prepare("DELETE FROM work_submissions WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $userId]);
            } else {
                $stmt = $this->conn->prepare("DELETE FROM work_submissions WHERE submission_date = ? AND user_id = ?");
                $stmt->execute([$date, $userId]);
            }

            $this->sendJsonResponse(200, 'Submission deleted');
        } catch (Exception $e) {
            error_log('WorkSubmission delete error: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to delete submission');
        }
    }

    public function templateText($q) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;

            $date = $q['date'] ?? date('Y-m-d');
            $since = $q['since'] ?? null;

            $stmt = $this->conn->prepare("SELECT username, name FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $name = $u['name'] ?? ($u['username'] ?? 'User');

            $stmt = $this->conn->prepare("SELECT * FROM work_submissions WHERE user_id = ? AND submission_date = ?");
            $stmt->execute([$userId, $date]);
            $sub = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $hours = number_format((float)($sub['hours_today'] ?? 0), 0);
            // Use check_in_time if available, otherwise fallback to start_time
            $checkInTime = $sub['check_in_time'] ?? null;
            $startRaw = $sub['start_time'] ?? null;
            $timeToUse = $checkInTime ? $checkInTime : $startRaw;
            
            // Format time to 12h with AM/PM for readability
            if ($timeToUse) {
                // Handle both TIME and TIMESTAMP formats
                if (strpos($timeToUse, ' ') !== false) {
                    // TIMESTAMP format: extract time part
                    $timePart = explode(' ', $timeToUse)[1];
                    $start = date('h:i A', strtotime($timePart));
                } else {
                    // TIME format
                    $start = date('h:i A', strtotime($timeToUse));
                }
            } else {
                $start = '----';
            }
            $completed = trim((string)($sub['completed_tasks'] ?? ''));
            $pending = trim((string)($sub['pending_tasks'] ?? ''));
            $upcoming = trim((string)($sub['notes'] ?? ''));
            $ongoing = trim((string)($sub['ongoing_tasks'] ?? ''));

            if ($since) {
                $stmt = $this->conn->prepare("SELECT COUNT(*) days, COALESCE(SUM(hours_today),0) hours FROM work_submissions WHERE user_id = ? AND submission_date >= ?");
                $stmt->execute([$userId, $since]);
            } else {
                $stmt = $this->conn->prepare("SELECT COUNT(*) days, COALESCE(SUM(hours_today),0) hours FROM work_submissions WHERE user_id = ?");
                $stmt->execute([$userId]);
            }
            $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['days' => 0, 'hours' => 0];
            $days = (int)$agg['days'];
            $totalHours = (float)$agg['hours'];

            $count = function($txt) {
                $lines = array_filter(array_map('trim', explode("\n", (string)$txt)), function($x){ return $x !== ''; });
                return count($lines);
            };
            $completedCount = $count($completed);
            $pendingCount = $count($pending);
            $ongoingCount = $count($ongoing);
            $upcomingCount = $count($upcoming);

            $completedLines = ($completedCount > 0 ? " (".$completedCount.")" : "");
            $pendingLines = ($pendingCount > 0 ? " (".$pendingCount.")" : "");
            $ongoingLines = ($ongoingCount > 0 ? " (".$ongoingCount.")" : "");
            $upcomingLines = ($upcomingCount > 0 ? " (".$upcomingCount.")" : "");

            $text =
"🧾 CODO Daily Work Update – $name
📅 Date: ".date('j/n/Y l', strtotime($date))."
🕘 Start Time: $start
⏱ Today’s Working Hours: $hours Hours
📊 Total Working Days".($since ? " (Since ".date('j F', strtotime($since)).")" : "").": $days ".($days===1?'Day':'Days')."
🧮 Total Hours Completed : $totalHours hours

✅ Completed$completedLines\n".($completed ? "\n$completed" : '')."

⌛ Pending$pendingLines\n".($pending ? "\n$pending" : '')."

🔄 Ongoing$ongoingLines\n".($ongoing ? "\n$ongoing" : '')."

🔥 Upcoming$upcomingLines\n".($upcoming ? "\n$upcoming" : '');

            $this->sendJsonResponse(200, 'OK', ['text' => $text]);
        } catch (Exception $e) {
            error_log('WorkSubmission templateText error: ' . $e->getMessage());
            // Fallback template if DB lookup fails (avoid 500s in UI)
            $date = $q['date'] ?? date('Y-m-d');
            $name = 'User';
            $fallback = "🧾 CODO Daily Work Update – $name\n".
                        "📅 Date: ".date('j/n/Y', strtotime($date))."\n".
                        "🕘 Start Time: ----\n".
                        "⏱ Today’s Working Hours: 0 Hours\n".
                        "📊 Total Working Days: 0 Days\n".
                        "🧮 Total Hours Completed : 0 hours\n\n".
                        "✅ Completed Tasks:\n\nPending\n";
            $this->sendJsonResponse(200, 'OK', ['text' => $fallback]);
        }
    }
}
?>


