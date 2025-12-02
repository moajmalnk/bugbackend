<?php
require_once '../BaseAPI.php';

class HeartbeatController extends BaseAPI {
    public function __construct() {
        parent::__construct();
    }

    public function updateHeartbeat() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }

        try {
            // Validate token and get user_id
            $decoded = $this->validateToken();
            if (!$decoded || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid token or user_id missing");
                return;
            }

            $userId = $decoded->user_id;
            $now = date('Y-m-d H:i:s');

            // Update last_active_at timestamp
            $stmt = $this->conn->prepare("UPDATE users SET last_active_at = NOW() WHERE id = ?");
            
            if (!$stmt) {
                error_log("Failed to prepare heartbeat statement: " . implode(", ", $this->conn->errorInfo()));
                $this->sendJsonResponse(500, "Database error occurred");
                return;
            }

            if (!$stmt->execute([$userId])) {
                error_log("Failed to execute heartbeat statement: " . implode(", ", $stmt->errorInfo()));
                $this->sendJsonResponse(500, "Database error occurred");
                return;
            }

            // Track activity session
            $this->trackActivitySession($userId, $now);

            // Return 204 No Content for optimal performance
            http_response_code(204);
            exit();

        } catch (Exception $e) {
            error_log("Heartbeat error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    private function trackActivitySession($userId, $timestamp) {
        try {
            // Check if user_activity_sessions table exists
            $tableExists = $this->conn->query("SHOW TABLES LIKE 'user_activity_sessions'")->rowCount() > 0;
            if (!$tableExists) {
                return;
            }

            // Check if there's an active session for this user
            $checkStmt = $this->conn->prepare("
                SELECT id, session_start, session_end, updated_at
                FROM user_activity_sessions 
                WHERE user_id = ? AND is_active = TRUE 
                ORDER BY session_start DESC 
                LIMIT 1
            ");
            $checkStmt->execute([$userId]);
            $activeSession = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($activeSession) {
                // Check if the last update was more than 5 minutes ago (idle timeout)
                // Use updated_at if available, otherwise use session_start
                $lastUpdateTime = $activeSession['updated_at'] ?? $activeSession['session_start'];
                $istTimezone = new DateTimeZone('Asia/Kolkata');
                $lastUpdate = new DateTime($lastUpdateTime, $istTimezone);
                $currentTime = new DateTime($timestamp, $istTimezone);
                $diffSeconds = $currentTime->getTimestamp() - $lastUpdate->getTimestamp();
                $diffMinutes = floor($diffSeconds / 60);

                if ($diffMinutes >= 5) {
                    // User was idle for 5+ minutes, close the previous session
                    $this->closeActivitySession($activeSession['id'], $lastUpdate);
                    // Start a new session for current activity
                    $this->startNewActivitySession($userId, $timestamp);
                } else {
                    // User is still active, update the existing session
                    $this->updateActivitySession($activeSession['id'], $timestamp);
                }
            } else {
                // No active session, start a new one
                $this->startNewActivitySession($userId, $timestamp);
            }
        } catch (Exception $e) {
            // Don't fail the heartbeat if activity tracking fails
            error_log("Activity session tracking error: " . $e->getMessage());
        }
    }

    private function startNewActivitySession($userId, $timestamp) {
        try {
            // Check if user_activity_sessions table exists
            $tableExists = $this->conn->query("SHOW TABLES LIKE 'user_activity_sessions'")->rowCount() > 0;
            if (!$tableExists) {
                return;
            }
            
            $sessionId = $this->utils->generateUUID();
            
            // Start new session with session_start and initial session_end (will be updated on each heartbeat)
            $stmt = $this->conn->prepare("
                INSERT INTO user_activity_sessions (id, user_id, session_start, session_end, is_active, created_at, updated_at) 
                VALUES (?, ?, ?, ?, TRUE, NOW(), NOW())
            ");
            $stmt->execute([$sessionId, $userId, $timestamp, $timestamp]);
        } catch (Exception $e) {
            // If activity tracking fails, log but don't fail the heartbeat
            error_log("Activity session creation failed: " . $e->getMessage());
        }
    }

    private function updateActivitySession($sessionId, $timestamp) {
        try {
            // Update session_end to current time and updated_at timestamp
            // This extends the session duration as long as user is active
            $stmt = $this->conn->prepare("
                UPDATE user_activity_sessions 
                SET session_end = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$timestamp, $sessionId]);
        } catch (Exception $e) {
            error_log("Activity session update failed: " . $e->getMessage());
        }
    }

    private function closeActivitySession($sessionId, $endTime) {
        try {
            // Get session start time to calculate duration
            $stmt = $this->conn->prepare("
                SELECT session_start FROM user_activity_sessions WHERE id = ?
            ");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($session) {
                $istTimezone = new DateTimeZone('Asia/Kolkata');
                $sessionStart = new DateTime($session['session_start'], $istTimezone);
                $sessionEnd = $endTime instanceof DateTime ? $endTime : new DateTime($endTime, $istTimezone);
                $durationMinutes = (int)(($sessionEnd->getTimestamp() - $sessionStart->getTimestamp()) / 60);
                
                // Close session with calculated duration
                $stmt = $this->conn->prepare("
                    UPDATE user_activity_sessions 
                    SET session_end = ?, 
                        session_duration_minutes = ?,
                        is_active = FALSE, 
                        updated_at = NOW() 
                    WHERE id = ?
                ");
                $endTimeStr = $endTime instanceof DateTime ? $endTime->format('Y-m-d H:i:s') : $endTime;
                $stmt->execute([$endTimeStr, $durationMinutes, $sessionId]);
            }
        } catch (Exception $e) {
            error_log("Activity session close failed: " . $e->getMessage());
        }
    }
}

// Ensure no output before this point
if (ob_get_length()) ob_clean();

$controller = new HeartbeatController();
$controller->updateHeartbeat();
?>
