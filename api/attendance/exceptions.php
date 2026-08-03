<?php
require_once __DIR__ . '/AttendanceExceptionController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$c = new AttendanceExceptionController();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $c->listForUser();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $c->save();
    exit();
}

http_response_code(405);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
