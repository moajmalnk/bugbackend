<?php
/**
 * Admin: disconnect a user's Google link, or require them to connect before the dashboard.
 * POST JSON: { user_id: string, action: "disconnect" | "notify" }
 */
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../PermissionManager.php';
require_once __DIR__ . '/../../utils/google_connect_gate.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-cache');
header('Vary: Authorization');

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    if (!$decoded || empty($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $isImpersonated = (isset($decoded->impersonated)
            && ($decoded->impersonated === true || $decoded->impersonated === 'true' || $decoded->impersonated === 1))
        || (!empty($decoded->admin_id) && (string) $decoded->admin_id !== (string) $decoded->user_id);
    if ($isImpersonated) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Leave impersonation before changing Google accounts.']);
        exit;
    }

    $pm = PermissionManager::getInstance();
    $role = strtolower((string) ($decoded->role ?? ''));
    if (!$pm->hasPermissionOrAdmin($decoded->user_id, 'PUSH_COVERAGE_VIEW', $role)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'PUSH_COVERAGE_VIEW permission required']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
        exit;
    }

    $userId = trim((string) ($input['user_id'] ?? ''));
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    if ($userId === '' || !in_array($action, ['disconnect', 'notify'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id and action are required']);
        exit;
    }

    $conn = $api->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    if (!br_ensure_google_connect_required($conn)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Google connect requirement is not available']);
        exit;
    }

    $check = $conn->prepare('SELECT id, username FROM users WHERE id = ? AND account_active = 1 LIMIT 1');
    $check->execute([$userId]);
    $user = $check->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    $username = (string) ($user['username'] ?? 'user');

    if ($action === 'notify') {
        if (br_user_has_google_link($conn, $userId)) {
            br_clear_google_connect_requirement($conn, $userId);
            echo json_encode([
                'success' => false,
                'message' => $username . ' already has a Google account connected.',
            ]);
            exit;
        }
        $upd = $conn->prepare('UPDATE users SET google_connect_required = 1 WHERE id = ?');
        $upd->execute([$userId]);
        echo json_encode([
            'success' => true,
            'message' => $username . ' will be asked to connect Google before the dashboard opens.',
            'data' => [
                'user_id' => $userId,
                'google_connect_required' => true,
            ],
        ]);
        exit;
    }

    try {
        require_once __DIR__ . '/../oauth/GoogleAuthService.php';
        $authService = new GoogleAuthService();
        $client = $authService->getClientForUser($userId);
        $client->revokeToken();
    } catch (Throwable $revokeError) {
        error_log('google_account_admin revoke: ' . $revokeError->getMessage());
    }

    $del = $conn->prepare('DELETE FROM google_tokens WHERE bugricer_user_id = ?');
    $del->execute([$userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Google account disconnected for ' . $username,
        'data' => [
            'user_id' => $userId,
            'deleted_tokens' => $del->rowCount(),
        ],
    ]);
} catch (Throwable $e) {
    error_log('google_account_admin.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
