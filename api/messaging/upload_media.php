<?php
require_once __DIR__ . '/../BaseAPI.php';

class UploadMediaAPI extends BaseAPI {
    
    private $uploadDir;
    private $allowedTypes = [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
        'audio' => ['audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm'],
        'document' => [
            'application/pdf',
            'application/x-pdf',
            'application/acrobat',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'text/csv',
            'application/csv',
            'application/zip',
            'application/x-zip-compressed',
            'application/x-rar-compressed',
            'application/vnd.rar',
        ]
    ];
    
    public function __construct() {
        parent::__construct();
        $this->uploadDir = __DIR__ . '/../../uploads/media/';
        
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0775, true);
        }
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            if (!is_object($decoded) || !isset($decoded->user_id)) {
                $this->sendJsonResponse(401, "Invalid or expired session. Please sign in again.");
                return;
            }
            $userId = $decoded->user_id;
            
            if (!isset($_FILES['file'])) {
                $this->sendJsonResponse(400, "No file uploaded");
                return;
            }
            
            $file = $_FILES['file'];
            
            // Check for upload errors
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $this->sendJsonResponse(400, "File upload error: " . $file['error']);
                return;
            }
            
            // Validate file size (100MB max)
            $maxSize = 100 * 1024 * 1024; // 100MB
            if ($file['size'] > $maxSize) {
                $this->sendJsonResponse(400, "File size exceeds maximum of 100MB");
                return;
            }
            
            if (!is_dir($this->uploadDir) || !is_writable($this->uploadDir)) {
                $this->sendJsonResponse(500, "Upload folder is not writable by the web server. On XAMPP/macOS: chmod -R ugo+w backend/uploads/media");
                return;
            }
            
            // Determine media type (sniff + client hint + extension fallback)
            $sniffed = @mime_content_type($file['tmp_name']);
            if ($sniffed === false || $sniffed === '') {
                $sniffed = '';
            }
            $clientType = isset($file['type']) ? trim((string) $file['type']) : '';
            $mimeType = $sniffed ?: $clientType;
            $lowerMime = strtolower(trim($mimeType));
            // Do not trust octet-stream alone — use filename (e.g. PDF) via extension fallback
            if ($lowerMime === 'application/octet-stream' || $lowerMime === '') {
                $mediaType = $this->getMediaTypeFromExtension($file['name'] ?? '');
                if ($mediaType && $mimeType === '') {
                    $mimeType = 'application/octet-stream';
                }
            } else {
                $mediaType = $this->getMediaType($mimeType);
                if (!$mediaType) {
                    $mediaType = $this->getMediaTypeFromExtension($file['name'] ?? '');
                }
            }
            if (!$mediaType) {
                $this->sendJsonResponse(400, "Unsupported file type: " . ($mimeType ?: 'unknown'));
                return;
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $this->utils->generateUUID() . '.' . $extension;
            $filepath = $this->uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                $this->sendJsonResponse(500, "Failed to save file. Check permissions on backend/uploads/media (web server must be able to write).");
                return;
            }
            
            // Generate thumbnail for images and videos
            $thumbnailUrl = null;
            if ($mediaType === 'image') {
                $thumbnailUrl = $this->generateImageThumbnail($filepath, $filename);
            } elseif ($mediaType === 'video') {
                $thumbnailUrl = $this->generateVideoThumbnail($filepath, $filename);
            }
            
            $fileUrl = $this->getFileUrl($filename);
            
            $response = [
                'file_url' => $fileUrl,
                'file_name' => $file['name'],
                'file_size' => $file['size'],
                'media_type' => $mediaType,
                'mime_type' => $mimeType,
                'thumbnail_url' => $thumbnailUrl
            ];
            
            $this->sendJsonResponse(200, "File uploaded successfully", $response);
            
        } catch (Throwable $e) {
            error_log("Error uploading media: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to upload media: " . $e->getMessage());
        }
    }
    
    private function getMediaType($mimeType) {
        $mimeType = strtolower(trim((string) $mimeType));
        if ($mimeType === '') {
            return null;
        }
        foreach ($this->allowedTypes as $type => $mimes) {
            if (in_array($mimeType, $mimes, true)) {
                return $type;
            }
        }
        // e.g. audio/webm; codecs=opus
        foreach ($this->allowedTypes as $type => $mimes) {
            foreach ($mimes as $allowed) {
                if (strpos($mimeType, $allowed) === 0) {
                    return $type;
                }
            }
        }
        return null;
    }

    /**
     * When mime_content_type returns octet-stream or empty (common for uploads).
     */
    private function getMediaTypeFromExtension($filename) {
        $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
        $image = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $video = ['mp4', 'webm', 'mov', 'qt'];
        $audio = ['mp3', 'mpeg', 'ogg', 'wav', 'm4a'];
        $doc = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip', 'rar'];
        if (in_array($ext, $image, true)) {
            return 'image';
        }
        if (in_array($ext, $video, true)) {
            return 'video';
        }
        if (in_array($ext, $audio, true)) {
            return 'audio';
        }
        if (in_array($ext, $doc, true)) {
            return 'document';
        }
        return null;
    }
    
    private function generateImageThumbnail($filepath, $filename) {
        try {
            $thumbnailDir = $this->uploadDir . 'thumbnails/';
            if (!is_dir($thumbnailDir)) {
                @mkdir($thumbnailDir, 0775, true);
            }
            if (!is_writable($thumbnailDir)) {
                return null;
            }
            
            $thumbnailFilename = 'thumb_' . $filename;
            $thumbnailPath = $thumbnailDir . $thumbnailFilename;
            
            // Get image info
            list($width, $height, $type) = getimagesize($filepath);
            
            // Create image resource
            switch ($type) {
                case IMAGETYPE_JPEG:
                    $source = imagecreatefromjpeg($filepath);
                    break;
                case IMAGETYPE_PNG:
                    $source = imagecreatefrompng($filepath);
                    break;
                case IMAGETYPE_GIF:
                    $source = imagecreatefromgif($filepath);
                    break;
                case IMAGETYPE_WEBP:
                    $source = imagecreatefromwebp($filepath);
                    break;
                default:
                    return null;
            }
            
            // Calculate thumbnail dimensions (max 300x300)
            $maxSize = 300;
            if ($width > $height) {
                $thumbWidth = $maxSize;
                $thumbHeight = floor($height * ($maxSize / $width));
            } else {
                $thumbHeight = $maxSize;
                $thumbWidth = floor($width * ($maxSize / $height));
            }
            
            // Create thumbnail
            $thumbnail = imagecreatetruecolor($thumbWidth, $thumbHeight);
            imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
            
            // Save thumbnail
            imagejpeg($thumbnail, $thumbnailPath, 85);
            
            // Free memory
            imagedestroy($source);
            imagedestroy($thumbnail);
            
            return $this->getFileUrl('thumbnails/' . $thumbnailFilename);
            
        } catch (Exception $e) {
            error_log("Error generating thumbnail: " . $e->getMessage());
            return null;
        }
    }
    
    private function generateVideoThumbnail($filepath, $filename) {
        // Video thumbnail generation requires FFmpeg
        // This is a placeholder - implement if FFmpeg is available
        return null;
    }
    
    private function getFileUrl($filename) {
        // SCRIPT_NAME e.g. /BugRicer/backend/api/messaging/upload_media.php → public backend root /BugRicer/backend
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $backendPublicPath = dirname(dirname(dirname($script)));
        if ($backendPublicPath === '/' || $backendPublicPath === '.' || $backendPublicPath === '\\') {
            $backendPublicPath = '';
        }
        return $protocol . '://' . $host . $backendPublicPath
            . '/api/messaging/get_media.php?file=' . urlencode($filename);
    }
}

$api = new UploadMediaAPI();
$api->handle();

