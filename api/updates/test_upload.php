<?php
// Simple test script to verify file uploads are working
header('Content-Type: application/json');

error_log("=== FILE UPLOAD TEST ===");
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log("Has FILES: " . (!empty($_FILES) ? 'yes' : 'no'));
error_log("Has POST: " . (!empty($_POST) ? 'yes' : 'no'));

if (!empty($_FILES)) {
    error_log("FILES structure: " . json_encode($_FILES, JSON_PRETTY_PRINT));
    foreach ($_FILES as $key => $file) {
        error_log("File key: $key");
        if (is_array($file['name'])) {
            error_log("  - Array with " . count($file['name']) . " files");
            foreach ($file['name'] as $idx => $name) {
                error_log("    [$idx] Name: $name, Size: " . ($file['size'][$idx] ?? 0) . ", Error: " . ($file['error'][$idx] ?? 'unknown'));
            }
        } else {
            error_log("  - Single file: " . ($file['name'] ?? 'no name') . ", Size: " . ($file['size'] ?? 0) . ", Error: " . ($file['error'] ?? 'unknown'));
        }
    }
} else {
    error_log("No FILES received");
}

if (!empty($_POST)) {
    error_log("POST data: " . json_encode($_POST));
}

echo json_encode([
    'success' => true,
    'message' => 'Check server logs for details',
    'files_received' => !empty($_FILES),
    'files_count' => !empty($_FILES) ? count($_FILES) : 0
]);

