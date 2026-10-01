<?php
require_once __DIR__ . '/WorkSubmissionController.php';
require_once __DIR__ . '/../../utils/user_onboarding.php';
require_once __DIR__ . '/../../utils/weekly_report.php';

class OwnWorkSubmissionController extends WorkSubmissionController {
    public function submitOwnWork($payload) {
        // Use the standard validateToken method which handles impersonation correctly
        $decoded = $this->validateToken();
        if (!br_require_workforce($this, $this->conn, $decoded)) {
            return null;
        }
        $userId = $decoded->user_id;
        
        // Debug logging to verify user isolation and impersonation
        $impersonationInfo = isset($decoded->impersonated) && $decoded->impersonated ? " (IMPERSONATED)" : "";
        $adminInfo = isset($decoded->admin_id) ? " Admin: " . $decoded->admin_id : "";
        error_log("🔍 OwnWorkSubmissionController::submitOwnWork - User ID: " . $userId . ", Username: " . ($decoded->username ?? 'unknown') . $impersonationInfo . $adminInfo . ", Date: " . ($payload['submission_date'] ?? 'no date'));

        $onboardingGate = br_assert_onboarding_allows_attendance($this->conn, (string)$userId);
        if (empty($onboardingGate['ok'])) {
            $this->sendJsonResponse(403, $onboardingGate['message'] ?? 'Checkout blocked until onboarding is verified.');
            return null;
        }

        $date = $payload['submission_date'] ?? date('Y-m-d');
        $resolvedDate = $this->resolveAttendanceDateOrFail($decoded, $date, 'submit');
        if ($resolvedDate === null) {
            return null;
        }
        $date = $resolvedDate;
        
        $start = isset($payload['start_time']) && trim($payload['start_time']) !== '' ? $payload['start_time'] : null;
        $hours = isset($payload['hours_today']) ? (float)$payload['hours_today'] : 0;
        $weeklyGate = br_assert_saturday_weekly_report_for_checkout($this->conn, (string)$userId, (string)$date, $hours);
        if (empty($weeklyGate['ok'])) {
            $this->sendJsonResponse(400, $weeklyGate['message'] ?? 'Submit your weekly report before Saturday checkout.');
            return null;
        }
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
        
        br_ensure_work_submission_ot_columns($this->conn);

        $columns = $this->conn->query("SHOW COLUMNS FROM work_submissions")->fetchAll(PDO::FETCH_COLUMN);
        $autoMigrations = [
            'overtime_hours' => "ALTER TABLE work_submissions ADD COLUMN overtime_hours DECIMAL(6,2) DEFAULT 0 AFTER hours_today",
            'ongoing_tasks' => "ALTER TABLE work_submissions ADD COLUMN ongoing_tasks MEDIUMTEXT AFTER pending_tasks",
            'requested_extra_hours' => "ALTER TABLE work_submissions ADD COLUMN requested_extra_hours DECIMAL(6,2) DEFAULT 0 AFTER overtime_hours",
            'approval_reason' => "ALTER TABLE work_submissions ADD COLUMN approval_reason TEXT NULL AFTER requested_extra_hours",
            'break_entries' => "ALTER TABLE work_submissions ADD COLUMN break_entries JSON NULL DEFAULT NULL AFTER approval_reason",
            'total_break_minutes' => "ALTER TABLE work_submissions ADD COLUMN total_break_minutes INT DEFAULT 0 AFTER break_entries",
        ];
        foreach ($autoMigrations as $col => $ddl) {
            if (in_array($col, $columns, true)) {
                continue;
            }
            try {
                $this->conn->exec($ddl);
                $columns[] = $col;
            } catch (Exception $e) {
                // ignore; migration may fail if no permissions
            }
        }
        
        // Keep overtime aligned with explicit extra-hours requests.
        $overtime = max(($hours > 8 ? $hours - 8 : 0), $requestedExtraHours);

        $reviveStmt = $this->conn->prepare(
            'SELECT id, deleted_at FROM work_submissions WHERE user_id = ? AND submission_date = ? LIMIT 1'
        );
        $reviveStmt->execute([$userId, $date]);
        $reviveRow = $reviveStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($reviveRow && br_work_submission_is_soft_deleted($reviveRow)) {
            br_work_submission_revive($this->conn, (string)$reviveRow['id']);
        }
        
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

        $this->persistCheckoutPlannedFields($userId, $date, $payload);

        $this->updateOvertimeApprovalOnSubmit($userId, $date, $requestedExtraHours, $approvalReason);
        
        error_log("🔍 OwnWorkSubmissionController::submitOwnWork - Saved submission for user: " . $userId . " on date: " . $date . $impersonationInfo);
        
        $conn = $this->conn;
        $checkInTime = isset($payload['check_in_time']) && trim((string)$payload['check_in_time']) !== ''
            ? (string)$payload['check_in_time']
            : null;
        $projectUpdates = $payload['project_updates'] ?? null;
        $startedAt = microtime(true);

        // Push, SMTP and WhatsApp take seconds — reply first so "Check out" is instant.
        $this->sendJsonThen(
            function () use (
                $conn, $userId, $date, $start, $hours, $overtime, $days, $cumulative, $requestedExtraHours,
                $approvalReason, $breakEntries, $totalBreakMinutes, $completed, $pending, $ongoing, $notes,
                $isUpdate, $checkInTime, $projectUpdates, $startedAt
            ) {
                if (is_array($projectUpdates) && !empty($projectUpdates)) {
                    try {
                        $this->recomputeDeveloperHoursTakenFromProjectUpdates($projectUpdates);
                    } catch (Throwable $e) {
                        error_log('⚠️ Deferred project hours recompute: ' . $e->getMessage());
                    }
                }

                $userStmt = $conn->prepare("SELECT username, email FROM users WHERE id = ? LIMIT 1");
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $userName = $user['username'] ?? 'User';
                $userEmail = $user['email'] ?? '';

                try {
                    require_once __DIR__ . '/../../utils/work_update_notifications.php';
                    br_notify_employee_work_checkout($conn, (string)$userId, (string)$date, [
                        'check_in_time' => $checkInTime,
                        'hours_today' => $hours,
                        'total_break_minutes' => $totalBreakMinutes,
                        'requested_extra_hours' => $requestedExtraHours,
                        'project_updates' => is_array($projectUpdates) ? $projectUpdates : null,
                        'total_working_days' => $days,
                        'total_hours_cumulative' => $cumulative,
                    ], $isUpdate);
                } catch (Throwable $e) {
                    error_log('⚠️ Failed employee checkout receipt: ' . $e->getMessage());
                }

                try {
                    require_once __DIR__ . '/../NotificationManager.php';
                    $nm = NotificationManager::getInstance();
                    $submissionKey = $userId . ':' . $date;
                    $nm->notifyWorkCheckOut($submissionKey, $userId, $userName, $date, $hours, $isUpdate);
                    if ($requestedExtraHours > 0) {
                        $nm->notifyOvertimeRequested($submissionKey, $userId, $requestedExtraHours);
                    }
                } catch (Throwable $e) {
                    error_log('⚠️ Failed in-app/push work update notification: ' . $e->getMessage());
                }

                $submissionData = [
                    'submission_date' => $date,
                    'start_time' => $start,
                    'check_in_time' => $checkInTime,
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
                    'is_update' => $isUpdate,
                ];

                if (!empty($userEmail)) {
                    try {
                        require_once __DIR__ . '/../../utils/email.php';
                        $adminStmt = $conn->prepare(
                            "SELECT email FROM users WHERE account_active = 1 AND (role = 'admin' OR role_id = 1)"
                        );
                        $adminStmt->execute();
                        $adminEmails = array_column($adminStmt->fetchAll(PDO::FETCH_ASSOC), 'email');
                        if (!empty($adminEmails)) {
                            sendDailyWorkUpdateEmailToAdmins($adminEmails, $userName, $userEmail, $submissionData);
                        }
                    } catch (Throwable $e) {
                        error_log('⚠️ Failed daily work admin email: ' . $e->getMessage());
                    }

                    try {
                        require_once __DIR__ . '/../../utils/whatsapp.php';
                        sendDailyWorkUpdateWhatsAppToAdmins($userName, $userEmail, $submissionData);
                    } catch (Throwable $e) {
                        error_log('⚠️ Failed daily work admin WhatsApp: ' . $e->getMessage());
                    }
                }

                try {
                    br_send_weekly_report_with_checkout($conn, (string)$userId, (string)$date, $userName, $userEmail);
                } catch (Throwable $e) {
                    error_log('⚠️ Failed weekly report checkout notify: ' . $e->getMessage());
                }

                error_log(json_encode([
                    'event' => 'work.checkout.notified',
                    'user_id' => (string)$userId,
                    'date' => (string)$date,
                    'is_update' => (bool)$isUpdate,
                    'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                ]));
            },
            200,
            'Submission saved'
        );
    }
}

$c = new OwnWorkSubmissionController();
$data = $c->getRequestData();
$c->submitOwnWork($data);
?>
