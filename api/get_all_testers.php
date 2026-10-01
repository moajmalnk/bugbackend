<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/BaseAPI.php';
require_once __DIR__ . '/../utils/role_directory.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');
header('Cache-Control: private, no-cache');

try {
    $api = new BaseAPI();

    // Why: Staff emails and phone numbers must not be listed to anonymous callers.
    try {
        $decoded = $api->validateToken();
    } catch (Throwable $e) {
        $decoded = null;
    }
    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $users = br_role_directory($api, 'tester', 'testers_directory');

    echo json_encode([
        'success' => true,
        'emails' => array_column($users, 'email'),
        'data' => $users,
    ]);
} catch (Throwable $e) {
    error_log('get_all_testers.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load users']);
}
