<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/work_period.php';

class WorkSubmissionController extends BaseAPI {
    public function submit($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Debug logging to verify user isolation
            error_log("🔍 WorkSubmissionController::submit - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . ", Date: " . ($payload['submission_date'] ?? 'no date'));

            $date = $payload['submission_date'] ?? date('Y-m-d');
            $resolvedDate = $this->resolveAttendanceDateOrFail($decoded, $date, 'submit');
            if ($resolvedDate === null) {
                return null;
            }
            $date = $resolvedDate;
            $start = isset($payload['start_time']) && trim($payload['start_time']) !== '' ? $payload['start_time'] : null; // empty string -> NULL for TIME column
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
            $plannedProjects = isset($payload['planned_projects']) && is_array($payload['planned_projects']) ? json_encode($payload['planned_projects']) : null;
            $plannedWork = $payload['planned_work'] ?? null;
            $plannedWorkStatus = $payload['planned_work_status'] ?? 'not_started';
            $plannedWorkNotes = $payload['planned_work_notes'] ?? null;

            // Calendar month totals (1st through submission date)
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

            $this->ensureExtraHoursApprovalColumns();

            // Keep overtime aligned with explicit extra-hours requests.
            $overtime = max(($hours > 8 ? $hours - 8 : 0), $requestedExtraHours);

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
            $hasRequestedExtraHours = in_array('requested_extra_hours', $columns);
            $hasApprovalReason = in_array('approval_reason', $columns);
            $hasBreakEntries = in_array('break_entries', $columns);
            $hasTotalBreakMinutes = in_array('total_break_minutes', $columns);
            
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

            // Persist OT request fields when columns exist (kept separate for backward-compatible inserts).
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

            $this->persistCheckoutPlannedFields($userId, $date, $payload);

            $this->updateOvertimeApprovalOnSubmit($userId, $date, $requestedExtraHours, $approvalReason);

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
                'requested_extra_hours' => $requestedExtraHours,
                'approval_reason' => $approvalReason,
                'break_entries' => $breakEntries,
                'total_break_minutes' => $totalBreakMinutes,
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

            // Send notifications BEFORE the HTTP response — sendJsonResponse() calls exit()
            $updateStatus = $isUpdate ? 'UPDATE' : 'NEW SUBMISSION';
            error_log("📢 NOTIFICATION: Sending admin notifications for work $updateStatus by $userName ($userEmail)");

            try {
                require_once __DIR__ . '/../NotificationManager.php';
                $nm = NotificationManager::getInstance();
                $submissionKey = $userId . ':' . $date;
                $nm->notifyWorkCheckOut($submissionKey, $userId, $userName, $date, $hours, $isUpdate);
                if ($requestedExtraHours > 0) {
                    $nm->notifyOvertimeRequested($submissionKey, $userId, $requestedExtraHours);
                }
            } catch (Throwable $e) {
                error_log("⚠️ Failed in-app/push work update notification: " . $e->getMessage());
            }

            error_log("EMAIL_NOTIFICATION: Starting email notification process");
            try {
                $emailPath = __DIR__ . '/../../utils/email.php';
                require_once $emailPath;

                $adminStmt = $this->conn->prepare(
                    "SELECT email FROM users WHERE account_active = 1 AND (role = 'admin' OR role_id = 1)"
                );
                $adminStmt->execute();
                $adminRows = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
                $adminEmails = array_column($adminRows, 'email');

                if (!empty($adminEmails) && !empty($userEmail)) {
                    $emailResults = sendDailyWorkUpdateEmailToAdmins($adminEmails, $userName, $userEmail, $submissionData);
                    error_log("📧 Daily work $updateStatus emails sent to admins. Results: " . json_encode($emailResults));
                }
            } catch (Exception $e) {
                error_log("⚠️ Failed to send daily work $updateStatus email notification: " . $e->getMessage());
            }

            try {
                $whatsappPath = __DIR__ . '/../../utils/whatsapp.php';
                require_once $whatsappPath;

                if (!empty($userEmail)) {
                    $whatsappResult = sendDailyWorkUpdateWhatsAppToAdmins($userName, $userEmail, $submissionData);
                    error_log($whatsappResult
                        ? "✅ Daily work $updateStatus WhatsApp sent to admins"
                        : "❌ Failed to send daily work $updateStatus WhatsApp to admins");
                }
            } catch (Exception $e) {
                error_log("⚠️ Failed to send daily work $updateStatus WhatsApp notification: " . $e->getMessage());
            }

            $this->sendJsonResponse(200, 'Submission saved');
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
        
        $this->sendSubmissionsListResponse($rows);
    }

    protected function isAdminRole($decoded): bool
    {
        return strtolower((string)($decoded->role ?? '')) === 'admin';
    }

    protected function resolveAttendanceDateOrFail($decoded, string $requestedDate, string $context): ?string
    {
        $userId = (int)($decoded->user_id ?? 0);
        $result = br_validate_attendance_date(
            $this->conn,
            $userId,
            $requestedDate,
            $context,
            $this->isAdminRole($decoded)
        );
        if (!$result['ok']) {
            $this->sendJsonResponse(400, $result['message'] ?? 'Invalid attendance date.');
            return null;
        }
        return $result['date'];
    }

    protected function sendSubmissionsListResponse(array $rows): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'OK',
            'data' => $rows,
            'server_today' => br_server_today(),
        ]);
        exit;
    }

    /**
     * Rolling 12 calendar months ending on the last day of the current month (same as frontend getSubmissionWindow).
     * Always use server time here: a wrong laptop/system clock must not hide real submissions in the DB.
     */
    protected function extraHoursAdminQueryWindow(): array {
        $tzName = @date_default_timezone_get() ?: 'Asia/Kolkata';
        $tz = new DateTimeZone($tzName);
        $base = new DateTime('now', $tz);
        $y = (int) $base->format('Y');
        $m = (int) $base->format('n');
        $windowEnd = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $y, $m), $tz);
        if (!$windowEnd) {
            $windowEnd = clone $base;
        }
        $windowEnd->modify('last day of this month');
        $windowStart = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $y, $m), $tz);
        if (!$windowStart) {
            $windowStart = clone $base;
        }
        $windowStart->modify('-11 months');
        return [
            'from' => $windowStart->format('Y-m-d'),
            'to' => $windowEnd->format('Y-m-d'),
        ];
    }

    public function allRequestSubmissions($q) {
        $decoded = $this->validateToken();
        $role = strtolower((string)($decoded->role ?? ''));
        if ($role !== 'admin') {
            $this->sendJsonResponse(403, 'Access denied');
            return;
        }

        $this->ensureExtraHoursApprovalColumns();

        $win = $this->extraHoursAdminQueryWindow();
        $tzName = @date_default_timezone_get() ?: 'Asia/Kolkata';
        $tz = new DateTimeZone($tzName);
        $now = new DateTime('now', $tz);
        $wideStart = (clone $now)->modify('-6 years')->format('Y-m-d');
        $wideEnd = (clone $now)->modify('+6 years')->format('Y-m-d');
        // Merge rolling 12-month window with a wide envelope so wrong system clocks (same bad date in
        // browser and PHP) do not hide real rows dated years ahead or behind.
        $from = min($win['from'], $wideStart);
        $to = max($win['to'], $wideEnd);

        $pendingOnly = isset($q['pending_only']) && $q['pending_only'] === '1';
        $statusSql = $pendingOnly
            ? " AND COALESCE(ws.extra_hours_approval_status, 'none') = 'pending' "
            : '';

        // Match frontend hasApprovalRequest(): row fields OR legacy/request text in notes.
        // DATE(submission_date): safe if column is DATETIME. LEFT JOIN: still list rows if user row is missing.
        $sql = "SELECT ws.*,
                       COALESCE(NULLIF(TRIM(u.username), ''), CONCAT('user #', ws.user_id)) AS username,
                       COALESCE(u.role, '') AS role
                FROM work_submissions ws
                LEFT JOIN users u ON u.id = ws.user_id
                WHERE DATE(ws.submission_date) BETWEEN ? AND ?
                  AND (
                    COALESCE(ws.requested_extra_hours, 0) > 0
                    OR NULLIF(TRIM(COALESCE(ws.approval_reason, '')), '') IS NOT NULL
                    OR (ws.notes IS NOT NULL AND (
                        ws.notes LIKE '%Requested Extra Hours:%'
                        OR ws.notes LIKE '%[OVERTIME APPROVAL REQUEST]%'
                    ))
                  )
                  $statusSql
                ORDER BY ws.submission_date DESC, ws.id DESC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $adminSql = "SELECT ws.*,
                            COALESCE(NULLIF(TRIM(u.username), ''), CONCAT('user #', ws.user_id)) AS username,
                            COALESCE(u.role, '') AS role
                     FROM work_submissions ws
                     LEFT JOIN users u ON u.id = ws.user_id
                     WHERE DATE(ws.submission_date) BETWEEN ? AND ?
                       AND ws.notes LIKE '%[ADMIN HOURS ENTRY%'
                       AND COALESCE(ws.hours_today, 0) >= 1
                     ORDER BY ws.submission_date DESC, ws.id DESC";
        $adminStmt = $this->conn->prepare($adminSql);
        $adminStmt->execute([$from, $to]);
        $adminRows = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->sendJsonResponse(200, 'OK', [
            'submissions' => $rows,
            'admin_hours_submissions' => $adminRows,
            'window' => ['from' => $from, 'to' => $to],
            'focus_window' => $win,
        ]);
    }

    public function deleteSubmission($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $role = strtolower((string)($decoded->role ?? ''));
            $id = $payload['id'] ?? null;
            $date = $payload['submission_date'] ?? null;

            if (!$id && !$date) {
                return $this->sendJsonResponse(400, 'id or submission_date is required');
            }

            if ($role === 'admin' && $id) {
                $stmt = $this->conn->prepare("DELETE FROM work_submissions WHERE id = ?");
                $stmt->execute([(int)$id]);
                if ($stmt->rowCount() === 0) {
                    $this->sendJsonResponse(404, 'Submission not found');
                    return;
                }
                $this->sendJsonResponse(200, 'Submission deleted');
                return;
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
            $since = $q['since'] ?? br_calendar_month_start($date);

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

            $periodLabel = br_calendar_month_period_label($date);
            $text =
"🧾 CODO Daily Work Update – $name
📅 Date: ".date('j/n/Y l', strtotime($date))."
🕘 Start Time: $start
⏱ Today’s Working Hours: $hours Hours
📊 Total Working Days ($periodLabel): $days ".($days===1?'Day':'Days')."
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

    protected function ensureProjectUpdatesColumn(): void {
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'project_updates'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN project_updates JSON NULL DEFAULT NULL AFTER planned_work_notes");
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    protected function normalizeProjectUpdatesPayload($raw): ?array {
        if (!is_array($raw)) {
            return null;
        }
        $allowed = ['not_started', 'in_progress', 'completed', 'blocked', 'cancelled'];
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item) || empty($item['project_id'])) {
                continue;
            }
            $status = (string)($item['status'] ?? 'in_progress');
            if (!in_array($status, $allowed, true)) {
                $status = 'in_progress';
            }
            $progress = max(0, min(100, (int)($item['progress_percentage'] ?? 0)));
            if ($status === 'completed') {
                $progress = max($progress, 100);
            }
            $notes = trim((string)($item['notes'] ?? ''));
            $out[] = [
                'project_id' => (string)$item['project_id'],
                'status' => $status,
                'progress_percentage' => $progress,
                'notes' => $notes,
            ];
        }
        return $out;
    }

    protected function persistCheckoutPlannedFields(string $userId, string $date, array $payload): void {
        $this->ensureProjectUpdatesColumn();

        $plannedWorkStatus = $payload['planned_work_status'] ?? null;
        $plannedWorkNotes = isset($payload['planned_work_notes']) ? (string)$payload['planned_work_notes'] : null;
        $normalizedUpdates = $this->normalizeProjectUpdatesPayload($payload['project_updates'] ?? null);
        $projectUpdatesJson = ($normalizedUpdates !== null && !empty($normalizedUpdates))
            ? json_encode($normalizedUpdates)
            : null;

        $columnsCheck = $this->conn->query("SHOW COLUMNS FROM work_submissions");
        $columns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);

        $parts = [];
        $values = [];
        if (in_array('planned_work_status', $columns, true) && $plannedWorkStatus !== null && $plannedWorkStatus !== '') {
            $parts[] = 'planned_work_status = ?';
            $values[] = $plannedWorkStatus;
        }
        if (in_array('planned_work_notes', $columns, true)) {
            $parts[] = 'planned_work_notes = ?';
            $values[] = $plannedWorkNotes;
        }
        if (in_array('project_updates', $columns, true)) {
            $parts[] = 'project_updates = ?';
            $values[] = $projectUpdatesJson;
        }

        if (empty($parts)) {
            return;
        }

        $values[] = $userId;
        $values[] = $date;
        $sql = 'UPDATE work_submissions SET ' . implode(', ', $parts) . ' WHERE user_id = ? AND submission_date = ?';
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($values);
    }

    protected function ensureExtraHoursApprovalColumns() {
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'extra_hours_approval_status'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN extra_hours_approval_status VARCHAR(24) NOT NULL DEFAULT 'none' AFTER approval_reason");
            }
        } catch (Exception $e) {
            // ignore
        }
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'extra_hours_approved_amount'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN extra_hours_approved_amount DECIMAL(6,2) NULL DEFAULT NULL AFTER extra_hours_approval_status");
            }
        } catch (Exception $e) {
            // ignore
        }
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'extra_hours_reviewed_by'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN extra_hours_reviewed_by INT UNSIGNED NULL DEFAULT NULL AFTER extra_hours_approved_amount");
            }
        } catch (Exception $e) {
            // ignore
        }
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'extra_hours_reviewed_at'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN extra_hours_reviewed_at DATETIME NULL DEFAULT NULL AFTER extra_hours_reviewed_by");
            }
        } catch (Exception $e) {
            // ignore
        }
        try {
            $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'extra_hours_admin_note'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN extra_hours_admin_note TEXT NULL DEFAULT NULL AFTER extra_hours_reviewed_at");
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    protected function updateOvertimeApprovalOnSubmit($userId, $date, $requestedExtraHours, $approvalReason) {
        try {
            $this->ensureExtraHoursApprovalColumns();
            $columnsCheck = $this->conn->query("SHOW COLUMNS FROM work_submissions");
            $columns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('extra_hours_approval_status', $columns)) {
                return;
            }
            $hasRequest = $requestedExtraHours > 0 || trim((string)$approvalReason) !== '';
            if ($hasRequest) {
                $sql = "UPDATE work_submissions SET extra_hours_approval_status = 'pending', extra_hours_approved_amount = NULL, extra_hours_reviewed_by = NULL, extra_hours_reviewed_at = NULL, extra_hours_admin_note = NULL WHERE user_id = ? AND submission_date = ?";
            } else {
                $sql = "UPDATE work_submissions SET extra_hours_approval_status = 'none', extra_hours_approved_amount = NULL, extra_hours_reviewed_by = NULL, extra_hours_reviewed_at = NULL, extra_hours_admin_note = NULL WHERE user_id = ? AND submission_date = ?";
            }
            $st = $this->conn->prepare($sql);
            $st->execute([$userId, $date]);
        } catch (Exception $e) {
            error_log('updateOvertimeApprovalOnSubmit: ' . $e->getMessage());
        }
    }

    public function reviewOvertimeRequest($payload) {
        try {
            $decoded = $this->validateToken();
            if (strtolower((string)($decoded->role ?? '')) !== 'admin') {
                $this->sendJsonResponse(403, 'Access denied');
                return;
            }
            $this->ensureExtraHoursApprovalColumns();

            $id = isset($payload['id']) ? (int)$payload['id'] : 0;
            $action = strtolower(trim((string)($payload['action'] ?? '')));
            if ($id <= 0 || !in_array($action, ['approve', 'reject', 'change'], true)) {
                $this->sendJsonResponse(400, 'id and action (approve|reject|change) are required');
                return;
            }

            $stmt = $this->conn->prepare("SELECT ws.*, u.username FROM work_submissions ws LEFT JOIN users u ON u.id = ws.user_id WHERE ws.id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $this->sendJsonResponse(404, 'Submission not found');
                return;
            }

            // Keep in sync with allRequestSubmissions(): structured fields, legacy text in notes, or any row
            // already in the extra-hours workflow (so admins can re-review after data quirks).
            $req = (float)($row['requested_extra_hours'] ?? 0) > 0;
            $reason = trim((string)($row['approval_reason'] ?? ''));
            $notes = (string)($row['notes'] ?? '');
            $legacyNotesRequest =
                (stripos($notes, 'Requested Extra Hours:') !== false)
                || (strpos($notes, '[OVERTIME APPROVAL REQUEST]') !== false);
            $statusNorm = strtolower(trim((string)($row['extra_hours_approval_status'] ?? 'none')));
            $inWorkflow = in_array($statusNorm, ['pending', 'approved', 'rejected', 'changed'], true);
            if (!$req && $reason === '' && !$legacyNotesRequest && !$inWorkflow) {
                $this->sendJsonResponse(400, 'This submission has no extra-hour approval request');
                return;
            }

            $adminId = (int)$decoded->user_id;
            $now = date('Y-m-d H:i:s');
            $note = trim((string)($payload['admin_note'] ?? ''));
            if ($action === 'approve' && $note === '') {
                $note = trim((string)($row['extra_hours_admin_note'] ?? ''));
            }
            $extraNote = $note !== '' ? $note : null;
            $reqH = (float)($row['requested_extra_hours'] ?? 0);
            $otNow = (float)($row['overtime_hours'] ?? 0);
            $prevAppr = (float)($row['extra_hours_approved_amount'] ?? 0);
            // Approve: prefer requested hours (e.g. after reject OT was cleared); else keep best-known approved/OT.
            $otToApprove = $reqH > 0 ? $reqH : max($otNow, $prevAppr);

            if ($action === 'approve') {
                $upd = $this->conn->prepare("UPDATE work_submissions SET extra_hours_approval_status = 'approved', extra_hours_approved_amount = ?, overtime_hours = ?, extra_hours_reviewed_by = ?, extra_hours_reviewed_at = ?, extra_hours_admin_note = ? WHERE id = ?");
                $upd->execute([$otToApprove, $otToApprove, $adminId, $now, $extraNote, $id]);
            } elseif ($action === 'reject') {
                $upd = $this->conn->prepare("UPDATE work_submissions SET extra_hours_approval_status = 'rejected', extra_hours_approved_amount = 0, overtime_hours = 0, extra_hours_reviewed_by = ?, extra_hours_reviewed_at = ?, extra_hours_admin_note = ? WHERE id = ?");
                $upd->execute([$adminId, $now, $extraNote, $id]);
            } else {
                $approvedHours = isset($payload['approved_hours']) ? (float)$payload['approved_hours'] : -1;
                if ($approvedHours < 0.25 || $approvedHours > 16) {
                    $this->sendJsonResponse(400, 'approved_hours must be between 0.25 and 16 for change action');
                    return;
                }
                $upd = $this->conn->prepare("UPDATE work_submissions SET extra_hours_approval_status = 'changed', extra_hours_approved_amount = ?, overtime_hours = ?, extra_hours_reviewed_by = ?, extra_hours_reviewed_at = ?, extra_hours_admin_note = ? WHERE id = ?");
                $upd->execute([$approvedHours, $approvedHours, $adminId, $now, $extraNote, $id]);
            }

            $stmt2 = $this->conn->prepare("SELECT ws.*, u.username, u.role FROM work_submissions ws LEFT JOIN users u ON u.id = ws.user_id WHERE ws.id = ? LIMIT 1");
            $stmt2->execute([$id]);
            $out = $stmt2->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(200, 'OK', $out);
        } catch (Exception $e) {
            error_log('reviewOvertimeRequest: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to update request');
        }
    }

    /**
     * Admin-only: add or fix regular work hours when a developer forgot checkout.
     */
    public function adminUpsertSubmission($payload) {
        try {
            $decoded = $this->validateToken();
            if (strtolower((string)($decoded->role ?? '')) !== 'admin') {
                $this->sendJsonResponse(403, 'Access denied');
                return;
            }

            $targetUserId = trim((string)($payload['user_id'] ?? ''));
            $date = trim((string)($payload['submission_date'] ?? ''));
            $hours = isset($payload['hours_today']) ? (float)$payload['hours_today'] : 0;
            $adminNote = trim((string)($payload['admin_note'] ?? ''));
            $workNote = trim((string)($payload['work_note'] ?? 'Admin entry — developer forgot checkout'));

            if ($targetUserId === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $this->sendJsonResponse(400, 'user_id and submission_date (YYYY-MM-DD) are required');
                return;
            }
            if ($hours < 1 || $hours > 8) {
                $this->sendJsonResponse(400, 'hours_today must be between 1 and 8');
                return;
            }
            if ($adminNote === '') {
                $this->sendJsonResponse(400, 'admin_note is required');
                return;
            }
            if ($workNote === '') {
                $workNote = 'Admin entry — developer forgot checkout';
            }

            $userStmt = $this->conn->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([$targetUserId]);
            $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$targetUser) {
                $this->sendJsonResponse(404, 'User not found');
                return;
            }

            $this->ensureExtraHoursApprovalColumns();

            $existingStmt = $this->conn->prepare('SELECT * FROM work_submissions WHERE user_id = ? AND submission_date = ? LIMIT 1');
            $existingStmt->execute([$targetUserId, $date]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($existing) {
                $existingHours = (float)($existing['hours_today'] ?? 0);
                if ($existingHours >= 1) {
                    $this->sendJsonResponse(
                        409,
                        'Work hours are already recorded for this date. Only one entry per day is allowed.'
                    );
                    return;
                }
            }

            $adminUsername = (string)($decoded->username ?? 'admin');
            $auditStamp = '[ADMIN HOURS ENTRY - ' . $adminUsername . ' - ' . date('Y-m-d H:i:s') . "]\n" . $adminNote;
            $existingNotes = trim((string)($existing['notes'] ?? ''));
            $notes = $existingNotes !== '' ? $existingNotes . "\n\n" . $auditStamp : $auditStamp;

            $completed = trim((string)($existing['completed_tasks'] ?? ''));
            if ($completed === '') {
                $completed = $workNote;
            }

            $overtime = 0.0;

            if ($existing) {
                $upd = $this->conn->prepare(
                    'UPDATE work_submissions SET
                        hours_today = ?,
                        overtime_hours = ?,
                        completed_tasks = ?,
                        notes = ?,
                        requested_extra_hours = 0,
                        extra_hours_approval_status = \'none\',
                        extra_hours_approved_amount = NULL,
                        extra_hours_reviewed_by = NULL,
                        extra_hours_reviewed_at = NULL,
                        extra_hours_admin_note = NULL
                     WHERE user_id = ? AND submission_date = ?'
                );
                $upd->execute([
                    $hours,
                    $overtime,
                    $completed,
                    $notes,
                    $targetUserId,
                    $date,
                ]);
            } else {
                $ins = $this->conn->prepare(
                    'INSERT INTO work_submissions (
                        user_id, submission_date, hours_today, overtime_hours,
                        completed_tasks, notes, requested_extra_hours, extra_hours_approval_status
                    ) VALUES (?, ?, ?, ?, ?, ?, 0, \'none\')'
                );
                $ins->execute([
                    $targetUserId,
                    $date,
                    $hours,
                    $overtime,
                    $completed,
                    $notes,
                ]);
            }

            $monthTotals = br_compute_calendar_month_totals($this->conn, $targetUserId, $date);
            $totalsUpd = $this->conn->prepare(
                'UPDATE work_submissions SET total_working_days = ?, total_hours_cumulative = ? WHERE user_id = ? AND submission_date = ?'
            );
            $totalsUpd->execute([
                $monthTotals['days'],
                $monthTotals['hours'],
                $targetUserId,
                $date,
            ]);

            try {
                $auditStmt = $this->conn->prepare(
                    'INSERT INTO admin_audit_log (admin_id, action, target_user_id, details, created_at) VALUES (?, ?, ?, ?, NOW())'
                );
                $auditStmt->execute([
                    (string)$decoded->user_id,
                    'admin_upsert_work_hours',
                    $targetUserId,
                    json_encode([
                        'submission_date' => $date,
                        'hours_today' => $hours,
                        'admin_note' => $adminNote,
                        'work_note' => $workNote,
                        'was_update' => (bool)$existing,
                    ]),
                ]);
            } catch (Exception $auditErr) {
                error_log('adminUpsertSubmission audit log: ' . $auditErr->getMessage());
            }

            $outStmt = $this->conn->prepare(
                'SELECT ws.*, u.username, u.role FROM work_submissions ws LEFT JOIN users u ON u.id = ws.user_id WHERE ws.user_id = ? AND ws.submission_date = ? LIMIT 1'
            );
            $outStmt->execute([$targetUserId, $date]);
            $out = $outStmt->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(200, 'Work hours saved', $out);
        } catch (Exception $e) {
            error_log('adminUpsertSubmission: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to save work hours');
        }
    }

    /**
     * Admin full-day edit: update any fields on an existing work_submissions row.
     */
    public function adminUpdateSubmission($payload) {
        try {
            $decoded = $this->validateToken();
            if (strtolower((string)($decoded->role ?? '')) !== 'admin') {
                $this->sendJsonResponse(403, 'Access denied');
                return;
            }

            $id = isset($payload['id']) ? (int)$payload['id'] : 0;
            if ($id <= 0) {
                $this->sendJsonResponse(400, 'id is required');
                return;
            }

            $this->ensureExtraHoursApprovalColumns();
            $this->ensureProjectUpdatesColumn();

            $existingStmt = $this->conn->prepare('SELECT * FROM work_submissions WHERE id = ? LIMIT 1');
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                $this->sendJsonResponse(404, 'Submission not found');
                return;
            }

            $targetUserId = (string)$existing['user_id'];
            $date = (string)$existing['submission_date'];

            $hours = array_key_exists('hours_today', $payload)
                ? (float)$payload['hours_today']
                : (float)($existing['hours_today'] ?? 0);
            if ($hours < 0 || $hours > 24) {
                $this->sendJsonResponse(400, 'hours_today must be between 0 and 24');
                return;
            }

            $overtime = array_key_exists('overtime_hours', $payload)
                ? (float)$payload['overtime_hours']
                : (float)($existing['overtime_hours'] ?? 0);
            if ($overtime < 0) {
                $overtime = 0.0;
            }

            $requestedExtra = array_key_exists('requested_extra_hours', $payload)
                ? (float)$payload['requested_extra_hours']
                : (float)($existing['requested_extra_hours'] ?? 0);
            if ($requestedExtra < 0) {
                $requestedExtra = 0.0;
            }

            $approvalReason = array_key_exists('approval_reason', $payload)
                ? trim((string)$payload['approval_reason'])
                : ($existing['approval_reason'] ?? null);
            if ($approvalReason === '') {
                $approvalReason = null;
            }

            $otStatus = array_key_exists('extra_hours_approval_status', $payload)
                ? strtolower(trim((string)$payload['extra_hours_approval_status']))
                : strtolower(trim((string)($existing['extra_hours_approval_status'] ?? 'none')));
            $allowedStatuses = ['none', 'pending', 'approved', 'rejected', 'changed'];
            if (!in_array($otStatus, $allowedStatuses, true)) {
                $this->sendJsonResponse(400, 'Invalid extra_hours_approval_status');
                return;
            }

            $approvedAmount = array_key_exists('extra_hours_approved_amount', $payload)
                ? ($payload['extra_hours_approved_amount'] === null || $payload['extra_hours_approved_amount'] === ''
                    ? null
                    : (float)$payload['extra_hours_approved_amount'])
                : (isset($existing['extra_hours_approved_amount']) ? (float)$existing['extra_hours_approved_amount'] : null);

            $startTime = array_key_exists('start_time', $payload)
                ? (trim((string)$payload['start_time']) !== '' ? trim((string)$payload['start_time']) : null)
                : ($existing['start_time'] ?? null);

            $checkInTime = array_key_exists('check_in_time', $payload)
                ? (trim((string)$payload['check_in_time']) !== '' ? trim((string)$payload['check_in_time']) : null)
                : ($existing['check_in_time'] ?? null);

            $completed = array_key_exists('completed_tasks', $payload)
                ? (string)$payload['completed_tasks']
                : (string)($existing['completed_tasks'] ?? '');
            $pending = array_key_exists('pending_tasks', $payload)
                ? (string)$payload['pending_tasks']
                : (string)($existing['pending_tasks'] ?? '');
            $ongoing = array_key_exists('ongoing_tasks', $payload)
                ? (string)$payload['ongoing_tasks']
                : (string)($existing['ongoing_tasks'] ?? '');
            $notes = array_key_exists('notes', $payload)
                ? (string)$payload['notes']
                : (string)($existing['notes'] ?? '');

            $plannedWork = array_key_exists('planned_work', $payload)
                ? (trim((string)$payload['planned_work']) !== '' ? (string)$payload['planned_work'] : null)
                : ($existing['planned_work'] ?? null);
            $plannedWorkStatus = array_key_exists('planned_work_status', $payload)
                ? (trim((string)$payload['planned_work_status']) !== '' ? trim((string)$payload['planned_work_status']) : null)
                : ($existing['planned_work_status'] ?? null);
            $plannedWorkNotes = array_key_exists('planned_work_notes', $payload)
                ? (trim((string)$payload['planned_work_notes']) !== '' ? (string)$payload['planned_work_notes'] : null)
                : ($existing['planned_work_notes'] ?? null);

            if (array_key_exists('planned_projects', $payload)) {
                if (is_array($payload['planned_projects'])) {
                    $plannedProjects = !empty($payload['planned_projects'])
                        ? json_encode(array_values($payload['planned_projects']))
                        : null;
                } elseif (is_string($payload['planned_projects']) && trim($payload['planned_projects']) !== '') {
                    $plannedProjects = trim($payload['planned_projects']);
                } else {
                    $plannedProjects = null;
                }
            } else {
                $plannedProjects = $existing['planned_projects'] ?? null;
            }

            $breakEntries = [];
            if (array_key_exists('break_entries', $payload)) {
                if (is_array($payload['break_entries'])) {
                    $breakEntries = array_values(array_filter(array_map(function ($entry) {
                        return trim((string)$entry);
                    }, $payload['break_entries']), function ($entry) {
                        return $entry !== '';
                    }));
                } elseif (is_string($payload['break_entries']) && trim($payload['break_entries']) !== '') {
                    $breakEntries = array_values(array_filter(array_map('trim', explode("\n", $payload['break_entries']))));
                }
            } else {
                $rawBreak = $existing['break_entries'] ?? null;
                if (is_string($rawBreak) && $rawBreak !== '') {
                    $decodedBreak = json_decode($rawBreak, true);
                    if (is_array($decodedBreak)) {
                        $breakEntries = $decodedBreak;
                    }
                } elseif (is_array($rawBreak)) {
                    $breakEntries = $rawBreak;
                }
            }

            $totalBreakMinutes = array_key_exists('total_break_minutes', $payload)
                ? max(0, (int)$payload['total_break_minutes'])
                : (int)($existing['total_break_minutes'] ?? 0);
            if ($totalBreakMinutes === 0 && !empty($breakEntries)) {
                $computedMins = 0;
                foreach ($breakEntries as $entry) {
                    if (preg_match('/\((\d+)\s*min\)/i', (string)$entry, $matches)) {
                        $computedMins += (int)$matches[1];
                    }
                }
                $totalBreakMinutes = $computedMins;
            }

            $adminNote = trim((string)($payload['admin_note'] ?? ''));
            if ($adminNote !== '') {
                $adminUsername = (string)($decoded->username ?? 'admin');
                $auditStamp = '[ADMIN EDIT - ' . $adminUsername . ' - ' . date('Y-m-d H:i:s') . "]\n" . $adminNote;
                $notes = trim($notes) !== '' ? $notes . "\n\n" . $auditStamp : $auditStamp;
            }

            $cols = $this->conn->query('SHOW COLUMNS FROM work_submissions')->fetchAll(PDO::FETCH_COLUMN);
            $setParts = [
                'hours_today = ?',
                'overtime_hours = ?',
                'completed_tasks = ?',
                'pending_tasks = ?',
                'ongoing_tasks = ?',
                'notes = ?',
            ];
            $values = [$hours, $overtime, $completed, $pending, $ongoing, $notes];

            if (in_array('start_time', $cols, true)) {
                $setParts[] = 'start_time = ?';
                $values[] = $startTime;
            }
            if (in_array('check_in_time', $cols, true)) {
                $setParts[] = 'check_in_time = ?';
                $values[] = $checkInTime;
            }
            if (in_array('requested_extra_hours', $cols, true)) {
                $setParts[] = 'requested_extra_hours = ?';
                $values[] = $requestedExtra;
            }
            if (in_array('approval_reason', $cols, true)) {
                $setParts[] = 'approval_reason = ?';
                $values[] = $approvalReason;
            }
            if (in_array('extra_hours_approval_status', $cols, true)) {
                $setParts[] = 'extra_hours_approval_status = ?';
                $values[] = $otStatus;
            }
            if (in_array('extra_hours_approved_amount', $cols, true)) {
                $setParts[] = 'extra_hours_approved_amount = ?';
                $values[] = $approvedAmount;
            }
            if (in_array('break_entries', $cols, true)) {
                $setParts[] = 'break_entries = ?';
                $values[] = !empty($breakEntries) ? json_encode($breakEntries) : null;
            }
            if (in_array('total_break_minutes', $cols, true)) {
                $setParts[] = 'total_break_minutes = ?';
                $values[] = $totalBreakMinutes;
            }
            if (in_array('planned_projects', $cols, true)) {
                $setParts[] = 'planned_projects = ?';
                $values[] = $plannedProjects;
            }
            if (in_array('planned_work', $cols, true)) {
                $setParts[] = 'planned_work = ?';
                $values[] = $plannedWork;
            }
            if (in_array('planned_work_status', $cols, true)) {
                $setParts[] = 'planned_work_status = ?';
                $values[] = $plannedWorkStatus;
            }
            if (in_array('planned_work_notes', $cols, true)) {
                $setParts[] = 'planned_work_notes = ?';
                $values[] = $plannedWorkNotes;
            }

            $values[] = $id;
            $sql = 'UPDATE work_submissions SET ' . implode(', ', $setParts) . ' WHERE id = ?';
            $upd = $this->conn->prepare($sql);
            $upd->execute($values);

            $monthTotals = br_compute_calendar_month_totals($this->conn, $targetUserId, $date);
            $totalsUpd = $this->conn->prepare(
                'UPDATE work_submissions SET total_working_days = ?, total_hours_cumulative = ? WHERE user_id = ? AND submission_date = ?'
            );
            $totalsUpd->execute([
                $monthTotals['days'],
                $monthTotals['hours'],
                $targetUserId,
                $date,
            ]);

            try {
                $auditStmt = $this->conn->prepare(
                    'INSERT INTO admin_audit_log (admin_id, action, target_user_id, details, created_at) VALUES (?, ?, ?, ?, NOW())'
                );
                $auditStmt->execute([
                    (string)$decoded->user_id,
                    'admin_update_work_submission',
                    $targetUserId,
                    json_encode([
                        'id' => $id,
                        'submission_date' => $date,
                        'hours_today' => $hours,
                        'overtime_hours' => $overtime,
                        'admin_note' => $adminNote !== '' ? $adminNote : null,
                    ]),
                ]);
            } catch (Exception $auditErr) {
                error_log('adminUpdateSubmission audit log: ' . $auditErr->getMessage());
            }

            $outStmt = $this->conn->prepare(
                'SELECT ws.*, u.username, u.role FROM work_submissions ws LEFT JOIN users u ON u.id = ws.user_id WHERE ws.id = ? LIMIT 1'
            );
            $outStmt->execute([$id]);
            $out = $outStmt->fetch(PDO::FETCH_ASSOC);
            $this->sendJsonResponse(200, 'Submission updated', $out);
        } catch (Exception $e) {
            error_log('adminUpdateSubmission: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Failed to update submission');
        }
    }
}
?>
