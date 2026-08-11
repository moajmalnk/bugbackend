<?php
/**
 * Send a birthday wish to a teammate celebrating today (IST).
 * One wish per sender → celebrant per calendar day.
 */
require_once __DIR__ . '/../../config/cors.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../NotificationManager.php';
require_once __DIR__ . '/../../config/utils.php';
require_once __DIR__ . '/../../utils/todays_birthdays.php';

/**
 * Why: PDO MySQL rowCount() is unreliable for SHOW TABLES — use fetch().
 * Auto-create matches migration 069 so Send wish works before manual migrate.
 */
function br_ensure_birthday_wishes_table(PDO $conn): bool
{
    try {
        $t = $conn->query("SHOW TABLES LIKE 'birthday_wishes'");
        if ($t && $t->fetch(PDO::FETCH_NUM)) {
            return true;
        }
    } catch (Throwable $e) {
        // fall through to create
    }

    try {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS `birthday_wishes` (
              `id` CHAR(36) NOT NULL,
              `from_user_id` VARCHAR(64) NOT NULL,
              `to_user_id` VARCHAR(64) NOT NULL,
              `wish_date` DATE NOT NULL,
              `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_birthday_wish_day` (`from_user_id`, `to_user_id`, `wish_date`),
              KEY `idx_birthday_wishes_to_date` (`to_user_id`, `wish_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        return true;
    } catch (Throwable $e) {
        error_log('br_ensure_birthday_wishes_table: ' . $e->getMessage());
        return false;
    }
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    $fromUserId = (string) ($decoded->user_id ?? '');
    $fromUsername = trim((string) ($decoded->username ?? 'A teammate'));

    if ($fromUserId === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $data = $api->getRequestData();
    $toUserId = trim((string) ($data['user_id'] ?? $data['to_user_id'] ?? ''));

    if ($toUserId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id is required.']);
        exit;
    }

    if ($toUserId === $fromUserId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'You cannot send a wish to yourself.']);
        exit;
    }

    $conn = $api->getConnection();
    $today = br_ist_today_ymd();

    if (!br_user_is_birthday_today($conn, $toUserId, $today)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'That teammate is not celebrating a birthday today.',
        ]);
        exit;
    }

    if (!br_ensure_birthday_wishes_table($conn)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Birthday wishes unavailable — run migration 069_birthday_wishes.sql',
        ]);
        exit;
    }

    $existing = $conn->prepare(
        'SELECT id FROM birthday_wishes
         WHERE from_user_id = ? AND to_user_id = ? AND wish_date = ?
         LIMIT 1'
    );
    $existing->execute([$fromUserId, $toUserId, $today]);
    if ($existing->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Already wished.',
            'data' => ['already_wished' => true],
        ]);
        exit;
    }

    $wishId = Utils::generateUUID();
    $insert = $conn->prepare(
        'INSERT INTO birthday_wishes (id, from_user_id, to_user_id, wish_date)
         VALUES (?, ?, ?, ?)'
    );
    try {
        $ok = $insert->execute([$wishId, $fromUserId, $toUserId, $today]);
    } catch (PDOException $e) {
        // Race: unique key — treat as already wished
        if (stripos($e->getMessage(), 'Duplicate') !== false) {
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Already wished.',
                'data' => ['already_wished' => true],
            ]);
            exit;
        }
        throw $e;
    }

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save birthday wish.']);
        exit;
    }

    // Why: Wish is already persisted — never fail the HTTP response on notify/push.
    try {
        $nm = new NotificationManager();
        $nm->notifyBirthdayWish($toUserId, $fromUserId, $fromUsername);
    } catch (Throwable $notifyErr) {
        error_log('send_birthday_wish notify: ' . $notifyErr->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Birthday wish sent.',
        'data' => ['already_wished' => true],
    ]);
} catch (Throwable $e) {
    error_log('send_birthday_wish: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send birthday wish.',
        'error' => $e->getMessage(),
    ]);
}
