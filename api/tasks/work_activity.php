<?php
/**
 * Notify admins of daily work activity (break start/end).
 * POST /api/tasks/work_activity.php
 */

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../NotificationManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $api = new BaseAPI();
    $userData = $api->validateToken();

    if (!$userData || !isset($userData->user_id)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $userId = (string) $userData->user_id;
    $data = $api->getRequestData();

    $action = isset($data['action']) ? strtolower(trim((string) $data['action'])) : '';
    $submissionDate = isset($data['submission_date']) ? trim((string) $data['submission_date']) : date('Y-m-d');
    $startedAt = isset($data['started_at']) ? trim((string) $data['started_at']) : null;
    $durationMinutes = isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null;

    if (!in_array($action, ['break_start', 'break_end'], true)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid action. Use break_start or break_end.']);
        exit();
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $submissionDate)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid submission_date format.']);
        exit();
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Activity recorded',
    ]);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    try {
        $nm = NotificationManager::getInstance();
        $breakAction = $action === 'break_end' ? 'end' : 'start';
        $nm->notifyWorkBreak($userId, $breakAction, $submissionDate, $startedAt, $durationMinutes);
    } catch (Throwable $e) {
        error_log('work_activity.php notification error: ' . $e->getMessage());
    }
} catch (Exception $e) {
    error_log('Error in work_activity.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
