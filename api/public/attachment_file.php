<?php
/**
 * Why: Stream one shared attachment to anyone who has the token,
 * including video players and tools that fetch the URL directly.
 */
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/attachment_share.php';

header('X-Robots-Tag: noindex, nofollow');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$token = isset($_GET['token']) ? strtolower(trim((string) $_GET['token'])) : '';
if (!attachmentShareIsToken($token)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Preview link not found']);
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();
    attachmentShareEnsureColumn($pdo);
    $row = attachmentShareFindByToken($pdo, $token);
    if ($row === null) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Preview link not found']);
        exit;
    }

    $uploadsDir = realpath(__DIR__ . '/../../uploads');
    $filePath = ($uploadsDir && is_dir($uploadsDir))
        ? attachmentShareResolveFile($uploadsDir, (string) $row['file_path'])
        : null;
    if ($filePath === null) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'File not found']);
        exit;
    }

    $fileName = (string) ($row['file_name'] !== '' ? $row['file_name'] : basename($filePath));
    $mime = (string) ($row['file_type'] ?? '');
    if ($mime === '' || $mime === 'application/octet-stream') {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = $finfo ? finfo_file($finfo, $filePath) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        if (is_string($detected) && $detected !== '') {
            $mime = $detected;
        }
    }
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }

    $forceDownload = isset($_GET['download']) && (string) $_GET['download'] !== '0';
    attachmentShareStream($filePath, $fileName, $mime, $forceDownload);
} catch (Throwable $e) {
    error_log('public attachment file failed: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Could not open this file']);
    }
}
