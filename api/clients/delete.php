<?php
require_once __DIR__ . '/../../config/cors.php';
header('Content-Type: application/json');
require_once __DIR__ . '/ClientController.php';

try {
    $controller = new ClientController();

    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        throw new Exception('Method not allowed', 405);
    }

    $decoded = $controller->validateToken();

    $clientId = $_GET['id'] ?? null;
    if (!$clientId) {
        throw new Exception('Client ID is required', 400);
    }

    $force = isset($_GET['force']) && $_GET['force'] === 'true';
    $controller->deleteClient($clientId, $force, $decoded);
} catch (Exception $e) {
    error_log('Error in clients/delete.php: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred',
    ]);
}
