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
            $query = "SELECT id FROM users WHERE account_active = 1 AND (role = 'admin' OR role_id = 1)";
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
     * Keep only active users for notification delivery.
     */
    private function filterActiveUserIds(array $userIds) {
        $userIds = array_values(array_unique(array_filter(array_map('strval', $userIds))));
        if (empty($userIds)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $this->conn->prepare(
                "SELECT id FROM users WHERE account_active = 1 AND id IN ($placeholders)"
            );
            $stmt->execute($userIds);
            $active = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_values(array_unique(array_map('strval', $active)));
        } catch (Exception $e) {
            error_log("NotificationManager::filterActiveUserIds - " . $e->getMessage());
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
     * All admins, optionally excluding an actor.
     * Falls back to the actor if they are the only admin.
     */
    private function resolveAdminRecipients($excludeUserId = null) {
        $excludeUserId = $excludeUserId !== null ? (string) $excludeUserId : null;
        $admins = $this->getAllAdmins();
        $userIds = array_values(array_filter($admins, function ($id) use ($excludeUserId) {
            return $excludeUserId === null || (string) $id !== $excludeUserId;
        }));

        if (empty($userIds) && $excludeUserId !== null) {
            return [$excludeUserId];
        }

        return $userIds;
    }

    /**
     * Project developers + testers + all admins, excluding actor.
     * Falls back to admins (then actor) when project has no members.
     */
    private function resolveProjectRecipients($projectId, $excludeUserId = null) {
        $excludeUserId = $excludeUserId !== null ? (string) $excludeUserId : null;
        $developers = $projectId ? $this->getProjectDevelopers($projectId) : [];
        $testers = $projectId ? $this->getProjectTesters($projectId) : [];
        $admins = $this->getAllAdmins();

        $userIds = array_unique(array_merge($developers, $testers, $admins));
        $userIds = array_values(array_filter($userIds, function ($id) use ($excludeUserId) {
            return $excludeUserId === null || (string) $id !== $excludeUserId;
        }));

        if (empty($userIds)) {
            return $this->resolveAdminRecipients($excludeUserId);
        }

        return $userIds;
    }

    /**
     * Role-neutral deep link path for push notification clicks.
     */
    private function resolveDeepLink($entityType, $entityId = null, array $extra = []) {
        $entityType = strtolower((string) $entityType);
        $entityId = $entityId !== null && $entityId !== '' ? (string) $entityId : null;
        $code = isset($extra['code']) ? (string) $extra['code'] : null;
        $projectId = isset($extra['project_id']) ? (string) $extra['project_id'] : null;

        switch ($entityType) {
            case 'bug':
            case 'fix':
                return $entityId ? '/bugs/' . $entityId : '/notifications';
            case 'update':
                return $entityId ? '/updates/' . $entityId : '/updates';
            case 'project':
                return $entityId ? '/projects/' . $entityId : '/projects';
            case 'task':
                return $entityId ? '/tasks/' . $entityId : '/my-tasks';
            case 'meet':
                return $code ? '/meet/' . rawurlencode($code) : ($entityId ? '/meet/' . $entityId : '/meet');
            case 'doc':
                return $projectId ? '/bugdocs/project/' . $projectId : '/bugdocs';
            case 'sheet':
                return $projectId ? '/bugsheets/project/' . $projectId : '/bugsheets';
            case 'work_update':
                return '/daily-work-update';
            case 'work_check_in':
            case 'work_break':
                if ($entityId && strpos($entityId, ':') !== false) {
                    return '/users/' . explode(':', $entityId, 2)[0];
                }
                return $entityId ? '/users/' . $entityId : '/users';
            case 'overtime':
                return '/overtime-requests';
            case 'feedback':
                return '/feedback-stats';
            case 'message':
                return '/messages';
            case 'user':
                return $entityId ? '/users/' . $entityId : '/users';
            default:
                return '/notifications';
        }
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
            $userIds = $this->filterActiveUserIds((array)$userIds);
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
            $successfulUserIds = [];
            
            foreach ($userIds as $userId) {
                try {
                    // Ensure userId is consistently treated as string
                    $userIdStr = (string)$userId;
                    
                    // First verify user exists
                    $userCheck = $this->conn->prepare("SELECT id FROM users WHERE id = ? AND account_active = 1");
                    $userCheck->execute([$userIdStr]);
                    if (!$userCheck->fetch()) {
                        error_log("NotificationManager::createNotification - WARNING: User $userIdStr inactive or missing, skipping");
                        $failedUserIds[] = $userIdStr;
                        continue;
                    }
                    
                    $userStmt->execute([$notificationId, $userIdStr]);
                    $insertedCount++;
                    $successfulUserIds[] = $userIdStr;
                    error_log("NotificationManager::createNotification - Successfully inserted notification $notificationId for userId $userIdStr");
                } catch (PDOException $e) {
                    // Skip duplicate entries
                    if ($e->getCode() != 23000) {
                        error_log("NotificationManager::createNotification - Error inserting user notification for userId $userId: " . $e->getMessage());
                        error_log("NotificationManager::createNotification - SQL State: " . $e->errorInfo[0] . ", Error Code: " . $e->errorInfo[1]);
                        $failedUserIds[] = (string)$userId;
                    } else {
                        // Already has this notification row — still eligible for push
                        $successfulUserIds[] = (string)$userId;
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

            // Push to devices (never fail the in-app notification if FCM errors)
            if (!empty($successfulUserIds)) {
                $this->sendPushToUsers($successfulUserIds, $title, $message, $type, $data, $notificationId);
            }

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
     * Send FCM push to the same users who received the in-app notification.
     */
    private function sendPushToUsers(array $userIds, $title, $message, $type, array $data, $notificationId) {
        try {
            require_once __DIR__ . '/../config/environment.php';
            require_once __DIR__ . '/../services/FirebaseMessagingService.php';

            $entityType = (string) ($data['entity_type'] ?? $type ?? '');
            $entityId = $data['entity_id'] ?? $data['bug_id'] ?? null;
            $bugId = $data['bug_id'] ?? (($entityType === 'bug' || $entityType === 'fix') ? $entityId : '');

            $path = $this->resolveDeepLink($entityType, $entityId, [
                'code' => $data['meet_code'] ?? $data['code'] ?? null,
                'project_id' => $data['project_id'] ?? null,
            ]);

            $url = $this->getFrontendAbsoluteUrl($path);
            $imageUrl = $this->resolveNotificationImageUrl($bugId);

            $payload = [
                'type' => (string) $type,
                'entity_type' => (string) $entityType,
                'notification_id' => (string) $notificationId,
                'url' => $url,
                'click_action' => $url,
                'title' => (string) $title,
                'body' => (string) $message,
                'image' => (string) $imageUrl,
                'icon' => $this->getFrontendAbsoluteUrl('/notification-icon.png'),
                'badge' => $this->getFrontendAbsoluteUrl('/notification-badge.png'),
                'bug_id' => (string) ($bugId ?: ''),
                'entity_id' => (string) ($entityId ?: ''),
                'project_id' => (string) ($data['project_id'] ?? ''),
                'tag' => (string) $type . '-' . ($entityId ?: $notificationId),
                'actions' => 'view,dismiss',
            ];

            $messaging = new FirebaseMessagingService($this->conn);
            $result = $messaging->sendToUsers($userIds, $title, $message, $payload);

            error_log(sprintf(
                'NotificationManager::sendPushToUsers - type=%s notification_id=%s users=%d sent=%d failed=%d image=%s',
                $type,
                $notificationId,
                count($userIds),
                $result['sent_count'] ?? 0,
                $result['failure_count'] ?? 0,
                $imageUrl
            ));
        } catch (Throwable $e) {
            error_log('NotificationManager::sendPushToUsers - FCM error: ' . $e->getMessage());
        }
    }

    private function getFrontendAbsoluteUrl($path) {
        $base = 'https://bugs.bugricer.com';
        if (class_exists('Environment') && method_exists('Environment', 'isProduction')) {
            // keep production default
        }
        if (isset($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
            if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
                $base = 'http://localhost:8080';
            }
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $base . $path;
    }

    private function resolveNotificationImageUrl($bugId) {
        $fallback = 'https://bugs.bugricer.com/icon-512.png';
        if (empty($bugId) || !$this->conn) {
            return $fallback;
        }

        try {
            $stmt = $this->conn->prepare("
                SELECT file_path, file_type, file_name
                FROM bug_attachments
                WHERE bug_id = ?
                ORDER BY created_at ASC
                LIMIT 8
            ");
            $stmt->execute([(string) $bugId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $path = (string) ($row['file_path'] ?? '');
                $type = (string) ($row['file_type'] ?? '');
                $name = (string) ($row['file_name'] ?? '');
                $isImage = strpos($type, 'image/') === 0
                    || preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $name)
                    || preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $path);
                if (!$isImage || $path === '') {
                    continue;
                }
                return $this->toPublicUploadUrl($path);
            }
        } catch (Throwable $e) {
            error_log('NotificationManager::resolveNotificationImageUrl - ' . $e->getMessage());
        }

        return $fallback;
    }

    private function toPublicUploadUrl($path) {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');
        // Normalize common stored prefixes
        $path = preg_replace('#^(backend/|public_html/bugbackend/)#', '', $path);

        return 'https://bugbackend.bugricer.com/' . $path;
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
    public function notifyBugCreated($bugId, $bugTitle, $projectId, $createdBy, $bugLevel = null, $alreadyRaised = null) {
        require_once __DIR__ . '/../utils/bug_meta.php';
        $bugId = (string) $bugId;
        $projectId = $projectId ? (string) $projectId : null;
        $createdBy = (string) $createdBy;
        $userIds = $this->resolveProjectRecipients($projectId, $createdBy);
        $creatorName = $this->getUserName($createdBy);
        $notificationType = $this->getValidNotificationType('bug_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Bug Reported',
            buildBugCreatedNotificationMessage($bugTitle, $bugLevel, $alreadyRaised, $creatorName),
            $userIds,
            [
                'entity_type' => 'bug',
                'entity_id' => $bugId,
                'project_id' => $projectId,
                'bug_id' => $bugId,
                'bug_title' => $bugTitle,
                'created_by' => $creatorName,
            ]
        );
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
    public function notifyBugFixed($bugId, $bugTitle, $projectId, $fixedBy, $reportedBy = null, $fixedAt = null) {
        require_once __DIR__ . '/../utils/bug_meta.php';
        $bugId = (string) $bugId;
        $projectId = $projectId ? (string) $projectId : null;
        $fixedBy = (string) $fixedBy;
        $userIds = $this->resolveProjectRecipients($projectId, $fixedBy);

        // Always notify the bug reporter when someone else fixes it
        if ($reportedBy !== null && $reportedBy !== '') {
            $reporterId = (string) $reportedBy;
            if ($reporterId !== $fixedBy) {
                $userIds[] = $reporterId;
                $userIds = array_values(array_unique($userIds));
            }
        }

        $fixerName = $this->getUserName($fixedBy);
        $reporterName = ($reportedBy !== null && $reportedBy !== '')
            ? $this->getUserName($reportedBy)
            : 'Unknown';
        $fixedAtLabel = $fixedAt ?: date('Y-m-d H:i:s');
        $message = buildBugFixedNotificationMessage($bugTitle, $reporterName, $fixerName, $fixedAtLabel);
        $notificationType = $this->getValidNotificationType('bug_fixed', 'status_change');

        return $this->createNotification(
            $notificationType,
            'Bug Fixed',
            $message,
            $userIds,
            [
                'entity_type' => 'bug',
                'entity_id' => $bugId,
                'project_id' => $projectId,
                'bug_id' => $bugId,
                'bug_title' => $bugTitle,
                'status' => 'fixed',
                'created_by' => $fixerName,
            ]
        );
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
        $updateId = (string) $updateId;
        $projectId = $projectId ? (string) $projectId : null;
        $createdBy = (string) $createdBy;
        $userIds = $this->resolveProjectRecipients($projectId, $createdBy);
        $creatorName = $this->getUserName($createdBy);
        $notificationType = $this->getValidNotificationType('update_created', 'new_update');

        return $this->createNotification(
            $notificationType,
            'New Update Posted',
            "A new update has been posted: {$updateTitle}",
            $userIds,
            [
                'entity_type' => 'update',
                'entity_id' => $updateId,
                'project_id' => $projectId,
                'created_by' => $creatorName,
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
        $taskId = (string) $taskId;
        $projectId = $projectId ? (string) $projectId : null;
        $createdBy = (string) $createdBy;
        $assignees = array_map('strval', $assignedToIds ?: []);
        $projectRecipients = $this->resolveProjectRecipients($projectId, $createdBy);
        $userIds = array_values(array_unique(array_filter(array_merge($assignees, $projectRecipients), function ($id) use ($createdBy) {
            return (string) $id !== $createdBy;
        })));
        if (empty($userIds)) {
            $userIds = $this->resolveAdminRecipients($createdBy);
        }
        $creatorName = $this->getUserName($createdBy);
        $notificationType = $this->getValidNotificationType('task_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Task Assigned',
            "A new task has been created: {$taskTitle}",
            $userIds,
            [
                'entity_type' => 'task',
                'entity_id' => $taskId,
                'project_id' => $projectId,
                'created_by' => $creatorName,
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
    public function notifyMeetCreated($meetingId, $meetingTitle, $projectId, $createdBy, $meetCode = null) {
        $meetingId = (string) $meetingId;
        $projectId = $projectId ? (string) $projectId : null;
        $createdBy = (string) $createdBy;
        $userIds = $projectId
            ? $this->resolveProjectRecipients($projectId, $createdBy)
            : $this->resolveAdminRecipients($createdBy);
        $creatorName = $this->getUserName($createdBy);
        $notificationType = $this->getValidNotificationType('meet_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Meeting Created',
            "A new meeting has been created: {$meetingTitle}",
            $userIds,
            [
                'entity_type' => 'meet',
                'entity_id' => $meetingId,
                'project_id' => $projectId,
                'meet_code' => $meetCode,
                'code' => $meetCode,
                'created_by' => $creatorName,
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
        $docId = (string) $docId;
        $projectId = $projectId ? (string) $projectId : null;
        $createdBy = (string) $createdBy;
        $userIds = $projectId
            ? $this->resolveProjectRecipients($projectId, $createdBy)
            : $this->resolveAdminRecipients($createdBy);
        $creatorName = $this->getUserName($createdBy);
        $notificationType = $this->getValidNotificationType('doc_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Document Created',
            "A new document has been created: {$docTitle}",
            $userIds,
            [
                'entity_type' => 'doc',
                'entity_id' => $docId,
                'project_id' => $projectId,
                'created_by' => $creatorName,
            ]
        );
    }

    /**
     * Notify when a sheet is created
     * Send to: project members (if project exists) or all admins (if no project)
     * 
     * @param string $sheetId Sheet ID (Google Sheet ID)
     * @param string $sheetTitle Sheet title
     * @param string|null $projectId Project ID (optional)
     * @param string $createdBy User ID who created the sheet
     * @return int|false Notification ID or false
     */
    public function notifySheetCreated($sheetId, $sheetTitle, $projectId, $createdBy) {
        $sheetId = (string) $sheetId;
        $projectId = $projectId ? (string) $projectId : null;
        $createdBy = (string) $createdBy;
        $userIds = $projectId
            ? $this->resolveProjectRecipients($projectId, $createdBy)
            : $this->resolveAdminRecipients($createdBy);
        $creatorName = $this->getUserName($createdBy);
        $notificationType = $this->getValidNotificationType('sheet_created', 'doc_created');

        return $this->createNotification(
            $notificationType,
            'New Sheet Created',
            "A new sheet has been created: {$sheetTitle}",
            $userIds,
            [
                'entity_type' => 'sheet',
                'entity_id' => $sheetId,
                'project_id' => $projectId,
                'created_by' => $creatorName,
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
        $projectId = (string) $projectId;
        $createdBy = (string) $createdBy;
        $userIds = $this->resolveAdminRecipients($createdBy);
        $creatorName = $this->getUserName($createdBy);
        $notificationType = $this->getValidNotificationType('project_created', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New Project Created',
            "A new project has been created: {$projectName}",
            $userIds,
            [
                'entity_type' => 'project',
                'entity_id' => $projectId,
                'project_id' => $projectId,
                'created_by' => $creatorName,
            ]
        );
    }

    public function notifyWorkUpdateSubmitted($submissionId, $userId, $userName = null, $date = null) {
        return $this->notifyWorkCheckOut($submissionId, $userId, $userName, $date, null, false);
    }

    /**
     * Notify admins when a user checks in for daily work.
     */
    public function notifyWorkCheckIn($userId, $checkInTime, $submissionDate, $plannedWorkSummary = null) {
        $userId = (string) $userId;
        $userName = $this->getUserName($userId);
        $userIds = $this->resolveAdminRecipients($userId);
        $notificationType = $this->getValidNotificationType('work_check_in', 'new_update');
        $entityId = $userId . ':' . $submissionDate;

        $timeLabel = $checkInTime;
        if ($checkInTime) {
            $ts = strtotime($checkInTime);
            if ($ts !== false) {
                $timeLabel = date('g:i A', $ts);
            }
        }

        $message = "{$userName} checked in at {$timeLabel} on {$submissionDate}";
        if ($plannedWorkSummary) {
            $snippet = mb_substr(trim((string) $plannedWorkSummary), 0, 120);
            if ($snippet !== '') {
                $message .= " — {$snippet}";
            }
        }

        return $this->createNotification(
            $notificationType,
            "Check-in: {$userName}",
            $message,
            $userIds,
            [
                'entity_type' => 'work_check_in',
                'entity_id' => $entityId,
                'created_by' => $userName,
            ]
        );
    }

    /**
     * Notify admins when a user starts or ends a break.
     *
     * @param string $action start|end
     */
    public function notifyWorkBreak($userId, $action, $submissionDate, $startedAt = null, $durationMinutes = null) {
        $userId = (string) $userId;
        $userName = $this->getUserName($userId);
        $userIds = $this->resolveAdminRecipients($userId);
        $notificationType = $this->getValidNotificationType('work_break', 'new_update');
        $entityId = $userId . ':' . $submissionDate;
        $action = strtolower((string) $action);

        if ($action === 'end' || $action === 'break_end') {
            $durationLabel = $durationMinutes !== null ? " ({$durationMinutes} min)" : '';
            $title = "Break ended: {$userName}";
            $message = "{$userName} ended a break on {$submissionDate}{$durationLabel}";
        } else {
            $timeLabel = $startedAt ? (string) $startedAt : '';
            $title = "Break started: {$userName}";
            $message = $timeLabel !== ''
                ? "{$userName} started a break at {$timeLabel} on {$submissionDate}"
                : "{$userName} started a break on {$submissionDate}";
        }

        return $this->createNotification(
            $notificationType,
            $title,
            $message,
            $userIds,
            [
                'entity_type' => 'work_break',
                'entity_id' => $entityId,
                'created_by' => $userName,
            ]
        );
    }

    /**
     * Notify admins when a user saves daily work (check-out / end of day).
     */
    public function notifyWorkCheckOut($submissionId, $userId, $userName = null, $date = null, $hoursToday = null, $isUpdate = false) {
        $submissionId = (string) $submissionId;
        $userId = (string) $userId;
        $userName = $userName ?: $this->getUserName($userId);
        $userIds = $this->resolveAdminRecipients($userId);
        $notificationType = $this->getValidNotificationType('work_update', 'new_update');
        $dateLabel = $date ? " for {$date}" : '';
        $hoursLabel = $hoursToday !== null && $hoursToday !== '' ? " ({$hoursToday}h)" : '';
        $actionLabel = $isUpdate ? 'updated daily work' : 'checked out';

        return $this->createNotification(
            $notificationType,
            "Check-out: {$userName}",
            "{$userName} {$actionLabel}{$dateLabel}{$hoursLabel}",
            $userIds,
            [
                'entity_type' => 'work_update',
                'entity_id' => $submissionId,
                'created_by' => $userName,
            ]
        );
    }

    public function notifyFeedbackSubmitted($feedbackId, $userId, $summary = null) {
        $feedbackId = (string) $feedbackId;
        $userId = (string) $userId;
        $userName = $this->getUserName($userId);
        $userIds = $this->resolveAdminRecipients($userId);
        $notificationType = $this->getValidNotificationType('feedback', 'new_update');
        $body = $summary
            ? "{$userName}: {$summary}"
            : "{$userName} submitted new feedback";

        return $this->createNotification(
            $notificationType,
            'New Feedback',
            $body,
            $userIds,
            [
                'entity_type' => 'feedback',
                'entity_id' => $feedbackId,
                'created_by' => $userName,
            ]
        );
    }

    public function notifyOvertimeRequested($requestId, $userId, $hours = null) {
        $requestId = (string) $requestId;
        $userId = (string) $userId;
        $userName = $this->getUserName($userId);
        $userIds = $this->resolveAdminRecipients($userId);
        $notificationType = $this->getValidNotificationType('overtime', 'status_change');
        $hoursLabel = $hours !== null && $hours !== '' ? " ({$hours}h)" : '';

        return $this->createNotification(
            $notificationType,
            'OT Request',
            "{$userName} requested overtime{$hoursLabel}",
            $userIds,
            [
                'entity_type' => 'overtime',
                'entity_id' => $requestId,
                'created_by' => $userName,
            ]
        );
    }

    public function notifyUserRegistered($newUserId, $username, $createdBy = null) {
        $newUserId = (string) $newUserId;
        $createdBy = $createdBy !== null ? (string) $createdBy : null;
        $userIds = $this->resolveAdminRecipients($createdBy ?: $newUserId);
        $notificationType = $this->getValidNotificationType('user_registered', 'new_bug');

        return $this->createNotification(
            $notificationType,
            'New User Registered',
            "A new user has been registered: {$username}",
            $userIds,
            [
                'entity_type' => 'user',
                'entity_id' => $newUserId,
                'created_by' => $createdBy ? $this->getUserName($createdBy) : 'system',
            ]
        );
    }

    /**
     * @param array $participantIds Chat participant user IDs
     */
    public function notifyChatMessage($messageId, $senderId, array $participantIds, $preview = null) {
        $messageId = (string) $messageId;
        $senderId = (string) $senderId;
        $senderName = $this->getUserName($senderId);
        $userIds = array_values(array_unique(array_filter(array_map('strval', $participantIds), function ($id) use ($senderId) {
            return (string) $id !== $senderId;
        })));
        if (empty($userIds)) {
            return false;
        }
        $notificationType = $this->getValidNotificationType('message', 'new_update');
        $body = $preview
            ? "{$senderName}: {$preview}"
            : "{$senderName} sent a message";

        return $this->createNotification(
            $notificationType,
            'New Message',
            mb_substr($body, 0, 180),
            $userIds,
            [
                'entity_type' => 'message',
                'entity_id' => $messageId,
                'created_by' => $senderName,
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
            
            // Check if user_notifications table exists (cached check)
            try {
                $tableCheck = $this->conn->query("SHOW TABLES LIKE 'user_notifications'");
                if ($tableCheck->rowCount() === 0) {
                    return [];
                }
            } catch (Exception $tableEx) {
                return [];
            }
            
            // Check if notifications table exists (cached check)
            try {
                $tableCheck = $this->conn->query("SHOW TABLES LIKE 'notifications'");
                if ($tableCheck->rowCount() === 0) {
                    return [];
                }
            } catch (Exception $tableEx) {
                return [];
            }
            
            // Use the proven working query from test_query.php
            // This exact query works, so we'll use it as the primary method
            $results = [];
            
            // Primary query: Direct JOIN (proven to work in test_query.php)
            // Include project name via LEFT JOIN with projects table
            $query = "
                SELECT 
                    n.id,
                    n.type,
                    n.title,
                    n.message,
                    n.entity_type,
                    n.entity_id,
                    n.project_id,
                    p.name as project_name,
                    n.bug_id,
                    n.bug_title,
                    n.status,
                    n.created_by,
                    n.created_at,
                    un.`read`,
                    un.read_at
                FROM user_notifications un
                JOIN notifications n ON un.notification_id = n.id
                LEFT JOIN projects p ON n.project_id = p.id
                WHERE un.user_id = ?
                ORDER BY n.created_at DESC
                LIMIT ? OFFSET ?
            ";
            
            try {
                $stmt = $this->conn->prepare($query);
                if ($stmt) {
                    $stmt->execute([$userId, $limit, $offset]);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (Exception $e1) {
                // Silent error handling for better performance
                // Only log in development if needed
            }
            
            // Fallback: If no results, try with CAST comparison (for edge cases)
            if (empty($results)) {
                $query2 = "
                    SELECT 
                        n.id,
                        n.type,
                        n.title,
                        n.message,
                        n.entity_type,
                        n.entity_id,
                        n.project_id,
                        p.name as project_name,
                        n.bug_id,
                        n.bug_title,
                        n.status,
                        n.created_by,
                        n.created_at,
                        un.`read`,
                        un.read_at
                    FROM user_notifications un
                    JOIN notifications n ON un.notification_id = n.id
                    LEFT JOIN projects p ON n.project_id = p.id
                    WHERE CAST(un.user_id AS CHAR) = CAST(? AS CHAR)
                    ORDER BY n.created_at DESC
                    LIMIT ? OFFSET ?
                ";
                
                try {
                    $stmt2 = $this->conn->prepare($query2);
                    if ($stmt2) {
                        $stmt2->execute([$userId, $limit, $offset]);
                        $results = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                        error_log("NotificationManager::getUserNotifications - Fallback query (CAST): Found " . count($results) . " notifications");
                    }
                } catch (Exception $e2) {
                    error_log("NotificationManager::getUserNotifications - Fallback query failed: " . $e2->getMessage());
                }
            }
            
            // Debug: If still no results, check what's in the database
            if (empty($results)) {
                try {
                    // Check user_notifications for this user
                    $debugStmt = $this->conn->prepare("SELECT COUNT(*) as count FROM user_notifications WHERE user_id = ?");
                    $debugStmt->execute([$userId]);
                    $userCount = $debugStmt->fetch(PDO::FETCH_ASSOC)['count'];
                    
                    // Check all user_ids in user_notifications
                    $debugQuery = "SELECT DISTINCT user_id, COUNT(*) as cnt FROM user_notifications GROUP BY user_id LIMIT 10";
                    $debugStmt2 = $this->conn->query($debugQuery);
                    $existingUserIds = $debugStmt2->fetchAll(PDO::FETCH_ASSOC);
                    
                    error_log("NotificationManager::getUserNotifications - DEBUG: User $userId has $userCount notifications in user_notifications");
                    error_log("NotificationManager::getUserNotifications - DEBUG: Sample user_ids in database: " . json_encode($existingUserIds));
                    
                    // Try to find user_id with LIKE pattern
                    $likeStmt = $this->conn->prepare("SELECT DISTINCT user_id FROM user_notifications WHERE user_id LIKE ? LIMIT 5");
                    $likeStmt->execute(["%$userId%"]);
                    $likeMatches = $likeStmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($likeMatches)) {
                        error_log("NotificationManager::getUserNotifications - DEBUG: Found similar user_ids with LIKE: " . json_encode($likeMatches));
                    }
                } catch (Exception $debugEx) {
                    error_log("NotificationManager::getUserNotifications - Debug queries failed: " . $debugEx->getMessage());
                }
            }
            
            error_log("NotificationManager::getUserNotifications - Final result: Found " . count($results) . " notifications for user $userId");
            
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
     * Get total, unread, and read notification counts for a user.
     *
     * @param string $userId User ID
     * @return array{total: int, unread: int, read: int}
     */
    public function getNotificationCounts($userId) {
        try {
            $userId = (string)$userId;

            $query = "
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN un.`read` = 0 THEN 1 ELSE 0 END) AS unread,
                    SUM(CASE WHEN un.`read` = 1 THEN 1 ELSE 0 END) AS `read`
                FROM user_notifications un
                WHERE CAST(un.user_id AS CHAR) = CAST(? AS CHAR)
            ";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'total' => (int)($row['total'] ?? 0),
                'unread' => (int)($row['unread'] ?? 0),
                'read' => (int)($row['read'] ?? 0),
            ];
        } catch (Exception $e) {
            error_log("Error getting notification counts: " . $e->getMessage());
            return ['total' => 0, 'unread' => 0, 'read' => 0];
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
            $userId = (string)$userId;
            $query = "
                UPDATE user_notifications 
                SET `read` = 1, read_at = NOW()
                WHERE CAST(user_id AS CHAR) = CAST(? AS CHAR) AND `read` = 0
            ";
            
            $stmt = $this->conn->prepare($query);
            return $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log("Error marking all notifications as read: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete all notifications for a user
     * Removes all entries from user_notifications table for the specified user
     * 
     * @param string $userId User ID
     * @return bool Success
     */
    public function deleteAllNotifications($userId) {
        try {
            $userId = (string)$userId;
            
            error_log("NotificationManager::deleteAllNotifications - UserId: $userId");
            
            $query = "
                DELETE FROM user_notifications 
                WHERE CAST(user_id AS CHAR) = CAST(? AS CHAR)
            ";
            
            $stmt = $this->conn->prepare($query);
            $result = $stmt->execute([$userId]);
            $deletedCount = $stmt->rowCount();
            
            error_log("NotificationManager::deleteAllNotifications - Deleted $deletedCount notifications for user $userId");
            
            return $result;
        } catch (Exception $e) {
            error_log("Error deleting all notifications: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            return false;
        }
    }
}

