<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');
http_response_code(404);

echo json_encode([
    "success" => false,
    "message" => "API endpoint not found",
    "requested_url" => $_SERVER['REQUEST_URI'] ?? 'unknown'
]);
?> 