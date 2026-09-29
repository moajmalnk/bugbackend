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

    $wishesByCelebrant = [];
    if (count($birthdays) > 0) {
        try {
            $wishesByCelebrant = br_fetch_birthday_wishes(
                $conn,
                array_column($birthdays, 'id'),
                $today,
                $viewerId
            );
        } catch (Throwable $e) {
            error_log('todays_birthdays wishes: ' . $e->getMessage());
        }
    }

    $payload = array_map(static function (array $person) use ($viewerId, $wishesByCelebrant) {
        $id = (string) $person['id'];
        $wishes = $wishesByCelebrant[$id] ?? [];
        $alreadyWished = false;
        foreach ($wishes as $wish) {
            if ($wish['is_mine']) {
                $alreadyWished = true;
                break;
            }
        }
        return [
            'id' => $id,
            'username' => $person['username'],
            'role' => $person['role'],
            'job_title' => $person['job_title'],
            'department' => $person['department'],
            'avatar' => $person['avatar'],
            'is_self' => $id === $viewerId,
            'already_wished' => $alreadyWished,
            'wish_count' => count($wishes),
            'wishes' => array_slice($wishes, 0, 100),
        ];
    }, $birthdays);

    header('Cache-Control: private, no-cache');
    header('Vary: Authorization');
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Today\'s birthdays retrieved.',
        'data' => [
            'date' => $today,
            'birthdays' => $payload,
        ],
    ]);
} catch (Throwable $e) {
    error_log('todays_birthdays: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load birthdays.']);
}
