<?php
/**
 * Start Activity Session on Login
 * Called after successful login to track user session start
 */

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../utils/activity_sessions_schema.php';

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
    
    // Check if user already has an active session (best-effort; schema differences should not 500)
    try {
        ActivitySessionsSchema::ensureSchema($conn);
        $activePredicate = ActivitySessionsSchema::activeSessionPredicate($conn);

        $checkStmt = $conn->prepare("
            SELECT id FROM user_activity_sessions 
            WHERE user_id = ? AND {$activePredicate}
            ORDER BY session_start DESC 
            LIMIT 1
        ");
        $checkStmt->execute([$userId]);
        $existingSession = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingSession) {
            $setClause = ActivitySessionsSchema::closeSessionSetClause($conn);
            $closeStmt = $conn->prepare("
                UPDATE user_activity_sessions 
                SET {$setClause}
                WHERE id = ?
            ");
            $now = date('Y-m-d H:i:s');
            $closeStmt->execute([$now, 0, $existingSession['id']]);
        }
    } catch (PDOException $e) {
        error_log("start_session_on_login existing session cleanup: " . $e->getMessage());
    }
    
    // Start new session (optional — failures must not block login)
    $sessionId = Utils::generateUUID();
    $now = date('Y-m-d H:i:s');

    try {
        $insert = ActivitySessionsSchema::insertColumns($conn);
        $columnList = implode(', ', $insert['columns']);
        $placeholderList = implode(', ', $insert['placeholders']);

        $stmt = $conn->prepare("
            INSERT INTO user_activity_sessions ({$columnList}) 
            VALUES ({$placeholderList})
        ");
        $stmt->execute([$sessionId, $userId, $now, $now]);
    } catch (PDOException $e) {
        error_log("start_session_on_login INSERT user_activity_sessions: " . $e->getMessage());
        echo json_encode([
            'success' => true,
            'message' => 'Login OK; activity session not recorded',
        ]);
        exit();
    }

    // Update users row (columns may be missing on older DBs)
    try {
        $cols = [];
        $cr = $conn->query("SHOW COLUMNS FROM users");
        if ($cr) {
            while ($row = $cr->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = $row['Field'];
            }
        }
        $sets = [];
        if (in_array('last_active_at', $cols, true)) {
            $sets[] = 'last_active_at = NOW()';
        }
        if (in_array('last_login_at', $cols, true)) {
            $sets[] = 'last_login_at = NOW()';
        }
        if (in_array('updated_at', $cols, true)) {
            $sets[] = 'updated_at = NOW()';
        }
        if (!empty($sets)) {
            $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $updateStmt = $conn->prepare($sql);
            $updateStmt->execute([$userId]);
        }
    } catch (PDOException $e) {
        error_log("start_session_on_login UPDATE users: " . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Session started',
        'session_id' => $sessionId,
    ]);
    exit();

} catch (Exception $e) {
    error_log("Error starting session on login: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to start session'
    ]);
}
?>

