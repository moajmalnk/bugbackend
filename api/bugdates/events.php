<?php
require_once __DIR__ . '/BugDatesController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$c = new BugDatesController();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (isset($_GET['id']) && (int)$_GET['id'] > 0) {
        $c->getEvent();
    } else {
        $c->listEvents();
    }
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}

if ($method === 'POST') {
    $action = strtolower(trim((string)($payload['action'] ?? 'create')));
    if ($action === 'update') {
        $c->updateEvent($payload);
    } elseif ($action === 'delete') {
        $c->deleteEvent($payload);
    } else {
        $c->createEvent($payload);
    }
    exit();
}

if ($method === 'PUT' || $method === 'PATCH') {
    $c->updateEvent($payload);
    exit();
}

if ($method === 'DELETE') {
    $c->deleteEvent($payload);
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
