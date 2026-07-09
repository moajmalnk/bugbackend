<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');

function normalizeDeviceType($deviceType) {
    $normalized = strtolower(trim((string) $deviceType));
    if (in_array($normalized, ['android', 'ios', 'desktop'], true)) {
        return $normalized;
    }
    return 'desktop';
}

function tableHasColumn(PDO $conn, $table, $column) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function parseBrowserFromUserAgent($userAgent) {
    $ua = (string) $userAgent;
    $browser = 'Unknown';
    $os = 'Unknown';

    if (preg_match('/Edg\//i', $ua)) {
        $browser = 'Edge';
    } elseif (preg_match('/Chrome\//i', $ua) && !preg_match('/Edg\//i', $ua)) {
        $browser = 'Chrome';
    } elseif (preg_match('/Firefox\//i', $ua)) {
        $browser = 'Firefox';
    } elseif (preg_match('/Safari\//i', $ua) && !preg_match('/Chrome\//i', $ua)) {
        $browser = 'Safari';
    }

    if (preg_match('/Android/i', $ua)) {
        $os = 'Android';
    } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
        $os = 'iOS';
    } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
        $os = 'macOS';
    } elseif (preg_match('/Windows/i', $ua)) {
        $os = 'Windows';
    } elseif (preg_match('/Linux/i', $ua)) {
        $os = 'Linux';
    }

    return ['browser' => $browser, 'os' => $os];
}

function ensureUserFcmTokensTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_fcm_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            token TEXT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            device_type ENUM('android', 'ios', 'desktop') NOT NULL DEFAULT 'desktop',
            platform VARCHAR(120) DEFAULT NULL,
            user_agent VARCHAR(512) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_fcm_tokens_hash (token_hash),
            KEY idx_user_fcm_tokens_user_id (user_id),
            CONSTRAINT fk_user_fcm_tokens_user_id
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $optionalColumns = [
        'username' => "VARCHAR(100) DEFAULT NULL",
        'browser_name' => "VARCHAR(64) DEFAULT NULL",
        'os_name' => "VARCHAR(64) DEFAULT NULL",
        'device_label' => "VARCHAR(120) DEFAULT NULL",
        'pwa_installed' => "TINYINT(1) NOT NULL DEFAULT 0",
        'is_active' => "TINYINT(1) NOT NULL DEFAULT 1",
        'last_delivered_at' => "TIMESTAMP NULL DEFAULT NULL",
        'delivery_failures' => "INT UNSIGNED NOT NULL DEFAULT 0",
    ];

    foreach ($optionalColumns as $column => $definition) {
        if (!tableHasColumn($conn, 'user_fcm_tokens', $column)) {
            try {
                $conn->exec("ALTER TABLE user_fcm_tokens ADD COLUMN {$column} {$definition}");
            } catch (Throwable $e) {
                error_log("save-fcm-token: could not add column {$column}: " . $e->getMessage());
            }
        }
    }

    // Widen user_agent if an older schema used VARCHAR(255)
    try {
        $conn->exec("ALTER TABLE user_fcm_tokens MODIFY COLUMN user_agent VARCHAR(512) DEFAULT NULL");
    } catch (Throwable $e) {
        // ignore
    }
}

function ensureUsersFcmTokenColumn(PDO $conn) {
    try {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'fcm_token'");
        if ($check && $check->rowCount() === 0) {
            $conn->exec("ALTER TABLE users ADD COLUMN fcm_token TEXT DEFAULT NULL");
            return;
        }
        $conn->exec("ALTER TABLE users MODIFY COLUMN fcm_token TEXT DEFAULT NULL");
    } catch (PDOException $e) {
        error_log("save-fcm-token: Could not ensure users.fcm_token column: " . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

try {
    require_once __DIR__ . '/users/UserController.php';
    $controller = new UserController();
    $conn = $controller->getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed', 500);
    }

    ensureUsersFcmTokenColumn($conn);

    $hasTokenTable = false;
    try {
        ensureUserFcmTokensTable($conn);
        $hasTokenTable = true;
    } catch (Throwable $schemaError) {
        error_log("save-fcm-token: user_fcm_tokens schema unavailable, falling back to users.fcm_token only: " . $schemaError->getMessage());
    }

    $decoded = $controller->validateToken();
    $userId = $decoded->user_id ?? null;
    $username = $decoded->username ?? null;

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = $data['token'] ?? null;
    $deviceType = normalizeDeviceType($data['device_type'] ?? 'desktop');
    $platform = isset($data['platform']) ? trim((string) $data['platform']) : null;
    $userAgent = isset($data['user_agent']) ? trim((string) $data['user_agent']) : ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $browserName = isset($data['browser_name']) ? trim((string) $data['browser_name']) : null;
    $osName = isset($data['os_name']) ? trim((string) $data['os_name']) : null;
    $deviceLabel = isset($data['device_label']) ? trim((string) $data['device_label']) : null;
    $pwaInstalled = isset($data['pwa_installed']) ? (int) !!$data['pwa_installed'] : 0;

    if (!$token || !$userId) {
        throw new Exception('Missing token or user', 400);
    }

    if (!$username) {
        $userStmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
        $userStmt->execute([$userId]);
        $username = $userStmt->fetchColumn() ?: null;
    }

    $parsed = parseBrowserFromUserAgent($userAgent ?? '');
    if (!$browserName) {
        $browserName = $parsed['browser'];
    }
    if (!$osName) {
        $osName = $parsed['os'];
    }
    if (!$deviceLabel) {
        $deviceLabel = trim($browserName . ' on ' . $osName);
    }

    $tokenHash = hash('sha256', $token);
    $savedToTable = false;

    if ($hasTokenTable) {
        try {
            $columns = ['user_id', 'token', 'token_hash', 'device_type', 'platform', 'user_agent', 'last_used'];
            $values = [$userId, $token, $tokenHash, $deviceType, $platform, $userAgent, date('Y-m-d H:i:s')];
            $updates = [
                'user_id = VALUES(user_id)',
                'token = VALUES(token)',
                'device_type = VALUES(device_type)',
                'platform = VALUES(platform)',
                'user_agent = VALUES(user_agent)',
                'last_used = NOW()',
            ];

            if (tableHasColumn($conn, 'user_fcm_tokens', 'username')) {
                $columns[] = 'username';
                $values[] = $username;
                $updates[] = 'username = VALUES(username)';
            }
            if (tableHasColumn($conn, 'user_fcm_tokens', 'browser_name')) {
                $columns[] = 'browser_name';
                $values[] = $browserName;
                $updates[] = 'browser_name = VALUES(browser_name)';
            }
            if (tableHasColumn($conn, 'user_fcm_tokens', 'os_name')) {
                $columns[] = 'os_name';
                $values[] = $osName;
                $updates[] = 'os_name = VALUES(os_name)';
            }
            if (tableHasColumn($conn, 'user_fcm_tokens', 'device_label')) {
                $columns[] = 'device_label';
                $values[] = $deviceLabel;
                $updates[] = 'device_label = VALUES(device_label)';
            }
            if (tableHasColumn($conn, 'user_fcm_tokens', 'pwa_installed')) {
                $columns[] = 'pwa_installed';
                $values[] = $pwaInstalled;
                $updates[] = 'pwa_installed = VALUES(pwa_installed)';
            }
            if (tableHasColumn($conn, 'user_fcm_tokens', 'is_active')) {
                $columns[] = 'is_active';
                $values[] = 1;
                $updates[] = 'is_active = 1';
            }

            $placeholders = implode(',', array_fill(0, count($columns), '?'));
            $sql = "
                INSERT INTO user_fcm_tokens (" . implode(', ', $columns) . ")
                VALUES ({$placeholders})
                ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
            $upsertStmt = $conn->prepare($sql);
            $upsertStmt->execute($values);
            $savedToTable = true;
        } catch (Throwable $upsertError) {
            error_log("save-fcm-token: user_fcm_tokens upsert failed, continuing with users.fcm_token update: " . $upsertError->getMessage());
        }
    }

    $stmt = $conn->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
    $stmt->execute([$token, $userId]);

    echo json_encode([
        'success' => true,
        'device_type' => $deviceType,
        'browser_name' => $browserName,
        'os_name' => $osName,
        'device_label' => $deviceLabel,
        'saved_to_table' => $savedToTable,
        'message' => 'FCM token saved',
    ]);
} catch (PDOException $e) {
    error_log("save-fcm-token PDO error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
} catch (Exception $e) {
    $code = $e->getCode();
    if (!is_int($code) || $code < 400) {
        $code = (strpos($e->getMessage(), 'token') !== false || strpos($e->getMessage(), 'auth') !== false) ? 401 : 500;
    }
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
