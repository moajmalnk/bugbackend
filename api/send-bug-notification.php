<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle preflight request immediately after CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/users/UserController.php';

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}

// Log request for debugging
$logFile = $logDir . '/email_api.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - API called\n", FILE_APPEND);
file_put_contents($logFile, "Request: " . file_get_contents('php://input') . "\n", FILE_APPEND);

// Include necessary files
require_once __DIR__ . '/../utils/send_email.php';

try {
    // Get request body
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Debug received data
    file_put_contents($logFile, "Decoded data: " . print_r($data, true) . "\n", FILE_APPEND);
    
    // Validate input
    if (!$data || !isset($data['to']) || !isset($data['subject']) || !isset($data['body'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        file_put_contents($logFile, "Error: Missing required fields\n", FILE_APPEND);
        exit;
    }
    
    $to = $data['to'];
    $subject = $data['subject'];
    $body = $data['body'];
    $attachments = isset($data['attachments']) ? $data['attachments'] : [];
    
    // Send the email notification
    $result = sendBugNotification($to, $subject, $body, $attachments);
    
    // Return response
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
        file_put_contents($logFile, "Success: Email sent\n", FILE_APPEND);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send email']);
        file_put_contents($logFile, "Error: Failed to send email\n", FILE_APPEND);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    file_put_contents($logFile, "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
}