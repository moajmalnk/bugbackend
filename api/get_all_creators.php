<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/BaseAPI.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();

    $users = $api->fetchCached(
        "SELECT email, phone FROM users WHERE role = 'creator' AND account_active = 1",
        [],
        'creators_data',
        600
    );

    $emailList = array_column($users, 'email');

    echo json_encode([
        'success' => true,
        'emails' => $emailList,
        'data' => $users
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
