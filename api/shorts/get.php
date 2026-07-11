<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ShortsController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$id = isset($_GET['id']) ? $_GET['id'] : null;
$c = new ShortsController();
$c->get($id);
