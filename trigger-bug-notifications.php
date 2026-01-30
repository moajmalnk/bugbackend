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

    require_once $baseDir . '/utils/whatsapp.php';
    require_once $baseDir . '/api/NotificationManager.php';

    $projectName = getProjectName($conn, $data['project_id']);
    $creatorName = null;
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$decoded->user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) $creatorName = $u['username'];
    $bugUrl = getFrontendBaseUrlForCli($baseDir) . '/bugs/' . $id;

    // In-app notifications
    try {
        $nm = NotificationManager::getInstance();
        $nm->notifyBugCreated($id, $data['title'], $data['project_id'], $decoded->user_id);
        error_log("✅ Bug $bugId: In-app notifications sent");
    } catch (Exception $e) {
        error_log("⚠️ Bug $bugId: In-app failed: " . $e->getMessage());
    }

    // Email
    try {
        $stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $emailEnabled = ($row['value'] ?? '1') === '1';
        if ($emailEnabled) {
            require_once $baseDir . '/utils/send_email.php';
            $developers = getProjectDevelopers($conn, $data['project_id']);
            $admins = getAllAdmins($conn);
            $userIds = array_unique(array_merge($developers, $admins));
            $userIds = array_filter($userIds, fn($uid) => (string)$uid !== (string)$decoded->user_id);
            if (empty($userIds)) $userIds = array_values(array_filter(getAllAdmins($conn), fn($uid) => (string)$uid !== (string)$decoded->user_id));
            if (empty($userIds)) $userIds = [$decoded->user_id];
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
            $stmt = $conn->prepare("SELECT email FROM users WHERE id IN ($placeholders) AND email IS NOT NULL AND email != ''");
            $stmt->execute(array_values($userIds));
            $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($emails)) {
                sendBugCreatedEmail($emails, $id, $data['title'], $projectName, $creatorName ?: 'BugRicer', $priority, $data['description'] ?? null, $expectedResult, $actualResult, $bugUrl);
                error_log("✅ Bug $bugId: Email sent to " . count($emails) . " recipients");
            }
        }
    } catch (Exception $e) {
        error_log("⚠️ Bug $bugId: Email failed: " . $e->getMessage());
    }

    // WhatsApp
    try {
        $developers = getProjectDevelopers($conn, $data['project_id']);
        $admins = getAllAdmins($conn);
        $userIds = array_unique(array_merge($developers, $admins));
        $userIds = array_filter($userIds, fn($uid) => (string)$uid !== (string)$decoded->user_id);
        if (empty($userIds)) {
            $userIds = array_filter(getAllAdmins($conn), fn($uid) => (string)$uid !== (string)$decoded->user_id);
            if (empty($userIds)) $userIds = [$decoded->user_id];
        }
        if (!empty($userIds)) {
            sendBugAssignmentWhatsApp($conn, array_values($userIds), $id, $data['title'], $priority, $projectName, $decoded->user_id, $data['description'] ?? null, $expectedResult, $actualResult);
        }
        sendNewBugToAdminNumbers($id, $data['title'], $priority, $projectName, $creatorName, $data['description'] ?? null, $expectedResult, $actualResult);
        error_log("✅ Bug $bugId: WhatsApp notifications sent");
    } catch (Exception $e) {
        error_log("⚠️ Bug $bugId: WhatsApp failed: " . $e->getMessage());
    }

} catch (Exception $e) {
    error_log("trigger-bug-notifications: " . $e->getMessage());
    exit(1);
}
exit(0);

function getFrontendBaseUrlForCli($baseDir) {
    $envFile = $baseDir . DIRECTORY_SEPARATOR . '.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^FRONTEND_URL=(.+)$/', trim($line), $m)) {
                return trim($m[1], " \t\n\r\0\x0B\"'");
            }
        }
    }
    return 'https://bugs.bugricer.com';
}
