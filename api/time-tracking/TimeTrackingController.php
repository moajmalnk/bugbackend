<?php
require_once __DIR__ . '/../BaseAPI.php';

class TimeTrackingController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
        $this->ensureTablesExist();
    }
    
    /**
     * Ensure time tracking tables exist
     */
    private function ensureTablesExist() {
        try {
            // Check and create work_sessions table
            $check = $this->conn->query("SHOW TABLES LIKE 'work_sessions'");
            if ($check->rowCount() === 0) {
                $this->conn->exec("
                    CREATE TABLE work_sessions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_id VARCHAR(36) NOT NULL,
                        submission_date DATE NOT NULL,
                        check_in_time TIMESTAMP NOT NULL,
                        check_out_time TIMESTAMP NULL,
                        total_duration_seconds INT DEFAULT 0,
                        net_duration_seconds INT DEFAULT 0,
                        is_active BOOLEAN DEFAULT TRUE,
                        session_notes TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_user_date (user_id, submission_date),
                        INDEX idx_active (user_id, is_active),
                        INDEX idx_check_in (check_in_time)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                ");
            }
            
            // Check and create session_pauses table (after work_sessions)
            $check = $this->conn->query("SHOW TABLES LIKE 'session_pauses'");
            if ($check->rowCount() === 0) {
                // Check if work_sessions exists first
                $workSessionsCheck = $this->conn->query("SHOW TABLES LIKE 'work_sessions'");
                if ($workSessionsCheck->rowCount() > 0) {
                    $this->conn->exec("
                        CREATE TABLE session_pauses (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            session_id INT NOT NULL,
                            pause_start TIMESTAMP NOT NULL,
                            pause_end TIMESTAMP NULL,
                            pause_reason VARCHAR(255) DEFAULT 'break',
                            duration_seconds INT DEFAULT 0,
                            is_active BOOLEAN DEFAULT TRUE,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            FOREIGN KEY (session_id) REFERENCES work_sessions(id) ON DELETE CASCADE,
                            INDEX idx_session (session_id),
                            INDEX idx_active_pause (session_id, is_active)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                    ");
                }
            }
            
            // Check and create session_activities table (after work_sessions)
            $check = $this->conn->query("SHOW TABLES LIKE 'session_activities'");
            if ($check->rowCount() === 0) {
                // Check if work_sessions exists first
                $workSessionsCheck = $this->conn->query("SHOW TABLES LIKE 'work_sessions'");
                if ($workSessionsCheck->rowCount() > 0) {
                    $this->conn->exec("
                        CREATE TABLE session_activities (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            session_id INT NOT NULL,
                            activity_type ENUM('work', 'break', 'meeting', 'training', 'other') DEFAULT 'work',
                            start_time TIMESTAMP NOT NULL,
                            end_time TIMESTAMP NULL,
                            activity_notes TEXT,
                            project_id VARCHAR(36) DEFAULT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            FOREIGN KEY (session_id) REFERENCES work_sessions(id) ON DELETE CASCADE,
                            INDEX idx_session (session_id),
                            INDEX idx_activity_type (activity_type),
                            INDEX idx_project (project_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
                    ");
                }
            }
        } catch (Exception $e) {
            // Log error but don't fail completely - tables might already exist
            error_log("TimeTracking table creation warning: " . $e->getMessage());
        }
    }
    
    /**
     * Check in - Start a new work session
     */
    public function checkIn($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Check if user already has an active session
            $activeSession = $this->getActiveSession($userId);
            if ($activeSession) {
                return $this->sendJsonResponse(400, 'You already have an active session. Please check out first.');
            }
            
            $submissionDate = $payload['submission_date'] ?? date('Y-m-d');
            $sessionNotes = $payload['session_notes'] ?? null;
            $projectId = $payload['project_id'] ?? null;
            
            // Insert new session with IST timezone
            // Convert current time to IST (UTC+5:30)
            $istTime = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
            $checkInTime = $istTime->format('Y-m-d H:i:s');
            
            $sql = "INSERT INTO work_sessions (user_id, submission_date, check_in_time, session_notes) VALUES (?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $submissionDate, $checkInTime, $sessionNotes]);
            
            $sessionId = $this->conn->lastInsertId();
            
            // Create initial activity record
            if ($projectId) {
                $activityStart = $istTime->format('Y-m-d H:i:s');
                $activitySql = "INSERT INTO session_activities (session_id, activity_type, start_time, project_id) VALUES (?, 'work', ?, ?)";
                $activityStmt = $this->conn->prepare($activitySql);
                $activityStmt->execute([$sessionId, $activityStart, $projectId]);
            }
            
            error_log("🔍 TimeTrackingController::checkIn - User {$userId} checked in at " . $checkInTime);
            
            return $this->sendJsonResponse(200, 'Checked in successfully', [
                'session_id' => $sessionId,
                'check_in_time' => $checkInTime,
                'submission_date' => $submissionDate
            ]);
            
        } catch (Exception $e) {
            error_log('TimeTracking checkIn error: ' . $e->getMessage());
            return $this->sendJsonResponse(500, 'Failed to check in');
        }
    }
    
    /**
     * Check out - End current work session
     */
    public function checkOut($payload = []) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Get active session
            $activeSession = $this->getActiveSession($userId);
            if (!$activeSession) {
                return $this->sendJsonResponse(400, 'No active session found');
            }
            
            $sessionId = $activeSession['id'];
            $checkInTime = new DateTime($activeSession['check_in_time']);
            $checkOutTime = new DateTime();
            
            // Calculate total duration
            $totalDuration = $checkOutTime->getTimestamp() - $checkInTime->getTimestamp();
            
            // Calculate net duration (total minus pause time)
            $pauseDuration = $this->calculateTotalPauseTime($sessionId);
            $netDuration = $totalDuration - $pauseDuration;
            
            // Update session
            $sql = "UPDATE work_sessions SET 
                    check_out_time = NOW(), 
                    total_duration_seconds = ?, 
                    net_duration_seconds = ?, 
                    is_active = FALSE 
                    WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$totalDuration, $netDuration, $sessionId]);
            
            // End any active activities
            $this->endActiveActivities($sessionId);
            
            error_log("🔍 TimeTrackingController::checkOut - User {$userId} checked out. Duration: " . gmdate('H:i:s', $netDuration));
            
            return $this->sendJsonResponse(200, 'Checked out successfully', [
                'session_id' => $sessionId,
                'check_out_time' => $checkOutTime->format('Y-m-d H:i:s'),
                'total_duration' => $totalDuration,
                'net_duration' => $netDuration,
                'total_hours' => round($netDuration / 3600, 2)
            ]);
            
        } catch (Exception $e) {
            error_log('TimeTracking checkOut error: ' . $e->getMessage());
            return $this->sendJsonResponse(500, 'Failed to check out');
        }
    }
    
    /**
     * Pause current session
     */
    public function pauseSession($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            $activeSession = $this->getActiveSession($userId);
            if (!$activeSession) {
                return $this->sendJsonResponse(400, 'No active session found');
            }
            
            $sessionId = $activeSession['id'];
            $pauseReason = $payload['pause_reason'] ?? 'break';
            
            // Check if already paused
            $pauseCheck = $this->conn->prepare("SELECT id FROM session_pauses WHERE session_id = ? AND is_active = TRUE");
            $pauseCheck->execute([$sessionId]);
            if ($pauseCheck->rowCount() > 0) {
                return $this->sendJsonResponse(400, 'Session is already paused');
            }
            
            // Insert pause record
            $sql = "INSERT INTO session_pauses (session_id, pause_start, pause_reason) VALUES (?, NOW(), ?)";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$sessionId, $pauseReason]);
            
            $pauseId = $this->conn->lastInsertId();
            
            error_log("🔍 TimeTrackingController::pauseSession - User {$userId} paused session {$sessionId}");
            
            return $this->sendJsonResponse(200, 'Session paused', [
                'pause_id' => $pauseId,
                'pause_start' => date('Y-m-d H:i:s'),
                'pause_reason' => $pauseReason
            ]);
            
        } catch (Exception $e) {
            error_log('TimeTracking pauseSession error: ' . $e->getMessage());
            return $this->sendJsonResponse(500, 'Failed to pause session');
        }
    }
    
    /**
     * Resume paused session
     */
    public function resumeSession($payload = []) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            $activeSession = $this->getActiveSession($userId);
            if (!$activeSession) {
                return $this->sendJsonResponse(400, 'No active session found');
            }
            
            $sessionId = $activeSession['id'];
            
            // Get active pause
            $pauseSql = "SELECT id, pause_start FROM session_pauses WHERE session_id = ? AND is_active = TRUE";
            $pauseStmt = $this->conn->prepare($pauseSql);
            $pauseStmt->execute([$sessionId]);
            $pause = $pauseStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pause) {
                return $this->sendJsonResponse(400, 'No active pause found');
            }
            
            // Calculate pause duration and end pause
            $pauseStart = new DateTime($pause['pause_start']);
            $pauseEnd = new DateTime();
            $pauseDuration = $pauseEnd->getTimestamp() - $pauseStart->getTimestamp();
            
            $sql = "UPDATE session_pauses SET pause_end = NOW(), duration_seconds = ?, is_active = FALSE WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$pauseDuration, $pause['id']]);
            
            error_log("🔍 TimeTrackingController::resumeSession - User {$userId} resumed session {$sessionId}. Pause duration: " . gmdate('H:i:s', $pauseDuration));
            
            return $this->sendJsonResponse(200, 'Session resumed', [
                'pause_duration' => $pauseDuration,
                'resume_time' => $pauseEnd->format('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            error_log('TimeTracking resumeSession error: ' . $e->getMessage());
            return $this->sendJsonResponse(500, 'Failed to resume session');
        }
    }
    
    /**
     * Get current active session
     */
    public function getCurrentSession($payload = []) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            $activeSession = $this->getActiveSession($userId);
            if (!$activeSession) {
                return $this->sendJsonResponse(200, 'No active session', ['session' => null]);
            }
            
            $sessionId = $activeSession['id'];
            $checkInTime = new DateTime($activeSession['check_in_time']);
            $currentTime = new DateTime();
            
            // Calculate current duration
            $totalDuration = $currentTime->getTimestamp() - $checkInTime->getTimestamp();
            $pauseDuration = $this->calculateTotalPauseTime($sessionId);
            $netDuration = $totalDuration - $pauseDuration;
            
            // Get current pause status
            $pauseStatus = $this->getCurrentPauseStatus($sessionId);
            
            $session = [
                'id' => $activeSession['id'],
                'user_id' => $activeSession['user_id'],
                'submission_date' => $activeSession['submission_date'],
                'check_in_time' => $activeSession['check_in_time'],
                'total_duration_seconds' => $totalDuration,
                'net_duration_seconds' => $netDuration,
                'is_paused' => $pauseStatus['is_paused'],
                'pause_reason' => $pauseStatus['pause_reason'],
                'pause_start' => $pauseStatus['pause_start']
            ];
            
            return $this->sendJsonResponse(200, 'Current session retrieved', ['session' => $session]);
            
        } catch (Exception $e) {
            error_log('TimeTracking getCurrentSession error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return $this->sendJsonResponse(500, 'Failed to get current session: ' . $e->getMessage());
        }
    }
    
    /**
     * Get session history
     */
    public function getSessionHistory($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            $from = $payload['from'] ?? date('Y-m-d', strtotime('-30 days'));
            $to = $payload['to'] ?? date('Y-m-d');
            $limit = $payload['limit'] ?? 50;
            $offset = $payload['offset'] ?? 0;
            
            $sql = "SELECT * FROM work_sessions 
                    WHERE user_id = ? AND submission_date BETWEEN ? AND ? 
                    ORDER BY check_in_time DESC 
                    LIMIT ? OFFSET ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId, $from, $to, $limit, $offset]);
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Add pause information to each session
            foreach ($sessions as &$session) {
                $session['pauses'] = $this->getSessionPauses($session['id']);
            }
            
            return $this->sendJsonResponse(200, 'Session history retrieved', ['sessions' => $sessions]);
            
        } catch (Exception $e) {
            error_log('TimeTracking getSessionHistory error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return $this->sendJsonResponse(500, 'Failed to get session history: ' . $e->getMessage());
        }
    }
    
    /**
     * Helper: Get active session for user
     */
    private function getActiveSession($userId) {
        $sql = "SELECT * FROM work_sessions WHERE user_id = ? AND is_active = TRUE ORDER BY check_in_time DESC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Helper: Calculate total pause time for session
     */
    private function calculateTotalPauseTime($sessionId) {
        $sql = "SELECT SUM(duration_seconds) as total_pause FROM session_pauses WHERE session_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($result['total_pause'] ?? 0);
    }
    
    /**
     * Helper: Get current pause status
     */
    private function getCurrentPauseStatus($sessionId) {
        $sql = "SELECT pause_reason, pause_start FROM session_pauses WHERE session_id = ? AND is_active = TRUE LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$sessionId]);
        $pause = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'is_paused' => $pause !== false,
            'pause_reason' => $pause['pause_reason'] ?? null,
            'pause_start' => $pause['pause_start'] ?? null
        ];
    }
    
    /**
     * Helper: Get all pauses for a session
     */
    private function getSessionPauses($sessionId) {
        $sql = "SELECT * FROM session_pauses WHERE session_id = ? ORDER BY pause_start ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Helper: End all active activities for session
     */
    private function endActiveActivities($sessionId) {
        $sql = "UPDATE session_activities SET end_time = NOW() WHERE session_id = ? AND end_time IS NULL";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$sessionId]);
    }
}
