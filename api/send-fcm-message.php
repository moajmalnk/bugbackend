<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/users/UserController.php';
require_once __DIR__ . '/../services/FirebaseMessagingService.php';

$controller = new UserController();
$conn = $controller->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = $input['title'] ?? 'Update!';
    $body = $input['body'] ?? 'A new update is available for BugRicer.';
    $messageData = isset($input['data']) && is_array($input['data']) ? $input['data'] : [];

    $messagingService = new FirebaseMessagingService($conn);
    $result = $messagingService->sendToAllUsers($title, $body, $messageData);

    echo json_encode($result);
} catch (Throwable $e) {
    error_log("send-fcm-message error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to send multicast notification',
        'details' => $e->getMessage(),
    ]);
}