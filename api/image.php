<?php
/**
 * Why: Bug attachments store paths relative to uploads/ (e.g. bugs/{id}/file.jpg).
 * Voice notes already resolve that via audio.php. This endpoint used to join the
 * path onto backend/, so WhatsApp screenshots 404'd while the JPEG on disk was fine.
 */
require_once __DIR__ . '/../config/cors.php';

$path = $_GET['path'] ?? '';

if ($path === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No image path provided']);
    exit;
}

$path = str_replace(["\0", '\\'], ['', '/'], $path);
$path = ltrim($path, '/');
$path = str_replace(['../', '..\\'], '', $path);

if (strpos($path, 'uploads/') === 0) {
    $path = substr($path, 8);
}

$uploadsDir = __DIR__ . '/../uploads/';
$uploadsDirReal = realpath($uploadsDir);
$candidates = [
    $uploadsDir . $path,
    __DIR__ . '/../' . $path,
];

$fullPath = null;
foreach ($candidates as $candidate) {
    $real = realpath($candidate);
    if (!$real || !is_file($real)) {
        continue;
    }
    if ($uploadsDirReal && strpos($real, $uploadsDirReal) !== 0) {
        continue;
    }
    $fullPath = $real;
    break;
}

if ($fullPath === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Image not found', 'path' => $path]);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $fullPath) : false;
if ($finfo) {
    finfo_close($finfo);
}

if (!is_string($mimeType) || substr($mimeType, 0, 6) !== 'image/') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File is not an image', 'mime_type' => $mimeType]);
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id');
header('X-Content-Type-Options: nosniff');

if (isset($_GET['download']) && $_GET['download'] == '1') {
    $filename = basename($fullPath);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}

if (ob_get_level()) {
    ob_end_clean();
}

readfile($fullPath);
exit;
?>
