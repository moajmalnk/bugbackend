<?php
require_once __DIR__ . '/BugController.php';

$controller = new BugController();

$bugId = isset($_GET['id']) ? $_GET['id'] : die(json_encode(['success' => false, 'message' => 'No ID provided']));

echo json_encode($controller->delete($bugId)); 