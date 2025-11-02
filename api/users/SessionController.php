<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';

class SessionController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Start a new work session
     */
    public function startSession($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Check if user already has an active session
            $activeSession = $this->getActiveSession($userId);
            if ($activeSession) {
                $this->sendJsonResponse(409, "User already has an active session", [
                    'active_session' => $activeSession
                ]);
                return;
            }
            
            // Additional check: prevent multiple sessions within 1 minute
            $recentSession = $this->getRecentSession($userId, 1); // 1 minute
            if ($recentSession) {
                $this->sendJsonResponse(429, "Please wait before starting a new session", [
                    'last_session' => $recentSession
                ]);
                return;
            }
            
            $sessionId = $this->utils->generateUUID();
            $projectId = $payload['project_id'] ?? null;
            $activityType = $payload['activity_type'] ?? 'work';
            $notes = $payload['notes'] ?? null;
            
            $sql = "INSERT INTO user_activity_sessions (id, user_id, session_start, activity_type, project_id, notes) VALUES (?, ?, NOW(), ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([$sessionId, $userId, $activityType, $projectId, $notes]);
            
            if ($result) {
                // Log session start activity
                try {
                    $logger = ActivityLogger::getInstance();
                    $logger->logActivity(
                        $userId,
                        $projectId,
                        'session_started',
                        "Work session started",
                        $sessionId,
                        [
                            'activity_type' => $activityType,
                            'notes' => $notes
                        ]
                    );
                } catch (Exception $e) {
                    error_log("Failed to log session start activity: " . $e->getMessage());
                }
                
                $this->sendJsonResponse(201, "Session started successfully", [
                    'session_id' => $sessionId,
                    'start_time' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->sendJsonResponse(500, "Failed to start session");
            }
            
        } catch (Exception $e) {
            error_log("Error starting session: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to start session: " . $e->getMessage());
        }
    }
    
    /**
     * End the current active session
     */
    public function endSession($payload) {
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            // Get active session
            $activeSession = $this->getActiveSession($userId);
            if (!$activeSession) {
                $this->sendJsonResponse(404, "No active session found");
                return;
            }
            
            $sessionId = $activeSession['id'];
            $sessionStart = new DateTime($activeSession['session_start']);
            $sessionEnd = new DateTime();
            $durationMinutes = $sessionEnd->diff($sessionStart)->i + ($sessionEnd->diff($sessionStart)->h * 60);
            
            $sql = "UPDATE user_activity_sessions SET session_end = NOW(), session_duration_minutes = ? WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([$durationMinutes, $sessionId]);
            
            if ($result) {
                // Log session end activity
                try {
                    $logger = ActivityLogger::getInstance();
                    $logger->logActivity(
                        $userId,
                        $activeSession['project_id'],
                        'session_ended',
                        "Work session ended",
                        $sessionId,
                        [
                            'duration_minutes' => $durationMinutes,
                            'duration_hours' => round($durationMinutes / 60, 2)
                        ]
                    );
                } catch (Exception $e) {
                    error_log("Failed to log session end activity: " . $e->getMessage());
                }
                
                $this->sendJsonResponse(200, "Session ended successfully", [
                    'session_id' => $sessionId,
                    'duration_minutes' => $durationMinutes,
                    'duration_hours' => round($durationMinutes / 60, 2),
                    'end_time' => $sessionEnd->format('Y-m-d H:i:s')
                ]);
            } else {
                $this->sendJsonResponse(500, "Failed to end session");
            }
            
        } catch (Exception $e) {
            error_log("Error ending session: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to end session: " . $e->getMessage());
        }
    }
    
    /**
     * Get current active session for user
     */
    public function getActiveSession($userId) {
        $sql = "SELECT * FROM user_activity_sessions WHERE user_id = ? AND session_end IS NULL ORDER BY session_start DESC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get recent session within specified minutes
     */
    public function getRecentSession($userId, $minutes = 5) {
        $sql = "SELECT * FROM user_activity_sessions WHERE user_id = ? AND session_start >= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY session_start DESC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId, $minutes]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get user's session history
     */
    public function getSessionHistory($userId, $limit = 50) {
        $sql = "SELECT * FROM user_activity_sessions WHERE user_id = ? ORDER BY session_start DESC LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all active sessions (admin only)
     */
    public function getAllActiveSessions() {
        try {
            $decoded = $this->validateToken();
            
            // Check if user is admin
            if (!isset($decoded->role) || $decoded->role !== 'admin') {
                $this->sendJsonResponse(403, "Access denied. Admin role required.");
                return;
            }
            
            $sql = "
                SELECT 
                    s.*,
                    u.username,
                    u.email,
                    p.name as project_name
                FROM user_activity_sessions s
                LEFT JOIN users u ON s.user_id = u.id
                LEFT JOIN projects p ON s.project_id = p.id
                WHERE s.session_end IS NULL
                ORDER BY s.session_start DESC
            ";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate current duration for each session
            foreach ($sessions as &$session) {
                $sessionStart = new DateTime($session['session_start']);
                $now = new DateTime();
                $durationMinutes = $now->diff($sessionStart)->i + ($now->diff($sessionStart)->h * 60);
                $session['current_duration_minutes'] = $durationMinutes;
                $session['current_duration_hours'] = round($durationMinutes / 60, 2);
            }
            
            $this->sendJsonResponse(200, "Active sessions retrieved successfully", $sessions);
            
        } catch (Exception $e) {
            error_log("Error getting active sessions: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to get active sessions: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up orphaned sessions (sessions that should have ended)
     */
    public function cleanupOrphanedSessions($userId = null) {
        try {
            $whereClause = $userId ? "WHERE user_id = ?" : "";
            $params = $userId ? [$userId] : [];
            
            // Find sessions that have been running for more than 12 hours (likely orphaned)
            $sql = "UPDATE user_activity_sessions 
                    SET session_end = NOW(), 
                        session_duration_minutes = TIMESTAMPDIFF(MINUTE, session_start, NOW())
                    WHERE session_end IS NULL 
                    AND session_start < DATE_SUB(NOW(), INTERVAL 12 HOUR)
                    " . ($userId ? "AND user_id = ?" : "");
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $affectedRows = $stmt->rowCount();
            
            if ($affectedRows > 0) {
                error_log("Cleaned up {$affectedRows} orphaned sessions");
            }
            
            return $affectedRows;
        } catch (Exception $e) {
            error_log("Error cleaning up orphaned sessions: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Force end a session (admin only)
     */
    public function forceEndSession($sessionId) {
        try {
            $decoded = $this->validateToken();
            
            // Check if user is admin
            if (!isset($decoded->role) || $decoded->role !== 'admin') {
                $this->sendJsonResponse(403, "Access denied. Admin role required.");
                return;
            }
            
            // Get session details
            $sql = "SELECT * FROM user_activity_sessions WHERE id = ? AND session_end IS NULL";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                $this->sendJsonResponse(404, "Active session not found");
                return;
            }
            
            $sessionStart = new DateTime($session['session_start']);
            $sessionEnd = new DateTime();
            $durationMinutes = $sessionEnd->diff($sessionStart)->i + ($sessionEnd->diff($sessionStart)->h * 60);
            
            $sql = "UPDATE user_activity_sessions SET session_end = NOW(), session_duration_minutes = ? WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([$durationMinutes, $sessionId]);
            
            if ($result) {
                // Log forced session end activity
                try {
                    $logger = ActivityLogger::getInstance();
                    $logger->logActivity(
                        $decoded->user_id,
                        $session['project_id'],
                        'session_force_ended',
                        "Work session force ended by admin",
                        $sessionId,
                        [
                            'target_user_id' => $session['user_id'],
                            'duration_minutes' => $durationMinutes,
                            'duration_hours' => round($durationMinutes / 60, 2)
                        ]
                    );
                } catch (Exception $e) {
                    error_log("Failed to log force end session activity: " . $e->getMessage());
                }
                
                $this->sendJsonResponse(200, "Session force ended successfully", [
                    'session_id' => $sessionId,
                    'duration_minutes' => $durationMinutes,
                    'duration_hours' => round($durationMinutes / 60, 2)
                ]);
            } else {
                $this->sendJsonResponse(500, "Failed to force end session");
            }
            
        } catch (Exception $e) {
            error_log("Error force ending session: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to force end session: " . $e->getMessage());
        }
    }
}
?>
