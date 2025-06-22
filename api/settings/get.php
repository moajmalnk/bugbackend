<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
$api = new BaseAPI();
$conn = $api->getConnection();

$stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$value = $row ? $row['value'] : '1'; // Default to enabled if not set

// Prevent caching on the client-side
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$api->sendJsonResponse(200, "Fetched global email notification setting", [
    'email_notifications_enabled' => $value === '1'
]);
