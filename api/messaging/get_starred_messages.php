<?php
require_once __DIR__ . '/../BaseAPI.php';

class GetStarredMessagesAPI extends BaseAPI {
    
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
            
            $groupId = $_GET['group_id'] ?? null;
            
            if (!$groupId) {
                $this->sendJsonResponse(400, "group_id is required");
                return;
            }

            if (!$this->tableExists('starred_messages')) {
                $this->sendJsonResponse(200, "Starred messages retrieved successfully", []);
                return;
            }

            $hasGroupId = $this->tableColumnExists('starred_messages', 'group_id');
            $hasStarredAt = $this->tableColumnExists('starred_messages', 'starred_at');
            $groupFilter = $hasGroupId ? 'sm.group_id = ?' : 'cm.group_id = ?';
            $starredAtSelect = $hasStarredAt ? 'sm.starred_at' : 'cm.created_at as starred_at';
            $starredAtOrder = $hasStarredAt ? 'sm.starred_at' : 'cm.created_at';
            
            // Get starred messages for this user in this group
            $stmt = $this->conn->prepare("
                SELECT 
                    cm.*,
                    COALESCE(u.username, 'BugRicer') as sender_name,
                    u.email as sender_email,
                    u.role as sender_role,
                    {$starredAtSelect}
                FROM starred_messages sm
                JOIN chat_messages cm ON sm.message_id = cm.id
                LEFT JOIN users u ON cm.sender_id = u.id
                WHERE sm.user_id = ? 
                    AND {$groupFilter}
                    AND cm.is_deleted = 0
                ORDER BY {$starredAtOrder} DESC
            ");
            $stmt->execute([$userId, $groupId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark as starred in the response
            foreach ($messages as &$message) {
                $message['is_starred'] = true;
            }
            
            $this->sendJsonResponse(200, "Starred messages retrieved successfully", $messages);
            
        } catch (Exception $e) {
            error_log("Error getting starred messages: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to get starred messages: " . $e->getMessage());
        }
    }

    private function tableExists($tableName) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function tableColumnExists($tableName, $columnName) {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$tableName, $columnName]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

$api = new GetStarredMessagesAPI();
$api->handle();

