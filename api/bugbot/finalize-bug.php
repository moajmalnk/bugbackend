<?php
require_once __DIR__ . '/BugBotController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$controller = new BugBotController();
$controller->handleFinalizeBug();
