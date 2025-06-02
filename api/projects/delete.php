<?php
error_log("DELETE.PHP FILE LOADED - IMMEDIATE TEST");

require_once __DIR__ . '/ProjectController.php';

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow DELETE
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get project ID
$projectId = $_GET['id'] ?? null;
if (!$projectId) {
    echo json_encode(['success' => false, 'message' => 'No ID provided']);
    exit;
}

// SIMPLE FORCE DELETE DETECTION
$forceDelete = false;

// Check if force_delete=true exists in the URL
if (strpos($_SERVER['QUERY_STRING'] ?? '', 'force_delete=true') !== false) {
    $forceDelete = true;
}

// Also check $_GET array
if (isset($_GET['force_delete']) && $_GET['force_delete'] === 'true') {
    $forceDelete = true;
}

// Log for debugging
error_log("DELETE REQUEST - Project: $projectId, Query: " . ($_SERVER['QUERY_STRING'] ?? ''));
error_log("FORCE DELETE VALUE: " . ($forceDelete ? 'TRUE' : 'FALSE'));

// Create controller and call delete
$controller = new ProjectController();
$controller->delete($projectId, $forceDelete); 