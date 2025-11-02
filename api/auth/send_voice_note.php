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

    // Only admins can send voice notes
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Admin privileges required']);
        exit;
    }

    // Check if it's a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Validate required fields
    $phone = $_POST['mobile'] ?? '';
    $duration = $_POST['duration'] ?? 0;
    
    if (!$phone) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Phone number is required']);
        exit;
    }

    // Validate phone number format
    $phone = Utils::normalizePhone($phone);
    if (!$phone) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid phone number format']);
        exit;
    }

    // Check if audio file was uploaded
    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Audio file is required']);
        exit;
    }

    $audioFile = $_FILES['audio'];
    
    // Validate file type
    $allowedTypes = ['audio/wav', 'audio/mp3', 'audio/ogg', 'audio/m4a'];
    $fileType = mime_content_type($audioFile['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid audio file type. Allowed: WAV, MP3, OGG, M4A']);
        exit;
    }

    // Validate file size (max 16MB for voice notes)
    $maxSize = 16 * 1024 * 1024; // 16MB
    if ($audioFile['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Audio file too large. Maximum size: 16MB']);
        exit;
    }

    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/../../../uploads/voice_notes/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $timestamp = date('Y-m-d_H-i-s');
    $randomId = uniqid();
    $extension = pathinfo($audioFile['name'], PATHINFO_EXTENSION);
    $filename = "voice_note_{$timestamp}_{$randomId}.{$extension}";
    $filepath = $uploadDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($audioFile['tmp_name'], $filepath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save audio file']);
        exit;
    }

    // Store voice note record in database
    $stmt = $pdo->prepare("INSERT INTO voice_notes (phone, file_path, duration, sent_by, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$phone, $filepath, $duration, $decoded->user_id]);

    $voiceNoteId = $pdo->lastInsertId();

    // Send via WhatsApp API
    $apikey = "05ce7a9046414e42b3983330611f8bf5";
    
    // For voice notes, we need to use a different endpoint or method
    // This depends on your WhatsApp API provider
    $whatsappMessage = "🎵 *Voice Note*\n\n";
    $whatsappMessage .= "Duration: " . gmdate("i:s", $duration) . "\n";
    $whatsappMessage .= "Sent via BugRicer\n\n";
    $whatsappMessage .= "🐞 _Voice note attached_";

    // Send text message first (as fallback)
    $textUrl = "http://148.251.129.118/whatsapp/api/send?mobile=$phone&msg=" . urlencode($whatsappMessage) . "&apikey=$apikey";
    $textResponse = file_get_contents($textUrl);
    
    // Log the response
    error_log('WhatsApp Voice Note API response: ' . $textResponse);

    // For actual voice note sending, you would need to implement file upload to your WhatsApp API
    // This is a placeholder for the voice note sending logic
    $voiceUrl = "http://148.251.129.118/whatsapp/api/send-voice";
    
    // Create cURL request for voice note
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $voiceUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'mobile' => $phone,
        'apikey' => $apikey,
        'audio' => new CURLFile($filepath, $fileType, $filename)
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $voiceResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log('WhatsApp Voice API response: ' . $voiceResponse);

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Voice note sent successfully',
        'data' => [
            'id' => $voiceNoteId,
            'phone' => $phone,
            'duration' => $duration,
            'file_path' => $filepath,
            'filename' => $filename
        ]
    ]);

} catch (Exception $e) {
    error_log("Error sending voice note: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
?> 