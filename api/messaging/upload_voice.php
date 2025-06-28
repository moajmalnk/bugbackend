<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

class VoiceUploadController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    public function upload() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            if (!isset($_FILES['voice_file'])) {
                $this->sendJsonResponse(400, "No voice file uploaded");
                return;
            }
            
            $file = $_FILES['voice_file'];
            
            // Validate file
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $this->sendJsonResponse(400, "File upload error: " . $file['error']);
                return;
            }
            
            // Check file size (max 10MB)
            if ($file['size'] > 10 * 1024 * 1024) {
                $this->sendJsonResponse(400, "File too large. Maximum size is 10MB");
                return;
            }
            
            // Check file type
            $allowedTypes = ['audio/webm', 'audio/wav', 'audio/mp3', 'audio/mpeg', 'audio/ogg'];
            if (!in_array($file['type'], $allowedTypes)) {
                $this->sendJsonResponse(400, "Invalid file type. Only audio files are allowed");
                return;
            }
            
            // Get the absolute path to the web root
            $webRoot = $_SERVER['DOCUMENT_ROOT'];
            $uploadDir = $webRoot . '/uploads/voice_messages/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $extension = 'webm';
            $filename = $this->utils->generateUUID() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                $this->sendJsonResponse(500, "Failed to save uploaded file");
                return;
            }
            
            // Get audio duration using FFmpeg if available, otherwise estimate
            $duration = $this->getAudioDuration($filepath);
            
            // Determine the base URL dynamically
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            $basePath = str_replace('/api/messaging', '', dirname($_SERVER['SCRIPT_NAME']));
            $publicPath = str_replace('/backend', '', $basePath);
            $publicUrl = $protocol . $host . '/uploads/voice_messages/' . $filename;
            
            // Return file info
            $this->sendJsonResponse(200, "Voice message uploaded successfully", [
                'file_url' => $publicUrl,
                'duration' => $duration,
                'file_size' => $file['size'],
                'file_type' => $file['type']
            ]);
            
            header('Content-Type: audio/webm');
            
        } catch (Exception $e) {
            error_log("Error uploading voice message: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to upload voice message: " . $e->getMessage());
        }
    }
    
    private function getAudioDuration($filepath) {
        // Try to use FFmpeg if available
        $ffmpegPath = $this->findFFmpeg();
        if ($ffmpegPath) {
            $command = sprintf(
                '%s -i "%s" 2>&1 | grep "Duration" | cut -d " " -f 4 | sed s/,//',
                $ffmpegPath,
                $filepath
            );
            
            $output = shell_exec($command);
            if ($output) {
                $duration = $this->parseDuration($output);
                if ($duration > 0) {
                    return $duration;
                }
            }
        }
        
        // Fallback: estimate duration based on file size
        // This is a rough estimate and may not be accurate
        $filesize = filesize($filepath);
        $estimatedDuration = $filesize / (16000 * 2); // Rough estimate for 16kHz, 16-bit audio
        return max(1, round($estimatedDuration));
    }
    
    private function findFFmpeg() {
        $possiblePaths = [
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            'ffmpeg'
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_executable($path) || shell_exec("which $path")) {
                return $path;
            }
        }
        
        return null;
    }
    
    private function parseDuration($durationString) {
        // Parse duration string like "00:01:23.45"
        if (preg_match('/(\d+):(\d+):(\d+\.?\d*)/', $durationString, $matches)) {
            $hours = (int)$matches[1];
            $minutes = (int)$matches[2];
            $seconds = (float)$matches[3];
            
            return $hours * 3600 + $minutes * 60 + $seconds;
        }
        
        return 0;
    }
}

$controller = new VoiceUploadController();
$controller->upload();
?> 