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
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'application/zip',
            'application/x-rar-compressed'
        ]
    ];
    
    public function __construct() {
        parent::__construct();
        $this->uploadDir = __DIR__ . '/../../uploads/media/';
        
        // Create directory if it doesn't exist
        if (!file_exists($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
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
            
            // Determine media type
            $mimeType = mime_content_type($file['tmp_name']);
            $mediaType = $this->getMediaType($mimeType);
            
            if (!$mediaType) {
                $this->sendJsonResponse(400, "Unsupported file type: $mimeType");
                return;
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $this->utils->generateUUID() . '.' . $extension;
            $filepath = $this->uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                $this->sendJsonResponse(500, "Failed to save file");
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
            
        } catch (Exception $e) {
            error_log("Error uploading media: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to upload media: " . $e->getMessage());
        }
    }
    
    private function getMediaType($mimeType) {
        foreach ($this->allowedTypes as $type => $mimes) {
            if (in_array($mimeType, $mimes)) {
                return $type;
            }
        }
        return null;
    }
    
    private function generateImageThumbnail($filepath, $filename) {
        try {
            $thumbnailDir = $this->uploadDir . 'thumbnails/';
            if (!file_exists($thumbnailDir)) {
                mkdir($thumbnailDir, 0755, true);
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
        $baseUrl = $this->getBaseUrl();
        return $baseUrl . '/api/messaging/get_media.php?file=' . urlencode($filename);
    }
    
    private function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . '://' . $host;
    }
}

$api = new UploadMediaAPI();
$api->handle();

