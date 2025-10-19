<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/updateController.php';

$controller = new UpdateController();
$id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Update ID is required']);
    exit();
}

$controller->update($id);
