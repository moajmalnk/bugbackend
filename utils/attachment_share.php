<?php
/**
 * Why: Bug media must be previewable from a link that does not require a login,
 * so a developer can paste it into Cursor instead of downloading the file into
 * the repo. The token is the only secret; internal upload paths stay private.
 */

/**
 * @return string|null Absolute path under uploads, or null
 */
function attachmentShareResolveFile(string $uploadsDir, string $relative): ?string
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

function attachmentShareEnsureColumn(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM bug_attachments LIKE 'share_token'");
    $hasColumn = $column && $column->fetch(PDO::FETCH_ASSOC);
    if (!$hasColumn) {
        $pdo->exec(
            'ALTER TABLE bug_attachments ADD COLUMN share_token VARCHAR(64) NULL DEFAULT NULL'
        );
    }

    $index = $pdo->query(
        "SHOW INDEX FROM bug_attachments WHERE Key_name = 'idx_bug_attachments_share_token'"
    );
    $hasIndex = $index && $index->fetch(PDO::FETCH_ASSOC);
    if (!$hasIndex) {
        $pdo->exec(
            'CREATE UNIQUE INDEX idx_bug_attachments_share_token ON bug_attachments (share_token)'
        );
    }
}

function attachmentShareKind(string $mime, string $fileName): string
{
    $ext = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
    if (str_starts_with($mime, 'image/')) {
        return 'image';
    }
    if (str_starts_with($mime, 'video/')) {
        return 'video';
    }
    if (str_starts_with($mime, 'audio/')) {
        return 'audio';
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'], true)) {
        return 'image';
    }
    if (in_array($ext, ['mp4', 'mov', 'mkv', 'm4v', 'avi'], true)) {
        return 'video';
    }
    if ($ext === 'webm') {
        return 'video';
    }
    if (in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'], true)) {
        return 'audio';
    }
    if ($mime === 'application/pdf' || $ext === 'pdf') {
        return 'pdf';
    }
    if (in_array($ext, ['txt', 'md', 'csv', 'json', 'log', 'xml', 'yml', 'yaml'], true)) {
        return 'text';
    }
    return 'file';
}

function attachmentShareIsToken(string $token): bool
{
    return (bool) preg_match('/^[a-f0-9]{32,64}$/', $token);
}

/**
 * @return array{id:string,bug_id:string,file_name:string,file_path:string,file_type:string,share_token:string}|null
 */
function attachmentShareFindByToken(PDO $pdo, string $token): ?array
{
    if (!attachmentShareIsToken($token)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, bug_id, file_name, file_path, file_type, share_token
         FROM bug_attachments
         WHERE share_token = ?
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Create a stable token the first time an attachment is shared.
 */
function attachmentShareMint(PDO $pdo, string $attachmentId): ?string
{
    $existing = $pdo->prepare(
        'SELECT share_token FROM bug_attachments WHERE id = ? LIMIT 1'
    );
    $existing->execute([$attachmentId]);
    $current = $existing->fetch(PDO::FETCH_ASSOC);
    if (!is_array($current)) {
        return null;
    }
    $token = isset($current['share_token']) ? trim((string) $current['share_token']) : '';
    if ($token !== '') {
        return $token;
    }

    $token = bin2hex(random_bytes(24));
    $update = $pdo->prepare(
        'UPDATE bug_attachments
         SET share_token = ?
         WHERE id = ? AND (share_token IS NULL OR share_token = \'\')'
    );
    $update->execute([$token, $attachmentId]);

    $existing->execute([$attachmentId]);
    $saved = $existing->fetch(PDO::FETCH_ASSOC);
    if (!is_array($saved)) {
        return null;
    }
    $savedToken = isset($saved['share_token']) ? trim((string) $saved['share_token']) : '';
    return $savedToken !== '' ? $savedToken : null;
}

/**
 * Stream a file inline, including byte ranges so video preview can seek.
 */
function attachmentShareStream(string $filePath, string $downloadName, string $mime, bool $forceDownload): void
{
    $size = filesize($filePath);
    if ($size === false) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'File not found']);
        return;
    }

    $safeName = str_replace(["\r", "\n", '"'], '', basename($downloadName));
    if ($safeName === '') {
        $safeName = 'download';
    }

    $start = 0;
    $end = $size > 0 ? $size - 1 : 0;
    $isRange = false;

    if (!$forceDownload && isset($_SERVER['HTTP_RANGE']) && $size > 0) {
        if (preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $match)) {
            $rangeStart = $match[1] === '' ? null : (int) $match[1];
            $rangeEnd = $match[2] === '' ? null : (int) $match[2];
            if ($rangeStart === null && $rangeEnd !== null) {
                $suffix = min($rangeEnd, $size);
                $start = max(0, $size - $suffix);
                $end = $size - 1;
            } else {
                $start = $rangeStart ?? 0;
                $end = $rangeEnd ?? ($size - 1);
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            $end = min($end, $size - 1);
            $isRange = true;
        }
    }

    if ($forceDownload) {
        header('Content-Type: application/octet-stream');
        header(
            'Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
        );
    } else {
        header('Content-Type: ' . $mime);
        header(
            'Content-Disposition: inline; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
        );
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-cache');
    header('Accept-Ranges: bytes');
    if (str_ends_with(strtolower($safeName), '.svg') || $mime === 'image/svg+xml') {
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }

    $length = $isRange ? ($end - $start + 1) : $size;
    if ($isRange) {
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . $length);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        return;
    }

    if (ob_get_level()) {
        ob_end_clean();
    }

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        return;
    }
    if ($start > 0) {
        fseek($handle, $start);
    }
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, (int) min(8192, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($handle);
}
