<?php
require_once __DIR__ . '/../../config/cors.php';
header('Content-Type: application/json');
require_once __DIR__ . '/ClientController.php';

try {
    $controller = new ClientController();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed', 405);
    }

    $controller->validateToken();

    $clientId = $_GET['id'] ?? null;
    if ($clientId) {
        $controller->getClient($clientId);
    } else {
        $controller->getClients();
    }
} catch (Exception $e) {
    error_log('Error in clients/get.php: ' . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred',
    ]);
}
