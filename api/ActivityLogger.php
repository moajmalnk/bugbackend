<?php
require_once __DIR__ . '/BaseAPI.php';

/**
 * Centralized Activity Logger
 * Handles logging of all activities across the application
 */
class ActivityLogger extends BaseAPI {
    private static $instance = null;

    private function __construct() {
        parent::__construct();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Log any activity
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
            error_log("Error logging activity: " . $e->getMessage());
            return false;
        }
    }

    // ===== BUG ACTIVITIES =====
    public function logBugCreated($userId, $projectId, $bugId, $bugTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'bug_created', 
            "Bug created: {$bugTitle}", 
            $bugId, 
            array_merge($metadata, ['action' => 'create'])
        );
    }

    public function logBugReported($userId, $projectId, $bugId, $bugTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'bug_reported', 
            "Bug reported: {$bugTitle}", 
            $bugId, 
            array_merge($metadata, ['action' => 'report'])
        );
    }

    public function logBugUpdated($userId, $projectId, $bugId, $bugTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'bug_updated', 
            "Bug updated: {$bugTitle}", 
            $bugId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logBugFixed($userId, $projectId, $bugId, $bugTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'bug_fixed', 
            "Bug fixed: {$bugTitle}", 
            $bugId, 
            array_merge($metadata, ['action' => 'fix'])
        );
    }

    public function logBugAssigned($userId, $projectId, $bugId, $bugTitle, $assignedTo, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'bug_assigned', 
            "Bug assigned: {$bugTitle}", 
            $bugId, 
            array_merge($metadata, ['action' => 'assign', 'assigned_to' => $assignedTo])
        );
    }

    public function logBugDeleted($userId, $projectId, $bugId, $bugTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'bug_deleted', 
            "Bug deleted: {$bugTitle}", 
            $bugId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    public function logBugStatusChanged($userId, $projectId, $bugId, $bugTitle, $fromStatus, $toStatus, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'bug_status_changed', 
            "Bug status changed from {$fromStatus} to {$toStatus}: {$bugTitle}", 
            $bugId, 
            array_merge($metadata, ['action' => 'status_change', 'from' => $fromStatus, 'to' => $toStatus])
        );
    }

    // ===== TASK ACTIVITIES =====
    public function logTaskCreated($userId, $projectId, $taskId, $taskTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'task_created', 
            "Task created: {$taskTitle}", 
            $taskId, 
            array_merge($metadata, ['action' => 'create'])
        );
    }

    public function logTaskUpdated($userId, $projectId, $taskId, $taskTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'task_updated', 
            "Task updated: {$taskTitle}", 
            $taskId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logTaskCompleted($userId, $projectId, $taskId, $taskTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'task_completed', 
            "Task completed: {$taskTitle}", 
            $taskId, 
            array_merge($metadata, ['action' => 'complete'])
        );
    }

    public function logTaskDeleted($userId, $projectId, $taskId, $taskTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'task_deleted', 
            "Task deleted: {$taskTitle}", 
            $taskId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    public function logTaskAssigned($userId, $projectId, $taskId, $taskTitle, $assignedTo, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'task_assigned', 
            "Task assigned: {$taskTitle}", 
            $taskId, 
            array_merge($metadata, ['action' => 'assign', 'assigned_to' => $assignedTo])
        );
    }

    // ===== UPDATE ACTIVITIES =====
    public function logUpdateCreated($userId, $projectId, $updateId, $updateTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'update_created', 
            "Update created: {$updateTitle}", 
            $updateId, 
            array_merge($metadata, ['action' => 'create'])
        );
    }

    public function logUpdateUpdated($userId, $projectId, $updateId, $updateTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'update_updated', 
            "Update updated: {$updateTitle}", 
            $updateId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logUpdateDeleted($userId, $projectId, $updateId, $updateTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'update_deleted', 
            "Update deleted: {$updateTitle}", 
            $updateId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    // ===== FIX ACTIVITIES =====
    public function logFixCreated($userId, $projectId, $fixId, $fixTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'fix_created', 
            "Fix created: {$fixTitle}", 
            $fixId, 
            array_merge($metadata, ['action' => 'create'])
        );
    }

    public function logFixUpdated($userId, $projectId, $fixId, $fixTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'fix_updated', 
            "Fix updated: {$fixTitle}", 
            $fixId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logFixDeleted($userId, $projectId, $fixId, $fixTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'fix_deleted', 
            "Fix deleted: {$fixTitle}", 
            $fixId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    // ===== PROJECT ACTIVITIES =====
    public function logProjectCreated($userId, $projectId, $projectName, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'project_created', 
            "Project created: {$projectName}", 
            $projectId, 
            array_merge($metadata, ['action' => 'create'])
        );
    }

    public function logProjectUpdated($userId, $projectId, $projectName, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'project_updated', 
            "Project updated: {$projectName}", 
            $projectId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logProjectDeleted($userId, $projectId, $projectName, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'project_deleted', 
            "Project deleted: {$projectName}", 
            $projectId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    public function logMemberAdded($userId, $projectId, $memberUsername, $role = null, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'member_added', 
            "Member added: {$memberUsername}", 
            null, 
            array_merge($metadata, ['action' => 'add_member', 'member_username' => $memberUsername, 'role' => $role])
        );
    }

    public function logMemberRemoved($userId, $projectId, $memberUsername, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'member_removed', 
            "Member removed: {$memberUsername}", 
            null, 
            array_merge($metadata, ['action' => 'remove_member', 'member_username' => $memberUsername])
        );
    }

    // ===== USER ACTIVITIES =====
    public function logUserCreated($userId, $projectId, $newUserId, $username, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'user_created', 
            "User created: {$username}", 
            $newUserId, 
            array_merge($metadata, ['action' => 'create', 'username' => $username])
        );
    }

    public function logUserUpdated($userId, $projectId, $updatedUserId, $username, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'user_updated', 
            "User updated: {$username}", 
            $updatedUserId, 
            array_merge($metadata, ['action' => 'update', 'username' => $username])
        );
    }

    public function logUserDeleted($userId, $projectId, $deletedUserId, $username, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'user_deleted', 
            "User deleted: {$username}", 
            $deletedUserId, 
            array_merge($metadata, ['action' => 'delete', 'username' => $username])
        );
    }

    public function logUserRoleChanged($userId, $projectId, $targetUserId, $username, $oldRole, $newRole, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'user_role_changed', 
            "User role changed: {$username} from {$oldRole} to {$newRole}", 
            $targetUserId, 
            array_merge($metadata, ['action' => 'role_change', 'username' => $username, 'old_role' => $oldRole, 'new_role' => $newRole])
        );
    }

    // ===== FEEDBACK ACTIVITIES =====
    public function logFeedbackCreated($userId, $projectId, $feedbackId, $feedbackTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'feedback_created', 
            "Feedback created: {$feedbackTitle}", 
            $feedbackId, 
            array_merge($metadata, ['action' => 'create'])
        );
    }

    public function logFeedbackUpdated($userId, $projectId, $feedbackId, $feedbackTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'feedback_updated', 
            "Feedback updated: {$feedbackTitle}", 
            $feedbackId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logFeedbackDeleted($userId, $projectId, $feedbackId, $feedbackTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'feedback_deleted', 
            "Feedback deleted: {$feedbackTitle}", 
            $feedbackId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    public function logFeedbackDismissed($userId, $projectId, $feedbackId, $feedbackTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'feedback_dismissed', 
            "Feedback dismissed: {$feedbackTitle}", 
            $feedbackId, 
            array_merge($metadata, ['action' => 'dismiss'])
        );
    }

    // ===== MEETING ACTIVITIES =====
    public function logMeetingCreated($userId, $projectId, $meetingId, $meetingTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'meeting_created', 
            "Meeting created: {$meetingTitle}", 
            $meetingId, 
            array_merge($metadata, ['action' => 'create'])
        );
    }

    public function logMeetingUpdated($userId, $projectId, $meetingId, $meetingTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'meeting_updated', 
            "Meeting updated: {$meetingTitle}", 
            $meetingId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logMeetingDeleted($userId, $projectId, $meetingId, $meetingTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'meeting_deleted', 
            "Meeting deleted: {$meetingTitle}", 
            $meetingId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    public function logMeetingJoined($userId, $projectId, $meetingId, $meetingTitle, $participantName, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'meeting_joined', 
            "Meeting joined: {$participantName} joined {$meetingTitle}", 
            $meetingId, 
            array_merge($metadata, ['action' => 'join', 'participant' => $participantName])
        );
    }

    public function logMeetingLeft($userId, $projectId, $meetingId, $meetingTitle, $participantName, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'meeting_left', 
            "Meeting left: {$participantName} left {$meetingTitle}", 
            $meetingId, 
            array_merge($metadata, ['action' => 'leave', 'participant' => $participantName])
        );
    }

    // ===== MESSAGE ACTIVITIES =====
    public function logMessageSent($userId, $projectId, $messageId, $messageContent, $chatGroup = null, $metadata = []) {
        $description = $chatGroup ? "Message sent in {$chatGroup}: " . substr($messageContent, 0, 50) . "..." : "Message sent: " . substr($messageContent, 0, 50) . "...";
        return $this->logActivity(
            $userId, 
            $projectId, 
            'message_sent', 
            $description, 
            $messageId, 
            array_merge($metadata, ['action' => 'send', 'chat_group' => $chatGroup])
        );
    }

    public function logMessageUpdated($userId, $projectId, $messageId, $messageContent, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'message_updated', 
            "Message updated: " . substr($messageContent, 0, 50) . "...", 
            $messageId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logMessageDeleted($userId, $projectId, $messageId, $messageContent, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'message_deleted', 
            "Message deleted: " . substr($messageContent, 0, 50) . "...", 
            $messageId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    public function logMessagePinned($userId, $projectId, $messageId, $messageContent, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'message_pinned', 
            "Message pinned: " . substr($messageContent, 0, 50) . "...", 
            $messageId, 
            array_merge($metadata, ['action' => 'pin'])
        );
    }

    public function logMessageUnpinned($userId, $projectId, $messageId, $messageContent, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'message_unpinned', 
            "Message unpinned: " . substr($messageContent, 0, 50) . "...", 
            $messageId, 
            array_merge($metadata, ['action' => 'unpin'])
        );
    }

    // ===== ANNOUNCEMENT ACTIVITIES =====
    public function logAnnouncementCreated($userId, $projectId, $announcementId, $announcementTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'announcement_created', 
            "Announcement created: {$announcementTitle}", 
            $announcementId, 
            array_merge($metadata, ['action' => 'create'])
        );
    }

    public function logAnnouncementUpdated($userId, $projectId, $announcementId, $announcementTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'announcement_updated', 
            "Announcement updated: {$announcementTitle}", 
            $announcementId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logAnnouncementDeleted($userId, $projectId, $announcementId, $announcementTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'announcement_deleted', 
            "Announcement deleted: {$announcementTitle}", 
            $announcementId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    public function logAnnouncementBroadcast($userId, $projectId, $announcementId, $announcementTitle, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'announcement_broadcast', 
            "Announcement broadcast: {$announcementTitle}", 
            $announcementId, 
            array_merge($metadata, ['action' => 'broadcast'])
        );
    }

    // ===== GENERAL ACTIVITIES =====
    public function logCommentAdded($userId, $projectId, $commentId, $commentContent, $relatedEntity = null, $metadata = []) {
        $description = $relatedEntity ? "Comment added to {$relatedEntity}: " . substr($commentContent, 0, 50) . "..." : "Comment added: " . substr($commentContent, 0, 50) . "...";
        return $this->logActivity(
            $userId, 
            $projectId, 
            'comment_added', 
            $description, 
            $commentId, 
            array_merge($metadata, ['action' => 'add', 'related_entity' => $relatedEntity])
        );
    }

    public function logCommentUpdated($userId, $projectId, $commentId, $commentContent, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'comment_updated', 
            "Comment updated: " . substr($commentContent, 0, 50) . "...", 
            $commentId, 
            array_merge($metadata, ['action' => 'update'])
        );
    }

    public function logCommentDeleted($userId, $projectId, $commentId, $commentContent, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'comment_deleted', 
            "Comment deleted: " . substr($commentContent, 0, 50) . "...", 
            $commentId, 
            array_merge($metadata, ['action' => 'delete'])
        );
    }

    public function logFileUploaded($userId, $projectId, $fileId, $fileName, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'file_uploaded', 
            "File uploaded: {$fileName}", 
            $fileId, 
            array_merge($metadata, ['action' => 'upload', 'filename' => $fileName])
        );
    }

    public function logFileDeleted($userId, $projectId, $fileId, $fileName, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'file_deleted', 
            "File deleted: {$fileName}", 
            $fileId, 
            array_merge($metadata, ['action' => 'delete', 'filename' => $fileName])
        );
    }

    public function logSettingsUpdated($userId, $projectId, $settingsType, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'settings_updated', 
            "Settings updated: {$settingsType}", 
            null, 
            array_merge($metadata, ['action' => 'update', 'settings_type' => $settingsType])
        );
    }

    public function logMilestoneReached($userId, $projectId, $milestoneId, $milestoneName, $metadata = []) {
        return $this->logActivity(
            $userId, 
            $projectId, 
            'milestone_reached', 
            "Milestone reached: {$milestoneName}", 
            $milestoneId, 
            array_merge($metadata, ['action' => 'milestone', 'milestone_name' => $milestoneName])
        );
    }

    /**
     * Invalidate activity caches
     */
    private function invalidateActivityCaches($projectId, $userId) {
        try {
            // Clear project-specific caches
            if ($projectId) {
                $this->clearCache("project_activities_{$projectId}_*");
                $this->clearCache("project_activity_stats_{$projectId}");
            }
            
            // Clear user-specific caches
            $this->clearCache("user_activities_{$userId}_*");
            
            // Clear general activity caches
            $this->clearCache("activities_*");
        } catch (Exception $e) {
            error_log("Error invalidating activity caches: " . $e->getMessage());
        }
    }
}
?>