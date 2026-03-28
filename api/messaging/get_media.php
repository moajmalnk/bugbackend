<?php
/**
 * Serves chat media from uploads/media (images, videos, documents).
 * GET ?file=<relative path under media>, e.g. uuid.pdf or thumbnails/thumb_uuid.jpg
 */
require_once __DIR__ . '/../../config/cors.php';

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
$file = str_replace("\0", '', $file);
$file = str_replace('\\', '/', $file);
$file = ltrim($file, '/');

if ($file === '' || strpos($file, '..') !== false) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid file parameter']);
    exit;
}

$mediaDir = realpath(__DIR__ . '/../../uploads/media');
if ($mediaDir === false || !is_dir($mediaDir)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Media storage not available']);
    exit;
}

$resolved = realpath($mediaDir . DIRECTORY_SEPARATOR . $file);
if ($resolved === false || !is_file($resolved)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit;
}

if (strpos($resolved, $mediaDir) !== 0) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
$contentTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'txt' => 'text/plain',
    'csv' => 'text/csv',
    'zip' => 'application/zip',
    'rar' => 'application/vnd.rar',
];

$mime = $contentTypes[$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($resolved));
header('Cache-Control: public, max-age=3600');
header('Accept-Ranges: bytes');

readfile($resolved);
