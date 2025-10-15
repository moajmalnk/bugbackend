<?php

require_once __DIR__ . '/BaseAPI.php';

/**
 * Comprehensive Activity Logger for BugRicer
 * Handles all CRUD operations across the application
 */
class ActivityLogger extends BaseAPI {
    private static $instance = null;
    
    // Activity type constants
    const BUG_CREATED = 'bug_created';
    const BUG_REPORTED = 'bug_reported';
    const BUG_UPDATED = 'bug_updated';
    const BUG_FIXED = 'bug_fixed';
    const BUG_ASSIGNED = 'bug_assigned';
    const BUG_DELETED = 'bug_deleted';
    const BUG_STATUS_CHANGED = 'bug_status_changed';
    
    const TASK_CREATED = 'task_created';
    const TASK_UPDATED = 'task_updated';
    const TASK_COMPLETED = 'task_completed';
    const TASK_DELETED = 'task_deleted';
    const TASK_ASSIGNED = 'task_assigned';
    
    const UPDATE_CREATED = 'update_created';
    const UPDATE_UPDATED = 'update_updated';
    const UPDATE_DELETED = 'update_deleted';
    
    const FIX_CREATED = 'fix_created';
    const FIX_UPDATED = 'fix_updated';
    const FIX_DELETED = 'fix_deleted';
    
    const PROJECT_CREATED = 'project_created';
    const PROJECT_UPDATED = 'project_updated';
    const PROJECT_DELETED = 'project_deleted';
    const MEMBER_ADDED = 'member_added';
    const MEMBER_REMOVED = 'member_removed';
    
    const USER_CREATED = 'user_created';
    const USER_UPDATED = 'user_updated';
    const USER_DELETED = 'user_deleted';
    const USER_ROLE_CHANGED = 'user_role_changed';
    
    const FEEDBACK_CREATED = 'feedback_created';
    const FEEDBACK_UPDATED = 'feedback_updated';
    const FEEDBACK_DELETED = 'feedback_deleted';
    const FEEDBACK_DISMISSED = 'feedback_dismissed';
    
    const MEETING_CREATED = 'meeting_created';
    const MEETING_UPDATED = 'meeting_updated';
    const MEETING_DELETED = 'meeting_deleted';
    const MEETING_JOINED = 'meeting_joined';
    const MEETING_LEFT = 'meeting_left';
    
    const MESSAGE_SENT = 'message_sent';
    const MESSAGE_UPDATED = 'message_updated';
    const MESSAGE_DELETED = 'message_deleted';
    const MESSAGE_PINNED = 'message_pinned';
    const MESSAGE_UNPINNED = 'message_unpinned';
    
    const ANNOUNCEMENT_CREATED = 'announcement_created';
    const ANNOUNCEMENT_UPDATED = 'announcement_updated';
    const ANNOUNCEMENT_DELETED = 'announcement_deleted';
    const ANNOUNCEMENT_BROADCAST = 'announcement_broadcast';
    
    const COMMENT_ADDED = 'comment_added';
    const COMMENT_UPDATED = 'comment_updated';
    const COMMENT_DELETED = 'comment_deleted';
    const FILE_UPLOADED = 'file_uploaded';
    const FILE_DELETED = 'file_deleted';
    const SETTINGS_UPDATED = 'settings_updated';
    const MILESTONE_REACHED = 'milestone_reached';
    
    private function __construct() {
        parent::__construct();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Log a general activity
     */
    public function logActivity($userId, $projectId, $activityType, $description, $relatedId = null, $metadata = null) {
        try {
            $sql = "INSERT INTO project_activities (user_id, project_id, activity_type, description, related_id, metadata, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($sql);
            $result = $stmt->execute([
                $userId,
                $projectId,
                $activityType,
                $description,
                $relatedId,
                $metadata ? json_encode($metadata) : null,
                date('Y-m-d H:i:s')
            ]);
            
            if ($result) {
                // Invalidate related caches
                $this->invalidateActivityCaches($projectId, $userId);
                return $this->conn->lastInsertId();
            }
            return false;
        } catch (Exception $e) {
            error_log("ActivityLogger::logActivity error: " . $e->getMessage());
            return false;
        }
    }
    
    // Bug Activities
    public function logBugCreated($userId, $projectId, $bugId, $bugTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::BUG_CREATED, 
            "Bug created: {$bugTitle}", 
            $bugId, 
            $metadata
        );
    }
    
    public function logBugReported($userId, $projectId, $bugId, $bugTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::BUG_REPORTED, 
            "Bug reported: {$bugTitle}", 
            $bugId, 
            $metadata
        );
    }
    
    public function logBugUpdated($userId, $projectId, $bugId, $bugTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::BUG_UPDATED, 
            "Bug updated: {$bugTitle}", 
            $bugId, 
            $metadata
        );
    }
    
    public function logBugFixed($userId, $projectId, $bugId, $bugTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::BUG_FIXED, 
            "Bug fixed: {$bugTitle}", 
            $bugId, 
            $metadata
        );
    }
    
    public function logBugAssigned($userId, $projectId, $bugId, $bugTitle, $assigneeId, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['assignee_id'] = $assigneeId;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::BUG_ASSIGNED, 
            "Bug assigned: {$bugTitle}", 
            $bugId, 
            $metadata
        );
    }
    
    public function logBugDeleted($userId, $projectId, $bugId, $bugTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::BUG_DELETED, 
            "Bug deleted: {$bugTitle}", 
            $bugId, 
            $metadata
        );
    }
    
    public function logBugStatusChanged($userId, $projectId, $bugId, $bugTitle, $oldStatus, $newStatus, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['old_status'] = $oldStatus;
        $metadata['new_status'] = $newStatus;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::BUG_STATUS_CHANGED, 
            "Bug status changed from {$oldStatus} to {$newStatus}: {$bugTitle}", 
            $bugId, 
            $metadata
        );
    }
    
    // Task Activities
    public function logTaskCreated($userId, $projectId, $taskId, $taskTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::TASK_CREATED, 
            "Task created: {$taskTitle}", 
            $taskId, 
            $metadata
        );
    }
    
    public function logTaskUpdated($userId, $projectId, $taskId, $taskTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::TASK_UPDATED, 
            "Task updated: {$taskTitle}", 
            $taskId, 
            $metadata
        );
    }
    
    public function logTaskCompleted($userId, $projectId, $taskId, $taskTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::TASK_COMPLETED, 
            "Task completed: {$taskTitle}", 
            $taskId, 
            $metadata
        );
    }
    
    public function logTaskDeleted($userId, $projectId, $taskId, $taskTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::TASK_DELETED, 
            "Task deleted: {$taskTitle}", 
            $taskId, 
            $metadata
        );
    }
    
    public function logTaskAssigned($userId, $projectId, $taskId, $taskTitle, $assigneeId, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['assignee_id'] = $assigneeId;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::TASK_ASSIGNED, 
            "Task assigned: {$taskTitle}", 
            $taskId, 
            $metadata
        );
    }
    
    // Update Activities
    public function logUpdateCreated($userId, $projectId, $updateId, $updateTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::UPDATE_CREATED, 
            "Update created: {$updateTitle}", 
            $updateId, 
            $metadata
        );
    }
    
    public function logUpdateUpdated($userId, $projectId, $updateId, $updateTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::UPDATE_UPDATED, 
            "Update updated: {$updateTitle}", 
            $updateId, 
            $metadata
        );
    }
    
    public function logUpdateDeleted($userId, $projectId, $updateId, $updateTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::UPDATE_DELETED, 
            "Update deleted: {$updateTitle}", 
            $updateId, 
            $metadata
        );
    }
    
    // Fix Activities
    public function logFixCreated($userId, $projectId, $fixId, $fixTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FIX_CREATED, 
            "Fix created: {$fixTitle}", 
            $fixId, 
            $metadata
        );
    }
    
    public function logFixUpdated($userId, $projectId, $fixId, $fixTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FIX_UPDATED, 
            "Fix updated: {$fixTitle}", 
            $fixId, 
            $metadata
        );
    }
    
    public function logFixDeleted($userId, $projectId, $fixId, $fixTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FIX_DELETED, 
            "Fix deleted: {$fixTitle}", 
            $fixId, 
            $metadata
        );
    }
    
    // Project Activities
    public function logProjectCreated($userId, $projectId, $projectName, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::PROJECT_CREATED, 
            "Project created: {$projectName}", 
            $projectId, 
            $metadata
        );
    }
    
    public function logProjectUpdated($userId, $projectId, $projectName, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::PROJECT_UPDATED, 
            "Project updated: {$projectName}", 
            $projectId, 
            $metadata
        );
    }
    
    public function logProjectDeleted($userId, $projectId, $projectName, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::PROJECT_DELETED, 
            "Project deleted: {$projectName}", 
            $projectId, 
            $metadata
        );
    }
    
    public function logMemberAdded($userId, $projectId, $memberId, $memberName, $role = null, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['member_id'] = $memberId;
        $metadata['member_name'] = $memberName;
        if ($role) $metadata['role'] = $role;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MEMBER_ADDED, 
            "Member added: {$memberName}" . ($role ? " ({$role})" : ""), 
            $memberId, 
            $metadata
        );
    }
    
    public function logMemberRemoved($userId, $projectId, $memberId, $memberName, $role = null, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['member_id'] = $memberId;
        $metadata['member_name'] = $memberName;
        if ($role) $metadata['role'] = $role;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MEMBER_REMOVED, 
            "Member removed: {$memberName}" . ($role ? " ({$role})" : ""), 
            $memberId, 
            $metadata
        );
    }
    
    // User Activities
    public function logUserCreated($userId, $projectId, $newUserId, $username, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['new_user_id'] = $newUserId;
        $metadata['username'] = $username;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::USER_CREATED, 
            "User created: {$username}", 
            $newUserId, 
            $metadata
        );
    }
    
    public function logUserUpdated($userId, $projectId, $targetUserId, $username, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['target_user_id'] = $targetUserId;
        $metadata['username'] = $username;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::USER_UPDATED, 
            "User updated: {$username}", 
            $targetUserId, 
            $metadata
        );
    }
    
    public function logUserDeleted($userId, $projectId, $targetUserId, $username, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['target_user_id'] = $targetUserId;
        $metadata['username'] = $username;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::USER_DELETED, 
            "User deleted: {$username}", 
            $targetUserId, 
            $metadata
        );
    }
    
    public function logUserRoleChanged($userId, $projectId, $targetUserId, $username, $oldRole, $newRole, $metadata = null) {
        $metadata = $metadata ?? [];
        $metadata['target_user_id'] = $targetUserId;
        $metadata['username'] = $username;
        $metadata['old_role'] = $oldRole;
        $metadata['new_role'] = $newRole;
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::USER_ROLE_CHANGED, 
            "User role changed from {$oldRole} to {$newRole}: {$username}", 
            $targetUserId, 
            $metadata
        );
    }
    
    // Feedback Activities
    public function logFeedbackCreated($userId, $projectId, $feedbackId, $feedbackTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FEEDBACK_CREATED, 
            "Feedback created: {$feedbackTitle}", 
            $feedbackId, 
            $metadata
        );
    }
    
    public function logFeedbackUpdated($userId, $projectId, $feedbackId, $feedbackTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FEEDBACK_UPDATED, 
            "Feedback updated: {$feedbackTitle}", 
            $feedbackId, 
            $metadata
        );
    }
    
    public function logFeedbackDeleted($userId, $projectId, $feedbackId, $feedbackTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FEEDBACK_DELETED, 
            "Feedback deleted: {$feedbackTitle}", 
            $feedbackId, 
            $metadata
        );
    }
    
    public function logFeedbackDismissed($userId, $projectId, $feedbackId, $feedbackTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FEEDBACK_DISMISSED, 
            "Feedback dismissed: {$feedbackTitle}", 
            $feedbackId, 
            $metadata
        );
    }
    
    // Meeting Activities
    public function logMeetingCreated($userId, $projectId, $meetingId, $meetingTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MEETING_CREATED, 
            "Meeting created: {$meetingTitle}", 
            $meetingId, 
            $metadata
        );
    }
    
    public function logMeetingUpdated($userId, $projectId, $meetingId, $meetingTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MEETING_UPDATED, 
            "Meeting updated: {$meetingTitle}", 
            $meetingId, 
            $metadata
        );
    }
    
    public function logMeetingDeleted($userId, $projectId, $meetingId, $meetingTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MEETING_DELETED, 
            "Meeting deleted: {$meetingTitle}", 
            $meetingId, 
            $metadata
        );
    }
    
    public function logMeetingJoined($userId, $projectId, $meetingId, $meetingTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MEETING_JOINED, 
            "Joined meeting: {$meetingTitle}", 
            $meetingId, 
            $metadata
        );
    }
    
    public function logMeetingLeft($userId, $projectId, $meetingId, $meetingTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MEETING_LEFT, 
            "Left meeting: {$meetingTitle}", 
            $meetingId, 
            $metadata
        );
    }
    
    // Message Activities
    public function logMessageSent($userId, $projectId, $messageId, $messagePreview, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MESSAGE_SENT, 
            "Message sent: {$messagePreview}", 
            $messageId, 
            $metadata
        );
    }
    
    public function logMessageUpdated($userId, $projectId, $messageId, $messagePreview, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MESSAGE_UPDATED, 
            "Message updated: {$messagePreview}", 
            $messageId, 
            $metadata
        );
    }
    
    public function logMessageDeleted($userId, $projectId, $messageId, $messagePreview, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MESSAGE_DELETED, 
            "Message deleted: {$messagePreview}", 
            $messageId, 
            $metadata
        );
    }
    
    public function logMessagePinned($userId, $projectId, $messageId, $messagePreview, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MESSAGE_PINNED, 
            "Message pinned: {$messagePreview}", 
            $messageId, 
            $metadata
        );
    }
    
    public function logMessageUnpinned($userId, $projectId, $messageId, $messagePreview, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MESSAGE_UNPINNED, 
            "Message unpinned: {$messagePreview}", 
            $messageId, 
            $metadata
        );
    }
    
    // Announcement Activities
    public function logAnnouncementCreated($userId, $projectId, $announcementId, $announcementTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::ANNOUNCEMENT_CREATED, 
            "Announcement created: {$announcementTitle}", 
            $announcementId, 
            $metadata
        );
    }
    
    public function logAnnouncementUpdated($userId, $projectId, $announcementId, $announcementTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::ANNOUNCEMENT_UPDATED, 
            "Announcement updated: {$announcementTitle}", 
            $announcementId, 
            $metadata
        );
    }
    
    public function logAnnouncementDeleted($userId, $projectId, $announcementId, $announcementTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::ANNOUNCEMENT_DELETED, 
            "Announcement deleted: {$announcementTitle}", 
            $announcementId, 
            $metadata
        );
    }
    
    public function logAnnouncementBroadcast($userId, $projectId, $announcementId, $announcementTitle, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::ANNOUNCEMENT_BROADCAST, 
            "Announcement broadcast: {$announcementTitle}", 
            $announcementId, 
            $metadata
        );
    }
    
    // General Activities
    public function logCommentAdded($userId, $projectId, $commentId, $commentPreview, $relatedId = null, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::COMMENT_ADDED, 
            "Comment added: {$commentPreview}", 
            $commentId, 
            $metadata
        );
    }
    
    public function logCommentUpdated($userId, $projectId, $commentId, $commentPreview, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::COMMENT_UPDATED, 
            "Comment updated: {$commentPreview}", 
            $commentId, 
            $metadata
        );
    }
    
    public function logCommentDeleted($userId, $projectId, $commentId, $commentPreview, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::COMMENT_DELETED, 
            "Comment deleted: {$commentPreview}", 
            $commentId, 
            $metadata
        );
    }
    
    public function logFileUploaded($userId, $projectId, $fileId, $fileName, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FILE_UPLOADED, 
            "File uploaded: {$fileName}", 
            $fileId, 
            $metadata
        );
    }
    
    public function logFileDeleted($userId, $projectId, $fileId, $fileName, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::FILE_DELETED, 
            "File deleted: {$fileName}", 
            $fileId, 
            $metadata
        );
    }
    
    public function logSettingsUpdated($userId, $projectId, $settingsType, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::SETTINGS_UPDATED, 
            "Settings updated: {$settingsType}", 
            null, 
            $metadata
        );
    }
    
    public function logMilestoneReached($userId, $projectId, $milestoneName, $metadata = null) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            self::MILESTONE_REACHED, 
            "Milestone reached: {$milestoneName}", 
            null, 
            $metadata
        );
    }
    
    /**
     * Invalidate activity-related caches
     */
    private function invalidateActivityCaches($projectId = null, $userId = null) {
        // Clear specific cache keys
        if ($projectId) {
            $this->clearCache("project_activities_{$projectId}");
            $this->clearCache("project_activity_stats_{$projectId}");
            $this->clearCache("total_activities_{$projectId}");
            $this->clearCache("recent_activities_{$projectId}");
            $this->clearCache("activity_types_{$projectId}");
            $this->clearCache("top_contributors_{$projectId}");
        }
        
        if ($userId) {
            $this->clearCache("user_activities_{$userId}");
        }
        
        // Clear general activity cache patterns
        $this->clearCache("activities_");
    }
    
    /**
     * Get all supported activity types
     */
    public static function getSupportedActivityTypes() {
        return [
            // Bug Activities
            self::BUG_CREATED, self::BUG_REPORTED, self::BUG_UPDATED, self::BUG_FIXED,
            self::BUG_ASSIGNED, self::BUG_DELETED, self::BUG_STATUS_CHANGED,
            
            // Task Activities
            self::TASK_CREATED, self::TASK_UPDATED, self::TASK_COMPLETED, 
            self::TASK_DELETED, self::TASK_ASSIGNED,
            
            // Update Activities
            self::UPDATE_CREATED, self::UPDATE_UPDATED, self::UPDATE_DELETED,
            
            // Fix Activities
            self::FIX_CREATED, self::FIX_UPDATED, self::FIX_DELETED,
            
            // Project Activities
            self::PROJECT_CREATED, self::PROJECT_UPDATED, self::PROJECT_DELETED,
            self::MEMBER_ADDED, self::MEMBER_REMOVED,
            
            // User Activities
            self::USER_CREATED, self::USER_UPDATED, self::USER_DELETED, self::USER_ROLE_CHANGED,
            
            // Feedback Activities
            self::FEEDBACK_CREATED, self::FEEDBACK_UPDATED, self::FEEDBACK_DELETED, self::FEEDBACK_DISMISSED,
            
            // Meeting Activities
            self::MEETING_CREATED, self::MEETING_UPDATED, self::MEETING_DELETED,
            self::MEETING_JOINED, self::MEETING_LEFT,
            
            // Message Activities
            self::MESSAGE_SENT, self::MESSAGE_UPDATED, self::MESSAGE_DELETED,
            self::MESSAGE_PINNED, self::MESSAGE_UNPINNED,
            
            // Announcement Activities
            self::ANNOUNCEMENT_CREATED, self::ANNOUNCEMENT_UPDATED, self::ANNOUNCEMENT_DELETED, self::ANNOUNCEMENT_BROADCAST,
            
            // General Activities
            self::COMMENT_ADDED, self::COMMENT_UPDATED, self::COMMENT_DELETED,
            self::FILE_UPLOADED, self::FILE_DELETED, self::SETTINGS_UPDATED, self::MILESTONE_REACHED
        ];
    }
}
?>
