<?php
/**
 * Why: Exchange welcome_invite JWT from the new-user email for a real session.
 * POST { token: string }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../config/fcm_config.php';
require_once __DIR__ . '/../../utils/user_avatar.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = trim((string) ($input['token'] ?? ''));
    if ($token === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Welcome token is required']);
        exit;
    }

    $decoded = Utils::validateJWT($token);
    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired welcome link']);
        exit;
    }

    $purpose = isset($decoded->purpose) ? (string) $decoded->purpose : '';
    if ($purpose !== 'welcome_invite') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid welcome link']);
        exit;
    }

    $db = getDBConnection();
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    $cols = [];
    $colRes = $db->query('SHOW COLUMNS FROM users');
    if ($colRes) {
        while ($row = $colRes->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = $row['Field'];
        }
    }

    $select = ['id', 'username', 'email', 'phone', 'role', 'role_id'];
    $select = br_user_avatar_select_cols($select, $cols);
    foreach (
        [
            'account_active',
            'onboarding_completed',
            'must_set_password',
            'onboarding_verification_status',
            'joining_date',
            'created_at',
        ] as $optional
    ) {
        if (in_array($optional, $cols, true)) {
            $select[] = $optional;
        }
    }

    $stmt = $db->prepare(
        'SELECT ' . implode(', ', $select) . ' FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([(string) $decoded->user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !Utils::userRowIsAllowedLogin($user)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'This account is no longer active',
            'error_code' => 'ACCOUNT_REVOKED',
        ]);
        exit;
    }

    unset($user['account_active']);
    $user = br_user_with_resolved_avatar($user);
    $user = FcmConfig::appendEpochToPayload($user);

    $sessionToken = Utils::generateJWT(
        (string) $user['id'],
        (string) $user['username'],
        (string) $user['role']
    );

    echo json_encode([
        'success' => true,
        'message' => 'Welcome link verified',
        'token' => $sessionToken,
        'user' => $user,
        'fcm_token_epoch' => FcmConfig::getTokenEpoch(),
    ]);
} catch (Throwable $e) {
    error_log('verify_welcome_invite error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
