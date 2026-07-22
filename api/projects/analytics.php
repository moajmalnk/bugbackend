<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ProjectAnalyticsController.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$projectId = $_GET['project_id'] ?? $_GET['id'] ?? null;
$controller = new ProjectAnalyticsController();
$controller->getAnalytics($projectId);
