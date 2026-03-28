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
            
            // Resolve MIME: browsers may send audio/webm, video/webm (WebM container), octet-stream, or empty
            $clientType = isset($file['type']) ? trim((string) $file['type']) : '';
            $sniffed = '';
            if (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name']) && function_exists('mime_content_type')) {
                $sniffed = @mime_content_type($file['tmp_name']) ?: '';
            }
            $effectiveMime = $sniffed ?: $clientType;
            if ($this->isAllowedVoiceMime($effectiveMime, $clientType)) {
                // ok
            } else {
                $this->sendJsonResponse(400, "Invalid file type (" . ($effectiveMime ?: $clientType ?: 'unknown') . "). Only audio recordings (e.g. WebM, OGG, MP3, WAV) are allowed.");
                return;
            }
            
            // Get the absolute path to the uploads directory
            $uploadDir = __DIR__ . '/../../uploads/voice_notes/';
            if (!is_dir($uploadDir)) {
                if (!@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    $this->sendJsonResponse(500, "Could not create upload directory. Check server permissions on backend/uploads/voice_notes.");
                    return;
                }
            }
            if (!is_writable($uploadDir)) {
                $this->sendJsonResponse(500, "Upload directory is not writable by the web server. On XAMPP/macOS run: chmod -R ugo+w backend/uploads (or chown uploads to the Apache user).");
                return;
            }
            
            // Generate unique filename
            $extension = 'webm';
            $filename = $this->utils->generateUUID() . '.' . $extension;
            $filepath = $uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                $this->sendJsonResponse(500, "Failed to save uploaded file (move_uploaded_file). Check that backend/uploads/voice_notes is writable by the web server.");
                return;
            }
            
            // Get audio duration using FFmpeg if available, otherwise estimate
            $duration = $this->getAudioDurationSafe($filepath);
            
            // Generate the URL to access the file through the audio API
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'];
            // Get base path: from /BugRicer/backend/api/messaging/upload_voice.php -> /BugRicer/backend
            $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))); // Go up 3 levels to backend folder
            $publicUrl = $protocol . $host . $basePath . '/api/audio.php?path=voice_notes/' . urlencode($filename);
            
            // Return file info
            $this->sendJsonResponse(200, "Voice message uploaded successfully", [
                'file_url' => $publicUrl,
                'duration' => $duration,
                'file_size' => $file['size'],
                'file_type' => $clientType ?: $effectiveMime
            ]);
            
        } catch (Exception $e) {
            error_log("❌ Error uploading voice message: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            error_log("Files: " . json_encode($_FILES));
            $this->sendJsonResponse(500, "Failed to upload voice message: " . $e->getMessage());
        }
    }
    
    /**
     * WebM voice notes are often reported as video/webm; allow common browser MIME quirks.
     */
    private function isAllowedVoiceMime($effectiveMime, $clientType) {
        $allowedExact = [
            'audio/webm', 'audio/wav', 'audio/x-wav', 'audio/mp3', 'audio/mpeg', 'audio/ogg', 'audio/opus',
            'video/webm', // MediaRecorder / file sniffing often use this for .webm
        ];
        $m = strtolower(trim($effectiveMime));
        $c = strtolower(trim($clientType));
        if ($m !== '' && in_array($m, $allowedExact, true)) {
            return true;
        }
        if ($c !== '' && in_array($c, $allowedExact, true)) {
            return true;
        }
        if ($m !== '' && (strpos($m, 'audio/webm') === 0 || strpos($m, 'audio/ogg') === 0 || strpos($m, 'audio/opus') === 0)) {
            return true;
        }
        if ($c !== '' && (strpos($c, 'audio/webm') === 0 || strpos($c, 'video/webm') === 0)) {
            return true;
        }
        // Empty or generic binary — accept only if client claimed an audio type or webm
        if (($m === '' || $m === 'application/octet-stream') && $c !== '' && (strpos($c, 'audio/') === 0 || strpos($c, 'video/webm') === 0)) {
            return true;
        }
        // Some stacks send no MIME; original filename from client is often voice-message.webm
        if (($m === '' || $m === 'application/octet-stream') && isset($_FILES['voice_file']['name'])) {
            $ext = strtolower(pathinfo((string) $_FILES['voice_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['webm', 'ogg', 'opus', 'wav', 'mp3', 'mpeg'], true)) {
                return true;
            }
        }
        return false;
    }

    private function getAudioDurationSafe($filepath) {
        try {
            return $this->getAudioDuration($filepath);
        } catch (Throwable $e) {
            error_log("Voice duration fallback: " . $e->getMessage());
            $filesize = @filesize($filepath) ?: 0;
            return max(1, (int) round($filesize / (16000 * 2)));
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