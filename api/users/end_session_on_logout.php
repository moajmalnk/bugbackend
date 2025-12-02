<?php
/**
 * End Activity Session on Logout
 * Called when user logs out to properly close their active session
 */

require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $api = new BaseAPI();
    
    // Try to validate token, but don't fail if token is invalid (user might have already logged out)
    $decoded = null;
    try {
        $decoded = $api->validateToken();
    } catch (Exception $e) {
        // Token might be invalid/expired, but we still want to try to close any active sessions
        // We'll need user_id from request body if token is invalid
    }
    
    $userId = null;
    if ($decoded && isset($decoded->user_id)) {
        $userId = $decoded->user_id;
    } else {
        // Try to get user_id from request body
        $data = json_decode(file_get_contents('php://input'), true);
        $userId = $data['user_id'] ?? null;
    }
    
    if (!$userId) {
        // Can't proceed without user_id
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit();
    }
    
    $conn = $api->getConnection();
    
    // Check if user_activity_sessions table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'user_activity_sessions'")->rowCount() > 0;
    if (!$tableExists) {
        echo json_encode(['success' => true, 'message' => 'Activity tracking table not available']);
        exit();
    }
    
    // Find and close all active sessions for this user
    $checkStmt = $conn->prepare("
        SELECT id, session_start 
        FROM user_activity_sessions 
        WHERE user_id = ? AND is_active = TRUE
    ");
    $checkStmt->execute([$userId]);
    $activeSessions = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $now = date('Y-m-d H:i:s');
    $closedCount = 0;
    
    $istTimezone = new DateTimeZone('Asia/Kolkata');
    foreach ($activeSessions as $session) {
        $sessionStart = new DateTime($session['session_start'], $istTimezone);
        $sessionEnd = new DateTime($now, $istTimezone);
        $durationMinutes = (int)(($sessionEnd->getTimestamp() - $sessionStart->getTimestamp()) / 60);
        
        $closeStmt = $conn->prepare("
            UPDATE user_activity_sessions 
            SET session_end = ?, 
                session_duration_minutes = ?,
                is_active = FALSE, 
                updated_at = NOW() 
            WHERE id = ?
        ");
        $closeStmt->execute([$now, $durationMinutes, $session['id']]);
        $closedCount++;
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Session ended',
        'sessions_closed' => $closedCount
    ]);
    
} catch (Exception $e) {
    error_log("Error ending session on logout: " . $e->getMessage());
    // Don't fail logout if session tracking fails
    echo json_encode([
        'success' => true, // Return success even if tracking fails
        'message' => 'Logout processed'
    ]);
}
?>

