<?php
// Handle CORS headers first
$allowedOrigins = [
    'https://bugs.moajmalnk.in',
    'http://localhost:8080',
    'http://localhost:3000',
    'http://127.0.0.1:8080'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else if (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://bugs.moajmalnk.in");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Max-Age: 3600");
header('Content-Type: application/json');

// Disable HTML error output to prevent JSON corruption
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/BugController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$bugId = $data['bug_id'] ?? null;
$attachmentId = $data['attachment_id'] ?? null;

if (!$bugId || !$attachmentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bug ID and attachment ID are required']);
    exit();
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    // Check permissions: only admin or bug reporter
    $stmt = $api->getConnection()->prepare("SELECT reported_by FROM bugs WHERE id = ?");
    $stmt->execute([$bugId]);
    $bug = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$bug) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Bug not found']);
        exit();
    }

    if ($decoded->role !== 'admin' && $decoded->user_id !== $bug['reported_by']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No permission to delete this image']);
        exit();
    }

    // Get file path from database before deleting
    $fileStmt = $api->getConnection()->prepare("SELECT file_path FROM bug_attachments WHERE bug_id = ? AND id = ?");
    $fileStmt->execute([$bugId, $attachmentId]);
    $attachment = $fileStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$attachment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Attachment not found']);
        exit();
    }
    
    // Delete file from filesystem
    $filePath = __DIR__ . '/../../' . ltrim($attachment['file_path'], '/');
    if (file_exists($filePath)) {
        if (!@unlink($filePath)) {
            // Log the error but don't fail the request
            error_log("Warning: Could not delete file: $filePath");
        }
    }
    
    // Delete from bug_attachments
    $delStmt = $api->getConnection()->prepare("DELETE FROM bug_attachments WHERE bug_id = ? AND id = ?");
    $delStmt->execute([$bugId, $attachmentId]);

    if ($delStmt->rowCount() === 0) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to delete attachment from database']);
        exit();
    }

    echo json_encode(['success' => true, 'message' => 'Image deleted successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
