<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';

$pdo = Database::getInstance()->getConnection();

header('Content-Type: application/json');

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
    $decoded = Utils::validateJWT($token);
    if (!$decoded || !isset($decoded->user_id)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        exit;
    }

    // Get user role
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$decoded->user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Only admins can view voice note history
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
                vn.id,
                vn.phone,
                vn.file_path,
                vn.duration,
                vn.status,
                vn.created_at,
                u.username as sent_by_username
              FROM voice_notes vn
              LEFT JOIN users u ON vn.sent_by = u.id";
    
    $params = [];

    if ($phone) {
        $query .= " WHERE vn.phone LIKE ?";
        $params[] = "%$phone%";
    }

    $query .= " ORDER BY vn.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $voiceNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format voice notes for frontend
    $formattedVoiceNotes = [];
    foreach ($voiceNotes as $note) {
        // Create a downloadable URL for the voice note
        $downloadUrl = null;
        if (file_exists($note['file_path'])) {
            $downloadUrl = "/api/auth/download_voice_note.php?id=" . $note['id'];
        }

        $formattedVoiceNotes[] = [
            'id' => $note['id'],
            'phone' => $note['phone'],
            'duration' => (int)$note['duration'],
            'status' => $note['status'],
            'timestamp' => $note['created_at'],
            'sent_by' => $note['sent_by_username'],
            'download_url' => $downloadUrl,
            'file_path' => $note['file_path']
        ];
    }

    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM voice_notes";
    if ($phone) {
        $countQuery .= " WHERE phone LIKE ?";
    }
    
    $countStmt = $pdo->prepare($countQuery);
    $countParams = $phone ? ["%$phone%"] : [];
    $countStmt->execute($countParams);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'data' => [
            'voice_notes' => $formattedVoiceNotes,
            'total' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset
        ]
    ]);

} catch (Exception $e) {
    error_log("Error fetching voice notes: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?> 