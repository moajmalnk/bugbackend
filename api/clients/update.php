<?php
require_once __DIR__ . '/../../config/cors.php';
header('Content-Type: application/json');
require_once __DIR__ . '/ClientController.php';

try {
    $controller = new ClientController();

    if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
        throw new Exception('Method not allowed', 405);
    }

    $decoded = $controller->validateToken();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $clientId = $data['id'] ?? ($_GET['id'] ?? null);
    if (!$clientId) {
        throw new Exception('Client ID is required', 400);
    }

    $controller->updateClient($clientId, $data, $decoded);
} catch (Exception $e) {
    error_log('Error in clients/update.php: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred',
    ]);
}
