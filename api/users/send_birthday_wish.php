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
    $rawMessage = (string) ($data['message'] ?? '');
    if (mb_strlen(trim($rawMessage)) > BR_BIRTHDAY_WISH_MESSAGE_MAX) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Keep your wish under ' . BR_BIRTHDAY_WISH_MESSAGE_MAX . ' characters.',
        ]);
        exit;
    }
    $message = br_sanitize_birthday_wish_message($rawMessage);

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
    $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
    if ($existingRow) {
        // Why: one wish per day, but the sender may add or edit the note afterwards.
        if ($message !== null) {
            $upd = $conn->prepare('UPDATE birthday_wishes SET message = ? WHERE id = ?');
            $upd->execute([$message, $existingRow['id']]);
        }
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message !== null ? 'Wish message updated.' : 'Already wished.',
            'data' => ['already_wished' => true, 'id' => (string) $existingRow['id'], 'message' => $message],
        ]);
        exit;
    }

    $wishId = Utils::generateUUID();
    $insert = $conn->prepare(
        'INSERT INTO birthday_wishes (id, from_user_id, to_user_id, wish_date, message)
         VALUES (?, ?, ?, ?, ?)'
    );
    try {
        $ok = $insert->execute([$wishId, $fromUserId, $toUserId, $today, $message]);
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
        $nm->notifyBirthdayWish($toUserId, $fromUserId, $fromUsername, $message);
    } catch (Throwable $notifyErr) {
        error_log('send_birthday_wish notify: ' . $notifyErr->getMessage());
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Birthday wish sent.',
        'data' => ['already_wished' => true, 'id' => $wishId, 'message' => $message],
    ]);
} catch (Throwable $e) {
    error_log('send_birthday_wish: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to send birthday wish.',
    ]);
}
