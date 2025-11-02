<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/ProjectController.php';

$controller = new ProjectController();
$id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$id) {
    $controller->sendJsonResponse(400, "Project ID is required");
    exit();
}

$controller->getById($id); 