<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/checkin_policy.php';

$api = new BaseAPI();
$conn = $api->getConnection();

$emailEnabled = '1';
try {
    $stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $emailEnabled = (string)$row['value'];
    }
} catch (Throwable $e) {
    error_log('settings/get email: ' . $e->getMessage());
}

$office = br_office_config($conn);
$cutoff = br_checkin_cutoff_config($conn);

// Prevent caching on the client-side
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$api->sendJsonResponse(200, "Fetched application settings", [
    'email_notifications_enabled' => $emailEnabled === '1',
    'office_lat' => $office['lat'],
    'office_lng' => $office['lng'],
    'office_radius_m' => $office['radius_m'],
    'office_label' => $office['label'],
    'checkin_cutoff_enabled' => $cutoff['enabled'],
    'checkin_cutoff_time' => $cutoff['time'],
    'checkin_cutoff_label' => $cutoff['label'],
    'office_defaults' => [
        'lat' => (float)BR_OFFICE_LAT,
        'lng' => (float)BR_OFFICE_LNG,
        'radius_m' => (int)BR_OFFICE_RADIUS_M,
        'label' => (string)BR_OFFICE_LABEL,
    ],
    'checkin_cutoff_defaults' => [
        'enabled' => true,
        'time' => (string)BR_CHECKIN_CUTOFF_TIME,
        'label' => br_format_cutoff_label((string)BR_CHECKIN_CUTOFF_TIME),
    ],
]);
