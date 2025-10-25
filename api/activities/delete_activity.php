<?php
require_once __DIR__ . '/ProjectActivityController.php';

$controller = new ProjectActivityController();

// Only handle DELETE and POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get activity ID from URL parameter or request body
$activityId = null;

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    // For DELETE requests, get ID from URL parameter
    $activityId = $_GET['id'] ?? null;
} else {
    // For POST requests, get ID from request body
    $input = json_decode(file_get_contents('php://input'), true);
    $activityId = $input['id'] ?? null;
}

// Validate activity ID
if (!$activityId || !is_numeric($activityId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid activity ID is required']);
    exit;
}

// Delete the activity
$controller->deleteActivity((int)$activityId);
?>
