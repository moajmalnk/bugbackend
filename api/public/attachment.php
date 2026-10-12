<?php
/**
 * Why: Public preview metadata for a shared bug attachment.
 * The unguessable token is the access check. No login, no file path.
 */
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/attachment_share.php';

header('Content-Type: application/json');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$token = isset($_GET['token']) ? strtolower(trim((string) $_GET['token'])) : '';
if (!attachmentShareIsToken($token)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Preview link not found']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    attachmentShareEnsureColumn($pdo);
    $row = attachmentShareFindByToken($pdo, $token);
    if ($row === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Preview link not found']);
        exit;
    }

    $uploadsDir = realpath(__DIR__ . '/../../uploads');
    $filePath = ($uploadsDir && is_dir($uploadsDir))
        ? attachmentShareResolveFile($uploadsDir, (string) $row['file_path'])
        : null;
    if ($filePath === null) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'File not found']);
        exit;
    }

    $fileName = (string) $row['file_name'];
    $mime = (string) ($row['file_type'] ?? '');
    if ($mime === '') {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_file($finfo, $filePath) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $mime = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'file_name' => $fileName,
            'file_type' => $mime,
            'kind' => attachmentShareKind($mime, $fileName),
            'size' => filesize($filePath) ?: 0,
        ],
    ]);
} catch (Throwable $e) {
    error_log('public attachment meta failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not open this preview']);
}
