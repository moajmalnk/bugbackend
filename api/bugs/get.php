<?php

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Bug ID is required'
    ]);
    exit;
}

$controller = new BugController();
$controller->getById($_GET['id']); 