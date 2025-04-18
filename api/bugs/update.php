<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$controller = new BugController();

try {
    // Validate token
    $decoded = $controller->validateToken();
    
    // Get request body
    $input = file_get_contents('php://input');
    error_log("Received update data: " . $input);
    
    $data = json_decode($input, true);
    if (!$data) {
        throw new Exception('Invalid JSON data received');
    }

    if (!isset($data['id'])) {
        throw new Exception('Bug ID is required');
    }

    // Add user ID from token
    $data['updated_by'] = $decoded->user_id;

    // Update the bug
    $result = $controller->updateBug($data);

    // Send success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Bug updated successfully',
        'data' => $result
    ]);

} catch (Exception $e) {
    error_log("Error in update.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} 