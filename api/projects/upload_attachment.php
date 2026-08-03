<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $api = new BaseAPI();
    $decoded = $api->validateToken();

    if ($decoded->role !== 'admin') {
        $api->sendJsonResponse(403, 'Only admins can upload project attachments');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $api->sendJsonResponse(405, 'Method not allowed');
        exit;
    }

    $conn = $api->getConnection();

    // Ensure category/folder columns exist
    try {
        $attCols = [];
        $attRes = $conn->query('SHOW COLUMNS FROM project_attachments');
        if ($attRes) {
            while ($row = $attRes->fetch(PDO::FETCH_ASSOC)) {
                $attCols[] = $row['Field'];
            }
        }
        if (!in_array('category', $attCols, true)) {
            $conn->exec("ALTER TABLE project_attachments ADD COLUMN category VARCHAR(32) DEFAULT NULL AFTER file_type");
            $attCols[] = 'category';
        }
        if (!in_array('folder', $attCols, true)) {
            $conn->exec("ALTER TABLE project_attachments ADD COLUMN folder VARCHAR(100) DEFAULT NULL AFTER category");
        }
    } catch (Exception $e) {
        error_log('upload_attachment ensure columns: ' . $e->getMessage());
    }

    $projectId = $_POST['project_id'] ?? null;
    if (!$projectId) {
        $api->sendJsonResponse(400, 'Missing project_id');
        exit;
    }

    $check = $conn->prepare('SELECT id FROM projects WHERE id = ?');
    $check->execute([$projectId]);
    if (!$check->fetch()) {
        $api->sendJsonResponse(404, 'Project not found');
        exit;
    }

    if (!isset($_FILES['files'])) {
        $api->sendJsonResponse(400, 'No files uploaded');
        exit;
    }

    $defaultCategory = isset($_POST['category']) ? trim((string)$_POST['category']) : null;
    $defaultFolder = isset($_POST['folder']) ? trim((string)$_POST['folder']) : null;
    $categories = isset($_POST['categories']) && is_array($_POST['categories'])
        ? $_POST['categories']
        : [];
    $folders = isset($_POST['folders']) && is_array($_POST['folders'])
        ? $_POST['folders']
        : [];
    $relativePaths = isset($_POST['relative_paths']) && is_array($_POST['relative_paths'])
        ? $_POST['relative_paths']
        : [];

    $uploadDir = __DIR__ . '/../../uploads/project_docs/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $files = $_FILES['files'];
    $fileCount = is_array($files['name']) ? count($files['name']) : 1;
    $saved = [];
    $hasCategoryCol = true;

    for ($i = 0; $i < $fileCount; $i++) {
        $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $fileType = is_array($files['type']) ? $files['type'][$i] : $files['type'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

        if ($error !== UPLOAD_ERR_OK || empty($tmpName) || !is_uploaded_file($tmpName)) {
            continue;
        }

        $category = isset($categories[$i]) && $categories[$i] !== ''
            ? trim((string)$categories[$i])
            : $defaultCategory;
        $folder = isset($folders[$i]) && $folders[$i] !== ''
            ? trim((string)$folders[$i])
            : $defaultFolder;

        // Prefer relative path folder segment when uploading a directory
        if ((!$folder || $folder === '') && isset($relativePaths[$i]) && $relativePaths[$i] !== '') {
            $rel = str_replace('\\', '/', (string)$relativePaths[$i]);
            $parts = array_values(array_filter(explode('/', $rel)));
            if (count($parts) > 1) {
                array_pop($parts); // drop filename
                $folder = implode('/', $parts);
            }
        }

        $displayName = $fileName;
        if (isset($relativePaths[$i]) && $relativePaths[$i] !== '') {
            $displayName = basename(str_replace('\\', '/', (string)$relativePaths[$i]));
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($displayName));
        $targetPath = $uploadDir . uniqid('proj_') . '_' . $safeName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            continue;
        }

        $attachmentId = Utils::generateUUID();
        $relativePath = str_replace(__DIR__ . '/../../', '', $targetPath);

        try {
            $stmt = $conn->prepare(
                "INSERT INTO project_attachments (id, project_id, file_name, file_path, file_type, category, folder, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $attachmentId,
                $projectId,
                $displayName,
                $relativePath,
                $fileType,
                $category ?: null,
                $folder ?: null,
                $decoded->user_id,
            ]);
        } catch (Exception $e) {
            $hasCategoryCol = false;
            $stmt = $conn->prepare(
                "INSERT INTO project_attachments (id, project_id, file_name, file_path, file_type, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $attachmentId,
                $projectId,
                $displayName,
                $relativePath,
                $fileType,
                $decoded->user_id,
            ]);
        }

        $saved[] = [
            'id' => $attachmentId,
            'project_id' => $projectId,
            'file_name' => $displayName,
            'file_path' => $relativePath,
            'file_type' => $fileType,
            'category' => $category ?: null,
            'folder' => $folder ?: null,
            'uploaded_by' => $decoded->user_id,
        ];
    }

    if (empty($saved)) {
        $api->sendJsonResponse(400, 'No files were uploaded successfully');
        exit;
    }

    $api->sendJsonResponse(200, 'Attachments uploaded successfully', $saved);
} catch (Exception $e) {
    error_log('Error in upload_attachment.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
