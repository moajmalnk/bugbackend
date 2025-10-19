<?php
require_once __DIR__ . '/ProjectActivityController.php';

$controller = new ProjectActivityController();

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['type']) || !isset($input['description'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: type, description']);
    exit;
}

$type = $input['type'];
$description = $input['description'];
$projectId = $input['project_id'] ?? null;
$relatedId = $input['related_id'] ?? null;
$metadata = $input['metadata'] ?? null;

// Log the activity
$controller->logActivity($type, $description, $projectId, $relatedId, $metadata);
?> 