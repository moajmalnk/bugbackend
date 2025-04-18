<?php
require_once __DIR__ . '/ProjectController.php';

$controller = new ProjectController();

$projectId = isset($_GET['id']) ? $_GET['id'] : die(json_encode(['success' => false, 'message' => 'No ID provided']));

echo json_encode($controller->delete($projectId)); 