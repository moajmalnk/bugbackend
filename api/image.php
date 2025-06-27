<?php
// Include CORS configuration
require_once __DIR__ . '/../config/cors.php';

// Get the image path from query parameter
$path = $_GET['path'] ?? '';

if (empty($path)) {
    http_response_code(400);
    header('Content-Type: application/json');
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
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Image not found', 'path' => $path]);
    exit;
}

// Get file info
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fullPath);
finfo_close($finfo);

// Verify it's an image (compatible with older PHP versions)
if (substr($mimeType, 0, 6) !== 'image/') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'File is not an image', 'mime_type' => $mimeType]);
    exit;
}

// Set headers for image serving with proper CORS
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=3600');

// Additional CORS headers for images
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');

// If ?download=1 is present, force download
if (isset($_GET['download']) && $_GET['download'] == '1') {
    $filename = basename($fullPath);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}

// Disable output buffering for large files
if (ob_get_level()) {
    ob_end_clean();
}

// Output the image
readfile($fullPath);
exit;
?>