<?php
/**
 * Start Activity Session on Login
 * Called after successful login to track user session start
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
    $decoded = $api->validateToken();
    
    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $userId = $decoded->user_id;
    $conn = $api->getConnection();
    
    // Check if user_activity_sessions table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'user_activity_sessions'")->rowCount() > 0;
    if (!$tableExists) {
        echo json_encode(['success' => true, 'message' => 'Activity tracking table not available']);
        exit();
    }
    
    // Check if user already has an active session (shouldn't happen on fresh login, but handle it)
    $checkStmt = $conn->prepare("
        SELECT id FROM user_activity_sessions 
        WHERE user_id = ? AND is_active = TRUE 
        ORDER BY session_start DESC 
        LIMIT 1
    ");
    $checkStmt->execute([$userId]);
    $existingSession = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingSession) {
        // Close any existing active session (shouldn't happen, but handle edge cases)
        $closeStmt = $conn->prepare("
            UPDATE user_activity_sessions 
            SET session_end = NOW(), 
                is_active = FALSE, 
                updated_at = NOW() 
            WHERE id = ?
        ");
        $closeStmt->execute([$existingSession['id']]);
    }
    
    // Start new session
    $sessionId = $api->utils->generateUUID();
    $now = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("
        INSERT INTO user_activity_sessions (id, user_id, session_start, session_end, is_active, created_at, updated_at) 
        VALUES (?, ?, ?, ?, TRUE, NOW(), NOW())
    ");
    $stmt->execute([$sessionId, $userId, $now, $now]);
    
    // Update last_login_at in users table
    $updateStmt = $conn->prepare("
        UPDATE users 
        SET last_login_at = NOW(), last_active_at = NOW(), updated_at = NOW() 
        WHERE id = ?
    ");
    $updateStmt->execute([$userId]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Session started',
        'session_id' => $sessionId
    ]);
    
} catch (Exception $e) {
    error_log("Error starting session on login: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to start session'
    ]);
}
?>

