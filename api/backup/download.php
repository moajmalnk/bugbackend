<?php
/**
 * Streams a finished backup archive for a signed, expiring link.
 * GET download.php?job=ID&type=database|files&exp=UNIX&sig=HMAC
 *
 * Links are opened from email or a plain <a href>, so auth is the HMAC signature
 * (issued only to BACKUP_MANAGE/SETTINGS_EDIT admins) rather than a JWT header.
 */

date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/backup_helpers.php';

function backup_download_fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"robots\" content=\"noindex\">"
        . "<title>BugRicer backup download</title></head>"
        . "<body style=\"font-family:'Segoe UI',Arial,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;"
        . "min-height:100vh;align-items:center;justify-content:center;margin:0;\">"
        . "<div style=\"max-width:420px;padding:28px;border-radius:16px;background:#1e293b;text-align:center;\">"
        . "<h1 style=\"font-size:20px;margin:0 0 8px 0;\">Download unavailable</h1>"
        . "<p style=\"margin:0;color:#94a3b8;font-size:14px;\">{$safe}</p></div></body></html>";
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    backup_download_fail(405, 'Method not allowed.');
}

$jobId = isset($_GET['job']) ? (int) $_GET['job'] : 0;
$type = isset($_GET['type']) ? (string) $_GET['type'] : '';
$expires = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
$signature = isset($_GET['sig']) ? (string) $_GET['sig'] : '';

if ($jobId < 1 || !in_array($type, ['database', 'files'], true) || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
    backup_download_fail(400, 'This download link is malformed.');
}
if ($expires < time()) {
    backup_download_fail(410, 'This download link has expired. Open BugBackup to get a fresh link or start a new backup.');
}
if (!backup_verify_signature($jobId, $type, $expires, $signature)) {
    backup_download_fail(403, 'This download link is invalid.');
}

try {
    $conn = Database::getInstance()->getConnection();
    backup_ensure_jobs_table($conn);
    if (!backup_job_has_artifacts($conn)) {
        backup_download_fail(404, 'Backup not found.');
    }

    $stmt = $conn->prepare(
        "SELECT status, artifacts, expires_at FROM backup_jobs WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Backup download lookup failed: ' . $e->getMessage());
    backup_download_fail(503, 'The server is busy. Please try again in a moment.');
}

if (!$job || $job['status'] !== 'completed') {
    backup_download_fail(404, 'Backup not found.');
}
if (empty($job['expires_at']) || strtotime($job['expires_at']) < time()) {
    backup_download_fail(410, 'This backup has expired and was removed from the server.');
}

$artifact = null;
foreach (backup_decode_artifacts($job['artifacts']) as $candidate) {
    if (($candidate['type'] ?? '') === $type) {
        $artifact = $candidate;
        break;
    }
}
if (!$artifact) {
    backup_download_fail(404, 'This archive is not part of the backup.');
}

$path = backup_storage_dir() . DIRECTORY_SEPARATOR . basename((string) $artifact['file']);
if (!is_file($path) || !is_readable($path)) {
    backup_download_fail(410, 'This archive is no longer available on the server.');
}

// Release the DB connection before a potentially long transfer.
$conn = null;
$stmt = null;

$size = (int) filesize($path);
$start = 0;
$end = $size - 1;
$status = 200;

if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        if ($m[2] !== '') {
            $end = min($end, (int) $m[2]);
        }
    }
    if ($start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        backup_download_fail(416, 'Requested range is not satisfiable.');
    }
    $status = 206;
}

while (ob_get_level()) {
    ob_end_clean();
}
set_time_limit(0);
ignore_user_abort(false);

http_response_code($status);
header('Content-Type: ' . ($type === 'database' ? 'application/gzip' : 'application/zip'));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . ($end - $start + 1));
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
if (!empty($artifact['sha256'])) {
    header('X-Checksum-SHA256: ' . $artifact['sha256']);
}
if ($status === 206) {
    header("Content-Range: bytes {$start}-{$end}/{$size}");
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

$fp = fopen($path, 'rb');
if (!$fp) {
    exit;
}
fseek($fp, $start);
$remaining = $end - $start + 1;
$chunk = 1024 * 1024;
while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
    $buffer = fread($fp, (int) min($chunk, $remaining));
    if ($buffer === false) {
        break;
    }
    echo $buffer;
    flush();
    $remaining -= strlen($buffer);
}
fclose($fp);
exit;
