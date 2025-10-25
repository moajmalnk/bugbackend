<?php
require_once __DIR__ . '/FeedbackController.php';

$controller = new FeedbackController();

// Only handle DELETE and POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Delete the feedback
$controller->deleteFeedback();
?>
