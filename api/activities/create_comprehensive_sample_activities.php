<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/../../config/utils.php';

/**
 * Create comprehensive sample activities for all activity types
 * This script will populate the project_activities table with sample data
 * covering all 50+ activity types mentioned in the documentation
 */

class SampleActivityCreator {
    private $conn;
    private $logger;
    private $utils;

    public function __construct() {
        $baseAPI = new BaseAPI();
        $this->conn = $baseAPI->getConnection();
        $this->logger = ActivityLogger::getInstance();
        $this->utils = new Utils();
    }

    public function createAllSampleActivities() {
        echo "Creating comprehensive sample activities...\n";
        
        try {
            // Get some sample users and projects
            $users = $this->getSampleUsers();
            $projects = $this->getSampleProjects();
            
            if (empty($users) || empty($projects)) {
                echo "Error: No users or projects found. Please create some users and projects first.\n";
                return;
            }

            $createdCount = 0;

            // Create bug activities
            $createdCount += $this->createBugActivities($users, $projects);
            
            // Create task activities
            $createdCount += $this->createTaskActivities($users, $projects);
            
            // Create update activities
            $createdCount += $this->createUpdateActivities($users, $projects);
            
            // Create fix activities
            $createdCount += $this->createFixActivities($users, $projects);
            
            // Create project activities (already exist, but add more variety)
            $createdCount += $this->createProjectActivities($users, $projects);
            
            // Create user activities
            $createdCount += $this->createUserActivities($users, $projects);
            
            // Create feedback activities
            $createdCount += $this->createFeedbackActivities($users, $projects);
            
            // Create meeting activities
            $createdCount += $this->createMeetingActivities($users, $projects);
            
            // Create message activities
            $createdCount += $this->createMessageActivities($users, $projects);
            
            // Create announcement activities
            $createdCount += $this->createAnnouncementActivities($users, $projects);
            
            // Create general activities
            $createdCount += $this->createGeneralActivities($users, $projects);

            echo "Successfully created {$createdCount} sample activities!\n";
            echo "All activity types are now represented in the database.\n";
            
        } catch (Exception $e) {
            echo "Error creating sample activities: " . $e->getMessage() . "\n";
        }
    }

    private function getSampleUsers() {
        $stmt = $this->conn->query("SELECT id, username, role FROM users LIMIT 5");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getSampleProjects() {
        $stmt = $this->conn->query("SELECT id, name FROM projects LIMIT 3");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function createBugActivities($users, $projects) {
        echo "Creating bug activities...\n";
        $count = 0;
        
        $bugTitles = [
            'Login authentication issue',
            'Database connection timeout',
            'UI responsiveness problem',
            'API endpoint error',
            'File upload failure',
            'Email notification bug',
            'Search functionality issue',
            'Mobile view layout problem'
        ];

        foreach ($bugTitles as $i => $title) {
            $user = $users[array_rand($users)];
            $project = $projects[array_rand($projects)];
            $bugId = $this->utils->generateUUID();
            
            $activities = [
                'bug_created' => "Bug created: {$title}",
                'bug_reported' => "Bug reported: {$title}",
                'bug_updated' => "Bug updated: {$title}",
                'bug_assigned' => "Bug assigned: {$title}",
                'bug_status_changed' => "Bug status changed from open to in_progress: {$title}",
                'bug_fixed' => "Bug fixed: {$title}",
                'bug_deleted' => "Bug deleted: {$title}"
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $user['id'],
                    $project['id'],
                    $type,
                    $description,
                    $bugId,
                    [
                        'sample' => true,
                        'severity' => ['low', 'medium', 'high'][array_rand([0, 1, 2])],
                        'priority' => ['low', 'medium', 'high'][array_rand([0, 1, 2])]
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createTaskActivities($users, $projects) {
        echo "Creating task activities...\n";
        $count = 0;
        
        $taskTitles = [
            'Implement user authentication',
            'Design new dashboard',
            'Optimize database queries',
            'Add responsive design',
            'Write unit tests',
            'Update documentation',
            'Fix security vulnerabilities',
            'Add new features'
        ];

        foreach ($taskTitles as $title) {
            $user = $users[array_rand($users)];
            $project = $projects[array_rand($projects)];
            $taskId = $this->utils->generateUUID();
            
            $activities = [
                'task_created' => "Task created: {$title}",
                'task_updated' => "Task updated: {$title}",
                'task_assigned' => "Task assigned: {$title}",
                'task_completed' => "Task completed: {$title}",
                'task_deleted' => "Task deleted: {$title}"
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $user['id'],
                    $project['id'],
                    $type,
                    $description,
                    $taskId,
                    [
                        'sample' => true,
                        'priority' => ['low', 'medium', 'high'][array_rand([0, 1, 2])],
                        'estimated_hours' => rand(1, 8)
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createUpdateActivities($users, $projects) {
        echo "Creating update activities...\n";
        $count = 0;
        
        $updateTitles = [
            'Version 2.1.0 Release',
            'Security Patch Update',
            'Performance Improvements',
            'Bug Fixes Release',
            'Feature Enhancement Update',
            'Database Migration',
            'UI/UX Improvements',
            'API Version Update'
        ];

        foreach ($updateTitles as $title) {
            $user = $users[array_rand($users)];
            $project = $projects[array_rand($projects)];
            $updateId = $this->utils->generateUUID();
            
            $activities = [
                'update_created' => "Update created: {$title}",
                'update_updated' => "Update updated: {$title}",
                'update_deleted' => "Update deleted: {$title}"
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $user['id'],
                    $project['id'],
                    $type,
                    $description,
                    $updateId,
                    [
                        'sample' => true,
                        'version' => 'v' . rand(1, 3) . '.' . rand(0, 9) . '.' . rand(0, 9),
                        'type' => ['major', 'minor', 'patch'][array_rand([0, 1, 2])]
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createFixActivities($users, $projects) {
        echo "Creating fix activities...\n";
        $count = 0;
        
        $fixTitles = [
            'Memory leak fix',
            'Authentication bypass fix',
            'Database connection fix',
            'UI rendering fix',
            'API response fix',
            'File permission fix',
            'Cache invalidation fix',
            'Error handling fix'
        ];

        foreach ($fixTitles as $title) {
            $user = $users[array_rand($users)];
            $project = $projects[array_rand($projects)];
            $fixId = $this->utils->generateUUID();
            
            $activities = [
                'fix_created' => "Fix created: {$title}",
                'fix_updated' => "Fix updated: {$title}",
                'fix_deleted' => "Fix deleted: {$title}"
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $user['id'],
                    $project['id'],
                    $type,
                    $description,
                    $fixId,
                    [
                        'sample' => true,
                        'severity' => ['low', 'medium', 'high', 'critical'][array_rand([0, 1, 2, 3])],
                        'tested' => [true, false][array_rand([0, 1])]
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createProjectActivities($users, $projects) {
        echo "Creating additional project activities...\n";
        $count = 0;
        
        foreach ($projects as $project) {
            $user = $users[array_rand($users)];
            
            // Member activities
            $memberUsernames = ['john_doe', 'jane_smith', 'bob_wilson', 'alice_brown'];
            foreach (array_slice($memberUsernames, 0, rand(2, 4)) as $memberUsername) {
                $activities = [
                    'member_added' => "Member added: {$memberUsername}",
                    'member_removed' => "Member removed: {$memberUsername}"
                ];

                foreach ($activities as $type => $description) {
                    $this->logger->logActivity(
                        $user['id'],
                        $project['id'],
                        $type,
                        $description,
                        null,
                        [
                            'sample' => true,
                            'member_username' => $memberUsername,
                            'role' => ['admin', 'developer', 'tester'][array_rand([0, 1, 2])]
                        ]
                    );
                    $count++;
                }
            }

            // Project updates
            $this->logger->logActivity(
                $user['id'],
                $project['id'],
                'project_updated',
                "Project updated: {$project['name']}",
                $project['id'],
                [
                    'sample' => true,
                    'updated_fields' => ['name', 'description', 'settings']
                ]
            );
            $count++;
        }
        
        return $count;
    }

    private function createUserActivities($users, $projects) {
        echo "Creating user activities...\n";
        $count = 0;
        
        foreach ($users as $user) {
            $adminUser = $users[array_rand($users)];
            
            $activities = [
                'user_created' => "User created: {$user['username']}",
                'user_updated' => "User updated: {$user['username']}",
                'user_role_changed' => "User role changed: {$user['username']} from developer to tester",
                'user_deleted' => "User deleted: {$user['username']}"
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $adminUser['id'],
                    null, // User activities are not project-specific
                    $type,
                    $description,
                    $user['id'],
                    [
                        'sample' => true,
                        'username' => $user['username'],
                        'role' => $user['role']
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createFeedbackActivities($users, $projects) {
        echo "Creating feedback activities...\n";
        $count = 0;
        
        $feedbackTitles = [
            'User Experience Feedback',
            'Feature Request',
            'Bug Report Feedback',
            'Performance Feedback',
            'UI/UX Improvement Suggestion',
            'System Stability Feedback',
            'Accessibility Feedback',
            'Mobile App Feedback'
        ];

        foreach ($feedbackTitles as $title) {
            $user = $users[array_rand($users)];
            $feedbackId = $this->utils->generateUUID();
            
            $activities = [
                'feedback_created' => "Feedback created: {$title}",
                'feedback_updated' => "Feedback updated: {$title}",
                'feedback_deleted' => "Feedback deleted: {$title}",
                'feedback_dismissed' => "Feedback dismissed: {$title}"
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $user['id'],
                    null, // Feedback is not project-specific
                    $type,
                    $description,
                    $feedbackId,
                    [
                        'sample' => true,
                        'rating' => rand(1, 5),
                        'category' => ['bug', 'feature', 'improvement'][array_rand([0, 1, 2])]
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createMeetingActivities($users, $projects) {
        echo "Creating meeting activities...\n";
        $count = 0;
        
        $meetingTitles = [
            'Sprint Planning Meeting',
            'Bug Review Session',
            'Code Review Meeting',
            'Project Status Update',
            'Feature Planning Session',
            'Retrospective Meeting',
            'Client Demo Meeting',
            'Technical Discussion'
        ];

        foreach ($meetingTitles as $title) {
            $user = $users[array_rand($users)];
            $meetingId = $this->utils->generateUUID();
            
            $activities = [
                'meeting_created' => "Meeting created: {$title}",
                'meeting_updated' => "Meeting updated: {$title}",
                'meeting_deleted' => "Meeting deleted: {$title}",
                'meeting_joined' => "Meeting joined: John joined {$title}",
                'meeting_left' => "Meeting left: John left {$title}"
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $user['id'],
                    null, // Meetings are not project-specific
                    $type,
                    $description,
                    $meetingId,
                    [
                        'sample' => true,
                        'meeting_code' => strtoupper(substr(uniqid(), -8)),
                        'duration' => rand(30, 120)
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createMessageActivities($users, $projects) {
        echo "Creating message activities...\n";
        $count = 0;
        
        $messageContents = [
            'Great work on the latest feature!',
            'I found an issue with the login page',
            'Can we schedule a meeting for tomorrow?',
            'The database query needs optimization',
            'New requirements from the client',
            'Testing completed successfully',
            'Deployment scheduled for tonight',
            'Code review comments added'
        ];

        foreach ($messageContents as $content) {
            $user = $users[array_rand($users)];
            $messageId = $this->utils->generateUUID();
            
            $activities = [
                'message_sent' => "Message sent in General Chat: " . substr($content, 0, 50) . "...",
                'message_updated' => "Message updated: " . substr($content, 0, 50) . "...",
                'message_deleted' => "Message deleted: " . substr($content, 0, 50) . "...",
                'message_pinned' => "Message pinned: " . substr($content, 0, 50) . "...",
                'message_unpinned' => "Message unpinned: " . substr($content, 0, 50) . "..."
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $user['id'],
                    null, // Messages are not project-specific
                    $type,
                    $description,
                    $messageId,
                    [
                        'sample' => true,
                        'chat_group' => 'General Chat',
                        'message_type' => 'text'
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createAnnouncementActivities($users, $projects) {
        echo "Creating announcement activities...\n";
        $count = 0;
        
        $announcementTitles = [
            'System Maintenance Notice',
            'New Feature Release',
            'Security Update Required',
            'Holiday Schedule Update',
            'Policy Changes',
            'Training Session Announcement',
            'Server Migration Notice',
            'Emergency Maintenance Alert'
        ];

        foreach ($announcementTitles as $title) {
            $user = $users[array_rand($users)];
            $announcementId = $this->utils->generateUUID();
            
            $activities = [
                'announcement_created' => "Announcement created: {$title}",
                'announcement_updated' => "Announcement updated: {$title}",
                'announcement_deleted' => "Announcement deleted: {$title}",
                'announcement_broadcast' => "Announcement broadcast: {$title}"
            ];

            foreach ($activities as $type => $description) {
                $this->logger->logActivity(
                    $user['id'],
                    null, // Announcements are not project-specific
                    $type,
                    $description,
                    $announcementId,
                    [
                        'sample' => true,
                        'priority' => ['low', 'medium', 'high'][array_rand([0, 1, 2])],
                        'is_active' => true
                    ]
                );
                $count++;
            }
        }
        
        return $count;
    }

    private function createGeneralActivities($users, $projects) {
        echo "Creating general activities...\n";
        $count = 0;
        
        $generalActivities = [
            'comment_added' => 'Comment added to bug report: "This looks like a database issue"',
            'comment_updated' => 'Comment updated: "Updated with more details"',
            'comment_deleted' => 'Comment deleted: "Removed outdated information"',
            'file_uploaded' => 'File uploaded: screenshot.png',
            'file_deleted' => 'File deleted: old_document.pdf',
            'settings_updated' => 'Settings updated: Notification preferences',
            'milestone_reached' => 'Milestone reached: Sprint 1 Completion'
        ];

        foreach ($generalActivities as $type => $description) {
            $user = $users[array_rand($users)];
            $project = $projects[array_rand($projects)];
            $relatedId = $this->utils->generateUUID();
            
            $this->logger->logActivity(
                $user['id'],
                $project['id'],
                $type,
                $description,
                $relatedId,
                [
                    'sample' => true,
                    'category' => 'general'
                ]
            );
            $count++;
        }
        
        return $count;
    }
}

// Run the script
if (php_sapi_name() === 'cli') {
    $creator = new SampleActivityCreator();
    $creator->createAllSampleActivities();
} else {
    echo "This script should be run from the command line.\n";
}
?>
