<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/RecycleBinController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$controller = new RecycleBinController();
$controller->handleStats();
