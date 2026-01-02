<?php
/**
 * Check Google Docs Connection Status
 * This endpoint checks if the current user has linked their Google account
 */

require_once __DIR__ . '/BugSheetsController.php';

header('Content-Type: application/json');

try {
    error_log("=== Check Google Sheets Connection Endpoint ===");
    
    // Initialize controller
    $sheetsController = new BugSheetsController();
    
    // Validate user authentication
    $userData = $sheetsController->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Check impersonation
    $is_impersonated = false;
    $admin_id = null;
    if (isset($userData->impersonated)) {
        $is_impersonated = $userData->impersonated === true || $userData->impersonated === 'true' || $userData->impersonated === 1;
    }
    if (!$is_impersonated && isset($userData->admin_id) && !empty($userData->admin_id)) {
        $is_impersonated = true;
        $admin_id = $userData->admin_id;
    } elseif (isset($userData->admin_id) && !empty($userData->admin_id)) {
        $admin_id = $userData->admin_id;
    }
    
    // Use admin's Google account if impersonating, otherwise use the user's account
    $googleAccountUserId = $is_impersonated && $admin_id ? $admin_id : $userId;
    
    error_log("Checking connection for user: " . $userId . ", googleAccountUserId: " . $googleAccountUserId . ", impersonated: " . ($is_impersonated ? 'yes' : 'no'));
    
    // Check if user has Google account linked using GoogleAuthService (check admin's account if impersonating)
    $authService = new GoogleAuthService();
    $hasAccount = $authService->isUserConnected($googleAccountUserId);
    
    error_log("Connection check result: " . ($hasAccount ? 'true' : 'false'));
    
    // Get connected email if account is linked (use Google account user ID)
    $connectedEmail = null;
    if ($hasAccount) {
        $connectedEmail = $authService->getUserGoogleEmail($googleAccountUserId);
        error_log("Connected email for user $googleAccountUserId: " . ($connectedEmail ?? 'null'));
    }
    
    // Additional debug: check database directly
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM google_tokens WHERE bugricer_user_id = ?");
    $stmt->execute([$googleAccountUserId]);
    $dbCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    error_log("Direct DB check for user $googleAccountUserId: $dbCount tokens found");
    
    echo json_encode([
        'success' => true,
        'data' => [
            'connected' => $hasAccount,
            'email' => $connectedEmail
        ],
        'debug' => [
            'user_id' => $userId,
            'db_count' => $dbCount
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Check connection error: " . $e->getMessage());
    
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

