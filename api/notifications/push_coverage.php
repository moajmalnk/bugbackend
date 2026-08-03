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

    // Ensure admin push preference column exists
    if (!tableHasColumn($conn, 'users', 'push_notifications_enabled')) {
        try {
            $after = tableHasColumn($conn, 'users', 'account_active') ? ' AFTER account_active' : '';
            $conn->exec(
                "ALTER TABLE users ADD COLUMN push_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1{$after}"
            );
        } catch (Throwable $e) {
            error_log('push_coverage ensure column: ' . $e->getMessage());
        }
    }
    $hasPushFlag = tableHasColumn($conn, 'users', 'push_notifications_enabled');
    $pushSelect = $hasPushFlag
        ? 'COALESCE(u.push_notifications_enabled, 1) AS push_notifications_enabled'
        : '1 AS push_notifications_enabled';

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

    // FCM rotates tokens often; old hashes stay as separate active rows.
    // Keep one active token per user + browser + OS before counting.
    if ($hasTokenTable && $hasIsActiveColumn) {
        try {
            $conn->exec(
                "UPDATE user_fcm_tokens t
                 INNER JOIN (
                     SELECT id
                     FROM (
                         SELECT id,
                                ROW_NUMBER() OVER (
                                    PARTITION BY user_id,
                                                 COALESCE(browser_name, ''),
                                                 COALESCE(os_name, '')
                                    ORDER BY last_used DESC, id DESC
                                ) AS rn
                         FROM user_fcm_tokens
                         WHERE is_active = 1
                     ) ranked
                     WHERE rn > 1
                 ) dup ON dup.id = t.id
                 SET t.is_active = 0"
            );
        } catch (Throwable $dedupeErr) {
            try {
                $conn->exec(
                    "UPDATE user_fcm_tokens t
                     INNER JOIN user_fcm_tokens newer
                       ON newer.user_id = t.user_id
                      AND COALESCE(newer.browser_name, '') = COALESCE(t.browser_name, '')
                      AND COALESCE(newer.os_name, '') = COALESCE(t.os_name, '')
                      AND newer.is_active = 1
                      AND t.is_active = 1
                      AND (
                        newer.last_used > t.last_used
                        OR (newer.last_used = t.last_used AND newer.id > t.id)
                      )
                     SET t.is_active = 0"
                );
            } catch (Throwable $fallbackErr) {
                error_log('push_coverage token dedupe: ' . $fallbackErr->getMessage());
            }
        }
    }

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
            if ($hasPushFlag) {
                try {
                    $en = $conn->query(
                        "SELECT COUNT(*) FROM users WHERE account_active = 1 AND COALESCE(push_notifications_enabled, 1) = 1"
                    );
                    $dis = $conn->query(
                        "SELECT COUNT(*) FROM users WHERE account_active = 1 AND COALESCE(push_notifications_enabled, 1) = 0"
                    );
                    $summary['notification_enabled_users'] = $en ? (int) $en->fetchColumn() : $summary['users_with_tokens'];
                    $summary['notification_disabled_users'] = $dis ? (int) $dis->fetchColumn() : 0;
                } catch (Throwable $e) {
                    $summary['notification_enabled_users'] = $summary['users_with_tokens'];
                    $summary['notification_disabled_users'] = $summary['users_without_tokens'];
                }
            } else {
                $summary['notification_enabled_users'] = $summary['users_with_tokens'];
                $summary['notification_disabled_users'] = $summary['users_without_tokens'];
            }
        }
    }

    $missingUsers = [];
    $devices = [];
    $pwaInstalledUsers = [];
    $notificationEnabledUsers = [];
    $notificationDisabledUsers = [];

    if ($hasTokenTable) {
        $missingUsersSql = "
            SELECT u.id, u.username, u.email, {$pushSelect}
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
        foreach ($missingUsers as &$mu) {
            $mu['push_notifications_enabled'] = (int) ($mu['push_notifications_enabled'] ?? 1) === 1;
        }
        unset($mu);

        $deviceBreakdownSql = "
            SELECT
                t.user_id,
                t.username,
                t.browser_name,
                t.os_name,
                t.device_label,
                t.platform,
                t.last_used,
                {$pushSelect},
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
            INNER JOIN users u ON u.id = t.user_id
            WHERE {$activeWhereT}
            ORDER BY t.last_used DESC
            LIMIT 200
        ";
        $deviceBreakdownStmt = $conn->query($deviceBreakdownSql);
        $devices = $deviceBreakdownStmt ? $deviceBreakdownStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($devices as &$deviceRow) {
            $deviceRow['is_legacy'] = (int) ($deviceRow['is_legacy'] ?? 0);
            $deviceRow['is_stale'] = (int) ($deviceRow['is_stale'] ?? 0);
            $deviceRow['push_notifications_enabled'] = (int) ($deviceRow['push_notifications_enabled'] ?? 1) === 1;
        }
        unset($deviceRow);

        // Count logical devices (browser + OS), not every rotated FCM token row.
        $deviceCountExpr = "COUNT(DISTINCT CONCAT(COALESCE(t.browser_name, ''), '|', COALESCE(t.os_name, ''), '|', COALESCE(t.device_type, '')))";

        if ($hasPwaInstalledColumn) {
            $pwaInstalledUsersSql = "
                SELECT
                    u.id,
                    u.username,
                    u.email,
                    {$pushSelect},
                    {$deviceCountExpr} AS device_count,
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
            foreach ($pwaInstalledUsers as &$pu) {
                $pu['push_notifications_enabled'] = (int) ($pu['push_notifications_enabled'] ?? 1) === 1;
                $pu['device_count'] = (int) ($pu['device_count'] ?? 0);
            }
            unset($pu);
        }

        $notificationEnabledUsersSql = "
            SELECT
                u.id,
                u.username,
                u.email,
                {$pushSelect},
                (
                    SELECT {$deviceCountExpr}
                    FROM user_fcm_tokens t
                    WHERE t.user_id = u.id AND {$activeWhereBare}
                ) AS device_count,
                (
                    SELECT MAX(t.last_used) FROM user_fcm_tokens t
                    WHERE t.user_id = u.id AND {$activeWhereBare}
                ) AS last_used
            FROM users u
            WHERE u.account_active = 1
              AND COALESCE(u.push_notifications_enabled, 1) = 1
            ORDER BY u.username ASC
            LIMIT 200
        ";
        if (!$hasPushFlag) {
            $notificationEnabledUsersSql = "
                SELECT
                    u.id,
                    u.username,
                    u.email,
                    1 AS push_notifications_enabled,
                    {$deviceCountExpr} AS device_count,
                    MAX(t.last_used) AS last_used
                FROM user_fcm_tokens t
                INNER JOIN users u ON u.id = t.user_id
                WHERE u.account_active = 1
                  AND {$activeWhereT}
                GROUP BY u.id, u.username, u.email
                ORDER BY last_used DESC
                LIMIT 200
            ";
        }
        $notificationEnabledUsersStmt = $conn->query($notificationEnabledUsersSql);
        $notificationEnabledUsers = $notificationEnabledUsersStmt
            ? $notificationEnabledUsersStmt->fetchAll(PDO::FETCH_ASSOC)
            : [];
        foreach ($notificationEnabledUsers as &$eu) {
            $eu['push_notifications_enabled'] = (int) ($eu['push_notifications_enabled'] ?? 1) === 1;
        }
        unset($eu);

        $notificationDisabledUsersSql = $hasPushFlag
            ? "
            SELECT u.id, u.username, u.email, {$pushSelect}
            FROM users u
            WHERE u.account_active = 1
              AND COALESCE(u.push_notifications_enabled, 1) = 0
            ORDER BY u.username
            LIMIT 200
            "
            : "
            SELECT u.id, u.username, u.email, 0 AS push_notifications_enabled
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
        foreach ($notificationDisabledUsers as &$du) {
            $du['push_notifications_enabled'] = (int) ($du['push_notifications_enabled'] ?? 0) === 1;
        }
        unset($du);
    } else {
        $legacySql = "
            SELECT u.id, u.username, u.email, {$pushSelect}
            FROM users u
            WHERE u.account_active = 1
              AND (u.fcm_token IS NULL OR TRIM(u.fcm_token) = '')
            ORDER BY u.username
            LIMIT 200
        ";
        $legacyStmt = $conn->query($legacySql);
        $missingUsers = $legacyStmt ? $legacyStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($missingUsers as &$mu) {
            $mu['push_notifications_enabled'] = (int) ($mu['push_notifications_enabled'] ?? 1) === 1;
        }
        unset($mu);
        $notificationDisabledUsers = $missingUsers;
    }

    // --- Email & WhatsApp coverage (recipients + delivery errors) ---
    require_once __DIR__ . '/../../utils/notification_delivery_log.php';
    br_ensure_notification_delivery_log($conn);

    $mailReadyUsers = [];
    $mailMissingUsers = [];
    $whatsappReadyUsers = [];
    $whatsappMissingUsers = [];
    $mailRecentSent = [];
    $mailRecentErrors = [];
    $whatsappRecentSent = [];
    $whatsappRecentErrors = [];
    $channelSummary = [
        'mail_ready' => 0,
        'mail_missing' => 0,
        'whatsapp_ready' => 0,
        'whatsapp_missing' => 0,
        'mail_sent_7d' => 0,
        'mail_failed_7d' => 0,
        'whatsapp_sent_7d' => 0,
        'whatsapp_failed_7d' => 0,
    ];

    try {
        $mailReadyStmt = $conn->query(
            "SELECT u.id, u.username, u.email, u.phone
             FROM users u
             WHERE u.account_active = 1
               AND u.email IS NOT NULL
               AND TRIM(u.email) <> ''
               AND u.email LIKE '%@%.%'
             ORDER BY u.username
             LIMIT 200"
        );
        $mailReadyUsers = $mailReadyStmt ? $mailReadyStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $mailMissingStmt = $conn->query(
            "SELECT u.id, u.username, u.email, u.phone
             FROM users u
             WHERE u.account_active = 1
               AND (
                 u.email IS NULL
                 OR TRIM(u.email) = ''
                 OR u.email NOT LIKE '%@%.%'
               )
             ORDER BY u.username
             LIMIT 200"
        );
        $mailMissingUsers = $mailMissingStmt ? $mailMissingStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $waReadyStmt = $conn->query(
            "SELECT u.id, u.username, u.email, u.phone
             FROM users u
             WHERE u.account_active = 1
               AND u.phone IS NOT NULL
               AND TRIM(u.phone) <> ''
               AND LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(u.phone,''), '+', ''), ' ', ''), '-', ''), '(', '')) >= 8
             ORDER BY u.username
             LIMIT 200"
        );
        $whatsappReadyUsers = $waReadyStmt ? $waReadyStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $waMissingStmt = $conn->query(
            "SELECT u.id, u.username, u.email, u.phone
             FROM users u
             WHERE u.account_active = 1
               AND (
                 u.phone IS NULL
                 OR TRIM(u.phone) = ''
                 OR LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(u.phone,''), '+', ''), ' ', ''), '-', ''), '(', '')) < 8
               )
             ORDER BY u.username
             LIMIT 200"
        );
        $whatsappMissingUsers = $waMissingStmt ? $waMissingStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $channelSummary['mail_ready'] = count($mailReadyUsers);
        $channelSummary['mail_missing'] = count($mailMissingUsers);
        $channelSummary['whatsapp_ready'] = count($whatsappReadyUsers);
        $channelSummary['whatsapp_missing'] = count($whatsappMissingUsers);

        // Prefer exact counts (not limited lists)
        $cMailReady = $conn->query(
            "SELECT COUNT(*) FROM users
             WHERE account_active = 1
               AND email IS NOT NULL AND TRIM(email) <> '' AND email LIKE '%@%.%'"
        );
        $cMailMissing = $conn->query(
            "SELECT COUNT(*) FROM users
             WHERE account_active = 1
               AND (email IS NULL OR TRIM(email) = '' OR email NOT LIKE '%@%.%')"
        );
        $cWaReady = $conn->query(
            "SELECT COUNT(*) FROM users
             WHERE account_active = 1
               AND phone IS NOT NULL AND TRIM(phone) <> ''
               AND LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '+', ''), ' ', ''), '-', ''), '(', '')) >= 8"
        );
        $cWaMissing = $conn->query(
            "SELECT COUNT(*) FROM users
             WHERE account_active = 1
               AND (
                 phone IS NULL OR TRIM(phone) = ''
                 OR LENGTH(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '+', ''), ' ', ''), '-', ''), '(', '')) < 8
               )"
        );
        if ($cMailReady) $channelSummary['mail_ready'] = (int) $cMailReady->fetchColumn();
        if ($cMailMissing) $channelSummary['mail_missing'] = (int) $cMailMissing->fetchColumn();
        if ($cWaReady) $channelSummary['whatsapp_ready'] = (int) $cWaReady->fetchColumn();
        if ($cWaMissing) $channelSummary['whatsapp_missing'] = (int) $cWaMissing->fetchColumn();

        $stats7d = $conn->query(
            "SELECT channel, status, COUNT(*) AS cnt
             FROM notification_delivery_log
             WHERE created_at >= NOW() - INTERVAL 7 DAY
             GROUP BY channel, status"
        );
        if ($stats7d) {
            while ($row = $stats7d->fetch(PDO::FETCH_ASSOC)) {
                $ch = $row['channel'] ?? '';
                $st = $row['status'] ?? '';
                $cnt = (int) ($row['cnt'] ?? 0);
                if ($ch === 'email' && $st === 'sent') $channelSummary['mail_sent_7d'] = $cnt;
                if ($ch === 'email' && $st === 'failed') $channelSummary['mail_failed_7d'] = $cnt;
                if ($ch === 'whatsapp' && $st === 'sent') $channelSummary['whatsapp_sent_7d'] = $cnt;
                if ($ch === 'whatsapp' && $st === 'failed') $channelSummary['whatsapp_failed_7d'] = $cnt;
            }
        }

        $fetchLog = static function (PDO $conn, string $channel, string $status) {
            $stmt = $conn->prepare(
                "SELECT l.id, l.channel, l.status, l.user_id, l.recipient, l.subject,
                        l.error_message, l.created_at, u.username
                 FROM notification_delivery_log l
                 LEFT JOIN users u ON u.id COLLATE utf8mb4_unicode_ci = l.user_id COLLATE utf8mb4_unicode_ci
                 WHERE l.channel = ? AND l.status = ?
                 ORDER BY l.created_at DESC
                 LIMIT 80"
            );
            $stmt->execute([$channel, $status]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        };

        // Collation-safe join fallback without COLLATE if it fails
        try {
            $mailRecentSent = $fetchLog($conn, 'email', 'sent');
            $mailRecentErrors = $fetchLog($conn, 'email', 'failed');
            $whatsappRecentSent = $fetchLog($conn, 'whatsapp', 'sent');
            $whatsappRecentErrors = $fetchLog($conn, 'whatsapp', 'failed');
        } catch (Throwable $joinErr) {
            $fetchLogSimple = static function (PDO $conn, string $channel, string $status) {
                $stmt = $conn->prepare(
                    "SELECT id, channel, status, user_id, recipient, subject, error_message, created_at
                     FROM notification_delivery_log
                     WHERE channel = ? AND status = ?
                     ORDER BY created_at DESC
                     LIMIT 80"
                );
                $stmt->execute([$channel, $status]);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            };
            $mailRecentSent = $fetchLogSimple($conn, 'email', 'sent');
            $mailRecentErrors = $fetchLogSimple($conn, 'email', 'failed');
            $whatsappRecentSent = $fetchLogSimple($conn, 'whatsapp', 'sent');
            $whatsappRecentErrors = $fetchLogSimple($conn, 'whatsapp', 'failed');
        }
    } catch (Throwable $e) {
        error_log('push_coverage channel coverage: ' . $e->getMessage());
    }

    $summary = array_merge($summary, $channelSummary);

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
            'mail_ready_users' => $mailReadyUsers,
            'mail_missing_users' => $mailMissingUsers,
            'whatsapp_ready_users' => $whatsappReadyUsers,
            'whatsapp_missing_users' => $whatsappMissingUsers,
            'mail_recent_sent' => $mailRecentSent,
            'mail_recent_errors' => $mailRecentErrors,
            'whatsapp_recent_sent' => $whatsappRecentSent,
            'whatsapp_recent_errors' => $whatsappRecentErrors,
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
