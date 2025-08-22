<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';

$pdo = Database::getInstance()->getConnection();

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

    // Only admins can download voice notes
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required']);
        exit;
    }

    // Get voice note ID from query parameter
    $voiceNoteId = $_GET['id'] ?? null;
    
    if (!$voiceNoteId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Voice note ID is required']);
        exit;
    }

    // Get voice note details
    $stmt = $pdo->prepare("SELECT file_path, phone, created_at FROM voice_notes WHERE id = ?");
    $stmt->execute([$voiceNoteId]);
    $voiceNote = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voiceNote) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Voice note not found']);
        exit;
    }

    $filePath = $voiceNote['file_path'];
    
    // Check if file exists
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Voice note file not found']);
        exit;
    }

    // Get file info
    $fileInfo = pathinfo($filePath);
    $fileName = $fileInfo['basename'];
    $fileSize = filesize($filePath);
    $mimeType = mime_content_type($filePath);

    // Set headers for file download
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Output file content
    readfile($filePath);
    exit;

} catch (Exception $e) {
    error_log("Error downloading voice note: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?> 