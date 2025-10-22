<?php
/**
 * Manual Google Account Linking
 * This endpoint manually links a Google account to the current user
 */

require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/api/BaseAPI.php';
require_once __DIR__ . '/api/oauth/GoogleOAuthController.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Initialize BaseAPI to validate token
    $api = new BaseAPI();
    
    // Get the current user
    $userData = $api->validateToken();
    
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('User not authenticated');
    }
    
    $userId = $userData->user_id;
    
    // Get request data
    $input = $api->getRequestData();
    
    if (empty($input['google_user_id']) || empty($input['email'])) {
        throw new Exception('Google user ID and email are required');
    }
    
    $googleUserId = $input['google_user_id'];
    $email = $input['email'];
    
    // Initialize OAuth controller
    $oauthController = new GoogleOAuthController();
    
    // Check if user already has tokens
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // First, check if the Google user ID already exists
    $stmt = $conn->prepare("SELECT bugricer_user_id FROM google_tokens WHERE google_user_id = ?");
    $stmt->execute([$googleUserId]);
    $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingRecord) {
        // Update the existing record to point to the current user
        $stmt = $conn->prepare("UPDATE google_tokens SET bugricer_user_id = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE google_user_id = ?");
        $stmt->execute([$userId, $email, $googleUserId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Google account linked to current user successfully',
            'action' => 'linked_existing'
        ]);
    } else {
        // Check if current user already has tokens
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM google_tokens WHERE bugricer_user_id = ?");
        $stmt->execute([$userId]);
        $existingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($existingCount > 0) {
            // Update existing tokens for current user
            $stmt = $conn->prepare("UPDATE google_tokens SET google_user_id = ?, email = ?, updated_at = CURRENT_TIMESTAMP WHERE bugricer_user_id = ?");
            $stmt->execute([$googleUserId, $email, $userId]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Google account updated successfully',
                'action' => 'updated'
            ]);
        } else {
            // Create new tokens entry
            $stmt = $conn->prepare("INSERT INTO google_tokens (google_user_id, bugricer_user_id, refresh_token, email, created_at, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([$googleUserId, $userId, 'MANUAL_LINK', $email]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Google account linked successfully',
                'action' => 'created'
            ]);
        }
    }
    
} catch (Exception $e) {
    error_log("Manual link error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
