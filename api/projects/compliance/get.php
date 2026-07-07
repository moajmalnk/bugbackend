<?php
require_once __DIR__ . '/../../../config/cors.php';
require_once __DIR__ . '/../ProjectComplianceController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$controller = new ProjectComplianceController();
$controller->get();
