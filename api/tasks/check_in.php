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
            
            error_log("🔍 CheckInController - User ID: $userId, Submission Date: $submissionDate");
            error_log("🔍 CheckInController - Planned Projects: " . json_encode($plannedProjects));
            error_log("🔍 CheckInController - Planned Work: " . substr($plannedWork, 0, 100));
            
            // Do not allow future dates
            if (strtotime($submissionDate) > strtotime(date('Y-m-d'))) {
                $this->sendJsonResponse(400, 'Future dates are not allowed');
                return;
            }
            
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
            
            // Use check-then-update/insert pattern for better compatibility
            error_log("🔍 CheckInController - Checking for existing record...");
            $checkStmt = $this->conn->prepare("SELECT id FROM work_submissions WHERE user_id = ? AND submission_date = ?");
            if (!$checkStmt) {
                throw new Exception("Failed to prepare check statement: " . implode(", ", $this->conn->errorInfo()));
            }
            
            $checkResult = $checkStmt->execute([$userId, $submissionDate]);
            if (!$checkResult) {
                throw new Exception("Failed to execute check statement: " . implode(", ", $checkStmt->errorInfo()));
            }
            
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing record
                error_log("🔍 CheckInController - Updating existing record (ID: " . $existing['id'] . ")");
                
                // Check if planned_projects, planned_work, and planned_work_status columns exist
                $columnsCheck = $this->conn->query("SHOW COLUMNS FROM work_submissions");
                $columns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
                $hasPlannedProjects = in_array('planned_projects', $columns);
                $hasPlannedWork = in_array('planned_work', $columns);
                $hasPlannedWorkStatus = in_array('planned_work_status', $columns);
                
                if ($hasPlannedProjects && $hasPlannedWork && $hasPlannedWorkStatus) {
                    $updateStmt = $this->conn->prepare("UPDATE work_submissions SET check_in_time = ?, planned_projects = ?, planned_work = ?, planned_work_status = ? WHERE user_id = ? AND submission_date = ?");
                    if (!$updateStmt) {
                        throw new Exception("Failed to prepare update statement: " . implode(", ", $this->conn->errorInfo()));
                    }
                    $updateResult = $updateStmt->execute([$checkInTime, $plannedProjectsJson, $plannedWork, $plannedWorkStatus, $userId, $submissionDate]);
                } elseif ($hasPlannedProjects && $hasPlannedWork) {
                    $updateStmt = $this->conn->prepare("UPDATE work_submissions SET check_in_time = ?, planned_projects = ?, planned_work = ? WHERE user_id = ? AND submission_date = ?");
                    if (!$updateStmt) {
                        throw new Exception("Failed to prepare update statement: " . implode(", ", $this->conn->errorInfo()));
                    }
                    $updateResult = $updateStmt->execute([$checkInTime, $plannedProjectsJson, $plannedWork, $userId, $submissionDate]);
                } else {
                    // Fallback if columns don't exist yet
                    $updateStmt = $this->conn->prepare("UPDATE work_submissions SET check_in_time = ? WHERE user_id = ? AND submission_date = ?");
                    if (!$updateStmt) {
                        throw new Exception("Failed to prepare update statement: " . implode(", ", $this->conn->errorInfo()));
                    }
                    $updateResult = $updateStmt->execute([$checkInTime, $userId, $submissionDate]);
                }
                
                if (!$updateResult) {
                    throw new Exception("Failed to update: " . implode(", ", $updateStmt->errorInfo()));
                }
                error_log("✅ CheckInController - Successfully updated check_in_time and planned data");
            } else {
                // Insert new record
                error_log("🔍 CheckInController - Inserting new record");
                
                // Check if planned_projects, planned_work, and planned_work_status columns exist
                $columnsCheck = $this->conn->query("SHOW COLUMNS FROM work_submissions");
                $columns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
                $hasPlannedProjects = in_array('planned_projects', $columns);
                $hasPlannedWork = in_array('planned_work', $columns);
                $hasPlannedWorkStatus = in_array('planned_work_status', $columns);
                
                if ($hasPlannedProjects && $hasPlannedWork && $hasPlannedWorkStatus) {
                    $insertStmt = $this->conn->prepare("INSERT INTO work_submissions (user_id, submission_date, check_in_time, planned_projects, planned_work, planned_work_status, hours_today) VALUES (?, ?, ?, ?, ?, ?, 0)");
                    if (!$insertStmt) {
                        throw new Exception("Failed to prepare insert statement: " . implode(", ", $this->conn->errorInfo()));
                    }
                    $insertResult = $insertStmt->execute([$userId, $submissionDate, $checkInTime, $plannedProjectsJson, $plannedWork, $plannedWorkStatus]);
                } elseif ($hasPlannedProjects && $hasPlannedWork) {
                    $insertStmt = $this->conn->prepare("INSERT INTO work_submissions (user_id, submission_date, check_in_time, planned_projects, planned_work, hours_today) VALUES (?, ?, ?, ?, ?, 0)");
                    if (!$insertStmt) {
                        throw new Exception("Failed to prepare insert statement: " . implode(", ", $this->conn->errorInfo()));
                    }
                    $insertResult = $insertStmt->execute([$userId, $submissionDate, $checkInTime, $plannedProjectsJson, $plannedWork]);
                } else {
                    // Fallback if columns don't exist yet
                    $insertStmt = $this->conn->prepare("INSERT INTO work_submissions (user_id, submission_date, check_in_time, hours_today) VALUES (?, ?, ?, 0)");
                    if (!$insertStmt) {
                        throw new Exception("Failed to prepare insert statement: " . implode(", ", $this->conn->errorInfo()));
                    }
                    $insertResult = $insertStmt->execute([$userId, $submissionDate, $checkInTime]);
                }
                
                if (!$insertResult) {
                    throw new Exception("Failed to insert: " . implode(", ", $insertStmt->errorInfo()));
                }
                error_log("✅ CheckInController - Successfully inserted new record with planned data");
            }

            error_log("✅ Check-in recorded for user: $userId on date: $submissionDate at time: $checkInTime");

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

            // Send response immediately (non-blocking) for faster user experience
            $responseData = [
                'check_in_time' => $checkInTime,
                'submission_date' => $submissionDate,
                'planned_projects' => $plannedProjects,
                'planned_work' => $plannedWork,
                'planned_work_status' => $plannedWorkStatus
            ];
            
            error_log("🔍 CheckInController - Sending success response: " . json_encode($responseData));
            $this->sendJsonResponse(200, "Checked in successfully", $responseData);
            
            // Send notifications asynchronously (non-blocking) after response is sent
            // This makes the check-in feel instant while notifications happen in background
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request(); // Flush response to client immediately
            }
            
            // Now send notifications in background (won't block user)
            try {
                // Admin email and phone
                $adminEmail = 'moajmalnk@gmail.com';
                $adminPhone = '8848676627, 9497792540';
                
                // Send email notification (async)
                try {
                    require_once __DIR__ . '/../../utils/email.php';
                    error_log("📧 Sending check-in email notification to admin: $adminEmail");
                    
                    $emailSent = sendCheckInNotificationEmail(
                        $adminEmail,
                        $username,
                        $checkInTime,
                        $submissionDate,
                        !empty($projectNames) ? $projectNames : null,
                        $plannedWork
                    );
                    
                    if ($emailSent) {
                        error_log("✅ Successfully sent check-in email notification to admin");
                    } else {
                        error_log("❌ Failed to send check-in email notification to admin");
                    }
                } catch (Exception $e) {
                    error_log("⚠️ Failed to send check-in email notification: " . $e->getMessage());
                }
                
                // Send WhatsApp notification (async)
                try {
                    require_once __DIR__ . '/../../utils/whatsapp.php';
                    error_log("📱 Sending check-in WhatsApp notification to admin: $adminPhone");
                    
                    $whatsappSent = sendCheckInNotificationWhatsApp(
                        $adminPhone,
                        $username,
                        $checkInTime,
                        $submissionDate,
                        !empty($projectNames) ? $projectNames : null,
                        $plannedWork
                    );
                    
                    if ($whatsappSent) {
                        error_log("✅ Successfully sent check-in WhatsApp notification to admin");
                    } else {
                        error_log("❌ Failed to send check-in WhatsApp notification to admin");
                    }
                } catch (Exception $e) {
                    error_log("⚠️ Failed to send check-in WhatsApp notification: " . $e->getMessage());
                }
            } catch (Exception $e) {
                // Don't fail check-in if notifications fail
                error_log("⚠️ Error sending check-in notifications: " . $e->getMessage());
            }
            
            return; // Explicit return to prevent any further execution

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

