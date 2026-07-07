<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/ProjectController.php';

$controller = new ProjectController();
$projectId = trim((string) ($_GET['project_id'] ?? ''));
$controller->getWorkActivity($projectId, $_GET);
