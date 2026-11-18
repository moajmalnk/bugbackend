<?php
/**
 * Why: Public upload for active App Publishing form links (store assets only).
 */
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../utils/app_publishing_links.php';

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function appPubUploadJson(int $code, string $message, $data = null): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $code >= 200 && $code < 300,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

$allowedFolders = [
    'publishing_icon',
    'publishing_feature_graphic',
    'publishing_phone_screenshots',
    'publishing_tablet_screenshots',
];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        appPubUploadJson(405, 'Method not allowed');
    }

    $token = isset($_POST['token']) ? strtolower(trim((string) $_POST['token'])) : '';
    if (!appPublishingLinksIsToken($token)) {
        appPubUploadJson(404, 'This form link is invalid or has expired');
    }

    $pdo = Database::getInstance()->getConnection();
    appPublishingLinksEnsureSchema($pdo);

    $link = appPublishingLinksFindByToken($pdo, $token);
    if (!$link || ($link['status'] ?? '') !== 'active') {
        appPubUploadJson(410, 'This form link has already been used or expired');
    }

    $projectId = (string) $link['project_id'];
    $folder = isset($_POST['folder']) ? trim((string) $_POST['folder']) : '';
    if (!in_array($folder, $allowedFolders, true)) {
        appPubUploadJson(422, 'Invalid asset folder');
    }

    // Ensure attachment columns
    try {
        $attCols = [];
        $attRes = $pdo->query('SHOW COLUMNS FROM project_attachments');
        if ($attRes) {
            while ($row = $attRes->fetch(PDO::FETCH_ASSOC)) {
                $attCols[] = $row['Field'];
            }
        }
        if (!in_array('category', $attCols, true)) {
            $pdo->exec(
                "ALTER TABLE project_attachments ADD COLUMN category VARCHAR(32) DEFAULT NULL AFTER file_type"
            );
        }
        if (!in_array('folder', $attCols, true)) {
            $pdo->exec(
                "ALTER TABLE project_attachments ADD COLUMN folder VARCHAR(100) DEFAULT NULL AFTER category"
            );
        }
    } catch (Throwable $e) {
        error_log('public upload ensure cols: ' . $e->getMessage());
    }

    if (!isset($_FILES['files'])) {
        appPubUploadJson(400, 'No files uploaded');
    }

    $uploadDir = __DIR__ . '/../../uploads/project_docs/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $files = $_FILES['files'];
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;
    $maxFiles = 10;
    if ($fileCount > $maxFiles) {
        appPubUploadJson(422, 'Too many files (max ' . $maxFiles . ')');
    }

    $saved = [];
    for ($i = 0; $i < $fileCount; $i++) {
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        if ($error !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $origName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $size = is_array($files['size']) ? (int) $files['size'][$i] : (int) $files['size'];
        if ($size <= 0 || $size > 15 * 1024 * 1024) {
            continue;
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $origName));
        $safeName = substr($safeName, 0, 180);
        $stored = uniqid('apppub_', true) . '_' . $safeName;
        $dest = $uploadDir . $stored;
        if (!move_uploaded_file($tmp, $dest)) {
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $dest) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $mime = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
        if (
            strpos($mime, 'image/') !== 0
            && strpos($mime, 'video/') !== 0
            && $mime !== 'application/pdf'
        ) {
            @unlink($dest);
            continue;
        }

        $idData = random_bytes(16);
        $idData[6] = chr((ord($idData[6]) & 0x0f) | 0x40);
        $idData[8] = chr((ord($idData[8]) & 0x3f) | 0x80);
        $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($idData), 4));

        $relPath = 'project_docs/' . $stored;
        $ins = $pdo->prepare(
            'INSERT INTO project_attachments
                (id, project_id, file_name, file_path, file_type, category, folder, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $ins->execute([
            $id,
            $projectId,
            $safeName,
            $relPath,
            $mime,
            'APP',
            $folder,
        ]);

        $saved[] = [
            'id' => $id,
            'project_id' => $projectId,
            'file_name' => $safeName,
            'file_path' => $relPath,
            'file_type' => $mime,
            'category' => 'APP',
            'folder' => $folder,
        ];
    }

    if (count($saved) === 0) {
        appPubUploadJson(400, 'No valid files were uploaded');
    }

    appPubUploadJson(200, 'Uploaded', $saved);
} catch (Throwable $e) {
    error_log('public app_publishing_upload: ' . $e->getMessage());
    appPubUploadJson(500, 'Upload failed');
}
