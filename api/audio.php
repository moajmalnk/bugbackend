<?php
/**
 * Why: Serve upload audio with CORS that browsers accept for <audio crossOrigin>.
 * Never pair Access-Control-Allow-Credentials: true with Origin *.
 */
require_once __DIR__ . '/../config/cors.php';

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id, Range');
header('Access-Control-Expose-Headers: Content-Length, Content-Range, Accept-Ranges, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$filePath = $_GET['path'] ?? '';

if ($filePath === '' || $filePath === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'File path is required']);
    exit();
}

$uploadsDir = __DIR__ . '/../uploads/';

if (strpos($filePath, 'uploads/') === 0) {
    $filePath = substr($filePath, 8);
}

$requestedPath = $uploadsDir . $filePath;
$uploadsDirReal = realpath($uploadsDir);
$requestedPathReal = realpath($requestedPath);

if (!$requestedPathReal || !$uploadsDirReal || strpos($requestedPathReal, $uploadsDirReal) !== 0) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied - Invalid path']);
    exit();
}

if (!is_file($requestedPathReal)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit();
}

$fileInfo = pathinfo($requestedPathReal);
$extension = strtolower((string)($fileInfo['extension'] ?? ''));

$contentTypes = [
    'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg',
    'm4a' => 'audio/mp4',
    'ogg' => 'audio/ogg',
    'webm' => 'audio/webm',
    'opus' => 'audio/ogg',
];

$contentType = $contentTypes[$extension] ?? 'application/octet-stream';
$fileSize = filesize($requestedPathReal);
if ($fileSize === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unable to read audio file']);
    exit();
}

header('Content-Type: ' . $contentType);
header('Accept-Ranges: bytes');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
if (preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
    $start = $matches[1] !== '' ? (int)$matches[1] : 0;
    $end = $matches[2] !== '' ? (int)$matches[2] : ($fileSize - 1);
    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header("Content-Range: bytes */{$fileSize}");
        exit();
    }
    $end = min($end, $fileSize - 1);
    $length = $end - $start + 1;
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
    header("Content-Length: {$length}");
    $fp = fopen($requestedPathReal, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit();
    }
    fseek($fp, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($fp);
    exit();
}

header('Content-Length: ' . $fileSize);
readfile($requestedPathReal);
