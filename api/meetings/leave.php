<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/MeetingController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit; }

$controller = new MeetingController();
$input = $controller->getRequestData();
$code = $input['code'] ?? ($_GET['code'] ?? '');

$userId = 0;
try { $decoded = $controller->validateToken(); if ($decoded && isset($decoded->user_id)) { $userId = (int)$decoded->user_id; } } catch (Exception $e) { $userId = 0; }

if ($code === '') { echo json_encode(['success'=>false,'message'=>'code required']); exit; }
$response = $controller->leaveMeeting($code, $userId);
echo json_encode($response);
exit;
