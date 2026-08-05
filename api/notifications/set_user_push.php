<?php
require_once __DIR__ . '/../PermissionManager.php';
/**
 * Admin: enable/disable push notifications for a user.
 * POST JSON: { user_id: string, enabled: bool }
 */
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

function tableHasColumn(PDO $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensurePushColumn(PDO $conn): void
{
    if (tableHasColumn($conn, 'users', 'push_notifications_enabled')) {
        return;
    }
    try {
        $after = tableHasColumn($conn, 'users', 'account_active')
            ? ' AFTER account_active'
            : '';
        $conn->exec(
            "ALTER TABLE users ADD COLUMN push_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1{$after}"
        );
    } catch (Throwable $e) {
        error_log('set_user_push ensurePushColumn: ' . $e->getMessage());
    }
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $pm = PermissionManager::getInstance();
    if (!$pm->hasPermissionOrAdmin($decoded->user_id ?? '', 'PUSH_COVERAGE_VIEW', $decoded->role ?? null)) {
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
    if ($userId === '' || !array_key_exists('enabled', $input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id and enabled are required']);
        exit;
    }

    $enabled = !empty($input['enabled']) ? 1 : 0;

    $conn = $api->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    ensurePushColumn($conn);

    $check = $conn->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
    $check->execute([$userId]);
    $user = $check->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    $upd = $conn->prepare('UPDATE users SET push_notifications_enabled = ? WHERE id = ?');
    $upd->execute([$enabled, $userId]);

    $tokensTouched = 0;
    if (tableHasColumn($conn, 'user_fcm_tokens', 'is_active')) {
        $tok = $conn->prepare('UPDATE user_fcm_tokens SET is_active = ? WHERE user_id = ?');
        $tok->execute([$enabled, $userId]);
        $tokensTouched = $tok->rowCount();
    }

    echo json_encode([
        'success' => true,
        'message' => $enabled
            ? 'Push notifications enabled for ' . ($user['username'] ?? 'user')
            : 'Push notifications disabled for ' . ($user['username'] ?? 'user'),
        'data' => [
            'user_id' => $userId,
            'username' => $user['username'] ?? null,
            'push_notifications_enabled' => $enabled === 1,
            'tokens_updated' => $tokensTouched,
        ],
    ]);
} catch (Throwable $e) {
    error_log('set_user_push.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
