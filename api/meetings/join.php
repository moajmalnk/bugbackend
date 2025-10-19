<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/MeetingController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

$controller = new MeetingController();
$input = $controller->getRequestData();
$code = $input['code'] ?? ($_GET['code'] ?? '');
$displayName = $input['displayName'] ?? 'Guest';

// If authenticated, use their id and name
try {
    $decoded = $controller->validateToken();
    if ($decoded && isset($decoded->user_id)) {
        $userId = (int)$decoded->user_id;
        $displayName = $decoded->username ?? $displayName;
    } else {
        $userId = 0;
    }
} catch (Exception $e) {
    $userId = 0;
}

if ($code === '') { echo json_encode(['success'=>false,'message'=>'code required']); exit; }
$response = $controller->joinMeeting($code, $userId, $displayName);
echo json_encode($response);
exit;
