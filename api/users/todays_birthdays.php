<?php
/**
 * Today's team birthdays (IST) — public display fields only (no birth year).
 */
require_once __DIR__ . '/../../config/cors.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/todays_birthdays.php';

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();
    $viewerId = (string) ($decoded->user_id ?? '');

    if ($viewerId === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    $conn = $api->getConnection();
    $today = br_ist_today_ymd();
    $birthdays = br_fetch_todays_birthdays($conn, $today);

    // Mark which entries the viewer already wished today (when table exists).
    $wishedIds = [];
    try {
        $t = $conn->query("SHOW TABLES LIKE 'birthday_wishes'");
        $hasWishTable = $t && $t->fetch(PDO::FETCH_NUM);
        if ($hasWishTable && count($birthdays) > 0) {
            $stmt = $conn->prepare(
                'SELECT to_user_id FROM birthday_wishes
                 WHERE from_user_id = ? AND wish_date = ?'
            );
            $stmt->execute([$viewerId, $today]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $wishedIds[(string) $row['to_user_id']] = true;
            }
        }
    } catch (Throwable $e) {
        // Table may not exist until migration 069 runs / auto-create on send.
    }

    $payload = array_map(static function (array $person) use ($viewerId, $wishedIds) {
        $id = (string) $person['id'];
        return [
            'id' => $id,
            'username' => $person['username'],
            'role' => $person['role'],
            'job_title' => $person['job_title'],
            'department' => $person['department'],
            'avatar' => $person['avatar'],
            'is_self' => $id === $viewerId,
            'already_wished' => isset($wishedIds[$id]),
        ];
    }, $birthdays);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Today\'s birthdays retrieved.',
        'data' => [
            'date' => $today,
            'birthdays' => $payload,
        ],
    ]);
} catch (Exception $e) {
    error_log('todays_birthdays: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load birthdays.']);
}
