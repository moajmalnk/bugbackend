<?php
header('Content-Type: application/json');
require_once __DIR__ . '/MeetingController.php';

$code = $_GET['code'] ?? '';
if ($code === '') {
    echo json_encode(['success' => false, 'error' => 'code required']);
    exit;
}

$controller = new MeetingController();
$response = $controller->getMeetingByCode($code);
echo json_encode($response);
exit;
