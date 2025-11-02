<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class BroadcastAPI extends BaseAPI {
    public function broadcastNotification() {
        try {
            // Validate authentication
            $userData = $this->validateToken();
            if (!$userData) {
                $this->sendJsonResponse(401, 'Invalid token');
                return;
            }
            
            // Get request body
            $data = $this->getRequestData();
            
            if (!$data) {
                $this->sendJsonResponse(400, 'Invalid JSON');
                return;
            }
            
            // Validate required fields
            $requiredFields = ['type', 'title', 'message', 'bugId', 'bugTitle', 'createdBy'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $this->sendJsonResponse(400, "Missing required field: $field");
                    return;
                }
            }
            
            // Create notifications table if it doesn't exist
            $createTableSQL = "
                CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    type ENUM('new_bug', 'status_change', 'new_update') NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    bug_id INT NOT NULL,
                    bug_title VARCHAR(255) NOT NULL,
                    status VARCHAR(50) NULL,
                    created_by VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_created_at (created_at),
                    INDEX idx_type (type),
                    INDEX idx_bug_id (bug_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ";
            
            $this->conn->exec($createTableSQL);
            
            // Insert the notification
            $bugId = isset($data['bugId']) ? (int)$data['bugId'] : null;
            $sql = "
                INSERT INTO notifications (type, title, message, bug_id, bug_title, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                $data['type'],
                $data['title'],
                $data['message'],
                $bugId,
                $data['bugTitle'],
                $data['status'] ?? null,
                $data['createdBy']
            ]);
            
            if ($result) {
                $notificationId = $this->conn->lastInsertId();
                
                $this->sendJsonResponse(200, 'Notification broadcasted successfully', [
                    'notificationId' => $notificationId
                ]);
            } else {
                $this->sendJsonResponse(500, 'Failed to broadcast notification');
            }
            
        } catch (Exception $e) {
            error_log('Error in broadcastNotification: ' . $e->getMessage());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }
}

// Create instance and handle request
$api = new BroadcastAPI();
$api->broadcastNotification(); 