<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/database.php';

// Get the image path from query parameter
$path = $_GET['path'] ?? '';

if (empty($path)) {
    http_response_code(400);
    echo json_encode(['error' => 'No image path provided']);
    exit;
}

// Security: Sanitize the path to prevent directory traversal
$path = str_replace(['../', '../', '..\\', '..\\\\'], '', $path);

// Construct the full file path
$fullPath = __DIR__ . '/../' . ltrim($path, '/');

// Check if file exists
if (!file_exists($fullPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Image not found']);
    exit;
}

// Get file info
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fullPath);
finfo_close($finfo);

// Verify it's an image (compatible with older PHP versions)
if (substr($mimeType, 0, 6) !== 'image/') {
    http_response_code(400);
    echo json_encode(['error' => 'File is not an image']);
    exit;
}

// Set headers for image serving
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

// Disable output buffering for large files
if (ob_get_level()) {
    ob_end_clean();
}

// Output the image
readfile($fullPath);
?> 