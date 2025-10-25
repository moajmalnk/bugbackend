<?php
header('Content-Type: application/json');
require_once 'UserController.php';

try {
    $controller = new UserController();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed', 405);
    }

    // Validate token
    $controller->validateToken();

    $userId = isset($_GET['id']) ? $_GET['id'] : null;
    $period = isset($_GET['period']) ? $_GET['period'] : 'daily';

    if (!$userId) {
        throw new Exception('User ID is required', 400);
    }

    // Validate period
    $validPeriods = ['daily', 'weekly', 'monthly', 'yearly'];
    if (!in_array($period, $validPeriods)) {
        throw new Exception('Invalid period. Must be one of: ' . implode(', ', $validPeriods), 400);
    }

    $controller->getActiveHours($userId, $period);
} catch (Exception $e) {
    error_log("Error in active_hours.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred'
    ]);
}
?>
