<?php
require_once __DIR__ . '/../BaseAPI.php';
$api = new BaseAPI();
$conn = $api->getConnection();

$decoded = $api->validateToken();
if (!$decoded || $decoded->role !== 'admin') {
    $api->sendJsonResponse(403, "Only admins can update settings");
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['email_notifications_enabled'])) {
    $api->sendJsonResponse(400, "Missing parameter");
    exit;
}

$value = $data['email_notifications_enabled'] ? '1' : '0';

$stmt = $conn->prepare("
    INSERT INTO settings (key_name, value) 
    VALUES ('email_notifications_enabled', ?)
    ON DUPLICATE KEY UPDATE value = VALUES(value)
");
$stmt->execute([$value]);

$api->sendJsonResponse(200, "Email notification setting updated", [
    'email_notifications_enabled' => $value === '1'
]);
