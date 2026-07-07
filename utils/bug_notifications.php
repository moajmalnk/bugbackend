<?php
/**
 * Shared logic for bug creation notifications (email, WhatsApp, in-app).
 * Used by both BugController (inline fallback) and trigger-bug-notifications.php.
 */
function runBugCreatedNotifications($conn, $id, $data, $decoded, $priority, $expectedResult, $actualResult) {
    require_once __DIR__ . '/bug_meta.php';
    $bugLevel = $data['bug_level'] ?? null;
    $alreadyRaised = $data['already_raised'] ?? null;
    $projectName = null;
    $creatorName = null;
    $bugUrl = null;
    
    require_once __DIR__ . '/whatsapp.php';
    $projectName = getProjectName($conn, $data['project_id']);
    
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$decoded->user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) $creatorName = $u['username'];
    
    $baseUrl = (function_exists('getFrontendBaseUrl') ? getFrontendBaseUrl() : null) ?? 'https://bugs.bugricer.com';
    if (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)) {
        $baseUrl = 'http://localhost:8080';
    }
    $bugUrl = $baseUrl . '/bugs/' . $id;

    // In-app
    try {
        require_once __DIR__ . '/../api/NotificationManager.php';
        NotificationManager::getInstance()->notifyBugCreated($id, $data['title'], $data['project_id'], $decoded->user_id, $bugLevel, $alreadyRaised);
    } catch (Exception $e) {
        error_log("⚠️ Bug $id: In-app failed: " . $e->getMessage());
    }

    // Email
    try {
        $stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = 'email_notifications_enabled' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (($row['value'] ?? '1') === '1') {
            require_once __DIR__ . '/send_email.php';
            $developers = getProjectDevelopers($conn, $data['project_id']);
            $admins = getAllAdmins($conn);
            $userIds = array_unique(array_merge($developers, $admins));
            $userIds = array_filter($userIds, fn($uid) => (string)$uid !== (string)$decoded->user_id);
            if (empty($userIds)) $userIds = array_values(array_filter(getAllAdmins($conn), fn($uid) => (string)$uid !== (string)$decoded->user_id));
            if (empty($userIds)) $userIds = [$decoded->user_id];
            $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
            $stmt = $conn->prepare("SELECT email FROM users WHERE account_active = 1 AND id IN ($placeholders) AND email IS NOT NULL AND email != ''");
            $stmt->execute(array_values($userIds));
            $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($emails)) {
                sendBugCreatedEmail($emails, $id, $data['title'], $projectName, $creatorName ?: 'BugRicer', $priority, $data['description'] ?? null, $expectedResult, $actualResult, $bugUrl, $bugLevel, $alreadyRaised);
            }
        }
    } catch (Exception $e) {
        error_log("⚠️ Bug $id: Email failed: " . $e->getMessage());
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
            sendBugAssignmentWhatsApp($conn, array_values($userIds), $id, $data['title'], $priority, $projectName, $decoded->user_id, $data['description'] ?? null, $expectedResult, $actualResult, $bugLevel, $alreadyRaised);
        }
        sendNewBugToAdminNumbers($id, $data['title'], $priority, $projectName, $creatorName, $data['description'] ?? null, $expectedResult, $actualResult, $bugLevel, $alreadyRaised);
    } catch (Exception $e) {
        error_log("⚠️ Bug $id: WhatsApp failed: " . $e->getMessage());
    }
}
