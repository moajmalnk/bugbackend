<?php
/**
 * Background script to send bug creation notifications (email, WhatsApp, in-app).
 * Called by BugController after bug is saved - runs in background so main request returns fast.
 * Usage: php trigger-bug-notifications.php <bug_id>
 */
if (php_sapi_name() !== 'cli') {
    exit(1);
}
$bugId = $argv[1] ?? null;
if (!$bugId) {
    error_log("trigger-bug-notifications: No bug_id provided");
    exit(1);
}

$baseDir = __DIR__;
require_once $baseDir . '/config/database.php';
require_once $baseDir . '/config/utils.php';
require_once $baseDir . '/api/Utils.php';

date_default_timezone_set('Asia/Kolkata');

// Ensure getFrontendBaseUrl() in whatsapp works in CLI (no HTTP_HOST)
if (empty($_SERVER['HTTP_HOST'])) {
    $hn = gethostname();
    $_SERVER['HTTP_HOST'] = ($hn && (strpos($hn, 'local') !== false || strpos($hn, '.local') !== false)) ? 'localhost' : 'bugs.bugricer.com';
    $_SERVER['HTTPS'] = ($_SERVER['HTTP_HOST'] === 'localhost') ? '' : 'on';
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    if (!$conn) {
        error_log("trigger-bug-notifications: DB connection failed");
        exit(1);
    }

    $stmt = $conn->prepare("SELECT id, title, description, expected_result, actual_result, project_id, reported_by, priority FROM bugs WHERE id = ? LIMIT 1");
    $stmt->execute([$bugId]);
    $bug = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bug) {
        error_log("trigger-bug-notifications: Bug $bugId not found");
        exit(1);
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

    require_once $baseDir . '/utils/bug_notifications.php';
    runBugCreatedNotifications($conn, $id, $data, $decoded, $priority, $expectedResult, $actualResult);
    error_log("✅ Bug $bugId: Notifications completed");

} catch (Exception $e) {
    error_log("trigger-bug-notifications: " . $e->getMessage());
    exit(1);
}
exit(0);
