<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/checkin_policy.php';

$api = new BaseAPI();
$conn = $api->getConnection();

$decoded = $api->validateToken();
if (!$decoded || $decoded->role !== 'admin') {
    $api->sendJsonResponse(403, "Only admins can update settings");
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $api->sendJsonResponse(400, "Invalid JSON body");
    exit;
}

$updated = [];
$hasAny = false;

if (array_key_exists('email_notifications_enabled', $data)) {
    $hasAny = true;
    $value = !empty($data['email_notifications_enabled']) ? '1' : '0';
    br_upsert_setting($conn, 'email_notifications_enabled', $value);
    $updated['email_notifications_enabled'] = $value === '1';
}

$updatingOffice =
    array_key_exists('office_lat', $data)
    || array_key_exists('office_lng', $data)
    || array_key_exists('office_radius_m', $data)
    || array_key_exists('office_label', $data);

if ($updatingOffice) {
    $hasAny = true;
    $current = br_office_config($conn);

    $lat = array_key_exists('office_lat', $data) ? $data['office_lat'] : $current['lat'];
    $lng = array_key_exists('office_lng', $data) ? $data['office_lng'] : $current['lng'];
    $radius = array_key_exists('office_radius_m', $data) ? $data['office_radius_m'] : $current['radius_m'];
    $label = array_key_exists('office_label', $data) ? $data['office_label'] : $current['label'];

    if (!is_numeric($lat) || !is_numeric($lng)) {
        $api->sendJsonResponse(400, "Office latitude and longitude must be numbers.");
        exit;
    }
    $latF = (float)$lat;
    $lngF = (float)$lng;
    if ($latF < -90 || $latF > 90 || $lngF < -180 || $lngF > 180) {
        $api->sendJsonResponse(400, "Office coordinates are out of range.");
        exit;
    }
    if (!is_numeric($radius)) {
        $api->sendJsonResponse(400, "Office radius must be a number (meters).");
        exit;
    }
    $radiusI = (int)round((float)$radius);
    if ($radiusI < 50 || $radiusI > 5000) {
        $api->sendJsonResponse(400, "Office radius must be between 50 m and 5000 m.");
        exit;
    }
    $labelS = trim((string)$label);
    if ($labelS === '') {
        $api->sendJsonResponse(400, "Office label is required.");
        exit;
    }
    if (mb_strlen($labelS) > 120) {
        $labelS = mb_substr($labelS, 0, 120);
    }

    br_upsert_setting($conn, 'office_lat', (string)$latF);
    br_upsert_setting($conn, 'office_lng', (string)$lngF);
    br_upsert_setting($conn, 'office_radius_m', (string)$radiusI);
    br_upsert_setting($conn, 'office_label', $labelS);
    br_clear_setting_cache();

    $office = br_office_config($conn);
    $updated['office_lat'] = $office['lat'];
    $updated['office_lng'] = $office['lng'];
    $updated['office_radius_m'] = $office['radius_m'];
    $updated['office_label'] = $office['label'];
}

if (!$hasAny) {
    $api->sendJsonResponse(400, "No settings fields provided");
    exit;
}

// Always include full snapshot for clients that merge state
$emailRow = $conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
$emailRow->execute();
$emailVal = $emailRow->fetch(PDO::FETCH_ASSOC);
$office = br_office_config($conn);

$api->sendJsonResponse(200, "Settings updated", array_merge([
    'email_notifications_enabled' => (($emailVal['value'] ?? '1') === '1'),
    'office_lat' => $office['lat'],
    'office_lng' => $office['lng'],
    'office_radius_m' => $office['radius_m'],
    'office_label' => $office['label'],
], $updated));
