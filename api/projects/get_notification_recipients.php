<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$api = null; // Initialize to null

try {
    $api = new BaseAPI();
    
    // Validate token
    $decoded = $api->validateToken();
    if (!$decoded) {
        // validateToken already sent a 401 response
        exit;
    }

    $project_id = $_GET['project_id'] ?? null;
    if (!$project_id) {
        $api->sendJsonResponse(400, 'Missing project_id');
        exit;
    }

    $cacheKey = 'notification_recipients_' . $project_id;
    $cachedRecipients = $api->getCache($cacheKey);
    if ($cachedRecipients !== null) {
        echo json_encode(['success' => true, 'recipients' => $cachedRecipients]);
        exit;
    }

    $pdo = $api->getConnection();

    // Get all admins with role information
    $adminStmt = $pdo->prepare("SELECT email, role FROM users WHERE role = 'admin'");
    $adminStmt->execute();
    $adminRecipients = $adminStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get project members (developers and testers) with role information
    $memberStmt = $pdo->prepare("
        SELECT u.email, u.role 
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = ? AND (u.role = 'developer' OR u.role = 'tester')
    ");
    $memberStmt->execute([$project_id]);
    $memberRecipients = $memberStmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine and remove duplicates based on email
    $allRecipients = array_merge($adminRecipients, $memberRecipients);
    $uniqueRecipients = [];
    $seenEmails = [];
    
    foreach ($allRecipients as $recipient) {
        if (!in_array($recipient['email'], $seenEmails)) {
            $uniqueRecipients[] = $recipient;
            $seenEmails[] = $recipient['email'];
        }
    }

    $api->setCache($cacheKey, $uniqueRecipients, 600); // Cache for 10 minutes

    echo json_encode(['success' => true, 'recipients' => $uniqueRecipients]);

} catch (Exception $e) {
    error_log("Error in get_notification_recipients.php: " . $e->getMessage());
    // Ensure API is instantiated before sending a response
    if ($api === null) {
        $api = new BaseAPI();
    }
    $api->sendJsonResponse(500, 'Internal Server Error');
} 