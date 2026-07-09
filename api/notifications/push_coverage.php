<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

header('Content-Type: application/json');

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $role = strtolower((string)($decoded->role ?? ''));
    if ($role !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only admins can view push coverage']);
        exit;
    }

    $conn = $api->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $summary = [
        'active_users' => 0,
        'users_with_tokens' => 0,
        'users_without_tokens' => 0,
        'total_device_tokens' => 0,
        'recent_tokens_24h' => 0,
    ];

    $summarySql = "
        SELECT
            (SELECT COUNT(*) FROM users WHERE account_active = 1) AS active_users,
            (SELECT COUNT(DISTINCT user_id) FROM user_fcm_tokens WHERE is_active = 1) AS users_with_tokens,
            (SELECT COUNT(*) FROM user_fcm_tokens WHERE is_active = 1) AS total_device_tokens,
            (SELECT COUNT(*) FROM user_fcm_tokens WHERE is_active = 1 AND last_used >= NOW() - INTERVAL 1 DAY) AS recent_tokens_24h
    ";
    $summaryStmt = $conn->query($summarySql);
    if ($summaryStmt) {
        $row = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $summary['active_users'] = (int)$row['active_users'];
            $summary['users_with_tokens'] = (int)$row['users_with_tokens'];
            $summary['total_device_tokens'] = (int)$row['total_device_tokens'];
            $summary['recent_tokens_24h'] = (int)$row['recent_tokens_24h'];
            $summary['users_without_tokens'] = max(0, $summary['active_users'] - $summary['users_with_tokens']);
        }
    }

    $missingUsersSql = "
        SELECT u.id, u.username, u.email
        FROM users u
        LEFT JOIN (
            SELECT DISTINCT user_id
            FROM user_fcm_tokens
            WHERE is_active = 1
        ) t ON t.user_id = u.id
        WHERE u.account_active = 1
          AND t.user_id IS NULL
        ORDER BY u.username
    ";
    $missingUsersStmt = $conn->query($missingUsersSql);
    $missingUsers = $missingUsersStmt ? $missingUsersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $deviceBreakdownSql = "
        SELECT user_id, username, browser_name, os_name, device_label, last_used
        FROM user_fcm_tokens
        WHERE is_active = 1
        ORDER BY last_used DESC
        LIMIT 200
    ";
    $deviceBreakdownStmt = $conn->query($deviceBreakdownSql);
    $devices = $deviceBreakdownStmt ? $deviceBreakdownStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    echo json_encode([
        'success' => true,
        'data' => [
            'summary' => $summary,
            'missing_users' => $missingUsers,
            'devices' => $devices,
        ],
    ]);
} catch (Exception $e) {
    error_log('push_coverage.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
    ]);
}

