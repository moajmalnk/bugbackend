<?php
/**
 * HTTP endpoint to trigger bug-creation notifications (email, WhatsApp, in-app).
 * Called in background by BugController so the main request can return immediately.
 * Internal use only: expects POST bug_id (no auth for localhost fire-and-forget).
 */
require_once __DIR__ . '/../../config/cors.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Get bug_id from POST body or query
$input = file_get_contents('php://input');
$params = [];
if (!empty($input) && (strpos($input, '{') === 0 || strpos($input, 'bug_id=') === 0)) {
    if (strpos($input, '{') === 0) {
        $params = json_decode($input, true) ?: [];
    } else {
        parse_str($input, $params);
    }
}
$bugId = $params['bug_id'] ?? $_POST['bug_id'] ?? null;
if (!$bugId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'bug_id required']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/bug_notifications.php';

date_default_timezone_set('Asia/Kolkata');

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    if (!$conn) {
        http_response_code(500);
        exit;
    }
    $stmt = $conn->prepare("SELECT id, title, description, expected_result, actual_result, project_id, reported_by, priority FROM bugs WHERE id = ? LIMIT 1");
    $stmt->execute([$bugId]);
    $bug = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bug) {
        http_response_code(404);
        exit;
    }
    $id = $bug['id'];
    $data = [
        'title' => $bug['title'],
        'description' => $bug['description'],
        'expected_result' => $bug['expected_result'],
        'actual_result' => $bug['actual_result'],
        'project_id' => $bug['project_id'],
        'priority' => $bug['priority'] ?? 'medium',
    ];
    $decoded = (object)['user_id' => $bug['reported_by']];
    $expectedResult = $bug['expected_result'];
    $actualResult = $bug['actual_result'];
    $priority = $data['priority'];

    runBugCreatedNotifications($conn, $id, $data, $decoded, $priority, $expectedResult, $actualResult);

    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log("trigger-bug HTTP: " . $e->getMessage());
    http_response_code(500);
}
