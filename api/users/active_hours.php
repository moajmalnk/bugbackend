<?php
header('Content-Type: application/json');
ini_set('serialize_precision', '-1');
require_once 'UserController.php';

try {
    $controller = new UserController();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed', 405);
    }

    $decoded = null;
    try {
        $decoded = $controller->validateToken();
    } catch (Exception $authError) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication failed',
        ]);
        exit();
    }

    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Authentication failed',
        ]);
        exit();
    }

    $userId = isset($_GET['id']) ? $_GET['id'] : null;
    $period = isset($_GET['period']) ? $_GET['period'] : 'daily';

    if (!$userId) {
        throw new Exception('User ID is required', 400);
    }

    $validPeriods = ActiveHoursCalculator::PERIODS;
    if (!in_array($period, $validPeriods, true)) {
        throw new Exception('Invalid period. Must be one of: ' . implode(', ', $validPeriods), 400);
    }

    $date = isset($_GET['date']) && $_GET['date'] !== '' ? (string) $_GET['date'] : null;
    if ($date !== null && !ActiveHoursCalculator::isValidDate($date)) {
        throw new Exception('Invalid date. Use YYYY-MM-DD and not a future date', 400);
    }

    $controller->getActiveHours($userId, $period, $date);
} catch (Exception $e) {
    error_log("Error in active_hours.php: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An unexpected error occurred'
    ]);
}
?>
