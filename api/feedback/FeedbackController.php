<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../config/utils.php';

class FeedbackController extends BaseAPI {
    
    public function __construct() {
        parent::__construct();
    }
    
    /**
     * Submit user feedback
     */
    public function submitFeedback() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            // Validate authentication
            $tokenData = $this->validateToken();
            $userId = $tokenData->user_id;
            
            $data = $this->getRequestData();
            
            // Validate required fields
            if (!isset($data['rating']) || !is_numeric($data['rating'])) {
                $this->sendJsonResponse(400, "Rating is required and must be a number");
                return;
            }
            
            $rating = (int)$data['rating'];
            if ($rating < 1 || $rating > 5) {
                $this->sendJsonResponse(400, "Rating must be between 1 and 5");
                return;
            }
            
            $feedbackText = isset($data['feedback_text']) ? trim($data['feedback_text']) : null;
            
            // Check if user has already submitted feedback
            $stmt = $this->conn->prepare("
                SELECT has_submitted_feedback 
                FROM user_feedback_tracking 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $tracking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tracking) {
                // Create tracking record if it doesn't exist
                $stmt = $this->conn->prepare("
                    INSERT INTO user_feedback_tracking (user_id, has_submitted_feedback, first_submission_at)
                    VALUES (?, TRUE, NOW())
                ");
                $stmt->execute([$userId]);
            } else if ($tracking['has_submitted_feedback']) {
                $this->sendJsonResponse(409, "Feedback has already been submitted");
                return;
            } else {
                // Update tracking record
                $stmt = $this->conn->prepare("
                    UPDATE user_feedback_tracking 
                    SET has_submitted_feedback = TRUE, first_submission_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
            }
            
            // Insert feedback
            $feedbackId = $this->utils->generateUUID();
            $stmt = $this->conn->prepare("
                INSERT INTO user_feedback (id, user_id, rating, feedback_text, submitted_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$feedbackId, $userId, $rating, $feedbackText]);
            
            $this->sendJsonResponse(200, "Feedback submitted successfully", [
                'feedback_id' => $feedbackId,
                'rating' => $rating,
                'has_submitted' => true
            ]);
            
        } catch (Exception $e) {
            error_log("Feedback submission error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to submit feedback");
        }
    }
    
    /**
     * Check if user has already submitted feedback
     */
    public function checkFeedbackStatus() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            // Validate authentication
            $tokenData = $this->validateToken();
            $userId = $tokenData->user_id;
            
            $stmt = $this->conn->prepare("
                SELECT has_submitted_feedback, first_submission_at
                FROM user_feedback_tracking 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $tracking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tracking) {
                // User hasn't been tracked yet, create record
                $stmt = $this->conn->prepare("
                    INSERT INTO user_feedback_tracking (user_id, has_submitted_feedback, first_submission_at)
                    VALUES (?, FALSE, NULL)
                ");
                $stmt->execute([$userId]);
                
                $this->sendJsonResponse(200, "Feedback status retrieved", [
                    'has_submitted' => false,
                    'should_show' => true
                ]);
                return;
            }
            
            $this->sendJsonResponse(200, "Feedback status retrieved", [
                'has_submitted' => (bool)$tracking['has_submitted_feedback'],
                'should_show' => !(bool)$tracking['has_submitted_feedback'],
                'first_submission_at' => $tracking['first_submission_at']
            ]);
            
        } catch (Exception $e) {
            error_log("Feedback status check error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to check feedback status");
        }
    }
    
    /**
     * Get feedback statistics (admin only)
     */
    public function getFeedbackStats() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            // Validate authentication and admin role
            $tokenData = $this->validateToken();
            if ($tokenData->role !== 'admin') {
                $this->sendJsonResponse(403, "Access denied. Admin role required.");
                return;
            }
            
            // Get overall statistics
            $stmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total_submissions,
                    AVG(rating) as average_rating,
                    COUNT(CASE WHEN rating = 5 THEN 1 END) as five_star_count,
                    COUNT(CASE WHEN rating = 4 THEN 1 END) as four_star_count,
                    COUNT(CASE WHEN rating = 3 THEN 1 END) as three_star_count,
                    COUNT(CASE WHEN rating = 2 THEN 1 END) as two_star_count,
                    COUNT(CASE WHEN rating = 1 THEN 1 END) as one_star_count,
                    COUNT(CASE WHEN feedback_text IS NOT NULL AND feedback_text != '' THEN 1 END) as text_feedback_count
                FROM user_feedback
            ");
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get recent feedback
            $stmt = $this->conn->prepare("
                SELECT 
                    uf.rating,
                    uf.feedback_text,
                    uf.submitted_at,
                    u.username,
                    u.role
                FROM user_feedback uf
                JOIN users u ON uf.user_id = u.id
                ORDER BY uf.submitted_at DESC
                LIMIT 10
            ");
            $stmt->execute();
            $recentFeedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendJsonResponse(200, "Feedback statistics retrieved", [
                'statistics' => $stats,
                'recent_feedback' => $recentFeedback
            ]);
            
        } catch (Exception $e) {
            error_log("Feedback stats error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve feedback statistics");
        }
    }
    
    /**
     * Dismiss feedback prompt (mark as seen without submitting)
     */
    public function dismissFeedback() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendJsonResponse(405, "Method not allowed");
            return;
        }
        
        try {
            // Validate authentication
            $tokenData = $this->validateToken();
            $userId = $tokenData->user_id;
            
            // Check if user has already submitted or dismissed
            $stmt = $this->conn->prepare("
                SELECT has_submitted_feedback 
                FROM user_feedback_tracking 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            $tracking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($tracking && $tracking['has_submitted_feedback']) {
                $this->sendJsonResponse(409, "Feedback has already been submitted");
                return;
            }
            
            if (!$tracking) {
                // Create tracking record with dismissed status
                $stmt = $this->conn->prepare("
                    INSERT INTO user_feedback_tracking (user_id, has_submitted_feedback, first_submission_at)
                    VALUES (?, FALSE, NULL)
                ");
                $stmt->execute([$userId]);
            }
            
            // Note: We don't mark as submitted, just ensure tracking exists
            // This allows the user to submit feedback later if they want
            
            $this->sendJsonResponse(200, "Feedback prompt dismissed");
            
        } catch (Exception $e) {
            error_log("Feedback dismiss error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to dismiss feedback prompt");
        }
    }
}
?>
