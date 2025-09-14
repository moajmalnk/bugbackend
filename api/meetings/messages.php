<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/MeetingController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

$controller = new MeetingController();
try {
    $decoded = $controller->validateToken();
    if (!$decoded || !isset($decoded->user_id)) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
    $response = $controller->listMeetings((int)$decoded->user_id);
    echo json_encode($response);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
exit;
