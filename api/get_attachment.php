<?php
/**
 * Why: Serve bug attachments (PDF, docs, etc.) from backend/uploads.
 * WhatsApp stores paths as bugs/{id}/file.pdf — the old resolver joined onto
 * the wrong directory, so previews showed JSON "File not found".
 */
require_once __DIR__ . '/../config/cors.php';

$path = isset($_GET['path']) ? urldecode((string) $_GET['path']) : '';
$name = isset($_GET['name']) ? urldecode((string) $_GET['name']) : '';
$bugId = isset($_GET['bug_id']) ? urldecode((string) $_GET['bug_id']) : '';
$type = isset($_GET['type']) ? urldecode((string) $_GET['type']) : '';
$filename = isset($_GET['filename']) ? urldecode((string) $_GET['filename']) : '';

$uploadsDir = realpath(__DIR__ . '/../uploads');
if ($uploadsDir === false || !is_dir($uploadsDir)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Uploads directory missing']);
    exit;
}

/**
 * @return string|null Absolute path under uploads, or null
 */
function brResolveUploadFile(string $uploadsDir, string $relative): ?string
{
    $relative = str_replace(["\0", '\\'], ['', '/'], $relative);
    $relative = ltrim($relative, '/');
    $relative = str_replace(['../', '..\\'], '', $relative);
    if ($relative === '') {
        return null;
    }
    if (strpos($relative, 'uploads/') === 0) {
        $relative = substr($relative, 8);
    }

    $candidate = $uploadsDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $real = realpath($candidate);
    if ($real === false || !is_file($real)) {
        return null;
    }
    if (strpos($real, $uploadsDir) !== 0) {
        return null;
    }
    return $real;
}

$filePath = null;
$displayName = '';

if ($path !== '') {
    $filePath = brResolveUploadFile($uploadsDir, $path);
    $displayName = $name !== '' ? $name : basename($path);

    // If DB path is bugs/{id}/file but only file name was passed somehow
    if ($filePath === null && $bugId !== '' && $bugId === basename(dirname($path))) {
        $filePath = brResolveUploadFile($uploadsDir, 'bugs/' . $bugId . '/' . basename($path));
    }
} else {
    if ($bugId === '' || ($filename === '' && $name === '')) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Missing required parameters',
            'received' => [
                'path' => $path,
                'name' => $name,
                'bug_id' => $bugId,
                'type' => $type,
                'filename' => $filename,
            ],
        ]);
        exit;
    }

    $displayName = $filename !== '' ? $filename : $name;
    $safeName = basename($displayName);

    // Prefer DB path when available
    try {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $pdo = $database->getConnection();
        $stmt = $pdo->prepare(
            'SELECT file_path, file_name FROM bug_attachments
             WHERE bug_id = ? AND file_name LIKE ?
             LIMIT 1'
        );
        $stmt->execute([$bugId, '%' . $safeName . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['file_path'])) {
            $filePath = brResolveUploadFile($uploadsDir, (string) $row['file_path']);
            if (!empty($row['file_name'])) {
                $displayName = (string) $row['file_name'];
            }
        }
    } catch (Throwable $e) {
        error_log('[get_attachment] DB lookup failed: ' . $e->getMessage());
    }

    if ($filePath === null) {
        $candidates = [
            'bugs/' . $bugId . '/' . $safeName,
            'screenshots/' . $bugId . '_' . $safeName,
            'screenshots/' . $safeName,
            'files/' . $bugId . '/' . $safeName,
            'files/' . $safeName,
            $safeName,
        ];
        if ($type !== '') {
            $candidates[] = $type . 's/' . $bugId . '/' . $safeName;
            $candidates[] = $type . 's/' . $safeName;
        }
        foreach ($candidates as $rel) {
            $filePath = brResolveUploadFile($uploadsDir, $rel);
            if ($filePath !== null) {
                break;
            }
        }
    }

    // Last resort: search under bugs/{bugId}/ only (bounded)
    if ($filePath === null) {
        $bugDir = $uploadsDir . DIRECTORY_SEPARATOR . 'bugs' . DIRECTORY_SEPARATOR . $bugId;
        if (is_dir($bugDir)) {
            $matches = glob($bugDir . DIRECTORY_SEPARATOR . '*' . $safeName . '*') ?: [];
            foreach ($matches as $match) {
                $real = realpath($match);
                if ($real && is_file($real) && strpos($real, $uploadsDir) === 0) {
                    $filePath = $real;
                    break;
                }
            }
        }
    }
}

if ($filePath === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'File not found',
        'debug' => [
            'path' => $path,
            'bug_id' => $bugId !== '' ? $bugId : null,
            'filename' => $displayName !== '' ? $displayName : ($filename ?: $name),
        ],
    ]);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? finfo_file($finfo, $filePath) : false;
if ($finfo) {
    finfo_close($finfo);
}
if (!is_string($mimeType) || $mimeType === '') {
    $mimeType = 'application/octet-stream';
}

$forceDownload = isset($_GET['download']) && (string) $_GET['download'] !== '0';
$safeName = str_replace(["\r", "\n", '"'], '', basename($displayName !== '' ? $displayName : $filePath));
if ($safeName === '') {
    $safeName = 'download';
}

$isAudio = str_starts_with($mimeType, 'audio/')
    || $mimeType === 'video/webm'
    || (bool) preg_match('/\.(webm|wav|mp3|m4a|ogg)$/i', $safeName);

if ($forceDownload) {
    header('Content-Type: application/octet-stream');
    header(
        'Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
    );
} else {
    header('Content-Type: ' . $mimeType);
    header(
        'Content-Disposition: inline; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
    );
}
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Impersonate-User, X-User-Id');
if ($isAudio && !$forceDownload) {
    header('Accept-Ranges: bytes');
}

if (ob_get_level()) {
    ob_end_clean();
}

readfile($filePath);
exit;
