<?php
// Simple test to verify CORS is working
require_once __DIR__ . '/../../config/cors.php';

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'CORS test successful',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
]);

