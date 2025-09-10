<?php
require_once __DIR__ . '/../config/cors.php';

// The main CORS configuration is already handled by cors.php
// We just need to add specific headers for audio files
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Get the file path from query parameter
$filePath = $_GET['path'] ?? '';

error_log("Audio request: " . $filePath);

if (empty($filePath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File path is required']);
    exit();
}

// Security: Ensure the file is within the uploads directory
$uploadsDir = __DIR__ . '/../uploads/';

// If the path already starts with 'uploads/', remove it to avoid duplication
if (strpos($filePath, 'uploads/') === 0) {
    $filePath = substr($filePath, 8); // Remove 'uploads/' prefix
}

$requestedPath = $uploadsDir . $filePath;

// Normalize paths for comparison
$uploadsDirReal = realpath($uploadsDir);
$requestedPathReal = realpath($requestedPath);

// Check if the requested path is within the uploads directory
if (!$requestedPathReal || !$uploadsDirReal || strpos($requestedPathReal, $uploadsDirReal) !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied - Invalid path']);
    exit();
}

// Check if file exists
if (!file_exists($requestedPathReal)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit();
}

// Get file info
$fileInfo = pathinfo($requestedPathReal);
$extension = strtolower($fileInfo['extension']);

// Set appropriate content type for audio files
$contentTypes = [
    'wav' => 'audio/wav',
    'mp3' => 'audio/mpeg',
    'm4a' => 'audio/mp4',
    'ogg' => 'audio/ogg',
    'webm' => 'audio/webm'
];

if (isset($contentTypes[$extension])) {
    header('Content-Type: ' . $contentTypes[$extension]);
    error_log("Audio file served: $filePath with Content-Type: " . $contentTypes[$extension]);
} else {
    header('Content-Type: application/octet-stream');
    error_log("Audio file served: $filePath with Content-Type: application/octet-stream");
}

// Set headers for audio streaming
header('Accept-Ranges: bytes');
header('Content-Length: ' . filesize($requestedPathReal));
header('Cache-Control: public, max-age=3600');

// Output the file
readfile($requestedPathReal);
?> 