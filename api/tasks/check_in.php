<?php
// Start output buffering to catch any premature output
ob_start();

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Log that we're starting
error_log("🚀 check_in.php - Script started");

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/work_period.php';
require_once __DIR__ . '/../../utils/leave_attendance.php';
require_once __DIR__ . '/../../utils/checkin_policy.php';

error_log("🚀 check_in.php - BaseAPI.php loaded");

class CheckInController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }

    public function checkIn() {
        error_log("🔍 CheckInController::checkIn - Method: " . $_SERVER['REQUEST_METHOD']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            if (!$this->conn) {
                throw new Exception("Database connection not available");
            }
            
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                error_log("❌ CheckInController - Invalid token or user_id missing");
                $this->sendJsonResponse(401, "Invalid token or user_id missing");
                return;
            }

            $userId = $decoded->user_id;
            $rawInput = file_get_contents('php://input');
            error_log("🔍 CheckInController - Raw input: " . $rawInput);
            
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("❌ CheckInController - JSON decode error: " . json_last_error_msg());
                throw new Exception("Invalid JSON input: " . json_last_error_msg());
            }
            
            $submissionDate = $input['submission_date'] ?? date('Y-m-d');
            $plannedProjects = $input['planned_projects'] ?? [];
            $plannedWork = $input['planned_work'] ?? '';
            $plannedWorkStatus = $input['planned_work_status'] ?? 'not_started';
            $workMode = br_normalize_work_mode($input['work_mode'] ?? null);

            if ($workMode === null) {
                $this->sendJsonResponse(400, 'Please select Office or WFH before checking in.');
                return;
            }

            $isAdmin = strtolower((string)($decoded->role ?? '')) === 'admin';
            $attendanceValidation = br_validate_attendance_date(
                $this->conn,
                (int)$userId,
                (string)$submissionDate,
                'check_in',
                $isAdmin
            );
            if (!$attendanceValidation['ok']) {
                $this->sendJsonResponse(400, $attendanceValidation['message'] ?? 'Invalid check-in date.');
                return;
            }
            $submissionDate = $attendanceValidation['date'];

            $leaveGate = br_assert_attendance_allowed($this->conn, (string)$userId, (string)$submissionDate, 'check_in');
            if (empty($leaveGate['ok'])) {
                $this->sendJsonResponse(400, $leaveGate['message'] ?? 'Check-in not allowed for this date.');
                return;
            }

            br_ensure_checkin_policy_schema($this->conn);

            $dayException = br_day_exception($this->conn, $userId, $submissionDate);
            $allowWfhToday = !empty($dayException['allow_wfh']);
            $forgiveLateToday = !empty($dayException['forgive_late']);

            $officeRestriction = br_active_office_restriction($this->conn, $userId, $submissionDate);
            if ($officeRestriction && $workMode === 'wfh' && !$allowWfhToday) {
                $this->sendJsonResponse(
                    403,
                    sprintf(
                        'Office only this week (%s – %s). WFH is not allowed after 3 late check-ins.',
                        $officeRestriction['week_start'],
                        $officeRestriction['week_end']
                    ),
                    [
                        'office_only' => true,
                        'office_only_week_start' => $officeRestriction['week_start'],
                        'office_only_week_end' => $officeRestriction['week_end'],
                        'work_mode_locked_to' => 'office',
                    ]
                );
                return;
            }
            if ($officeRestriction && !$allowWfhToday) {
                $workMode = 'office';
            }

            // Office geofence — require GPS within 500 m of Wired In Coworks
            $checkInLat = null;
            $checkInLng = null;
            $checkInAccuracy = null;
            $checkInDistance = null;
            if ($workMode === 'office') {
                $latRaw = $input['latitude'] ?? $input['lat'] ?? null;
                $lngRaw = $input['longitude'] ?? $input['lng'] ?? null;
                $accuracyRaw = $input['accuracy'] ?? $input['accuracy_m'] ?? null;
                $geo = br_validate_office_location($latRaw, $lngRaw, $this->conn);
                if (empty($geo['ok'])) {
                    $this->sendJsonResponse(
                        403,
                        $geo['message'] ?? 'Office check-in requires location at Wired In Coworks.',
                        [
                            'office_label' => br_office_label($this->conn),
                            'office_radius_m' => br_office_radius_m($this->conn),
                            'distance_m' => $geo['distance_m'] ?? null,
                            'office_lat' => br_office_coords($this->conn)['lat'],
                            'office_lng' => br_office_coords($this->conn)['lng'],
                        ]
                    );
                    return;
                }
                $checkInLat = round((float)$latRaw, 7);
                $checkInLng = round((float)$lngRaw, 7);
                $checkInDistance = $geo['distance_m'];
                if (is_numeric($accuracyRaw) && (float)$accuracyRaw >= 0) {
                    $checkInAccuracy = round((float)$accuracyRaw, 2);
                }
            }
            
            error_log("🔍 CheckInController - User ID: $userId, Submission Date: $submissionDate");
            error_log("🔍 CheckInController - Planned Projects: " . json_encode($plannedProjects));
            error_log("🔍 CheckInController - Planned Work: " . substr($plannedWork, 0, 100));
            
            // Validate planned_projects is an array
            if (!is_array($plannedProjects)) {
                $plannedProjects = [];
            }
            
            // Convert planned_projects array to JSON
            $plannedProjectsJson = !empty($plannedProjects) ? json_encode($plannedProjects) : null;

            // Auto-migrate: add check_in_time column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'check_in_time'");
                if ($check->rowCount() === 0) {
                    error_log("🔧 CheckInController - Adding check_in_time column to work_submissions table");
                    $alterResult = $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN check_in_time TIMESTAMP NULL DEFAULT NULL AFTER start_time");
                    if ($alterResult === false) {
                        $errorInfo = $this->conn->errorInfo();
                        error_log("❌ CheckInController - Failed to add column: " . implode(", ", $errorInfo));
                        throw new Exception("Failed to add check_in_time column. Please run the SQL migration manually: " . implode(", ", $errorInfo));
                    }
                    error_log("✅ CheckInController - Successfully added check_in_time column");
                } else {
                    error_log("✅ CheckInController - check_in_time column already exists");
                }
            } catch (Exception $e) {
                error_log("❌ CheckInController - Column migration error: " . $e->getMessage());
                // Don't ignore - throw the error so user knows they need to run SQL
                throw new Exception("Database migration failed. Please run this SQL manually: ALTER TABLE work_submissions ADD COLUMN check_in_time TIMESTAMP NULL DEFAULT NULL AFTER start_time; Error: " . $e->getMessage());
            }

            // Auto-migrate: add planned_projects column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'planned_projects'");
                if ($check->rowCount() === 0) {
                    error_log("🔧 CheckInController - Adding planned_projects column to work_submissions table");
                    $alterResult = $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN planned_projects JSON NULL DEFAULT NULL AFTER check_in_time");
                    if ($alterResult === false) {
                        $errorInfo = $this->conn->errorInfo();
                        error_log("❌ CheckInController - Failed to add planned_projects column: " . implode(", ", $errorInfo));
                    } else {
                        error_log("✅ CheckInController - Successfully added planned_projects column");
                    }
                }
            } catch (Exception $e) {
                error_log("⚠️ CheckInController - planned_projects column migration error (non-fatal): " . $e->getMessage());
            }

            // Auto-migrate: add planned_work column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'planned_work'");
                if ($check->rowCount() === 0) {
                    error_log("🔧 CheckInController - Adding planned_work column to work_submissions table");
                    $alterResult = $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN planned_work TEXT NULL DEFAULT NULL AFTER planned_projects");
                    if ($alterResult === false) {
                        $errorInfo = $this->conn->errorInfo();
                        error_log("❌ CheckInController - Failed to add planned_work column: " . implode(", ", $errorInfo));
                    } else {
                        error_log("✅ CheckInController - Successfully added planned_work column");
                    }
                }
            } catch (Exception $e) {
                error_log("⚠️ CheckInController - planned_work column migration error (non-fatal): " . $e->getMessage());
            }

            // Auto-migrate: add planned_work_status column if missing
            try {
                $check = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'planned_work_status'");
                if ($check->rowCount() === 0) {
                    error_log("🔧 CheckInController - Adding planned_work_status column to work_submissions table");
                    $alterResult = $this->conn->exec("ALTER TABLE work_submissions ADD COLUMN planned_work_status ENUM('not_started', 'in_progress', 'completed', 'blocked', 'cancelled') NULL DEFAULT 'not_started' AFTER planned_work");
                    if ($alterResult === false) {
                        $errorInfo = $this->conn->errorInfo();
                        error_log("❌ CheckInController - Failed to add planned_work_status column: " . implode(", ", $errorInfo));
                    } else {
                        error_log("✅ CheckInController - Successfully added planned_work_status column");
                    }
                }
            } catch (Exception $e) {
                error_log("⚠️ CheckInController - planned_work_status column migration error (non-fatal): " . $e->getMessage());
            }

            $checkInTime = date('Y-m-d H:i:s');
            $tz = new DateTimeZone('Asia/Kolkata');
            $nowIst = new DateTime('now', $tz);
            $computedLate = br_is_late_checkin($nowIst, $submissionDate) ? 1 : 0;
            if ($forgiveLateToday) {
                $computedLate = 0;
            }
            $isSunday = br_is_sunday($submissionDate);
            $justMarkedLate = false;

            // Use check-then-update/insert pattern for better compatibility
            error_log("🔍 CheckInController - Checking for existing record...");
            $checkStmt = $this->conn->prepare(
                'SELECT id, check_in_time, work_mode, is_late, check_in_lat, check_in_lng, check_in_accuracy_m, check_in_distance_m
                 FROM work_submissions WHERE user_id = ? AND submission_date = ?'
            );
            if (!$checkStmt) {
                throw new Exception("Failed to prepare check statement: " . implode(", ", $this->conn->errorInfo()));
            }

            $checkResult = $checkStmt->execute([$userId, $submissionDate]);
            if (!$checkResult) {
                throw new Exception("Failed to execute check statement: " . implode(", ", $checkStmt->errorInfo()));
            }

            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            $hadPriorCheckIn = $existing && !empty($existing['check_in_time']);

            if ($existing) {
                // Re-check-in: keep first is_late / work_mode / geo; update planned fields + check_in_time only
                $persistedLate = $hadPriorCheckIn
                    ? (int)($existing['is_late'] ?? 0)
                    : $computedLate;
                $persistedMode = $hadPriorCheckIn && !empty($existing['work_mode'])
                    ? br_normalize_work_mode($existing['work_mode'])
                    : $workMode;
                if ($persistedMode === null) {
                    $persistedMode = $workMode;
                }
                if ($officeRestriction) {
                    $persistedMode = 'office';
                }

                // First check-in on an existing empty row counts as a new late strike
                if (!$hadPriorCheckIn && $computedLate === 1) {
                    $justMarkedLate = true;
                }

                $isLate = $persistedLate;
                $workMode = $persistedMode;

                // Keep first geo proof if already checked in as Office
                if ($hadPriorCheckIn && $existing['check_in_lat'] !== null && $existing['check_in_lng'] !== null) {
                    $checkInLat = $existing['check_in_lat'] !== null ? (float)$existing['check_in_lat'] : null;
                    $checkInLng = $existing['check_in_lng'] !== null ? (float)$existing['check_in_lng'] : null;
                    $checkInAccuracy = $existing['check_in_accuracy_m'] !== null ? (float)$existing['check_in_accuracy_m'] : null;
                    $checkInDistance = $existing['check_in_distance_m'] !== null ? (float)$existing['check_in_distance_m'] : null;
                } elseif ($workMode !== 'office') {
                    $checkInLat = null;
                    $checkInLng = null;
                    $checkInAccuracy = null;
                    $checkInDistance = null;
                }

                error_log("🔍 CheckInController - Updating existing record (ID: " . $existing['id'] . ")");

                $updateStmt = $this->conn->prepare(
                    'UPDATE work_submissions
                     SET check_in_time = ?, planned_projects = ?, planned_work = ?, planned_work_status = ?,
                         work_mode = ?, is_late = ?,
                         check_in_lat = ?, check_in_lng = ?, check_in_accuracy_m = ?, check_in_distance_m = ?
                     WHERE user_id = ? AND submission_date = ?'
                );
                if (!$updateStmt) {
                    throw new Exception("Failed to prepare update statement: " . implode(", ", $this->conn->errorInfo()));
                }
                $updateResult = $updateStmt->execute([
                    $checkInTime,
                    $plannedProjectsJson,
                    $plannedWork,
                    $plannedWorkStatus,
                    $workMode,
                    $isLate,
                    $checkInLat,
                    $checkInLng,
                    $checkInAccuracy,
                    $checkInDistance,
                    $userId,
                    $submissionDate,
                ]);

                if (!$updateResult) {
                    throw new Exception("Failed to update: " . implode(", ", $updateStmt->errorInfo()));
                }
                error_log("✅ CheckInController - Successfully updated check_in_time and planned data");
            } else {
                $isLate = $computedLate;
                if ($isLate === 1) {
                    $justMarkedLate = true;
                }

                error_log("🔍 CheckInController - Inserting new record");

                // hours_today always starts at 0 (Sunday included — never auto-add 8h)
                $insertStmt = $this->conn->prepare(
                    'INSERT INTO work_submissions
                        (user_id, submission_date, check_in_time, planned_projects, planned_work, planned_work_status,
                         work_mode, is_late, late_strike_consumed, hours_today,
                         check_in_lat, check_in_lng, check_in_accuracy_m, check_in_distance_m)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?)'
                );
                if (!$insertStmt) {
                    throw new Exception("Failed to prepare insert statement: " . implode(", ", $this->conn->errorInfo()));
                }
                $insertResult = $insertStmt->execute([
                    $userId,
                    $submissionDate,
                    $checkInTime,
                    $plannedProjectsJson,
                    $plannedWork,
                    $plannedWorkStatus,
                    $workMode,
                    $isLate,
                    $checkInLat,
                    $checkInLng,
                    $checkInAccuracy,
                    $checkInDistance,
                ]);

                if (!$insertResult) {
                    throw new Exception("Failed to insert: " . implode(", ", $insertStmt->errorInfo()));
                }
                error_log("✅ CheckInController - Successfully inserted new record with planned data");
            }

            $strikeResult = br_apply_late_strike_and_maybe_restrict(
                $this->conn,
                $userId,
                $submissionDate,
                $justMarkedLate
            );
            $policyStatus = br_checkin_policy_status($this->conn, $userId, $submissionDate);

            error_log("✅ Check-in recorded for user: $userId on date: $submissionDate at time: $checkInTime mode=$workMode late=$isLate");

            // Send email and WhatsApp notifications to admin
            try {
                // Get user details
                $userStmt = $this->conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                $userStmt->execute([$userId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                $username = $user['username'] ?? 'User';
                
                // Get project names if planned_projects contains IDs
                $projectNames = [];
                if (!empty($plannedProjects) && is_array($plannedProjects)) {
                    try {
                        $placeholders = str_repeat('?,', count($plannedProjects) - 1) . '?';
                        $projectStmt = $this->conn->prepare("SELECT id, name FROM projects WHERE id IN ($placeholders)");
                        $projectStmt->execute($plannedProjects);
                        $projectRows = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Create a map of id => name
                        $projectMap = [];
                        foreach ($projectRows as $row) {
                            $projectMap[$row['id']] = $row['name'];
                        }
                        
                        // Replace IDs with names, keep IDs if name not found
                        foreach ($plannedProjects as $projectId) {
                            if (isset($projectMap[$projectId])) {
                                $projectNames[] = $projectMap[$projectId];
                            } else {
                                $projectNames[] = $projectId;
                            }
                        }
                    } catch (Exception $e) {
                        error_log("⚠️ Could not fetch project names: " . $e->getMessage());
                        $projectNames = $plannedProjects; // Fallback to IDs
                    }
                }
            } catch (Exception $e) {
                // Don't fail check-in if project name lookup fails
                error_log("⚠️ Error fetching project names: " . $e->getMessage());
            }

            $responseData = [
                'check_in_time' => $checkInTime,
                'submission_date' => $submissionDate,
                'planned_projects' => $plannedProjects,
                'planned_work' => $plannedWork,
                'planned_work_status' => $plannedWorkStatus,
                'work_mode' => $workMode,
                'is_late' => (bool)$isLate,
                'is_sunday' => $isSunday,
                'late_count' => (int)($strikeResult['late_count'] ?? $policyStatus['late_count'] ?? 0),
                'late_limit' => (int)($strikeResult['late_limit'] ?? br_checkin_late_limit()),
                'office_only' => !empty($policyStatus['office_only']),
                'office_only_week_start' => $policyStatus['office_only_week_start'] ?? null,
                'office_only_week_end' => $policyStatus['office_only_week_end'] ?? null,
                'upcoming_office_only_week' => $policyStatus['upcoming_office_only_week']
                    ?? ($strikeResult['office_only_week'] ?? null),
                'warning' => $strikeResult['warning'] ?? null,
                'restriction_created' => !empty($strikeResult['restriction_created']),
                'check_in_lat' => $checkInLat,
                'check_in_lng' => $checkInLng,
                'check_in_distance_m' => $checkInDistance,
                'office_label' => br_office_label($this->conn),
                'office_radius_m' => br_office_radius_m($this->conn),
            ];

            // Notifications before response — sendJsonResponse() exits and skips code below it
            try {
                if (!isset($username)) {
                    $userStmt = $this->conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
                    $userStmt->execute([$userId]);
                    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                    $username = $user['username'] ?? 'User';
                }
                if (!isset($projectNames)) {
                    $projectNames = [];
                }

                $yesterdaySummary = [
                    'has_record' => false,
                    'date' => date('Y-m-d', strtotime($submissionDate . ' -1 day')),
                    'check_in_time' => null,
                    'check_out_time' => null,
                    'hours_today' => 0,
                    'overtime_hours' => 0,
                ];
                try {
                    require_once __DIR__ . '/../../utils/work_submission_ot.php';
                    $yesterdayDate = $yesterdaySummary['date'];
                    $yCols = [];
                    $yColRes = $this->conn->query('SHOW COLUMNS FROM work_submissions');
                    if ($yColRes) {
                        while ($yCol = $yColRes->fetch(PDO::FETCH_ASSOC)) {
                            $yCols[] = $yCol['Field'];
                        }
                    }
                    $selectParts = ['submission_date', 'hours_today', 'updated_at', 'created_at'];
                    foreach ([
                        'check_in_time',
                        'start_time',
                        'overtime_hours',
                        'requested_extra_hours',
                        'approval_reason',
                        'extra_hours_approval_status',
                        'extra_hours_approved_amount',
                        'completed_tasks',
                        'pending_tasks',
                        'ongoing_tasks',
                        'notes',
                        'total_break_minutes',
                        'work_mode',
                        'is_late',
                    ] as $optionalCol) {
                        if (in_array($optionalCol, $yCols, true)) {
                            $selectParts[] = $optionalCol;
                        }
                    }
                    $yStmt = $this->conn->prepare(
                        'SELECT ' . implode(', ', $selectParts) . '
                         FROM work_submissions
                         WHERE user_id = ? AND submission_date = ?
                         LIMIT 1'
                    );
                    $yStmt->execute([$userId, $yesterdayDate]);
                    $yRow = $yStmt->fetch(PDO::FETCH_ASSOC);
                    if ($yRow) {
                        $hoursToday = (float)($yRow['hours_today'] ?? 0);
                        $hasWorkUpdate = $hoursToday > 0
                            || ((int)($yRow['total_break_minutes'] ?? 0)) > 0
                            || trim((string)($yRow['completed_tasks'] ?? '')) !== ''
                            || trim((string)($yRow['pending_tasks'] ?? '')) !== ''
                            || trim((string)($yRow['ongoing_tasks'] ?? '')) !== ''
                            || trim((string)($yRow['notes'] ?? '')) !== '';

                        $checkOutTime = null;
                        if ($hasWorkUpdate && !empty($yRow['updated_at'])) {
                            $updatedAt = strtotime($yRow['updated_at']);
                            $checkInAt = !empty($yRow['check_in_time'])
                                ? strtotime($yRow['check_in_time'])
                                : (!empty($yRow['start_time']) ? strtotime($yesterdayDate . ' ' . $yRow['start_time']) : null);
                            if ($updatedAt && (!$checkInAt || $updatedAt > $checkInAt)) {
                                $checkOutTime = $yRow['updated_at'];
                            }
                        }

                        $yesterdaySummary = [
                            'has_record' => true,
                            'date' => $yRow['submission_date'] ?? $yesterdayDate,
                            'check_in_time' => $yRow['check_in_time']
                                ?? (!empty($yRow['start_time']) ? ($yesterdayDate . ' ' . $yRow['start_time']) : null),
                            'check_out_time' => $checkOutTime,
                            'hours_today' => $hoursToday,
                            'overtime_hours' => br_effective_overtime_hours_for_stats($yRow),
                            'work_mode' => $yRow['work_mode'] ?? null,
                            'is_late' => !empty($yRow['is_late']),
                        ];
                    }
                } catch (Exception $e) {
                    error_log("⚠️ Failed to load yesterday attendance for check-in notice: " . $e->getMessage());
                }

                $plannedSummary = $plannedWork;
                if (!empty($projectNames)) {
                    $plannedSummary = implode(', ', $projectNames);
                    if ($plannedWork) {
                        $plannedSummary .= ' — ' . $plannedWork;
                    }
                }

                try {
                    require_once __DIR__ . '/../NotificationManager.php';
                    $nm = NotificationManager::getInstance();
                    $modeLabel = $workMode === 'wfh' ? 'WFH' : 'Office';
                    $latePrefix = $isLate ? 'LATE · ' : '';
                    $plannedWithMode = trim($latePrefix . $modeLabel . ($plannedSummary ? ' — ' . $plannedSummary : ''));
                    $nm->notifyWorkCheckIn($userId, $checkInTime, $submissionDate, $plannedWithMode);

                    if (!empty($strikeResult['restriction_created']) && !empty($strikeResult['office_only_week'])) {
                        $nm->notifyOfficeOnlyWeek(
                            $userId,
                            $strikeResult['office_only_week']['week_start'],
                            $strikeResult['office_only_week']['week_end']
                        );
                    }
                } catch (Exception $e) {
                    error_log("⚠️ Failed in-app/push check-in notification: " . $e->getMessage());
                }

                $adminStmt = $this->conn->prepare(
                    "SELECT email, phone FROM users WHERE account_active = 1 AND (role = 'admin' OR role_id = 1) AND (email IS NOT NULL OR phone IS NOT NULL)"
                );
                $adminStmt->execute();
                $adminRows = $adminStmt->fetchAll(PDO::FETCH_ASSOC);
                $adminEmails = array_values(array_filter(array_column($adminRows, 'email')));
                $adminPhones = array_values(array_filter(array_column($adminRows, 'phone')));

                $attendanceMeta = [
                    'work_mode' => $workMode,
                    'is_late' => (bool)$isLate,
                    'is_sunday' => (bool)$isSunday,
                    'late_count' => (int)($strikeResult['late_count'] ?? $policyStatus['late_count'] ?? 0),
                    'late_limit' => (int)($strikeResult['late_limit'] ?? br_checkin_late_limit()),
                    'check_in_distance_m' => $checkInDistance,
                    'office_label' => br_office_label($this->conn),
                    'office_only' => !empty($policyStatus['office_only']),
                    'office_only_week_start' => $policyStatus['office_only_week_start'] ?? null,
                    'office_only_week_end' => $policyStatus['office_only_week_end'] ?? null,
                    'upcoming_office_only_week' => $policyStatus['upcoming_office_only_week']
                        ?? ($strikeResult['office_only_week'] ?? null),
                    'restriction_created' => !empty($strikeResult['restriction_created']),
                    'warning' => $strikeResult['warning'] ?? null,
                ];

                try {
                    require_once __DIR__ . '/../../utils/email.php';
                    foreach ($adminEmails as $adminEmail) {
                        sendCheckInNotificationEmail(
                            $adminEmail,
                            $username,
                            $checkInTime,
                            $submissionDate,
                            !empty($projectNames) ? $projectNames : null,
                            $plannedWork,
                            $yesterdaySummary,
                            $attendanceMeta
                        );
                    }
                } catch (Exception $e) {
                    error_log("⚠️ Failed to send check-in email notification: " . $e->getMessage());
                }

                try {
                    require_once __DIR__ . '/../../utils/whatsapp.php';
                    foreach ($adminPhones as $adminPhone) {
                        sendCheckInNotificationWhatsApp(
                            $adminPhone,
                            $username,
                            $checkInTime,
                            $submissionDate,
                            !empty($projectNames) ? $projectNames : null,
                            $plannedWork,
                            $yesterdaySummary,
                            $attendanceMeta
                        );
                    }
                } catch (Exception $e) {
                    error_log("⚠️ Failed to send check-in WhatsApp notification: " . $e->getMessage());
                }
            } catch (Exception $e) {
                error_log("⚠️ Error sending check-in notifications: " . $e->getMessage());
            }

            error_log("🔍 CheckInController - Sending success response: " . json_encode($responseData));
            $this->sendJsonResponse(200, "Checked in successfully", $responseData);
            
            return;

        } catch (PDOException $e) {
            $errorMsg = "PDO Error in check-in: " . $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            error_log($errorMsg);
            error_log("PDO Error trace: " . $errorTrace);
            error_log("PDO Error code: " . $e->getCode());
            error_log("PDO Error info: " . json_encode($e->errorInfo ?? []));
            
            // Ensure we send a proper error response
            try {
                $this->sendJsonResponse(500, "Database error: " . $e->getMessage());
            } catch (Exception $responseError) {
                error_log("Failed to send error response: " . $responseError->getMessage());
                // Last resort - send raw JSON
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Database error occurred']);
            }
        } catch (Exception $e) {
            $errorMsg = "Error in check-in: " . $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            error_log($errorMsg);
            error_log("Error trace: " . $errorTrace);
            
            // Ensure we send a proper error response
            try {
                $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
            } catch (Exception $responseError) {
                error_log("Failed to send error response: " . $responseError->getMessage());
                // Last resort - send raw JSON
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Server error occurred']);
            }
        }
    }
}

try {
    error_log("🚀 check_in.php - About to create CheckInController");
    
    // Clear any output that might have been sent
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    $controller = new CheckInController();
    error_log("🚀 check_in.php - CheckInController created");
    
    // Check if constructor failed (database connection error)
    $conn = $controller->getConnection();
    if (!$conn) {
        error_log("❌ check_in.php - Database connection is null");
        throw new Exception("Database connection failed during initialization");
    }
    
    error_log("🚀 check_in.php - Database connection OK, calling checkIn");
    $controller->checkIn();
    error_log("🚀 check_in.php - checkIn completed");
} catch (Throwable $e) {
    // Catch any fatal errors or exceptions
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    error_log("Fatal error in check_in.php: " . $e->getMessage());
    error_log("Fatal error trace: " . $e->getTraceAsString());
    error_log("Fatal error file: " . $e->getFile() . " line: " . $e->getLine());
    
    // Ensure we send a valid JSON response
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    
    $errorResponse = [
        'success' => false,
        'message' => 'Internal server error occurred'
    ];
    
    // Only include error details in development
    if (ini_get('display_errors')) {
        $errorResponse['error'] = $e->getMessage();
        $errorResponse['file'] = $e->getFile();
        $errorResponse['line'] = $e->getLine();
    }
    
    echo json_encode($errorResponse);
    exit();
}
?>

