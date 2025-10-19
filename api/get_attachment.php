<?php
require_once __DIR__ . '/../config/cors.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';

// Get request parameters with proper URL decoding
$path = isset($_GET['path']) ? urldecode($_GET['path']) : null;
$name = isset($_GET['name']) ? urldecode($_GET['name']) : null;
$bug_id = isset($_GET['bug_id']) ? urldecode($_GET['bug_id']) : null;
$type = isset($_GET['type']) ? urldecode($_GET['type']) : null;
$filename = isset($_GET['filename']) ? urldecode($_GET['filename']) : null;

// For debugging - log the incoming parameters
error_log("Attachment request: path=$path, name=$name, bug_id=$bug_id, type=$type, filename=$filename");

// Handle both old and new parameter formats
if ($path) {
    // New format: use path directly
    $file_path = $path;
    $filename = $name ?: basename($path);
} else {
    // Old format: validate required parameters
    if (!$bug_id || !$filename) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Missing required parameters',
            'received' => [
                'path' => $path,
                'name' => $name,
                'bug_id' => $bug_id,
                'type' => $type,
                'filename' => $filename
            ]
        ]);
        exit;
    }
}

try {
    // If we have a direct path, use it
    if (isset($file_path)) {
        // Convert relative path to absolute if needed
        if (!file_exists($file_path)) {
            // Try to convert relative path to absolute if needed
            if (strpos($file_path, 'uploads/') === 0) {
                $file_path = __DIR__ . '/../' . $file_path;
            } elseif (strpos($file_path, 'bugricer/backend/') !== false) {
                $file_path = str_replace('bugricer/backend/', __DIR__ . '/../../', $file_path);
            } else {
                $file_path = __DIR__ . '/../..' . $file_path;
            }
        }
    } else {
        // Initialize database connection for old format
        $database = new Database();
        $pdo = $database->getConnection();
        
        // First check for the file path in the database
        $attachmentQuery = $pdo->prepare("
            SELECT file_path, file_name 
            FROM bug_attachments 
            WHERE bug_id = :bug_id AND file_name LIKE :filename
        ");
        $attachmentQuery->bindParam(':bug_id', $bug_id);
        // Use LIKE comparison with % for partial matches
        $filenameLike = '%' . $filename . '%';
        $attachmentQuery->bindParam(':filename', $filenameLike);
        $attachmentQuery->execute();
        $attachment = $attachmentQuery->fetch(PDO::FETCH_ASSOC);
        
        // If found in database, use that path directly
        if ($attachment && !empty($attachment['file_path'])) {
            $file_path = $attachment['file_path'];
            // Make sure this is an absolute path
            if (!file_exists($file_path)) {
                // Try to convert relative path to absolute if needed
                if (strpos($file_path, 'bugricer/backend/') !== false) {
                    $file_path = str_replace('bugricer/backend/', __DIR__ . '/../../', $file_path);
                } else {
                    $file_path = __DIR__ . '/../..' . $file_path;
                }
            }
        } else {
            // Handle file paths - try multiple locations
            $uploads_dir = __DIR__ . '/../uploads/';
            $type_dir = $type ? $type . 's/' : '';
            
            // Try multiple possible file path formats
            $possible_paths = [
                $uploads_dir . $type_dir . $bug_id . '_' . $filename,  // bug_id_filename.ext
                $uploads_dir . $type_dir . $filename,                  // filename.ext
                $uploads_dir . $type_dir . $bug_id . '/' . $filename,  // bug_id/filename.ext
                $uploads_dir . $type_dir . '/' . $bug_id . '/' . $filename,  // type_dir/bug_id/filename
                $uploads_dir . $type_dir . '/' . $filename,            // type_dir/filename
                $uploads_dir . $filename,                             // direct filename.ext
                // Also try without URL encoding in case it was double-encoded
                $uploads_dir . $type_dir . $bug_id . '_' . rawurldecode($filename),
                $uploads_dir . $type_dir . rawurldecode($filename),
                // Add this path to your possible_paths array
                $uploads_dir . 'screenshots/' . $bug_id . '_' . $filename
            ];
            
            $file_path = null;
            foreach ($possible_paths as $path) {
                if (file_exists($path)) {
                    $file_path = $path;
                    break;
                }
            }
        }
    }
    
    // Final check if file exists
    if (empty($file_path) || !file_exists($file_path)) {
        // One more attempt - try to locate the file by globbing the directory
        $pattern = $uploads_dir . $type_dir . '*' . basename($filename) . '*';
        $glob_results = glob($pattern);
        
        if (!empty($glob_results) && file_exists($glob_results[0])) {
            $file_path = $glob_results[0];
        } else {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false, 
                'message' => 'File not found',
                'debug' => [
                    'searched_paths' => $possible_paths,
                    'glob_pattern' => $pattern,
                    'glob_results' => $glob_results ?? []
                ]
            ]);
            exit;
        }
    }
    
    // Get file MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_path);
    finfo_close($finfo);
    
    // Output the file with appropriate headers
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: inline; filename="' . basename($filename) . '"');
    header('Content-Length: ' . filesize($file_path));
    
    // Disable output buffering to handle large files better
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Read file and exit
    readfile($file_path);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Error retrieving attachment: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    exit;
}