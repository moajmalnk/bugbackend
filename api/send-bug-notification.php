<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');

// Disable HTML error output to prevent JSON corruption
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Handle preflight request immediately after CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include necessary files
require_once __DIR__ . '/../utils/send_email.php';
require_once __DIR__ . '/../api/BaseAPI.php';

try {
    $api = new BaseAPI();
    
    try {
        $decoded = $api->validateToken();
    } catch (Exception $e) {
        $api->sendJsonResponse(401, $e->getMessage());
        exit;
    }

    $conn = $api->getConnection();

    $stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
    $stmt->execute();
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($setting && $setting['value'] === '0') {
        echo json_encode(['success' => true, 'message' => 'Email notifications are disabled globally.']);
        exit;
    }

    // Get request body
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    
    // Validate input
    if (!$data || !isset($data['to']) || !isset($data['subject']) || !isset($data['body'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
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
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to send email']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
