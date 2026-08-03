<?php
/**
 * End session on logout.
 * Body: { user_id?: string, scope?: "this_device" | "all_devices" }
 *
 * this_device  — close activity sessions; client clears local JWT
 * all_devices  — bump auth_token_epoch (invalidate all JWTs) + close activity sessions
 */

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/activity_sessions_schema.php';
require_once __DIR__ . '/../../config/utils.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

/**
 * Read Bearer token without going through BaseAPI::validateToken (which exits on failure).
 */
function logoutReadBearerToken() {
    $header = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (isset($requestHeaders['Authorization'])) {
            $header = trim($requestHeaders['Authorization']);
        }
    }
    if ($header && preg_match('/Bearer\s(\S+)/', $header, $matches)) {
        return $matches[1];
    }
    return null;
}

try {
    $api = new BaseAPI();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $scope = isset($data['scope']) && $data['scope'] === 'all_devices'
        ? 'all_devices'
        : 'this_device';

    $token = logoutReadBearerToken();
    $decoded = $token ? Utils::validateJWT($token) : false;

    $userId = null;
    if ($decoded && isset($decoded->user_id)) {
        $userId = $decoded->user_id;
    } elseif (!empty($data['user_id']) && $scope === 'this_device') {
        // Soft fallback for this-device only (activity cleanup)
        $userId = $data['user_id'];
    }

    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit();
    }

    // all_devices requires a valid authenticated token
    if ($scope === 'all_devices' && (!$decoded || !isset($decoded->user_id))) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication required to sign out all devices',
            'error_code' => 'UNAUTHORIZED',
        ]);
        exit();
    }

    $conn = $api->getConnection();
    $sessionsClosed = 0;
    $newEpoch = null;

    if ($scope === 'all_devices') {
        Utils::ensureAuthTokenEpochColumn($conn);

        // Reject already-revoked tokens before bumping again
        $epochStmt = $conn->prepare("SELECT auth_token_epoch FROM users WHERE id = ? LIMIT 1");
        $epochStmt->execute([$userId]);
        $epochRow = $epochStmt->fetch(PDO::FETCH_ASSOC);
        $dbEpoch = $epochRow ? (int) ($epochRow['auth_token_epoch'] ?? 0) : 0;
        $tokenEpoch = isset($decoded->auth_epoch) ? (int) $decoded->auth_epoch : 0;
        if ($tokenEpoch !== $dbEpoch) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Session already ended. Please sign in again.',
                'error_code' => 'SESSION_REVOKED',
            ]);
            exit();
        }

        $stmt = $conn->prepare(
            "UPDATE users SET auth_token_epoch = auth_token_epoch + 1 WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $epochStmt->execute([$userId]);
        $epochRow = $epochStmt->fetch(PDO::FETCH_ASSOC);
        $newEpoch = $epochRow ? (int) $epochRow['auth_token_epoch'] : null;
    }

    $tableExists = $conn->query("SHOW TABLES LIKE 'user_activity_sessions'")->rowCount() > 0;
    if ($tableExists) {
        ActivitySessionsSchema::ensureSchema($conn);
        $activePredicate = ActivitySessionsSchema::activeSessionPredicate($conn);

        $checkStmt = $conn->prepare("
            SELECT id, session_start 
            FROM user_activity_sessions 
            WHERE user_id = ? AND {$activePredicate}
        ");
        $checkStmt->execute([$userId]);
        $activeSessions = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

        $now = date('Y-m-d H:i:s');
        $istTimezone = new DateTimeZone('Asia/Kolkata');
        $setClause = ActivitySessionsSchema::closeSessionSetClause($conn);

        foreach ($activeSessions as $session) {
            $sessionStart = new DateTime($session['session_start'], $istTimezone);
            $sessionEnd = new DateTime($now, $istTimezone);
            $durationMinutes = (int) (($sessionEnd->getTimestamp() - $sessionStart->getTimestamp()) / 60);

            $closeStmt = $conn->prepare("
                UPDATE user_activity_sessions 
                SET {$setClause}
                WHERE id = ?
            ");
            $closeStmt->execute([$now, $durationMinutes, $session['id']]);
            $sessionsClosed++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $scope === 'all_devices'
            ? 'Signed out of all devices'
            : 'Session ended',
        'scope' => $scope,
        'sessions_closed' => $sessionsClosed,
        'auth_token_epoch' => $newEpoch,
    ]);
} catch (Exception $e) {
    error_log("Error ending session on logout: " . $e->getMessage());
    echo json_encode([
        'success' => true,
        'message' => 'Logout processed',
    ]);
}
