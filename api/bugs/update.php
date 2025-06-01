<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

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
    
    // Use $_POST and $_FILES for multipart/form-data
    $data = $_POST;
    $files = $_FILES;

    // If $_POST is empty, try to get JSON input
    if (empty($data)) {
        $rawInput = file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true);
        if ($jsonData) {
            $data = $jsonData;
        }
    }

    error_log("Received update data (POST): " . print_r($data, true));
    error_log("Received update files (FILES): " . print_r($files, true));

    if (!isset($data['id'])) {
        throw new Exception('Bug ID is required');
    }

    // Add user ID from token if fixed_by is not set in form data
    // The frontend is already sending fixed_by, but this is a safeguard
    if (!isset($data['fixed_by'])) {
         $data['fixed_by'] = $decoded->user_id;
    }

    // Update the bug
    $result = $controller->updateBug($data, $files);

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

file_put_contents('D:/xampp/htdocs/debug.log', date('Y-m-d H:i:s') . " - Accessed update.php\n", FILE_APPEND); 