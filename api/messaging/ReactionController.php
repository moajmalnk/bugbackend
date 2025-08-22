<?php
require_once __DIR__ . '/../BaseAPI.php';

class ReactionController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Add a reaction to a message
     */
    public function add() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['message_id']) || !isset($input['emoji'])) {
                $this->sendJsonResponse(400, "message_id and emoji are required");
                return;
            }
            
            $messageId = $input['message_id'];
            $emoji = $input['emoji'];
            
            // Validate emoji (basic validation)
            if (strlen($emoji) > 10) {
                $this->sendJsonResponse(400, "Invalid emoji");
                return;
            }
            
            // Check if user has access to the message
            if (!$this->validateMessageAccess($messageId, $userId, $userRole)) {
                $this->sendJsonResponse(403, "Access denied to this message");
                return;
            }
            
            // Check if reaction already exists
            $checkStmt = $this->conn->prepare("
                SELECT id FROM message_reactions 
                WHERE message_id = ? AND user_id = ? AND emoji = ?
            ");
            $checkStmt->execute([$messageId, $userId, $emoji]);
            
            if ($checkStmt->fetch()) {
                $this->sendJsonResponse(409, "Reaction already exists");
                return;
            }
            
            $reactionId = $this->utils->generateUUID();
            
            $stmt = $this->conn->prepare("
                INSERT INTO message_reactions (id, message_id, user_id, emoji)
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([$reactionId, $messageId, $userId, $emoji]);
            
            // Get the created reaction with user details
            $reaction = $this->getReactionWithDetails($reactionId);
            
            $this->sendJsonResponse(201, "Reaction added successfully", $reaction);
            
        } catch (Exception $e) {
            error_log("Error adding reaction: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to add reaction: " . $e->getMessage());
        }
    }
    
    /**
     * Remove a reaction from a message
     */
    public function remove() {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            
            $messageId = $_GET['message_id'] ?? null;
            $emoji = $_GET['emoji'] ?? null;
            
            if (!$messageId || !$emoji) {
                $this->sendJsonResponse(400, "message_id and emoji are required");
                return;
            }
            
            $stmt = $this->conn->prepare("
                DELETE FROM message_reactions 
                WHERE message_id = ? AND user_id = ? AND emoji = ?
            ");
            
            $result = $stmt->execute([$messageId, $userId, $emoji]);
            
            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "Reaction not found");
                return;
            }
            
            $this->sendJsonResponse(200, "Reaction removed successfully");
            
        } catch (Exception $e) {
            error_log("Error removing reaction: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to remove reaction: " . $e->getMessage());
        }
    }
    
    /**
     * Get reactions for a message
     */
    public function getByMessage($messageId) {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            $decoded = $this->validateToken();
            $userId = $decoded->user_id;
            $userRole = $decoded->role;
            
            // Check if user has access to the message
            if (!$this->validateMessageAccess($messageId, $userId, $userRole)) {
                $this->sendJsonResponse(403, "Access denied to this message");
                return;
            }
            
            $query = "
                SELECT 
                    mr.*,
                    u.username as user_name
                FROM message_reactions mr
                JOIN users u ON mr.user_id = u.id
                WHERE mr.message_id = ?
                ORDER BY mr.created_at ASC
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$messageId]);
            $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group reactions by emoji
            $groupedReactions = [];
            foreach ($reactions as $reaction) {
                $emoji = $reaction['emoji'];
                if (!isset($groupedReactions[$emoji])) {
                    $groupedReactions[$emoji] = [
                        'emoji' => $emoji,
                        'count' => 0,
                        'users' => [],
                        'reactions' => []
                    ];
                }
                $groupedReactions[$emoji]['count']++;
                $groupedReactions[$emoji]['users'][] = $reaction['user_name'];
                $groupedReactions[$emoji]['reactions'][] = $reaction;
            }
            
            $this->sendJsonResponse(200, "Reactions retrieved successfully", array_values($groupedReactions));
            
        } catch (Exception $e) {
            error_log("Error fetching reactions: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve reactions: " . $e->getMessage());
        }
    }
    
    /**
     * Helper methods
     */
    private function validateMessageAccess($messageId, $userId, $userRole) {
        if ($userRole === 'admin') {
            return true;
        }
        
        $query = "
            SELECT 1 FROM chat_messages cm
            JOIN chat_group_members cgm ON cm.group_id = cgm.group_id
            WHERE cm.id = ? AND cgm.user_id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$messageId, $userId]);
        return (bool) $stmt->fetch();
    }
    
    private function getReactionWithDetails($reactionId) {
        $query = "
            SELECT 
                mr.*,
                u.username as user_name
            FROM message_reactions mr
            JOIN users u ON mr.user_id = u.id
            WHERE mr.id = ?
        ";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$reactionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?> 