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

function ensureUserFcmTokensSchema(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS user_fcm_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
            token TEXT NOT NULL,
            token_hash CHAR(64) NOT NULL,
            device_type ENUM('android', 'ios', 'desktop') NOT NULL DEFAULT 'desktop',
            platform VARCHAR(120) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_fcm_tokens_hash (token_hash),
            KEY idx_user_fcm_tokens_user_id (user_id),
            CONSTRAINT fk_user_fcm_tokens_user_id
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->exec("
        INSERT INTO user_fcm_tokens (user_id, token, token_hash, device_type, platform, user_agent, created_at, last_used)
        SELECT
            u.id,
            u.fcm_token,
            SHA2(u.fcm_token, 256),
            'desktop',
            'legacy-migration',
            'legacy-users-table',
            NOW(),
            NOW()
        FROM users u
        WHERE u.fcm_token IS NOT NULL
          AND TRIM(u.fcm_token) <> ''
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            token = VALUES(token),
            device_type = VALUES(device_type),
            platform = VALUES(platform),
            user_agent = VALUES(user_agent),
            last_used = NOW()
    ");
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

    // Check if fcm_token column exists, add if missing
    try {
        $check = $conn->query("SHOW COLUMNS FROM users LIKE 'fcm_token'");
        if ($check && $check->rowCount() === 0) {
            $conn->exec("ALTER TABLE users ADD COLUMN fcm_token VARCHAR(255) DEFAULT NULL");
        }
    } catch (PDOException $e) {
        error_log("save-fcm-token: Could not add fcm_token column: " . $e->getMessage());
    }

    $hasTokenTable = false;
    try {
        ensureUserFcmTokensSchema($conn);
        $hasTokenTable = true;
    } catch (Throwable $schemaError) {
        // Do not hard-fail push setup if schema migration is blocked in production.
        error_log("save-fcm-token: user_fcm_tokens schema unavailable, falling back to users.fcm_token only: " . $schemaError->getMessage());
    }

    $decoded = $controller->validateToken();
    $userId = $decoded->user_id ?? null;

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = $data['token'] ?? null;
    $deviceType = normalizeDeviceType($data['device_type'] ?? 'desktop');
    $platform = isset($data['platform']) ? trim((string) $data['platform']) : null;
    $userAgent = isset($data['user_agent']) ? trim((string) $data['user_agent']) : ($_SERVER['HTTP_USER_AGENT'] ?? null);

    if (!$token || !$userId) {
        throw new Exception('Missing token or user', 400);
    }

    $tokenHash = hash('sha256', $token);

    if ($hasTokenTable) {
        try {
            $upsertStmt = $conn->prepare("
                INSERT INTO user_fcm_tokens (user_id, token, token_hash, device_type, platform, user_agent, last_used)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    token = VALUES(token),
                    device_type = VALUES(device_type),
                    platform = VALUES(platform),
                    user_agent = VALUES(user_agent),
                    last_used = NOW()
            ");
            $upsertStmt->execute([$userId, $token, $tokenHash, $deviceType, $platform, $userAgent]);
        } catch (Throwable $upsertError) {
            // Keep legacy path working even if side-table write fails.
            error_log("save-fcm-token: user_fcm_tokens upsert failed, continuing with users.fcm_token update: " . $upsertError->getMessage());
        }
    }

    $stmt = $conn->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
    $stmt->execute([$token, $userId]);

    echo json_encode([
        'success' => true,
        'device_type' => $deviceType,
        'message' => 'FCM token saved'
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