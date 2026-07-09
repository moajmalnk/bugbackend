<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/fcm_config.php';

header('Content-Type: application/json');

function tableExists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableHasColumn(PDO $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $stmt->execute([$table, $column]);
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

    $role = strtolower((string) ($decoded->role ?? ''));
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
        'pwa_installed_users' => 0,
        'notification_enabled_users' => 0,
        'notification_disabled_users' => 0,
        'stale_tokens_30d' => 0,
        'legacy_recovered_tokens' => 0,
    ];

    $hasTokenTable = tableExists($conn, 'user_fcm_tokens');
    $hasPwaInstalledColumn = $hasTokenTable && tableHasColumn($conn, 'user_fcm_tokens', 'pwa_installed');
    $hasIsActiveColumn = $hasTokenTable && tableHasColumn($conn, 'user_fcm_tokens', 'is_active');
    $activeWhereT = $hasIsActiveColumn ? 't.is_active = 1' : '1=1';
    $activeWhereBare = $hasIsActiveColumn ? 'is_active = 1' : '1=1';
    $pwaWhereT = $hasPwaInstalledColumn ? 'COALESCE(t.pwa_installed, 0) = 1' : '1=0';

    if ($hasTokenTable) {
        $summarySql = "
            SELECT
                (SELECT COUNT(*) FROM users WHERE account_active = 1) AS active_users,
                (
                    SELECT COUNT(DISTINCT uid) FROM (
                        SELECT t.user_id AS uid
                        FROM user_fcm_tokens t
                        INNER JOIN users u ON u.id = t.user_id
                        WHERE u.account_active = 1 AND {$activeWhereT}
                        UNION
                        SELECT u.id AS uid
                        FROM users u
                        WHERE u.account_active = 1
                          AND u.fcm_token IS NOT NULL
                          AND TRIM(u.fcm_token) <> ''
                    ) AS covered
                ) AS users_with_tokens,
                (
                    SELECT COUNT(*) FROM user_fcm_tokens t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE u.account_active = 1 AND {$activeWhereT}
                ) AS total_device_tokens,
                (
                    SELECT COUNT(DISTINCT t.user_id)
                    FROM user_fcm_tokens t
                    INNER JOIN users u ON u.id = t.user_id
                    WHERE u.account_active = 1
                      AND {$activeWhereT}
                      AND {$pwaWhereT}
                ) AS pwa_installed_users,
                (
                    SELECT COUNT(*) FROM user_fcm_tokens t
                    WHERE {$activeWhereT}
                      AND t.last_used >= NOW() - INTERVAL 1 DAY
                ) AS recent_tokens_24h,
                (
                    SELECT COUNT(*) FROM user_fcm_tokens t
                    WHERE {$activeWhereT}
                      AND (t.last_used IS NULL OR t.last_used < NOW() - INTERVAL 30 DAY)
                ) AS stale_tokens_30d,
                (
                    SELECT COUNT(*) FROM user_fcm_tokens t
                    WHERE (t.device_label LIKE '%Recovered%' OR t.device_label LIKE '%recovered%')
                       OR (t.platform LIKE '%legacy%' OR t.platform LIKE '%migration%')
                       OR (t.user_agent LIKE '%legacy%')
                ) AS legacy_recovered_tokens
        ";
    } else {
        $summarySql = "
            SELECT
                (SELECT COUNT(*) FROM users WHERE account_active = 1) AS active_users,
                (
                    SELECT COUNT(*)
                    FROM users u
                    WHERE u.account_active = 1
                      AND u.fcm_token IS NOT NULL
                      AND TRIM(u.fcm_token) <> ''
                ) AS users_with_tokens,
                0 AS total_device_tokens,
                0 AS pwa_installed_users,
                0 AS recent_tokens_24h,
                0 AS stale_tokens_30d,
                0 AS legacy_recovered_tokens
        ";
    }

    $summaryStmt = $conn->query($summarySql);
    if ($summaryStmt) {
        $row = $summaryStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $summary['active_users'] = (int) $row['active_users'];
            $summary['users_with_tokens'] = (int) $row['users_with_tokens'];
            $summary['total_device_tokens'] = (int) ($row['total_device_tokens'] ?? 0);
            $summary['pwa_installed_users'] = (int) ($row['pwa_installed_users'] ?? 0);
            $summary['recent_tokens_24h'] = (int) ($row['recent_tokens_24h'] ?? 0);
            $summary['stale_tokens_30d'] = (int) ($row['stale_tokens_30d'] ?? 0);
            $summary['legacy_recovered_tokens'] = (int) ($row['legacy_recovered_tokens'] ?? 0);
            $summary['users_without_tokens'] = max(0, $summary['active_users'] - $summary['users_with_tokens']);
            $summary['notification_enabled_users'] = $summary['users_with_tokens'];
            $summary['notification_disabled_users'] = $summary['users_without_tokens'];
        }
    }

    $missingUsers = [];
    $devices = [];
    $pwaInstalledUsers = [];
    $notificationEnabledUsers = [];
    $notificationDisabledUsers = [];

    if ($hasTokenTable) {
        $missingUsersSql = "
            SELECT u.id, u.username, u.email
            FROM users u
            LEFT JOIN (
                SELECT DISTINCT user_id
                FROM user_fcm_tokens
                WHERE {$activeWhereBare}
            ) covered ON covered.user_id = u.id
            WHERE u.account_active = 1
              AND (u.fcm_token IS NULL OR TRIM(u.fcm_token) = '')
              AND covered.user_id IS NULL
            ORDER BY u.username
            LIMIT 200
        ";
        $missingUsersStmt = $conn->query($missingUsersSql);
        $missingUsers = $missingUsersStmt ? $missingUsersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $deviceBreakdownSql = "
            SELECT
                t.user_id,
                t.username,
                t.browser_name,
                t.os_name,
                t.device_label,
                t.platform,
                t.last_used,
                CASE
                    WHEN (t.device_label LIKE '%Recovered%' OR t.device_label LIKE '%recovered%'
                       OR t.platform LIKE '%legacy%' OR t.platform LIKE '%migration%'
                       OR t.user_agent LIKE '%legacy%') THEN 1
                    ELSE 0
                END AS is_legacy,
                CASE
                    WHEN t.last_used IS NULL OR t.last_used < NOW() - INTERVAL 30 DAY THEN 1
                    ELSE 0
                END AS is_stale
            FROM user_fcm_tokens t
            WHERE {$activeWhereT}
            ORDER BY t.last_used DESC
            LIMIT 200
        ";
        $deviceBreakdownStmt = $conn->query($deviceBreakdownSql);
        $devices = $deviceBreakdownStmt ? $deviceBreakdownStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($devices as &$deviceRow) {
            $deviceRow['is_legacy'] = (int) ($deviceRow['is_legacy'] ?? 0);
            $deviceRow['is_stale'] = (int) ($deviceRow['is_stale'] ?? 0);
        }
        unset($deviceRow);

        if ($hasPwaInstalledColumn) {
            $pwaInstalledUsersSql = "
                SELECT
                    u.id,
                    u.username,
                    u.email,
                    COUNT(*) AS device_count,
                    MAX(t.last_used) AS last_used
                FROM user_fcm_tokens t
                INNER JOIN users u ON u.id = t.user_id
                WHERE u.account_active = 1
                  AND {$activeWhereT}
                  AND {$pwaWhereT}
                GROUP BY u.id, u.username, u.email
                ORDER BY last_used DESC
                LIMIT 200
            ";
            $pwaInstalledUsersStmt = $conn->query($pwaInstalledUsersSql);
            $pwaInstalledUsers = $pwaInstalledUsersStmt ? $pwaInstalledUsersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }

        $notificationEnabledUsersSql = "
            SELECT
                u.id,
                u.username,
                u.email,
                COUNT(*) AS device_count,
                MAX(t.last_used) AS last_used
            FROM user_fcm_tokens t
            INNER JOIN users u ON u.id = t.user_id
            WHERE u.account_active = 1
              AND {$activeWhereT}
            GROUP BY u.id, u.username, u.email
            ORDER BY last_used DESC
            LIMIT 200
        ";
        $notificationEnabledUsersStmt = $conn->query($notificationEnabledUsersSql);
        $notificationEnabledUsers = $notificationEnabledUsersStmt
            ? $notificationEnabledUsersStmt->fetchAll(PDO::FETCH_ASSOC)
            : [];

        $notificationDisabledUsersSql = "
            SELECT u.id, u.username, u.email
            FROM users u
            LEFT JOIN (
                SELECT DISTINCT user_id
                FROM user_fcm_tokens
                WHERE {$activeWhereBare}
            ) covered ON covered.user_id = u.id
            WHERE u.account_active = 1
              AND (u.fcm_token IS NULL OR TRIM(u.fcm_token) = '')
              AND covered.user_id IS NULL
            ORDER BY u.username
            LIMIT 200
        ";
        $notificationDisabledUsersStmt = $conn->query($notificationDisabledUsersSql);
        $notificationDisabledUsers = $notificationDisabledUsersStmt
            ? $notificationDisabledUsersStmt->fetchAll(PDO::FETCH_ASSOC)
            : [];
    } else {
        $legacySql = "
            SELECT u.id, u.username, u.email
            FROM users u
            WHERE u.account_active = 1
              AND (u.fcm_token IS NULL OR TRIM(u.fcm_token) = '')
            ORDER BY u.username
            LIMIT 200
        ";
        $legacyStmt = $conn->query($legacySql);
        $missingUsers = $legacyStmt ? $legacyStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $notificationDisabledUsers = $missingUsers;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'summary' => $summary,
            'missing_users' => $missingUsers,
            'devices' => $devices,
            'pwa_installed_users' => $pwaInstalledUsers,
            'notification_enabled_users' => $notificationEnabledUsers,
            'notification_disabled_users' => $notificationDisabledUsers,
            'fcm_token_epoch' => FcmConfig::getTokenEpoch(),
        ],
    ]);
} catch (Throwable $e) {
    error_log('push_coverage.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
    ]);
}
