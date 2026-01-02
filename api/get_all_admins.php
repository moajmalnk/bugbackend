<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/BaseAPI.php';

// Disable HTML error output to prevent JSON corruption
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();
    
    // Use cached query for better performance - include phone number
    $users = $api->fetchCached(
        "SELECT email, phone FROM users WHERE role = 'admin'",
        [],
        'admins_data',
        600 // Cache for 10 minutes
    );
    
    // Extract just the email values from the result for backward compatibility
    $emailList = array_column($users, 'email');
    
    // Return both emails and full user data with phone numbers
    echo json_encode([
        'success' => true, 
        'emails' => $emailList,
        'data' => $users
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}