<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/AnnouncementController.php';

$controller = new AnnouncementController();
$id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$id) {
    $controller->sendJsonResponse(400, "Announcement ID is required.");
    exit;
}

$controller->broadcast($id); 