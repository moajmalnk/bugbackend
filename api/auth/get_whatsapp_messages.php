<?php
// Disable error display to prevent warnings from breaking JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';

try {
    $pdo = Database::getInstance()->getConnection();
    header('Content-Type: application/json');
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Check if user is authenticated
$headers = getallheaders();
$token = null;

if (isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
    if (strpos($authHeader, 'Bearer ') === 0) {
        $token = substr($authHeader, 7);
    }
}

if (!$token) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

try {
    // Validate token and get user
    error_log("Validating token: " . substr($token, 0, 20) . "...");
    $decoded = Utils::validateJWT($token);
    if (!$decoded || !isset($decoded->user_id)) {
        error_log("Token validation failed");
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }
    error_log("Token validated for user_id: " . $decoded->user_id);

    // Get user role
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$decoded->user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Only admins can view WhatsApp message history
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required']);
        exit;
    }

    // Get query parameters
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $phone = isset($_GET['phone']) ? $_GET['phone'] : null;

    // Build query
    $query = "SELECT 
                id,
                phone,
                otp,
                expires_at,
                created_at
              FROM user_otps 
              WHERE phone IS NOT NULL";
    
    $params = [];

    if ($phone) {
        $query .= " AND phone LIKE ?";
        $params[] = "%$phone%";
    }

    $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    error_log("Executing query: " . $query);
    error_log("Query parameters: " . json_encode($params));
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Found " . count($messages) . " messages");

    // Format messages for frontend
    $formattedMessages = [];
    foreach ($messages as $msg) {
        // Create WhatsApp message format
        $whatsappMessage = "🔐 *BugRicer Login OTP*\n\n";
        $whatsappMessage .= "Your one-time password is: *{$msg['otp']}*\n";
        $whatsappMessage .= "This OTP is valid for 5 minutes.\n\n";
        $whatsappMessage .= "⚠️ *Do not share this code with anyone.*\n";
        $whatsappMessage .= "If you did not request this, please ignore this message.\n\n";
        $whatsappMessage .= "🐞 _Sent from BugRicer_";

        $formattedMessages[] = [
            'id' => $msg['id'],
            'message' => $whatsappMessage,
            'otp' => $msg['otp'],
            'phone' => $msg['phone'],
            'timestamp' => $msg['created_at'],
            'status' => 'sent', // Default status since we don't track delivery
            'expires_at' => isset($msg['expires_at']) ? $msg['expires_at'] : null
        ];
    }

    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM user_otps WHERE phone IS NOT NULL";
    if ($phone) {
        $countQuery .= " AND phone LIKE ?";
    }
    
    $countStmt = $pdo->prepare($countQuery);
    $countParams = $phone ? ["%$phone%"] : [];
    $countStmt->execute($countParams);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'data' => [
            'messages' => $formattedMessages,
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching WhatsApp messages: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?> 