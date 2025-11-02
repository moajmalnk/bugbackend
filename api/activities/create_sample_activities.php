<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';

class SampleActivityCreator extends BaseAPI {
    private $logger;
    
    public function __construct() {
        parent::__construct();
        $this->logger = ActivityLogger::getInstance();
    }
    
    public function createSampleActivities() {
        try {
            // Get a sample project and user for testing
            $projectQuery = "SELECT id, name FROM projects LIMIT 1";
            $projectStmt = $this->conn->prepare($projectQuery);
            $projectStmt->execute();
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$project) {
                throw new Exception("No projects found. Please create a project first.");
            }
            
            $userQuery = "SELECT id, username FROM users LIMIT 1";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute();
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception("No users found. Please create a user first.");
            }
            
            $projectId = $project['id'];
            $userId = $user['id'];
            $projectName = $project['name'];
            $username = $user['username'];
            
            echo "Creating sample activities for project: {$projectName} (ID: {$projectId})\n";
            echo "Using user: {$username} (ID: {$userId})\n\n";
            
            // Bug Activities
            echo "Creating Bug Activities...\n";
            $this->logger->logBugCreated($userId, $projectId, 'sample-bug-1', 'Sample Bug: Login Issue', [
                'priority' => 'high',
                'status' => 'open',
                'severity' => 'critical'
            ]);
            
            $this->logger->logBugReported($userId, $projectId, 'sample-bug-2', 'Sample Bug: UI Glitch', [
                'priority' => 'medium',
                'status' => 'reported',
                'severity' => 'minor'
            ]);
            
            $this->logger->logBugUpdated($userId, $projectId, 'sample-bug-3', 'Sample Bug: Database Error', [
                'priority' => 'high',
                'status' => 'in_progress',
                'updated_fields' => ['status', 'priority']
            ]);
            
            $this->logger->logBugFixed($userId, $projectId, 'sample-bug-4', 'Sample Bug: Memory Leak', [
                'priority' => 'high',
                'status' => 'fixed',
                'fix_version' => '1.2.3'
            ]);
            
            $this->logger->logBugAssigned($userId, $projectId, 'sample-bug-5', 'Sample Bug: Performance Issue', $userId, [
                'priority' => 'medium',
                'status' => 'assigned'
            ]);
            
            $this->logger->logBugDeleted($userId, $projectId, 'sample-bug-6', 'Sample Bug: Duplicate Report', [
                'priority' => 'low',
                'deleted_reason' => 'duplicate'
            ]);
            
            $this->logger->logBugStatusChanged($userId, $projectId, 'sample-bug-7', 'Sample Bug: Feature Request', 'open', 'closed', [
                'priority' => 'low',
                'resolution' => 'wont_fix'
            ]);
            
            // Task Activities
            echo "Creating Task Activities...\n";
            $this->logger->logTaskCreated($userId, $projectId, 'sample-task-1', 'Sample Task: Implement Authentication', [
                'priority' => 'high',
                'status' => 'todo',
                'due_date' => '2024-01-15'
            ]);
            
            $this->logger->logTaskUpdated($userId, $projectId, 'sample-task-2', 'Sample Task: Update Documentation', [
                'priority' => 'medium',
                'status' => 'in_progress',
                'updated_fields' => ['status', 'description']
            ]);
            
            $this->logger->logTaskCompleted($userId, $projectId, 'sample-task-3', 'Sample Task: Code Review', [
                'priority' => 'high',
                'status' => 'completed',
                'completion_time' => '2 hours'
            ]);
            
            $this->logger->logTaskDeleted($userId, $projectId, 'sample-task-4', 'Sample Task: Obsolete Feature', [
                'priority' => 'low',
                'deleted_reason' => 'obsolete'
            ]);
            
            $this->logger->logTaskAssigned($userId, $projectId, 'sample-task-5', 'Sample Task: Bug Investigation', $userId, [
                'priority' => 'high',
                'status' => 'assigned'
            ]);
            
            // Update Activities
            echo "Creating Update Activities...\n";
            $this->logger->logUpdateCreated($userId, $projectId, 'sample-update-1', 'Sample Update: Version 1.2.0 Release Notes', [
                'version' => '1.2.0',
                'type' => 'release_notes'
            ]);
            
            $this->logger->logUpdateUpdated($userId, $projectId, 'sample-update-2', 'Sample Update: Security Patch Notes', [
                'version' => '1.2.1',
                'type' => 'security_patch',
                'updated_fields' => ['content', 'version']
            ]);
            
            $this->logger->logUpdateDeleted($userId, $projectId, 'sample-update-3', 'Sample Update: Deprecated Feature Notice', [
                'version' => '1.1.0',
                'deleted_reason' => 'outdated'
            ]);
            
            // Fix Activities
            echo "Creating Fix Activities...\n";
            $this->logger->logFixCreated($userId, $projectId, 'sample-fix-1', 'Sample Fix: Memory Optimization', [
                'type' => 'performance',
                'impact' => 'high'
            ]);
            
            $this->logger->logFixUpdated($userId, $projectId, 'sample-fix-2', 'Sample Fix: Security Vulnerability', [
                'type' => 'security',
                'severity' => 'critical',
                'updated_fields' => ['description', 'severity']
            ]);
            
            $this->logger->logFixDeleted($userId, $projectId, 'sample-fix-3', 'Sample Fix: Temporary Workaround', [
                'type' => 'workaround',
                'deleted_reason' => 'replaced_by_permanent_fix'
            ]);
            
            // Project Activities
            echo "Creating Project Activities...\n";
            $this->logger->logProjectUpdated($userId, $projectId, $projectName, [
                'updated_fields' => ['description', 'status'],
                'version' => '2.0.0'
            ]);
            
            $this->logger->logMemberAdded($userId, $projectId, 'sample-user-1', 'John Doe', 'developer', [
                'role' => 'developer',
                'permissions' => ['read', 'write']
            ]);
            
            $this->logger->logMemberRemoved($userId, $projectId, 'sample-user-2', 'Jane Smith', 'tester', [
                'role' => 'tester',
                'removal_reason' => 'project_completed'
            ]);
            
            // User Activities
            echo "Creating User Activities...\n";
            $this->logger->logUserCreated($userId, $projectId, 'sample-user-3', 'New Developer', [
                'role' => 'developer',
                'department' => 'engineering'
            ]);
            
            $this->logger->logUserUpdated($userId, $projectId, 'sample-user-4', 'Updated Tester', [
                'updated_fields' => ['email', 'role'],
                'role' => 'senior_tester'
            ]);
            
            $this->logger->logUserRoleChanged($userId, $projectId, 'sample-user-5', 'Promoted User', 'developer', 'senior_developer', [
                'old_role' => 'developer',
                'new_role' => 'senior_developer',
                'promotion_date' => '2024-01-01'
            ]);
            
            // Feedback Activities
            echo "Creating Feedback Activities...\n";
            $this->logger->logFeedbackCreated($userId, $projectId, 'sample-feedback-1', 'Sample Feedback: UI Improvement Suggestion', [
                'type' => 'suggestion',
                'priority' => 'medium'
            ]);
            
            $this->logger->logFeedbackUpdated($userId, $projectId, 'sample-feedback-2', 'Sample Feedback: Bug Report Update', [
                'type' => 'bug_report',
                'status' => 'under_review',
                'updated_fields' => ['description', 'status']
            ]);
            
            $this->logger->logFeedbackDismissed($userId, $projectId, 'sample-feedback-3', 'Sample Feedback: Feature Request', [
                'type' => 'feature_request',
                'dismissal_reason' => 'not_feasible'
            ]);
            
            // Meeting Activities
            echo "Creating Meeting Activities...\n";
            $this->logger->logMeetingCreated($userId, $projectId, 'sample-meeting-1', 'Sample Meeting: Sprint Planning', [
                'type' => 'sprint_planning',
                'duration' => '2 hours',
                'attendees' => 5
            ]);
            
            $this->logger->logMeetingUpdated($userId, $projectId, 'sample-meeting-2', 'Sample Meeting: Code Review Session', [
                'type' => 'code_review',
                'duration' => '1 hour',
                'updated_fields' => ['time', 'duration']
            ]);
            
            $this->logger->logMeetingJoined($userId, $projectId, 'sample-meeting-3', 'Sample Meeting: Daily Standup', [
                'type' => 'daily_standup',
                'duration' => '30 minutes'
            ]);
            
            $this->logger->logMeetingLeft($userId, $projectId, 'sample-meeting-4', 'Sample Meeting: Retrospective', [
                'type' => 'retrospective',
                'duration' => '1 hour'
            ]);
            
            // Message Activities
            echo "Creating Message Activities...\n";
            $this->logger->logMessageSent($userId, $projectId, 'sample-message-1', 'Sample Message: Project Update', [
                'channel' => 'general',
                'type' => 'text'
            ]);
            
            $this->logger->logMessageUpdated($userId, $projectId, 'sample-message-2', 'Sample Message: Bug Discussion', [
                'channel' => 'bugs',
                'type' => 'text',
                'updated_fields' => ['content']
            ]);
            
            $this->logger->logMessagePinned($userId, $projectId, 'sample-message-3', 'Sample Message: Important Announcement', [
                'channel' => 'announcements',
                'type' => 'announcement'
            ]);
            
            // Announcement Activities
            echo "Creating Announcement Activities...\n";
            $this->logger->logAnnouncementCreated($userId, $projectId, 'sample-announcement-1', 'Sample Announcement: System Maintenance', [
                'type' => 'maintenance',
                'priority' => 'high'
            ]);
            
            $this->logger->logAnnouncementBroadcast($userId, $projectId, 'sample-announcement-2', 'Sample Announcement: New Feature Release', [
                'type' => 'feature_release',
                'version' => '2.0.0'
            ]);
            
            // General Activities
            echo "Creating General Activities...\n";
            $this->logger->logCommentAdded($userId, $projectId, 'sample-comment-1', 'Sample Comment: Great work on the fix!', 'sample-bug-1', [
                'type' => 'praise',
                'context' => 'bug_fix'
            ]);
            
            $this->logger->logFileUploaded($userId, $projectId, 'sample-file-1', 'Sample File: design-mockup.png', [
                'type' => 'image',
                'size' => '2.5MB',
                'category' => 'design'
            ]);
            
            $this->logger->logSettingsUpdated($userId, $projectId, 'Project Settings', [
                'updated_fields' => ['notifications', 'permissions'],
                'category' => 'project_settings'
            ]);
            
            $this->logger->logMilestoneReached($userId, $projectId, 'First Release Milestone', [
                'milestone_type' => 'release',
                'version' => '1.0.0',
                'achievement_date' => '2024-01-15'
            ]);
            
            echo "\n✅ Successfully created 50+ sample activities covering all activity types!\n";
            echo "Activity types created:\n";
            echo "- Bug Activities: 7 activities\n";
            echo "- Task Activities: 5 activities\n";
            echo "- Update Activities: 3 activities\n";
            echo "- Fix Activities: 3 activities\n";
            echo "- Project Activities: 3 activities\n";
            echo "- User Activities: 3 activities\n";
            echo "- Feedback Activities: 3 activities\n";
            echo "- Meeting Activities: 4 activities\n";
            echo "- Message Activities: 3 activities\n";
            echo "- Announcement Activities: 2 activities\n";
            echo "- General Activities: 4 activities\n";
            echo "\nTotal: 40+ sample activities created!\n";
            
        } catch (Exception $e) {
            echo "❌ Error creating sample activities: " . $e->getMessage() . "\n";
            error_log("SampleActivityCreator error: " . $e->getMessage());
        }
    }
}

// Run the script if called directly
if (basename($_SERVER['PHP_SELF']) === 'create_sample_activities.php') {
    $creator = new SampleActivityCreator();
    $creator->createSampleActivities();
}
?>
