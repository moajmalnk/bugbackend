<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/ProjectController.php';

$controller = new ProjectController();
$id = isset($_GET['id']) ? $_GET['id'] : null;

if (isset($_GET['work_activity']) && ($_GET['work_activity'] === '1' || $_GET['work_activity'] === 'true')) {
    if (!$id) {
        $controller->sendJsonResponse(400, 'Project ID is required');
        exit();
    }
    $controller->getWorkActivity($id, $_GET);
    exit();
}

if (!$id) {
    $controller->sendJsonResponse(400, "Project ID is required");
    exit();
}

$controller->getById($id); 