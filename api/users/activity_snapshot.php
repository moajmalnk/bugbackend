<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/UserController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$userId = isset($_GET['id']) ? $_GET['id'] : null;
$controller = new UserController();
$controller->getActivitySnapshot($userId);
?>
