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
            // Check if there's an active session for this user
            $checkStmt = $this->conn->prepare("
                SELECT id, session_start 
                FROM user_activity_sessions 
                WHERE user_id = ? AND is_active = TRUE 
                ORDER BY session_start DESC 
                LIMIT 1
            ");
            $checkStmt->execute([$userId]);
            $activeSession = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($activeSession) {
                // Check if the last activity was more than 5 minutes ago
                $lastActivity = new DateTime($activeSession['session_start']);
                $currentTime = new DateTime($timestamp);
                $diffMinutes = $currentTime->diff($lastActivity)->i;

                if ($diffMinutes >= 5) {
                    // Close the previous session and start a new one
                    $this->closeActivitySession($activeSession['id'], $timestamp);
                    $this->startNewActivitySession($userId, $timestamp);
                } else {
                    // Update the existing session
                    $this->updateActivitySession($activeSession['id'], $timestamp);
                }
            } else {
                // Start a new session
                $this->startNewActivitySession($userId, $timestamp);
            }
        } catch (Exception $e) {
            // Don't fail the heartbeat if activity tracking fails
            error_log("Activity session tracking error: " . $e->getMessage());
        }
    }

    private function startNewActivitySession($userId, $timestamp) {
        $sessionId = $this->utils->generateUUID();
        
        // Check if user_activity_sessions table exists
        $tableExists = $this->conn->query("SHOW TABLES LIKE 'user_activity_sessions'")->rowCount() > 0;
        
        if (!$tableExists) {
            // Table doesn't exist, skip activity tracking
            return;
        }
        
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO user_activity_sessions (id, user_id, session_start, is_active) 
                VALUES (?, ?, ?, TRUE)
            ");
            $stmt->execute([$sessionId, $userId, $timestamp]);
        } catch (Exception $e) {
            // If activity tracking fails, log but don't fail the heartbeat
            error_log("Activity session creation failed: " . $e->getMessage());
        }
    }

    private function updateActivitySession($sessionId, $timestamp) {
        try {
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

    private function closeActivitySession($sessionId, $timestamp) {
        try {
            $stmt = $this->conn->prepare("
                UPDATE user_activity_sessions 
                SET session_end = ?, is_active = FALSE, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$timestamp, $sessionId]);
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
