<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

function tableExists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    if (strtolower((string) ($decoded->role ?? '')) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only admins can run FCM cleanup']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $conn = $api->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $fullReset = !empty($input['full_reset']);

    $deletedLegacy = 0;
    $clearedLegacyUsers = 0;

    if (tableExists($conn, 'user_fcm_tokens')) {
        if ($fullReset) {
            $deletedLegacy = (int) $conn->exec('DELETE FROM user_fcm_tokens');
        } else {
            $stmt = $conn->prepare("
                DELETE FROM user_fcm_tokens
                WHERE (device_label LIKE '%Recovered%' OR device_label LIKE '%recovered%')
                   OR (platform LIKE '%legacy%' OR platform LIKE '%migration%')
                   OR (user_agent LIKE '%legacy%')
            ");
            $stmt->execute();
            $deletedLegacy = $stmt->rowCount();
        }
    }

    if ($fullReset) {
        $clearedLegacyUsers = (int) $conn->exec('UPDATE users SET fcm_token = NULL');
    } else {
        $clearStmt = $conn->prepare("
            UPDATE users u
            SET u.fcm_token = NULL
            WHERE u.fcm_token IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM user_fcm_tokens t
                  WHERE t.user_id = u.id
                    AND t.is_active = 1
              )
        ");
        try {
            $clearStmt->execute();
            $clearedLegacyUsers = $clearStmt->rowCount();
        } catch (Throwable $e) {
            $fallback = $conn->exec("
                UPDATE users SET fcm_token = NULL
                WHERE fcm_token IS NOT NULL
            ");
            $clearedLegacyUsers = $fallback !== false ? (int) $fallback : 0;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => $fullReset
            ? 'Full FCM token reset completed'
            : 'Legacy/recovered FCM tokens removed',
        'data' => [
            'deleted_token_rows' => $deletedLegacy,
            'cleared_users_fcm_token' => $clearedLegacyUsers,
            'full_reset' => $fullReset,
            'next_step' => 'Bump FCM_TOKEN_EPOCH in backend/.env and deploy so clients re-register on login.',
        ],
    ]);
} catch (Throwable $e) {
    error_log('cleanup_legacy_fcm_tokens.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
