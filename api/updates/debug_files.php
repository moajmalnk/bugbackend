<?php
// Debug script to check if files are being received
header('Content-Type: application/json');

$debug = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'has_files' => !empty($_FILES),
    'files_count' => !empty($_FILES) ? count($_FILES) : 0,
    'has_post' => !empty($_POST),
    'post_keys' => array_keys($_POST ?? []),
    'files_keys' => array_keys($_FILES ?? []),
];

if (!empty($_FILES)) {
    $debug['files_detail'] = [];
    foreach ($_FILES as $key => $file) {
        if (is_array($file['name'])) {
            $debug['files_detail'][$key] = [
                'count' => count($file['name']),
                'names' => $file['name'],
                'sizes' => $file['size'] ?? [],
                'errors' => $file['error'] ?? [],
                'types' => $file['type'] ?? []
            ];
        } else {
            $debug['files_detail'][$key] = [
                'single_file' => [
                    'name' => $file['name'] ?? 'no name',
                    'size' => $file['size'] ?? 0,
                    'error' => $file['error'] ?? 'unknown',
                    'type' => $file['type'] ?? 'unknown'
                ]
            ];
        }
    }
}

// Also log to error log
error_log("DEBUG_FILES: " . json_encode($debug, JSON_PRETTY_PRINT));

echo json_encode([
    'success' => true,
    'debug' => $debug
], JSON_PRETTY_PRINT);

