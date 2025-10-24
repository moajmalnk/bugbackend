<?php
/**
 * CORS Test Endpoint
 * Test CORS configuration for debugging
 */

require_once __DIR__ . '/../config/cors.php';

header('Content-Type: application/json');

echo json_encode([
    'success' => true,
    'message' => 'CORS test successful',
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? 'No origin header',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'No method',
    'headers' => getallheaders()
]);
?>
