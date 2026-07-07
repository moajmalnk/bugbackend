<?php
/**
 * Lightweight server clock endpoint (no Composer / JWT dependencies).
 * Used to detect manual device date changes before login and attendance.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$tz = new DateTimeZone('Asia/Kolkata');
$now = new DateTime('now', $tz);

echo json_encode([
    'success' => true,
    'timezone' => 'Asia/Kolkata',
    'server_now' => $now->format('c'),
    'server_today' => $now->format('Y-m-d'),
    'server_time' => $now->format('H:i:s'),
]);
