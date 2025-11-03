<?php
/**
 * NotificationManager - Centralized notification service
 * Handles creation and distribution of notifications to users
 */

require_once __DIR__ . '/BaseAPI.php';
require_once __DIR__ . '/projects/ProjectMemberController.php';
require_once __DIR__ . '/../config/utils.php';

class NotificationManager extends BaseAPI {
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
     * Get project members by role
     * 
     * @param string $projectId The project ID
     * @param string|null $role Filter by role (developer, tester, or null for all)
     * @return array List of user IDs
     */
    private function getProjectMembersByRole($projectId, $role = null) {
        try {
            if (empty($projectId)) {
                error_log("NotificationManager::getProjectMembersByRole - WARNING: Empty projectId provided");
                return [];
            }
            
            $pmc = new ProjectMemberController();
            $members = $pmc->getProjectMembers($projectId);
            
            error_log("NotificationManager::getProjectMembersByRole - ProjectId: $projectId, Role: " . ($role ?? 'all') . ", Found members: " . count($members));
            
            $userIds = [];
            foreach ($members as $member) {
                if ($role === null) {
                    // Get all project members
                    $userIds[] = (string)$member['user_id'];
                } elseif ($member['user_role'] === $role) {
                    // Match by user's system role (developer, tester, admin)
                    $userIds[] = (string)$member['user_id'];
                    error_log("NotificationManager::getProjectMembersByRole - Matched user {$member['user_id']} with role {$member['user_role']}");
                }
                // Note: We don't check pm.role (project_members.role) as that's the project-specific role,
                // not the system role. We need to match by user's actual role (developer/tester/admin).
            }
            
            $uniqueUserIds = array_unique($userIds);
            error_log("NotificationManager::getProjectMembersByRole - Returning " . count($uniqueUserIds) . " unique userIds");
            return $uniqueUserIds;
        } catch (Exception $e) {
            error_log("Error getting project members by role: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * Get all admin user IDs
     * 
     * @return array List of admin user IDs
     */
    private function getAllAdmins() {
        try {
            $query = "SELECT id FROM users WHERE role = 'admin'";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
            // Convert to strings for consistency
            $admins = array_map(function($id) { return (string)$id; }, $admins);
            error_log("NotificationManager::getAllAdmins - Found " . count($admins) . " admins: " . json_encode($admins));
            return $admins;
        } catch (Exception $e) {
            error_log("Error getting all admins: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get project developers
     * 
     * @param string $projectId The project ID
     * @return array List of developer user IDs
     */
    private function getProjectDevelopers($projectId) {
        return $this->getProjectMembersByRole($projectId, 'developer');
    }

    /**
     * Get project testers
     * 
     * @param string $projectId The project ID
     * @return array List of tester user IDs
     */
    private function getProjectTesters($projectId) {
        return $this->getProjectMembersByRole($projectId, 'tester');
    }

    /**
     * Create notification and distribute to users
     * 
     * @param string $type Notification type
     * @param string $title Notification title
     * @param string $message Notification message
     * @param array $userIds List of user IDs to notify
     * @param array $data Additional data (entity_type, entity_id, project_id, bug_id, bug_title, status, created_by)
     * @return int|false Notification ID or false on failure
     */
    public function createNotification($type, $title, $message, $userIds, $data = []) {
        try {
            error_log("NotificationManager::createNotification - Type: $type, Title: $title, UserIds count: " . count($userIds));
            error_log("NotificationManager::createNotification - UserIds: " . json_encode($userIds));
            
            if (empty($userIds)) {
                error_log("NotificationManager::createNotification - ERROR: No users to notify for notification: $title");
                return false;
            }

            if (!$this->conn) {
                error_log("NotificationManager::createNotification - ERROR: Database connection is null");
                return false;
            }

            $this->conn->beginTransaction();
            error_log("NotificationManager::createNotification - Transaction started");

            // Insert notification
            $insertNotificationSQL = "
                INSERT INTO notifications (
                    type, title, message, 
                    entity_type, entity_id, project_id,
                    bug_id, bug_title, status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";

            $stmt = $this->conn->prepare($insertNotificationSQL);
            if (!$stmt) {
                $errorInfo = $this->conn->errorInfo();
                error_log("NotificationManager::createNotification - ERROR: Failed to prepare statement: " . json_encode($errorInfo));
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return false;
            }
            
            $executeResult = $stmt->execute([
                $type,
                $title,
                $message,
                $data['entity_type'] ?? null,
                $data['entity_id'] ?? null,
                $data['project_id'] ?? null,
                $data['bug_id'] ?? null,
                $data['bug_title'] ?? null,
                $data['status'] ?? null,
                $data['created_by'] ?? 'system'
            ]);
            
            if (!$executeResult) {
                $errorInfo = $stmt->errorInfo();
                error_log("NotificationManager::createNotification - ERROR: Failed to execute notification insert: " . json_encode($errorInfo));
                error_log("NotificationManager::createNotification - SQL: $insertNotificationSQL");
                error_log("NotificationManager::createNotification - Params: " . json_encode([$type, $title, $message, $data['entity_type'] ?? null, $data['entity_id'] ?? null, $data['project_id'] ?? null, $data['bug_id'] ?? null, $data['bug_title'] ?? null, $data['status'] ?? null, $data['created_by'] ?? 'system']));
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return false;
            }

            $notificationId = $this->conn->lastInsertId();
            if (!$notificationId) {
                error_log("NotificationManager::createNotification - ERROR: Failed to get last insert ID");
                if ($this->conn->inTransaction()) {
                    $this->conn->rollBack();
                }
                return false;
            }
            
            error_log("NotificationManager::createNotification - Notification inserted successfully with ID: $notificationId");

            // Distribute to users
            $insertUserNotificationSQL = "
                INSERT INTO user_notifications (notification_id, user_id, `read`, created_at)
                VALUES (?, ?, 0, NOW())
            ";
            $userStmt = $this->conn->prepare($insertUserNotificationSQL);

            $insertedCount = 0;
            $failedUserIds = [];
            
            foreach ($userIds as $userId) {
                try {
                    // Ensure userId is consistently treated as string
                    $userIdStr = (string)$userId;
                    
                    // First verify user exists
                    $userCheck = $this->conn->prepare("SELECT id FROM users WHERE id = ?");
                    $userCheck->execute([$userIdStr]);
                    if (!$userCheck->fetch()) {
                        error_log("NotificationManager::createNotification - WARNING: User $userIdStr does not exist, skipping");
                        $failedUserIds[] = $userIdStr;
                        continue;
                    }
                    
                    $userStmt->execute([$notificationId, $userIdStr]);
                    $insertedCount++;
                    error_log("NotificationManager::createNotification - Successfully inserted notification $notificationId for userId $userIdStr");
                } catch (PDOException $e) {
                    // Skip duplicate entries
                    if ($e->getCode() != 23000) {
                        error_log("NotificationManager::createNotification - Error inserting user notification for userId $userId: " . $e->getMessage());
                        error_log("NotificationManager::createNotification - SQL State: " . $e->errorInfo[0] . ", Error Code: " . $e->errorInfo[1]);
                        $failedUserIds[] = (string)$userId;
                    } else {
                        error_log("NotificationManager::createNotification - Duplicate entry skipped for userId $userId");
                    }
                }
            }
            
            // If no users were inserted successfully, that's a problem
            if ($insertedCount == 0 && !empty($userIds)) {
                error_log("NotificationManager::createNotification - CRITICAL: No users were inserted. Failed user IDs: " . json_encode($failedUserIds));
                // Don't fail completely - at least create the notification record
            }

            $this->conn->commit();
            
            error_log("NotificationManager::createNotification - SUCCESS: ID=$notificationId, Type=$type, Total Users=" . count($userIds) . ", Inserted=$insertedCount");
            return $notificationId;

        } catch (Exception $e) {
            if ($this->conn && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("NotificationManager::createNotification - EXCEPTION: " . $e->getMessage());
            error_log("NotificationManager::createNotification - Exception trace: " . $e->getTraceAsString());
            error_log("NotificationManager::createNotification - Exception file: " . $e->getFile() . ":" . $e->getLine());
            return false;
        } catch (Error $e) {
            if ($this->conn && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("NotificationManager::createNotification - FATAL ERROR: " . $e->getMessage());
            error_log("NotificationManager::createNotification - Error trace: " . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Notify when a bug is created
     * Send to: project developers + admins
     * 
     * @param string $bugId Bug ID
     * @param string $bugTitle Bug title
     * @param string $projectId Project ID
     * @param string $createdBy User ID who created the bug
     * @return int|false Notification ID or false
     */
    public function notifyBugCreated($bugId, $bugTitle, $projectId, $createdBy) {
        // Ensure all IDs are strings
        $bugId = (string)$bugId;
        $projectId = $projectId ? (string)$projectId : null;
        $createdBy = (string)$createdBy;
        
        if (empty($projectId)) {
            error_log("NotificationManager::notifyBugCreated - WARNING: Empty projectId, cannot find project members");
        }
        
        $developers = $this->getProjectDevelopers($projectId);
        $admins = $this->getAllAdmins();
        
        error_log("NotificationManager::notifyBugCreated - BugId: $bugId, ProjectId: $projectId, CreatedBy: $createdBy");
        error_log("NotificationManager::notifyBugCreated - Developers found: " . count($developers) . " - " . json_encode($developers));
        error_log("NotificationManager::notifyBugCreated - Admins found: " . count($admins) . " - " . json_encode($admins));
        
        // Combine and remove duplicates, exclude creator
        $userIds = array_unique(array_merge($developers, $admins));
        $userIds = array_filter($userIds, function($id) use ($createdBy) {
            return (string)$id !== (string)$createdBy;
        });

        error_log("NotificationManager::notifyBugCreated - Final userIds to notify: " . count($userIds) . " - " . json_encode(array_values($userIds)));

        // IMPORTANT: If no users to notify (no developers and no admins), still notify admins only
        // This ensures notifications are never completely skipped
        if (empty($userIds)) {
            error_log("NotificationManager::notifyBugCreated - WARNING: No users found, notifying all admins as fallback");
            $allAdmins = $this->getAllAdmins();
            $userIds = array_filter($allAdmins, function($id) use ($createdBy) {
                return (string)$id !== (string)$createdBy;
            });
            
            // If still empty (only creator is admin), notify the creator anyway
            if (empty($userIds)) {
                error_log("NotificationManager::notifyBugCreated - Only creator is admin, creating notification for creator anyway");
                $userIds = [$createdBy];
            }
        }

        // Get creator name
        $creatorName = $this->getUserName($createdBy);

        // Use 'new_bug' if 'bug_created' is not in the ENUM (fallback for older schemas)
        $notificationType = $this->getValidNotificationType('bug_created', 'new_bug');

        $result = $this->createNotification(
            $notificationType,
            'New Bug Reported',
            "A new bug has been reported: {$bugTitle}",
            array_values($userIds),
            [
                'entity_type' => 'bug',
                'entity_id' => $bugId,
                'project_id' => $projectId,
                'bug_id' => $bugId,
                'bug_title' => $bugTitle,
                'created_by' => $creatorName
            ]
        );
        
        error_log("NotificationManager::notifyBugCreated - Result: " . ($result ? "Success (ID: $result)" : "Failed"));
        return $result;
    }

    /**
     * Notify when a bug is fixed
     * Send to: project testers + admins
     * 
     * @param string $bugId Bug ID
     * @param string $bugTitle Bug title
     * @param string $projectId Project ID
     * @param string $fixedBy User ID who fixed the bug
     * @return int|false Notification ID or false
     */
    public function notifyBugFixed($bugId, $bugTitle, $projectId, $fixedBy) {
        // Ensure all IDs are strings
        $bugId = (string)$bugId;
        $projectId = $projectId ? (string)$projectId : null;
        $fixedBy = (string)$fixedBy;
        
        if (empty($projectId)) {
            error_log("NotificationManager::notifyBugFixed - WARNING: Empty projectId, cannot find project members");
        }
        
        $testers = $this->getProjectTesters($projectId);
        $admins = $this->getAllAdmins();
        
        error_log("NotificationManager::notifyBugFixed - BugId: $bugId, ProjectId: $projectId, FixedBy: $fixedBy");
        error_log("NotificationManager::notifyBugFixed - Testers found: " . count($testers) . " - " . json_encode($testers));
        error_log("NotificationManager::notifyBugFixed - Admins found: " . count($admins) . " - " . json_encode($admins));
        
        // Combine and remove duplicates, exclude fixer
        $userIds = array_unique(array_merge($testers, $admins));
        $userIds = array_filter($userIds, function($id) use ($fixedBy) {
            return (string)$id !== (string)$fixedBy;
        });

        error_log("NotificationManager::notifyBugFixed - Final userIds to notify: " . count($userIds) . " - " . json_encode(array_values($userIds)));

        // IMPORTANT: If no users to notify, still notify all admins (except fixer if fixer is admin)
        // This ensures notifications are never completely skipped
        if (empty($userIds)) {
            error_log("NotificationManager::notifyBugFixed - WARNING: No users found, notifying all admins as fallback");
            $allAdmins = $this->getAllAdmins();
            $userIds = array_filter($allAdmins, function($id) use ($fixedBy) {
                return (string)$id !== (string)$fixedBy;
            });
            
            // If fixer is the only admin, notify them anyway
            if (empty($userIds)) {
                error_log("NotificationManager::notifyBugFixed - Only fixer is admin, creating notification for fixer anyway");
                $userIds = [$fixedBy];
            }
        }

        // Get fixer name
        $fixerName = $this->getUserName($fixedBy);

        // Use 'status_change' if 'bug_fixed' is not in the ENUM (fallback for older schemas)
        $notificationType = $this->getValidNotificationType('bug_fixed', 'status_change');

        $result = $this->createNotification(
            $notificationType,
            'Bug Fixed',
            "Bug '{$bugTitle}' has been marked as fixed",
            array_values($userIds),
            [
                'entity_type' => 'bug',
                'entity_id' => $bugId,
                'project_id' => $projectId,
                'bug_id' => $bugId,
                'bug_title' => $bugTitle,
                'status' => 'fixed',
                'created_by' => $fixerName
            ]
        );
        
        error_log("NotificationManager::notifyBugFixed - Result: " . ($result ? "Success (ID: $result)" : "Failed"));
        return $result;
    }

    /**
     * Get a valid notification type, using fallback if preferred type is not in ENUM
     * 
     * @param string $preferredType The preferred notification type
     * @param string $fallbackType The fallback type if preferred is not available
     * @return string Valid notification type
     */
    private function getValidNotificationType($preferredType, $fallbackType) {
        try {
            // Check if the preferred type exists in the ENUM
            $checkQuery = "SHOW COLUMNS FROM notifications WHERE Field = 'type'";
            $result = $this->conn->query($checkQuery);
            $column = $result->fetch(PDO::FETCH_ASSOC);
            
            if ($column && isset($column['Type'])) {
                $enumValues = $column['Type'];
                // Check if preferred type is in the ENUM string
                if (stripos($enumValues, $preferredType) !== false) {
                    return $preferredType;
                } else {
                    error_log("NotificationManager::getValidNotificationType - Preferred type '$preferredType' not in ENUM, using fallback '$fallbackType'");
                    return $fallbackType;
                }
            }
        } catch (Exception $e) {
            error_log("NotificationManager::getValidNotificationType - Error checking ENUM: " . $e->getMessage());
        }
        
        // Default to fallback if we can't check
        return $fallbackType;
    }

    /**
     * Notify when an update is created
     * Send to: admins + testers + developers of project
     * 
     * @param string $updateId Update ID
     * @param string $updateTitle Update title
     * @param string $projectId Project ID
     * @param string $createdBy User ID who created the update
     * @return int|false Notification ID or false
     */
    public function notifyUpdateCreated($updateId, $updateTitle, $projectId, $createdBy) {
        $developers = $this->getProjectDevelopers($projectId);
        $testers = $this->getProjectTesters($projectId);
        $admins = $this->getAllAdmins();
        
        // Combine all, remove duplicates, exclude creator
        $userIds = array_unique(array_merge($developers, $testers, $admins));
        $userIds = array_filter($userIds, function($id) use ($createdBy) {
            return $id !== $createdBy;
        });

        // Get creator name
        $creatorName = $this->getUserName($createdBy);

        // Use 'new_update' if 'update_created' is not in the ENUM
        $notificationType = $this->getValidNotificationType('update_created', 'new_update');

        return $this->createNotification(
            $notificationType,
            'New Update Posted',
            "A new update has been posted: {$updateTitle}",
            array_values($userIds),
            [
                'entity_type' => 'update',
                'entity_id' => $updateId,
                'project_id' => $projectId,
                'created_by' => $creatorName
            ]
        );
    }

    /**
     * Notify when a shared task is created
     * Send to: task assignees + project members
     * 
     * @param string $taskId Task ID
     * @param string $taskTitle Task title
     * @param string|null $projectId Project ID (optional)
     * @param array $assignedToIds List of user IDs assigned to the task
     * @param string $createdBy User ID who created the task
     * @return int|false Notification ID or false
     */
    public function notifyTaskCreated($taskId, $taskTitle, $projectId, $assignedToIds, $createdBy) {
        $userIds = [];
        
        // Add assignees
        if (!empty($assignedToIds)) {
            $userIds = array_merge($userIds, $assignedToIds);
        }
        
        // Add project members if project exists
        if ($projectId) {
            $projectMembers = $this->getProjectMembersByRole($projectId, null);
            $userIds = array_merge($userIds, $projectMembers);
        }
        
        // Remove duplicates and creator
        $userIds = array_unique($userIds);
        $userIds = array_filter($userIds, function($id) use ($createdBy) {
            return $id !== $createdBy;
        });

        // Get creator name
        $creatorName = $this->getUserName($createdBy);

        // Use fallback if 'task_created' is not in ENUM
        $notificationType = $this->getValidNotificationType('task_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Task Assigned',
            "A new task has been created: {$taskTitle}",
            array_values($userIds),
            [
                'entity_type' => 'task',
                'entity_id' => $taskId,
                'project_id' => $projectId,
                'created_by' => $creatorName
            ]
        );
    }

    /**
     * Notify when a meeting is created
     * Send to: project members (if project_id exists)
     * 
     * @param string $meetingId Meeting ID
     * @param string $meetingTitle Meeting title
     * @param string|null $projectId Project ID (optional)
     * @param string $createdBy User ID who created the meeting
     * @return int|false Notification ID or false
     */
    public function notifyMeetCreated($meetingId, $meetingTitle, $projectId, $createdBy) {
        $userIds = [];
        
        // If project exists, notify project members
        if ($projectId) {
            $userIds = $this->getProjectMembersByRole($projectId, null);
        } else {
            // If no project, notify all admins
            $userIds = $this->getAllAdmins();
        }
        
        // Remove creator
        $userIds = array_filter($userIds, function($id) use ($createdBy) {
            return $id !== $createdBy;
        });

        // Get creator name
        $creatorName = $this->getUserName($createdBy);

        // Use fallback if 'meet_created' is not in ENUM
        $notificationType = $this->getValidNotificationType('meet_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Meeting Created',
            "A new meeting has been created: {$meetingTitle}",
            array_values($userIds),
            [
                'entity_type' => 'meet',
                'entity_id' => $meetingId,
                'project_id' => $projectId,
                'created_by' => $creatorName
            ]
        );
    }

    /**
     * Notify when a document is created
     * Send to: project members
     * 
     * @param string $docId Document ID
     * @param string $docTitle Document title
     * @param string|null $projectId Project ID (optional)
     * @param string $createdBy User ID who created the document
     * @return int|false Notification ID or false
     */
    public function notifyDocCreated($docId, $docTitle, $projectId, $createdBy) {
        $userIds = [];
        
        // If project exists, notify project members
        if ($projectId) {
            $userIds = $this->getProjectMembersByRole($projectId, null);
        } else {
            // If no project, notify all admins
            $userIds = $this->getAllAdmins();
        }
        
        // Remove creator
        $userIds = array_filter($userIds, function($id) use ($createdBy) {
            return $id !== $createdBy;
        });

        // Get creator name
        $creatorName = $this->getUserName($createdBy);

        // Use fallback if 'doc_created' is not in ENUM
        $notificationType = $this->getValidNotificationType('doc_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Document Created',
            "A new document has been created: {$docTitle}",
            array_values($userIds),
            [
                'entity_type' => 'doc',
                'entity_id' => $docId,
                'project_id' => $projectId,
                'created_by' => $creatorName
            ]
        );
    }

    /**
     * Notify when a project is created
     * Send to: all admins
     * 
     * @param string $projectId Project ID
     * @param string $projectName Project name
     * @param string $createdBy User ID who created the project
     * @return int|false Notification ID or false
     */
    public function notifyProjectCreated($projectId, $projectName, $createdBy) {
        $admins = $this->getAllAdmins();
        
        // Remove creator
        $userIds = array_filter($admins, function($id) use ($createdBy) {
            return $id !== $createdBy;
        });

        // Get creator name
        $creatorName = $this->getUserName($createdBy);

        // Use fallback if 'project_created' is not in ENUM
        $notificationType = $this->getValidNotificationType('project_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Project Created',
            "A new project has been created: {$projectName}",
            array_values($userIds),
            [
                'entity_type' => 'project',
                'entity_id' => $projectId,
                'project_id' => $projectId,
                'created_by' => $creatorName
            ]
        );
    }

    /**
     * Get user name by user ID
     * 
     * @param string $userId User ID
     * @return string User name or user ID if not found
     */
    private function getUserName($userId) {
        try {
            $query = "SELECT username FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$userId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['username'] : $userId;
        } catch (Exception $e) {
            error_log("Error getting user name: " . $e->getMessage());
            return $userId;
        }
    }

    /**
     * Get notifications for a user
     * 
     * @param string $userId User ID
     * @param int $limit Number of notifications to return
     * @param int $offset Offset for pagination
     * @return array List of notifications
     */
    public function getUserNotifications($userId, $limit = 50, $offset = 0) {
        try {
            // Ensure userId is treated consistently (convert to string for comparison)
            $userId = (string)$userId;
            
            error_log("NotificationManager::getUserNotifications - UserId: $userId, Limit: $limit, Offset: $offset");
            
            // Check if user_notifications table exists
            try {
                $tableCheck = $this->conn->query("SHOW TABLES LIKE 'user_notifications'");
                if ($tableCheck->rowCount() === 0) {
                    error_log("NotificationManager::getUserNotifications - WARNING: user_notifications table does not exist, returning empty array");
                    return [];
                }
            } catch (Exception $tableEx) {
                error_log("NotificationManager::getUserNotifications - Error checking table existence: " . $tableEx->getMessage());
                return [];
            }
            
            // Check if notifications table exists
            try {
                $tableCheck = $this->conn->query("SHOW TABLES LIKE 'notifications'");
                if ($tableCheck->rowCount() === 0) {
                    error_log("NotificationManager::getUserNotifications - WARNING: notifications table does not exist, returning empty array");
                    return [];
                }
            } catch (Exception $tableEx) {
                error_log("NotificationManager::getUserNotifications - Error checking notifications table: " . $tableEx->getMessage());
                return [];
            }
            
            $query = "
                SELECT 
                    n.id,
                    n.type,
                    n.title,
                    n.message,
                    n.entity_type,
                    n.entity_id,
                    n.project_id,
                    n.bug_id,
                    n.bug_title,
                    n.status,
                    n.created_by,
                    n.created_at,
                    un.`read`,
                    un.read_at
                FROM user_notifications un
                JOIN notifications n ON un.notification_id = n.id
                WHERE CAST(un.user_id AS CHAR) = CAST(? AS CHAR)
                ORDER BY n.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                $errorInfo = $this->conn->errorInfo();
                error_log("NotificationManager::getUserNotifications - ERROR: Failed to prepare statement: " . json_encode($errorInfo));
                return [];
            }
            
            $executeResult = $stmt->execute([$userId, $limit, $offset]);
            if (!$executeResult) {
                $errorInfo = $stmt->errorInfo();
                error_log("NotificationManager::getUserNotifications - ERROR: Failed to execute query: " . json_encode($errorInfo));
                return [];
            }
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("NotificationManager::getUserNotifications - Found " . count($results) . " notifications for user $userId");
            
            // If no results, check what user_ids exist in user_notifications
            if (empty($results)) {
                try {
                    $debugQuery = "SELECT DISTINCT user_id FROM user_notifications LIMIT 10";
                    $debugStmt = $this->conn->query($debugQuery);
                    if ($debugStmt) {
                        $existingUserIds = $debugStmt->fetchAll(PDO::FETCH_COLUMN);
                        error_log("NotificationManager::getUserNotifications - DEBUG: Existing user_ids in user_notifications: " . json_encode($existingUserIds));
                        error_log("NotificationManager::getUserNotifications - DEBUG: Searching for user_id: $userId (type: " . gettype($userId) . ")");
                    }
                } catch (Exception $debugEx) {
                    // Ignore debug query errors
                    error_log("NotificationManager::getUserNotifications - Debug query failed: " . $debugEx->getMessage());
                }
            }
            
            return $results;
        } catch (PDOException $e) {
            error_log("PDO Error getting user notifications: " . $e->getMessage());
            error_log("PDO Error code: " . $e->getCode());
            error_log("PDO Error info: " . json_encode($e->errorInfo));
            return [];
        } catch (Exception $e) {
            error_log("Error getting user notifications: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            return [];
        } catch (Error $e) {
            error_log("Fatal error getting user notifications: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * Get unread count for a user
     * 
     * @param string $userId User ID
     * @return int Unread count
     */
    public function getUnreadCount($userId) {
        try {
            // Ensure userId is treated consistently
            $userId = (string)$userId;
            
            $query = "
                SELECT COUNT(*) 
                FROM user_notifications un
                WHERE CAST(un.user_id AS CHAR) = CAST(? AS CHAR) AND un.`read` = 0
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$userId]);
            
            $count = (int)$stmt->fetchColumn();
            error_log("NotificationManager::getUnreadCount - UserId: $userId, Unread count: $count");
            return $count;
        } catch (Exception $e) {
            error_log("Error getting unread count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mark notification as read
     * 
     * @param string $userId User ID
     * @param int $notificationId Notification ID
     * @return bool Success
     */
    public function markAsRead($userId, $notificationId) {
        try {
            $query = "
                UPDATE user_notifications 
                SET `read` = 1, read_at = NOW()
                WHERE user_id = ? AND notification_id = ? AND `read` = 0
            ";
            
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([$userId, $notificationId]);
        } catch (Exception $e) {
            error_log("Error marking notification as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark all notifications as read for a user
     * 
     * @param string $userId User ID
     * @return bool Success
     */
    public function markAllAsRead($userId) {
        try {
            $query = "
                UPDATE user_notifications 
                SET `read` = 1, read_at = NOW()
                WHERE user_id = ? AND `read` = 0
            ";
            
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log("Error marking all notifications as read: " . $e->getMessage());
            return false;
        }
    }
}

