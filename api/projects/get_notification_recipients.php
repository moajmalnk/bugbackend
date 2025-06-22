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
    $cachedEmails = $api->getCache($cacheKey);
    if ($cachedEmails !== null) {
        echo json_encode(['success' => true, 'emails' => $cachedEmails]);
        exit;
    }

    $pdo = $api->getConnection();

    // Get all admins
    $adminStmt = $pdo->prepare("SELECT email FROM users WHERE role = 'admin'");
    $adminStmt->execute();
    $adminEmails = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

    // Get project members (developers and testers)
    $memberStmt = $pdo->prepare("
        SELECT u.email 
        FROM project_members pm
        JOIN users u ON pm.user_id = u.id
        WHERE pm.project_id = ? AND (u.role = 'developer' OR u.role = 'tester')
    ");
    $memberStmt->execute([$project_id]);
    $memberEmails = $memberStmt->fetchAll(PDO::FETCH_COLUMN);

    $allEmails = array_unique(array_merge($adminEmails, $memberEmails));
    $emailList = array_values($allEmails);

    $api->setCache($cacheKey, $emailList, 600); // Cache for 10 minutes

    echo json_encode(['success' => true, 'emails' => $emailList]);

} catch (Exception $e) {
    error_log("Error in get_notification_recipients.php: " . $e->getMessage());
    // Ensure API is instantiated before sending a response
    if ($api === null) {
        $api = new BaseAPI();
    }
    $api->sendJsonResponse(500, 'Internal Server Error');
} 