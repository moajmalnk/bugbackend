<?php
require_once __DIR__ . '/../BaseAPI.php';

class SearchMessagesAPI extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    public function handle() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            $groupId = $_GET['group_id'] ?? null;
            $query = $_GET['query'] ?? '';
            
            if (!$groupId) {
                $this->sendJsonResponse(400, "group_id is required");
                return;
            }
            
            if (strlen(trim($query)) < 2) {
                $this->sendJsonResponse(400, "Search query must be at least 2 characters");
                return;
            }
            
            // Check if user has access to the group
            if ($userRole !== 'admin') {
                $accessStmt = $this->conn->prepare("
                    SELECT 1 FROM chat_group_members 
                    WHERE group_id = ? AND user_id = ?
                ");
                $accessStmt->execute([$groupId, $userId]);
                if (!$accessStmt->fetch()) {
                    $this->sendJsonResponse(403, "Access denied to this chat group");
                    return;
                }
            }
            
            // Search messages using full-text search
            $searchQuery = '%' . $query . '%';
            $stmt = $this->conn->prepare("
                SELECT 
                    cm.*,
                    u.username as sender_name,
                    u.email as sender_email
                FROM chat_messages cm
                JOIN users u ON cm.sender_id = u.id
                WHERE cm.group_id = ? 
                AND cm.is_deleted = 0
                AND cm.message_type IN ('text', 'reply')
                AND cm.content LIKE ?
                ORDER BY cm.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([$groupId, $searchQuery]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendJsonResponse(200, "Search completed successfully", $results);
            
        } catch (Exception $e) {
            error_log("Error searching messages: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to search messages: " . $e->getMessage());
        }
    }
}

$api = new SearchMessagesAPI();
$api->handle();

