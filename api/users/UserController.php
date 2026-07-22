<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../ActivityLogger.php';
require_once __DIR__ . '/../../utils/activity_sessions_schema.php';

class UserController extends BaseAPI {
    public function getUsers() {
        try {
            // Validate token first
            try {
                $this->validateToken();
            } catch (Exception $e) {
                error_log("Token validation failed: " . $e->getMessage());
                $this->sendJsonResponse(401, "Authentication failed");
                return;
            }

            if (!$this->conn) {
                error_log("Database connection failed in UserController");
                $this->sendJsonResponse(500, "Database connection failed");
                return;
            }

            // Check which columns exist (phone, last_active_at)
            $cols = [];
            $res = $this->conn->query("SHOW COLUMNS FROM users");
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }
            $hasPhone = in_array('phone', $cols);
            $hasLastActive = in_array('last_active_at', $cols);
            $hasAccountActive = in_array('account_active', $cols);
            $hasJoiningDate = in_array('joining_date', $cols);

            $select = ['id', 'username', 'email', 'role', 'role_id', 'created_at', 'updated_at'];
            if ($hasPhone) $select[] = 'phone';
            if ($hasAccountActive) $select[] = 'account_active';
            if ($hasJoiningDate) $select[] = 'joining_date';
            if ($hasLastActive) {
                $select[] = 'last_active_at';
                $select[] = "(CASE WHEN last_active_at IS NULL THEN 'offline' WHEN TIMESTAMPDIFF(SECOND, last_active_at, NOW()) < 120 THEN 'active' WHEN TIMESTAMPDIFF(SECOND, last_active_at, NOW()) < 900 THEN 'idle' ELSE 'offline' END) as status";
            } else {
                $select[] = "'offline' as status";
            }
            $query = "SELECT " . implode(', ', $select) . " FROM users ORDER BY created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt) {
                error_log("Failed to prepare statement: " . implode(", ", $this->conn->errorInfo()));
                $this->sendJsonResponse(500, "Database error occurred");
                return;
            }

            if (!$stmt->execute()) {
                error_log("Failed to execute statement: " . implode(", ", $stmt->errorInfo()));
                $this->sendJsonResponse(500, "Database error occurred");
                return;
            }

            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $checkedInToday = [];
            try {
                $wsCheck = $this->conn->query("SHOW COLUMNS FROM work_submissions LIKE 'check_in_time'");
                if ($wsCheck && $wsCheck->fetch(PDO::FETCH_ASSOC)) {
                    $wsColumns = [];
                    $colRes = $this->conn->query("SHOW COLUMNS FROM work_submissions");
                    if ($colRes) {
                        while ($colRow = $colRes->fetch(PDO::FETCH_ASSOC)) {
                            $wsColumns[] = $colRow['Field'];
                        }
                    }
                    $hasTotalBreakMinutes = in_array('total_break_minutes', $wsColumns, true);

                    $selectFields = [
                        'user_id',
                        'check_in_time',
                        'hours_today',
                        'updated_at',
                        'completed_tasks',
                        'pending_tasks',
                        'ongoing_tasks',
                        'notes',
                    ];
                    if ($hasTotalBreakMinutes) {
                        $selectFields[] = 'total_break_minutes';
                    }

                    $checkInStmt = $this->conn->query(
                        'SELECT ' . implode(', ', $selectFields) . '
                         FROM work_submissions
                         WHERE submission_date = CURDATE() AND check_in_time IS NOT NULL'
                    );
                    if ($checkInStmt) {
                        while ($row = $checkInStmt->fetch(PDO::FETCH_ASSOC)) {
                            $hasWorkUpdate = ((float)($row['hours_today'] ?? 0)) > 0
                                || ($hasTotalBreakMinutes && ((int)($row['total_break_minutes'] ?? 0)) > 0)
                                || trim((string)($row['completed_tasks'] ?? '')) !== ''
                                || trim((string)($row['pending_tasks'] ?? '')) !== ''
                                || trim((string)($row['ongoing_tasks'] ?? '')) !== ''
                                || trim((string)($row['notes'] ?? '')) !== '';

                            $checkoutTime = null;
                            if ($hasWorkUpdate && !empty($row['updated_at'])) {
                                $updatedAt = strtotime($row['updated_at']);
                                $checkInAt = !empty($row['check_in_time']) ? strtotime($row['check_in_time']) : null;
                                if ($updatedAt && (!$checkInAt || $updatedAt > ($checkInAt + 60))) {
                                    $checkoutTime = $row['updated_at'];
                                }
                            }

                            $checkedInToday[$row['user_id']] = [
                                'check_in_time' => $row['check_in_time'],
                                'hours_today' => (float)($row['hours_today'] ?? 0),
                                'break_minutes' => $hasTotalBreakMinutes ? (int)($row['total_break_minutes'] ?? 0) : 0,
                                'checkout_time' => $checkoutTime,
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('getUsers check-in lookup skipped: ' . $e->getMessage());
            }
            
            // Add name field and ensure phone field exists
            foreach ($users as &$user) {
                $user['name'] = $user['username']; // Use username as name
                if (!isset($user['phone'])) {
                    $user['phone'] = null; // Set phone to null if column doesn't exist
                }
                $todayWork = $checkedInToday[$user['id']] ?? null;
                $user['check_in_time'] = $todayWork['check_in_time'] ?? null;
                $user['checked_in_today'] = !empty($user['check_in_time']);
                $user['today_hours_worked'] = $todayWork['hours_today'] ?? 0;
                $user['today_break_minutes'] = $todayWork['break_minutes'] ?? 0;
                $user['checkout_time'] = $todayWork['checkout_time'] ?? null;
            }
            unset($user);

            $this->sendJsonResponse(200, "Users retrieved successfully", $users);
        } catch (PDOException $e) {
            error_log("Database error in getUsers: " . $e->getMessage());
            $this->sendJsonResponse(500, "Database error occurred");
        } catch (Exception $e) {
            error_log("Error in getUsers: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    public function getUser($userId) {
        try {
            // Validate token first
            try {
                $this->validateToken();
            } catch (Exception $e) {
                error_log("Token validation failed: " . $e->getMessage());
                $this->sendJsonResponse(401, "Authentication failed");
                return;
            }

            if (!$this->conn) {
                error_log("Database connection failed in UserController");
                $this->sendJsonResponse(500, "Database connection failed");
                return;
            }

            // Validate user ID
            if (!$userId || !$this->utils->isValidUUID($userId)) {
                $this->sendJsonResponse(400, "Invalid user ID format");
                return;
            }

            $cols = [];
            $res = $this->conn->query("SHOW COLUMNS FROM users");
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }
            $select = ['id', 'username', 'email', 'phone', 'role', 'role_id', 'created_at', 'updated_at'];
            if (in_array('account_active', $cols, true)) {
                $select[] = 'account_active';
            }
            if (in_array('joining_date', $cols, true)) {
                $select[] = 'joining_date';
            }

            $query = "SELECT " . implode(', ', $select) . " FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt) {
                error_log("Failed to prepare statement: " . implode(", ", $this->conn->errorInfo()));
                $this->sendJsonResponse(500, "Database error occurred");
                return;
            }

            if (!$stmt->execute([$userId])) {
                error_log("Failed to execute statement: " . implode(", ", $stmt->errorInfo()));
                $this->sendJsonResponse(500, "Database error occurred");
                return;
            }

            if ($stmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "User not found");
                return;
            }

            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user === false) {
                error_log("Failed to fetch user data after successful query");
                $this->sendJsonResponse(500, "Failed to retrieve user data");
                return;
            }

            $this->sendJsonResponse(200, "User retrieved successfully", $user);
        } catch (PDOException $e) {
            error_log("Database error in getUser: " . $e->getMessage());
            $this->sendJsonResponse(500, "Database error occurred");
        } catch (Exception $e) {
            error_log("Error in getUser: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    public function getAllUsers($page = 1, $limit = 10) {
        try {
            // Validate pagination parameters
            $page = max(1, intval($page));
            $limit = max(1, min(100, intval($limit)));
            $offset = ($page - 1) * $limit;

            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM users";
            $countStmt = $this->conn->query($countQuery);
            $totalUsers = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Get users with pagination
            $query = "SELECT id, username, email, phone, role, role_id, created_at, updated_at 
                     FROM users 
                     ORDER BY created_at DESC 
                     LIMIT ? OFFSET ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$limit, $offset]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $response = [
                'users' => $users,
                'pagination' => [
                    'currentPage' => $page,
                    'totalPages' => ceil($totalUsers / $limit),
                    'totalUsers' => $totalUsers,
                    'limit' => $limit
                ]
            ];

            $this->sendJsonResponse(200, "Users retrieved successfully", $response);
        } catch (PDOException $e) {
            error_log("Database error in getAllUsers: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve users");
        } catch (Exception $e) {
            error_log("Error in getAllUsers: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    public function delete($userId, $force = false) {
        try {
            if (!$this->conn) {
                error_log("Database connection failed in delete()");
                $this->sendJsonResponse(500, "Database connection failed");
                return;
            }
            
            if (!$userId || !$this->utils->isValidUUID($userId)) {
                $this->sendJsonResponse(400, "Invalid user ID format");
                return;
            }

            // Start transaction for safe deletion
            $this->conn->beginTransaction();

            try {
                // Check if user exists
                $checkStmt = $this->conn->prepare("SELECT id, username FROM users WHERE id = ?");
                $checkStmt->execute([$userId]);
                $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    $this->conn->rollback();
                    $this->sendJsonResponse(404, "User not found");
                    return;
                }

                // Check for dependencies and handle them
                $dependencies = [];

                // Check projects created by this user
                $projectStmt = $this->conn->prepare("SELECT COUNT(*) as count FROM projects WHERE created_by = ?");
                $projectStmt->execute([$userId]);
                $projectCount = $projectStmt->fetch(PDO::FETCH_ASSOC)['count'];
                if ($projectCount > 0) {
                    $dependencies[] = "$projectCount projects";
                }

                // Check bugs reported by this user
                $bugStmt = $this->conn->prepare("SELECT COUNT(*) as count FROM bugs WHERE reported_by = ?");
                $bugStmt->execute([$userId]);
                $bugCount = $bugStmt->fetch(PDO::FETCH_ASSOC)['count'];
                if ($bugCount > 0) {
                    $dependencies[] = "$bugCount bugs";
                }

                // Check project memberships
                $memberStmt = $this->conn->prepare("SELECT COUNT(*) as count FROM project_members WHERE user_id = ?");
                $memberStmt->execute([$userId]);
                $memberCount = $memberStmt->fetch(PDO::FETCH_ASSOC)['count'];
                if ($memberCount > 0) {
                    $dependencies[] = "$memberCount project memberships";
                }

                // Check bug attachments
                $attachmentStmt = $this->conn->prepare("SELECT COUNT(*) as count FROM bug_attachments WHERE uploaded_by = ?");
                $attachmentStmt->execute([$userId]);
                $attachmentCount = $attachmentStmt->fetch(PDO::FETCH_ASSOC)['count'];
                if ($attachmentCount > 0) {
                    $dependencies[] = "$attachmentCount file uploads";
                }

                // If there are dependencies and force is not enabled, provide options
                if (!empty($dependencies) && !$force) {
                    $this->conn->rollback();
                    $dependencyText = implode(', ', $dependencies);
                    $this->sendJsonResponse(409, "Cannot delete user '{$user['username']}'. User has associated data: $dependencyText. Please reassign or remove these items first, or use force delete.", ['canForceDelete' => true]);
                    return;
                }

                // If force delete is enabled, handle dependencies
                if ($force && !empty($dependencies)) {
                    // Remove project memberships first (no foreign key dependency)
                    if ($memberCount > 0) {
                        $deleteMembersStmt = $this->conn->prepare("DELETE FROM project_members WHERE user_id = ?");
                        $deleteMembersStmt->execute([$userId]);
                    }

                    // Handle bug attachments - delete files and records
                    if ($attachmentCount > 0) {
                        // Get attachment file paths for cleanup
                        $getAttachmentsStmt = $this->conn->prepare("SELECT file_path FROM bug_attachments WHERE uploaded_by = ?");
                        $getAttachmentsStmt->execute([$userId]);
                        $attachments = $getAttachmentsStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Delete attachment records
                        $deleteAttachmentsStmt = $this->conn->prepare("DELETE FROM bug_attachments WHERE uploaded_by = ?");
                        $deleteAttachmentsStmt->execute([$userId]);
                        
                        // Note: You may want to delete actual files from filesystem here
                        // foreach ($attachments as $attachment) {
                        //     if (file_exists($attachment['file_path'])) {
                        //         unlink($attachment['file_path']);
                        //     }
                        // }
                    }

                    // Handle bugs - set reported_by to NULL or delete
                    if ($bugCount > 0) {
                        // Option 1: Set reported_by to NULL (recommended for data integrity)
                        $updateBugsStmt = $this->conn->prepare("UPDATE bugs SET reported_by = NULL WHERE reported_by = ?");
                        $updateBugsStmt->execute([$userId]);
                        
                        // Option 2: Delete bugs entirely (uncomment if preferred)
                        // $deleteBugsStmt = $this->conn->prepare("DELETE FROM bugs WHERE reported_by = ?");
                        // $deleteBugsStmt->execute([$userId]);
                    }

                    // Handle projects - set created_by to NULL or delete  
                    if ($projectCount > 0) {
                        // Option 1: Set created_by to NULL (recommended for data integrity)
                        $updateProjectsStmt = $this->conn->prepare("UPDATE projects SET created_by = NULL WHERE created_by = ?");
                        $updateProjectsStmt->execute([$userId]);
                        
                        // Option 2: Delete projects entirely (uncomment if preferred) 
                        // $deleteProjectsStmt = $this->conn->prepare("DELETE FROM projects WHERE created_by = ?");
                        // $deleteProjectsStmt->execute([$userId]);
                    }

                    // Handle activity logs
                    $deleteActivityStmt = $this->conn->prepare("DELETE FROM activity_log WHERE user_id = ?");
                    $deleteActivityStmt->execute([$userId]);

                    // Handle activities table if it exists
                    $deleteActivitiesStmt = $this->conn->prepare("DELETE FROM activities WHERE user_id = ?");
                    $deleteActivitiesStmt->execute([$userId]);
                }

                // Now safe to delete the user
                $deleteStmt = $this->conn->prepare("DELETE FROM users WHERE id = ?");
                $result = $deleteStmt->execute([$userId]);
                
                if ($result && $deleteStmt->rowCount() > 0) {
                    $this->conn->commit();
                    $message = $force && !empty($dependencies) 
                        ? "User '{$user['username']}' and all associated data deleted successfully" 
                        : "User '{$user['username']}' deleted successfully";
                    $this->sendJsonResponse(200, $message);
                } else {
                    $this->conn->rollback();
                    $this->sendJsonResponse(500, "Failed to delete user");
                }

            } catch (Exception $e) {
                $this->conn->rollback();
                throw $e;
            }

        } catch (PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            
            // Check if it's a foreign key constraint error
            if (strpos($e->getMessage(), 'foreign key constraint') !== false || 
                strpos($e->getMessage(), 'FOREIGN KEY') !== false ||
                $e->getCode() == '23000') {
                error_log("Foreign key constraint error in delete(): " . $e->getMessage());
                $this->sendJsonResponse(409, "Cannot delete user. User has associated data that must be removed first.");
            } else {
                error_log("Database error in delete(): " . $e->getMessage());
                $this->sendJsonResponse(500, "Database error occurred");
            }
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            error_log("Delete error: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function createUser($data) {
        try {
            $username = trim($data['username'] ?? '');
            $email = trim($data['email'] ?? '');
            $password = $data['password'] ?? '';
            $role = $data['role'] ?? '';
            $roleId = isset($data['role_id']) && !empty($data['role_id']) ? $data['role_id'] : null;
            $phone = isset($data['phone']) && trim($data['phone']) !== '' ? trim($data['phone']) : null;
            $joiningDateRaw = isset($data['joining_date']) ? trim((string)$data['joining_date']) : '';
            $joiningDate = null;
            if ($joiningDateRaw !== '') {
                $actor = $this->validateToken();
                if (!isset($actor->role) || strtolower((string)$actor->role) !== 'admin') {
                    $this->sendJsonResponse(403, "Only administrators can set joining date");
                    return;
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $joiningDateRaw)) {
                    $this->sendJsonResponse(400, "joining_date must be YYYY-MM-DD");
                    return;
                }
                $joiningDate = $joiningDateRaw;
            }

            // Log the incoming data for debugging (remove in production if sensitive)
            error_log("Creating user - Username: $username, Email: $email, Phone: " . ($phone ?? 'null'));

            // Validate required fields
            if (!$username || !$email || !$password) {
                $this->sendJsonResponse(400, "Username, email, and password are required.");
                return;
            }

            // Check for duplicate username
            $checkUsername = $this->conn->prepare("SELECT id FROM users WHERE username = ?");
            $checkUsername->execute([$username]);
            if ($checkUsername->rowCount() > 0) {
                $this->sendJsonResponse(400, "Username already exists");
                return;
            }
            
            // Check for duplicate email
            $checkEmail = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
            $checkEmail->execute([$email]);
            if ($checkEmail->rowCount() > 0) {
                $this->sendJsonResponse(400, "Email already exists");
                return;
            }
            
            // Check for duplicate phone (only if provided)
            if ($phone !== null) {
                $checkPhone = $this->conn->prepare("SELECT id FROM users WHERE phone = ?");
                $checkPhone->execute([$phone]);
                if ($checkPhone->rowCount() > 0) {
                    $this->sendJsonResponse(400, "Phone number already exists");
                    return;
                }
            }

            // Generate UUID for id
            $id = $this->utils->generateUUID(); // Make sure you have a UUID generator in your utils

            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // If role_id not provided, try to map from role string
            if (!$roleId && $role) {
                $mapStmt = $this->conn->prepare("SELECT id, role_name FROM roles WHERE LOWER(role_name) = LOWER(?) LIMIT 1");
                $mapStmt->execute([$role]);
                $mappedRole = $mapStmt->fetch(PDO::FETCH_ASSOC);
                if ($mappedRole) {
                    $roleId = $mappedRole['id'];
                    $role = $mappedRole['role_name']; // Get the actual role name from DB
                }
            }

            // If no role specified, default to user role (tester)
            if (!$roleId) {
                $roleId = 3; // Tester default
                $role = 'tester'; // Set default role for ENUM
            }

            // Normalize role to a valid ENUM value if we have role_id
            // Get the role_name from roles table based on role_id to ensure ENUM compatibility
            if ($roleId) {
                $roleStmt = $this->conn->prepare("SELECT role_name FROM roles WHERE id = ? LIMIT 1");
                $roleStmt->execute([$roleId]);
                $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);
                if ($roleData) {
                    // Map the role_name to a valid ENUM value
                    $actualRole = strtolower($roleData['role_name']);
                    // Map to valid ENUM values
                    if (in_array($actualRole, ['admin', 'developer', 'tester', 'user'])) {
                        $role = $actualRole;
                    } else {
                        // For custom roles, use a default ENUM value ('user')
                        $role = 'user';
                    }
                }
            }

            // Insert user
            $userCols = [];
            $ucRes = $this->conn->query("SHOW COLUMNS FROM users");
            if ($ucRes) {
                while ($row = $ucRes->fetch(PDO::FETCH_ASSOC)) {
                    $userCols[] = $row['Field'];
                }
            }
            $hasJoiningDateCol = in_array('joining_date', $userCols, true);

            if ($hasJoiningDateCol) {
                $query = "INSERT INTO users (id, username, email, phone, password, role, role_id, joining_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $this->conn->prepare($query);
                $ok = $stmt->execute([$id, $username, $email, $phone, $hashedPassword, $role, $roleId, $joiningDate]);
            } else {
                $query = "INSERT INTO users (id, username, email, phone, password, role, role_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $this->conn->prepare($query);
                $ok = $stmt->execute([$id, $username, $email, $phone, $hashedPassword, $role, $roleId]);
            }
            if (!$ok) {
                $errorInfo = $stmt->errorInfo();
                if (strpos($errorInfo[2], 'username') !== false) {
                    $this->sendJsonResponse(409, "Username already exists.");
                } elseif (strpos($errorInfo[2], 'email') !== false) {
                    $this->sendJsonResponse(409, "Email already exists.");
                } else {
                    $this->sendJsonResponse(500, "Failed to create user.");
                }
                return;
            }

            // Log user creation activity
            try {
                $logger = ActivityLogger::getInstance();
                $logger->logUserCreated(
                    $id, // Current user ID (admin who created the user)
                    null, // No specific project for user creation
                    $id, // The newly created user's ID
                    $username,
                    [
                        'email' => $email,
                        'role' => $role,
                        'phone' => $phone
                    ]
                );
            } catch (Exception $e) {
                error_log("Failed to log user creation activity: " . $e->getMessage());
            }

            try {
                require_once __DIR__ . '/../NotificationManager.php';
                // Exclude the new user; all admins (including creator) receive the alert
                NotificationManager::getInstance()->notifyUserRegistered($id, $username, $id);
            } catch (Throwable $e) {
                error_log("Failed to send new user notification: " . $e->getMessage());
            }

            // If user created successfully, send welcome email and WhatsApp to ALL users
            $emailSent = false;
            
            // Generate role-based login URL
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            
            // Determine if we're in development or production
            if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
                // Development - use localhost with role-based routing
                $loginLink = "http://localhost:8080/login";
            } else {
                // Production - use the bug tracker domain with role-based routing
                $loginLink = "https://bugs.bugricer.com/login";
            }
            
            $subject = 'Welcome to BugRicer!';
            $body = "
                <div style=\"font-family: 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f7f6; padding: 20px;\">
                    <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);\">
                        <div style=\"background-color: #2563eb; color: #ffffff; padding: 20px; text-align: center;\">
                            <h1 style=\"margin: 0; font-size: 24px;\">Welcome to BugRicer!</h1>
                            <p style=\"margin: 5px 0 0 0; font-size: 16px;\">Your account has been created.</p>
                        </div>
                        <div style=\"padding: 20px; border-bottom: 1px solid #e2e8f0;\">
                            <h3 style=\"margin-top: 0; color: #1e293b; font-size: 18px;\">Hello {$username},</h3>
                            <p>Welcome to the team! Your BugRicer account is ready. You can now log in to collaborate on projects, report bugs, and track updates.</p>
                            <p>Here are your login details:</p>
                            <div style=\"background-color: #f8fafc; padding: 15px; border-radius: 5px; margin-bottom: 15px;\">
                                <p style=\"font-size: 14px; margin: 5px 0;\"><strong>Username:</strong> {$username}</p>
                                <p style=\"font-size: 14px; margin: 5px 0;\"><strong>Email:</strong> {$email}</p>
                                <p style=\"font-size: 14px; margin: 5px 0;\"><strong>Password:</strong> {$password}</p>
                                <p style=\"font-size: 14px; margin: 5px 0;\"><strong>Role:</strong> " . ucfirst($role) . "</p>
                            </div>
                            <p style=\"text-align: center;\">
                                <a href=\"{$loginLink}\" style=\"background-color: #2563eb; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 5px; display: inline-block;\">Access Your Dashboard</a>
                            </p>
                            <p style=\"font-size: 14px; color: #64748b; text-align: center; margin-top: 15px;\">
                                <strong>Note:</strong> You'll be redirected to your role-specific dashboard after login.
                            </p>
                        </div>
                        <div style=\"background-color: #f8fafc; color: #64748b; padding: 20px; text-align: center; font-size: 12px;\">
                            <p style=\"margin: 0;\">This is an automated notification. Please do not reply to this email.</p>
                            <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " BugRicer. All rights reserved.</p>
                        </div>
                    </div>
                </div>
            ";
            
            // Send email notification
            try {
                require_once __DIR__ . '/../../utils/send_email.php';
                error_log("📧 Sending welcome email notification to new user: $username ($email)");
                $emailSent = sendWelcomeEmail($email, $subject, $body);
                
                if ($emailSent) {
                    error_log("✅ Successfully sent welcome email to: $email");
                } else {
                    error_log("❌ Failed to send welcome email to: $email");
                }
            } catch (Exception $e) {
                error_log("⚠️ Failed to send welcome email notification: " . $e->getMessage());
            }
            
            // Send welcome WhatsApp notification if phone number is provided
            if (!empty(trim($phone))) {
                try {
                    require_once __DIR__ . '/../../utils/whatsapp.php';
                    error_log("📱 Sending welcome WhatsApp notification to new user: $username");
                    
                    $whatsappSent = sendWelcomeWhatsApp(
                        $phone,
                        $username,
                        $loginLink,
                        $email,
                        $password, // Original password before hashing
                        $role
                    );
                    
                    if ($whatsappSent) {
                        error_log("✅ Successfully sent welcome WhatsApp notification to: $phone");
                    } else {
                        error_log("❌ Failed to send welcome WhatsApp notification to: $phone");
                    }
                } catch (Exception $e) {
                    // Don't fail user creation if WhatsApp fails
                    error_log("⚠️ Failed to send welcome WhatsApp notification: " . $e->getMessage());
                }
            } else {
                error_log("⚠️ No phone number provided for user $username, skipping WhatsApp notification");
            }

            $message = "User '{$username}' created successfully";
            if ($emailSent) {
                $message .= " and a welcome email has been sent.";
            } else {
                $message .= ", but the welcome email could not be sent.";
            }

            
            $this->sendJsonResponse(201, $message, [
                "id" => $id,
                "username" => $username,
                "email" => $email,
                "phone" => $phone,
                "role" => $role,
                "role_id" => $roleId
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function updateUser($id, $data) {
        try {
            $conn = $this->getConnection();
            $userCols = [];
            $ucRes = $conn->query("SHOW COLUMNS FROM users");
            if ($ucRes) {
                while ($row = $ucRes->fetch(PDO::FETCH_ASSOC)) {
                    $userCols[] = $row['Field'];
                }
            }
            $hasAccountActiveCol = in_array('account_active', $userCols, true);
            $hasJoiningDateCol = in_array('joining_date', $userCols, true);

            $fields = [];
            $params = [];
            $deactivatingAccount = false;
            if (isset($data['username'])) {
                $fields[] = "username = ?";
                $params[] = $data['username'];
            }
            if (isset($data['email'])) {
                $fields[] = "email = ?";
                $params[] = $data['email'];
            }
            if (isset($data['role'])) {
                // Map role to a valid ENUM value
                $role = $data['role'];
                $roleId = isset($data['role_id']) ? $data['role_id'] : null;
                
                // If role_id not provided, try to map from role string
                if (!$roleId && $role) {
                    $mapStmt = $conn->prepare("SELECT id, role_name FROM roles WHERE LOWER(role_name) = LOWER(?) LIMIT 1");
                    $mapStmt->execute([$role]);
                    $mappedRole = $mapStmt->fetch(PDO::FETCH_ASSOC);
                    if ($mappedRole) {
                        $roleId = $mappedRole['id'];
                    }
                }
                
                // Normalize role to a valid ENUM value if we have role_id
                if ($roleId) {
                    $roleStmt = $conn->prepare("SELECT role_name FROM roles WHERE id = ? LIMIT 1");
                    $roleStmt->execute([$roleId]);
                    $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);
                    if ($roleData) {
                        // Map the role_name to a valid ENUM value
                        $actualRole = strtolower($roleData['role_name']);
                        // Map to valid ENUM values
                        if (in_array($actualRole, ['admin', 'developer', 'tester', 'user'])) {
                            $role = $actualRole;
                        } else {
                            // For custom roles, use a default ENUM value
                            $role = 'user';
                        }
                    }
                }
                
                $fields[] = "role = ?";
                $params[] = $role;
                
                // Also update role_id if we have it
                if ($roleId) {
                    $fields[] = "role_id = ?";
                    $params[] = $roleId;
                }
            } else if (isset($data['role_id'])) {
                // If only role_id is provided
                $roleId = $data['role_id'];
                if ($roleId) {
                    $fields[] = "role_id = ?";
                    $params[] = $roleId;
                }
            }
            if (isset($data['phone'])) {
                // Check for duplicate phone (exclude current user)
                $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
                $stmt->execute([$data['phone'], $id]);
                if ($stmt->rowCount() > 0) {
                    $this->sendJsonResponse(409, "Phone number already exists for another user.");
                    return;
                }
                $fields[] = "phone = ?";
                $params[] = $data['phone'];
            }

            if (array_key_exists('account_active', $data) && $hasAccountActiveCol) {
                $actor = $this->validateToken();
                if (!isset($actor->role) || $actor->role !== 'admin') {
                    $this->sendJsonResponse(403, "Only administrators can change account status");
                    return;
                }
                if (isset($actor->user_id) && $actor->user_id === $id) {
                    $this->sendJsonResponse(400, "You cannot change your own account status from here");
                    return;
                }
                $targetStmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                $targetStmt->execute([$id]);
                $targetRow = $targetStmt->fetch(PDO::FETCH_ASSOC);
                if (!$targetRow) {
                    $this->sendJsonResponse(404, "User not found");
                    return;
                }
                $v = $data['account_active'];
                $activeVal = ($v === true || $v === 1 || $v === '1' || $v === 'true') ? 1 : 0;
                if ($activeVal === 0 && isset($targetRow['role']) && $targetRow['role'] === 'admin') {
                    $this->sendJsonResponse(403, "Cannot deactivate administrator accounts");
                    return;
                }
                $fields[] = "account_active = ?";
                $params[] = $activeVal;
                if ($activeVal === 0) {
                    $deactivatingAccount = true;
                }
            }

            if (array_key_exists('joining_date', $data) && $hasJoiningDateCol) {
                $actor = $this->validateToken();
                if (!isset($actor->role) || strtolower((string)$actor->role) !== 'admin') {
                    $this->sendJsonResponse(403, "Only administrators can set joining date");
                    return;
                }
                $jd = $data['joining_date'];
                if ($jd === null || $jd === '') {
                    $fields[] = "joining_date = ?";
                    $params[] = null;
                } else {
                    $jd = trim((string)$jd);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jd)) {
                        $this->sendJsonResponse(400, "joining_date must be YYYY-MM-DD");
                        return;
                    }
                    $fields[] = "joining_date = ?";
                    $params[] = $jd;
                }
            }

            if (empty($fields)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }

            $params[] = $id;
            $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt->execute($params)) {
                // Revoke push tokens so deactivated users cannot receive FCM
                if ($deactivatingAccount) {
                    try {
                        $fcmCols = [];
                        $fcmColRes = $conn->query("SHOW COLUMNS FROM user_fcm_tokens");
                        if ($fcmColRes) {
                            while ($row = $fcmColRes->fetch(PDO::FETCH_ASSOC)) {
                                $fcmCols[] = $row['Field'];
                            }
                        }
                        if (in_array('is_active', $fcmCols, true)) {
                            $fcmStmt = $conn->prepare("UPDATE user_fcm_tokens SET is_active = 0 WHERE user_id = ?");
                            $fcmStmt->execute([$id]);
                        } else {
                            $fcmStmt = $conn->prepare("DELETE FROM user_fcm_tokens WHERE user_id = ?");
                            $fcmStmt->execute([$id]);
                        }
                        if (in_array('fcm_token', $userCols, true)) {
                            $legacyStmt = $conn->prepare("UPDATE users SET fcm_token = NULL WHERE id = ?");
                            $legacyStmt->execute([$id]);
                        }
                    } catch (Exception $e) {
                        error_log("Failed to deactivate FCM tokens for user {$id}: " . $e->getMessage());
                    }
                }

                // Log user update activity
                try {
                    // Get current user info for logging
                    $currentUser = $this->conn->prepare("SELECT username FROM users WHERE id = ?");
                    $currentUser->execute([$id]);
                    $user = $currentUser->fetch(PDO::FETCH_ASSOC);
                    
                    $logger = ActivityLogger::getInstance();
                    $logger->logUserUpdated(
                        $id, // Current user ID (admin who updated the user)
                        null, // No specific project for user updates
                        $id, // The updated user's ID
                        $user['username'],
                        [
                            'updated_fields' => array_keys($data),
                            'role' => $data['role'] ?? null
                        ]
                    );
                } catch (Exception $e) {
                    error_log("Failed to log user update activity: " . $e->getMessage());
                }
                
                // Fetch and return the updated user data
                $selectParts = [
                    'id',
                    'username',
                    'email',
                    'phone',
                    'role',
                    'role_id',
                    'created_at',
                    'updated_at',
                    'last_active_at',
                ];
                if ($hasAccountActiveCol) {
                    $selectParts[] = 'account_active';
                }
                if ($hasJoiningDateCol) {
                    $selectParts[] = 'joining_date';
                }
                $selectSql = implode(",\n                        ", $selectParts) . ",
                        COALESCE(
                            (SELECT username FROM users WHERE id = ?),
                            username
                        ) as name";
                $fetchStmt = $conn->prepare("
                    SELECT 
                        {$selectSql}
                    FROM users 
                    WHERE id = ?
                ");
                $fetchStmt->execute([$id, $id]);
                $updatedUser = $fetchStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($updatedUser) {
                    $this->sendJsonResponse(200, "User updated successfully", $updatedUser);
                } else {
                    $this->sendJsonResponse(200, "User updated successfully");
                }
            } else {
                $this->sendJsonResponse(500, "Failed to update user");
            }
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getActiveHours($userId, $period = 'daily') {
        try {
            // Validate user ID
            if (!$userId || !$this->utils->isValidUUID($userId)) {
                $this->sendJsonResponse(400, "Invalid user ID format");
                return;
            }

            ActivitySessionsSchema::ensureSchema($this->conn);

            // Check if user_activity_sessions table exists
            if (!ActivitySessionsSchema::tableExists($this->conn)) {
                // Return empty data if table doesn't exist
                $response = [
                    'period' => $period,
                    'date_range' => $this->getDateRange($period),
                    'summary' => [
                        'total_hours' => 0,
                    'total_minutes' => 0,
                    'total_sessions' => 0,
                    'active_days' => 0,
                    'average_hours_per_day' => 0
                ],
                'daily_breakdown' => []
            ];
                $this->sendJsonResponse(200, "Active hours retrieved successfully (no activity data available)", $response);
                return;
            }

            // Determine date range based on period
            $dateRange = $this->getDateRange($period);
            $startDate = $dateRange['start'];
            $endDate = $dateRange['end'];

            $minutesExpr = ActivitySessionsSchema::minutesCaseExpression($this->conn);

            // Calculate active hours for the period
            $query = "
                SELECT 
                    DATE(session_start) as date,
                    SUM({$minutesExpr}) as total_minutes,
                    COUNT(*) as session_count
                FROM user_activity_sessions 
                WHERE user_id = ? 
                AND session_start >= ? 
                AND session_start <= ?
                GROUP BY DATE(session_start)
                ORDER BY date DESC
            ";

            $stmt = $this->conn->prepare($query);
            $stmt->execute([$userId, $startDate, $endDate]);
            $dailyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate summary statistics
            $totalMinutes = 0;
            $totalSessions = 0;
            $activeDays = count($dailyData);

            foreach ($dailyData as $day) {
                $totalMinutes += (int)$day['total_minutes'];
                $totalSessions += (int)$day['session_count'];
            }

            $totalHours = round($totalMinutes / 60, 2);
            $averageHoursPerDay = $activeDays > 0 ? round($totalHours / $activeDays, 2) : 0;

            $response = [
                'period' => $period,
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate
                ],
                'summary' => [
                    'total_hours' => $totalHours,
                    'total_minutes' => $totalMinutes,
                    'total_sessions' => $totalSessions,
                    'active_days' => $activeDays,
                    'average_hours_per_day' => $averageHoursPerDay
                ],
                'daily_breakdown' => $dailyData
            ];

            $this->sendJsonResponse(200, "Active hours retrieved successfully", $response);

        } catch (Exception $e) {
            error_log("Error in getActiveHours: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    private function getDateRange($period) {
        $istTimezone = new DateTimeZone('Asia/Kolkata');
        $now = new DateTime('now', $istTimezone);
        $start = new DateTime('now', $istTimezone);

        switch ($period) {
            case 'daily':
                $start->modify('today');
                $end = clone $start;
                $end->modify('+1 day');
                break;
            case 'weekly':
                $start->modify('monday this week');
                $end = clone $start;
                $end->modify('+7 days');
                break;
            case 'monthly':
                $start->modify('first day of this month');
                $end = clone $start;
                $end->modify('+1 month');
                break;
            case 'yearly':
                $start->modify('first day of January this year');
                $end = clone $start;
                $end->modify('+1 year');
                break;
            default:
                $start->modify('today');
                $end = clone $start;
                $end->modify('+1 day');
        }

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Admin-only activity snapshot for a single user (today's work + recent bugs/fixes/updates).
     */
    public function getActivitySnapshot($userId) {
        try {
            try {
                $decoded = $this->validateToken();
            } catch (Exception $e) {
                error_log("Token validation failed: " . $e->getMessage());
                $this->sendJsonResponse(401, "Authentication failed");
                return;
            }

            if (!$decoded || strtolower((string)($decoded->role ?? '')) !== 'admin') {
                $this->sendJsonResponse(403, "Only administrators can view activity snapshots");
                return;
            }

            if (!$this->conn) {
                $this->sendJsonResponse(500, "Database connection failed");
                return;
            }

            if (!$userId || !$this->utils->isValidUUID($userId)) {
                $this->sendJsonResponse(400, "Invalid user ID format");
                return;
            }

            $cols = [];
            $res = $this->conn->query("SHOW COLUMNS FROM users");
            if ($res) {
                while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
                    $cols[] = $row['Field'];
                }
            }
            $hasLastActive = in_array('last_active_at', $cols, true);

            $select = ['id', 'username', 'email', 'role', 'role_id'];
            if ($hasLastActive) {
                $select[] = 'last_active_at';
                $select[] = "(CASE WHEN last_active_at IS NULL THEN 'offline' WHEN TIMESTAMPDIFF(SECOND, last_active_at, NOW()) < 120 THEN 'active' WHEN TIMESTAMPDIFF(SECOND, last_active_at, NOW()) < 900 THEN 'idle' ELSE 'offline' END) as status";
            } else {
                $select[] = "'offline' as status";
            }

            $stmt = $this->conn->prepare("SELECT " . implode(', ', $select) . " FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $this->sendJsonResponse(404, "User not found");
                return;
            }
            $user['name'] = $user['username'];

            $checkInTime = null;
            $todayHours = 0.0;
            $breakMinutes = 0;
            $checkoutTime = null;
            $work = null;
            $workHistory = [];

            try {
                $wsColumns = [];
                $colRes = $this->conn->query("SHOW COLUMNS FROM work_submissions");
                if ($colRes) {
                    while ($colRow = $colRes->fetch(PDO::FETCH_ASSOC)) {
                        $wsColumns[] = $colRow['Field'];
                    }
                }

                if (!empty($wsColumns)) {
                    $hasTotalBreakMinutes = in_array('total_break_minutes', $wsColumns, true);
                    $hasCheckIn = in_array('check_in_time', $wsColumns, true);
                    $hasPlannedWork = in_array('planned_work', $wsColumns, true);
                    $hasPlannedWorkStatus = in_array('planned_work_status', $wsColumns, true);
                    $hasPlannedWorkNotes = in_array('planned_work_notes', $wsColumns, true);
                    $hasPlannedProjects = in_array('planned_projects', $wsColumns, true);
                    $hasProjectUpdates = in_array('project_updates', $wsColumns, true);

                    $selectFields = [
                        'id',
                        'user_id',
                        'submission_date',
                        'hours_today',
                        'updated_at',
                        'created_at',
                        'completed_tasks',
                        'pending_tasks',
                        'ongoing_tasks',
                        'notes',
                        'start_time',
                    ];
                    if ($hasCheckIn) $selectFields[] = 'check_in_time';
                    if ($hasTotalBreakMinutes) $selectFields[] = 'total_break_minutes';
                    if ($hasPlannedWork) $selectFields[] = 'planned_work';
                    if ($hasPlannedWorkStatus) $selectFields[] = 'planned_work_status';
                    if ($hasPlannedWorkNotes) $selectFields[] = 'planned_work_notes';
                    if ($hasPlannedProjects) $selectFields[] = 'planned_projects';
                    if ($hasProjectUpdates) $selectFields[] = 'project_updates';

                    $splitLines = function ($text) {
                        $lines = [];
                        foreach (explode("\n", (string)$text) as $line) {
                            $line = trim($line);
                            if ($line === '' || preg_match('/^\[BREAK\]/i', $line)) continue;
                            if (stripos($line, '[OVERTIME APPROVAL REQUEST]') === 0) continue;
                            $lines[] = $line;
                        }
                        return $lines;
                    };

                    $parseProjectIds = function ($raw) {
                        if ($raw === null || $raw === '') {
                            return [];
                        }
                        if (is_array($raw)) {
                            return array_values(array_filter(array_map('strval', $raw)));
                        }
                        $decoded = json_decode((string)$raw, true);
                        if (is_array($decoded)) {
                            return array_values(array_filter(array_map('strval', $decoded)));
                        }
                        $trimmed = trim((string)$raw);
                        return $trimmed !== '' ? [$trimmed] : [];
                    };

                    // Today + previous days (newest first). Cap keeps payload reasonable for long tenure.
                    $wsStmt = $this->conn->prepare(
                        'SELECT ' . implode(', ', $selectFields) . '
                         FROM work_submissions
                         WHERE user_id = ?
                         ORDER BY submission_date DESC, id DESC
                         LIMIT 120'
                    );
                    $wsStmt->execute([$userId]);
                    $submissions = $wsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $allProjectIds = [];
                    foreach ($submissions as $row) {
                        if (!$hasPlannedProjects) {
                            break;
                        }
                        foreach ($parseProjectIds($row['planned_projects'] ?? null) as $pid) {
                            $allProjectIds[$pid] = true;
                        }
                    }

                    $projectNameMap = [];
                    if (!empty($allProjectIds)) {
                        $ids = array_keys($allProjectIds);
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $pStmt = $this->conn->prepare("SELECT id, name FROM projects WHERE id IN ($placeholders)");
                        $pStmt->execute($ids);
                        foreach ($pStmt->fetchAll(PDO::FETCH_ASSOC) as $pRow) {
                            $projectNameMap[(string)$pRow['id']] = $pRow['name'];
                        }
                    }

                    $todayDate = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');

                    foreach ($submissions as $submission) {
                        $submissionDate = (string)($submission['submission_date'] ?? '');
                        $projectIds = $hasPlannedProjects
                            ? $parseProjectIds($submission['planned_projects'] ?? null)
                            : [];
                        $projectNames = [];
                        foreach ($projectIds as $pid) {
                            if (isset($projectNameMap[$pid])) {
                                $projectNames[] = $projectNameMap[$pid];
                            }
                        }

                        $completedLines = $splitLines($submission['completed_tasks'] ?? '');
                        $pendingLines = $splitLines($submission['pending_tasks'] ?? '');
                        $ongoingLines = $splitLines($submission['ongoing_tasks'] ?? '');
                        $upcomingLines = $splitLines($submission['notes'] ?? '');

                        $projectUpdates = [];
                        if ($hasProjectUpdates && !empty($submission['project_updates'])) {
                            $decodedUpdates = json_decode($submission['project_updates'], true);
                            if (is_array($decodedUpdates)) {
                                foreach ($decodedUpdates as $pu) {
                                    $pid = (string)($pu['project_id'] ?? $pu['id'] ?? '');
                                    $projectUpdates[] = [
                                        'project_id' => $pid,
                                        'project_name' => $projectNameMap[$pid] ?? ($pu['project_name'] ?? null),
                                        'update' => $pu['update'] ?? $pu['text'] ?? $pu['notes'] ?? '',
                                    ];
                                }
                            }
                        }

                        $rowCheckIn = $hasCheckIn ? ($submission['check_in_time'] ?? null) : null;
                        $rowHours = (float)($submission['hours_today'] ?? 0);
                        $rowBreak = $hasTotalBreakMinutes ? (int)($submission['total_break_minutes'] ?? 0) : 0;
                        $plannedWork = $hasPlannedWork ? ($submission['planned_work'] ?? null) : null;
                        $plannedWorkNotes = $hasPlannedWorkNotes ? ($submission['planned_work_notes'] ?? null) : null;
                        $plannedWorkStatus = $hasPlannedWorkStatus ? ($submission['planned_work_status'] ?? null) : null;

                        $hasPlannedOrNotes = !empty($projectNames)
                            || trim((string)$plannedWork) !== ''
                            || trim((string)$plannedWorkNotes) !== ''
                            || !empty($upcomingLines)
                            || !empty($projectUpdates);

                        // Keep days that have check-in / hours / tasks / planned content
                        $hasAnySignal = $hasPlannedOrNotes
                            || !empty($rowCheckIn)
                            || $rowHours > 0
                            || $rowBreak > 0
                            || !empty($completedLines)
                            || !empty($pendingLines)
                            || !empty($ongoingLines);

                        if (!$hasAnySignal) {
                            continue;
                        }

                        $dayEntry = [
                            'id' => isset($submission['id']) ? (int)$submission['id'] : null,
                            'submission_date' => $submissionDate,
                            'is_today' => ($submissionDate === $todayDate),
                            'check_in_time' => $rowCheckIn,
                            'hours_today' => $rowHours,
                            'break_minutes' => $rowBreak,
                            'start_time' => $submission['start_time'] ?? null,
                            'planned_work' => $plannedWork,
                            'planned_work_status' => $plannedWorkStatus,
                            'planned_work_notes' => $plannedWorkNotes,
                            'planned_projects' => $projectIds,
                            'project_names' => $projectNames,
                            'project_updates' => $projectUpdates,
                            'notes' => $submission['notes'] ?? '',
                            'tasks' => [
                                'completed' => $completedLines,
                                'pending' => $pendingLines,
                                'ongoing' => $ongoingLines,
                                'upcoming' => $upcomingLines,
                            ],
                        ];

                        if ($submissionDate === $todayDate && $work === null) {
                            $checkInTime = $rowCheckIn;
                            $todayHours = $rowHours;
                            $breakMinutes = $rowBreak;

                            $hasWorkUpdate = $todayHours > 0
                                || $breakMinutes > 0
                                || trim((string)($submission['completed_tasks'] ?? '')) !== ''
                                || trim((string)($submission['pending_tasks'] ?? '')) !== ''
                                || trim((string)($submission['ongoing_tasks'] ?? '')) !== ''
                                || trim((string)($submission['notes'] ?? '')) !== '';

                            if ($hasWorkUpdate && !empty($submission['updated_at'])) {
                                $updatedAt = strtotime($submission['updated_at']);
                                $checkInAt = !empty($checkInTime) ? strtotime($checkInTime) : null;
                                if ($updatedAt && (!$checkInAt || $updatedAt > ($checkInAt + 60))) {
                                    $checkoutTime = $submission['updated_at'];
                                }
                            }

                            $work = array_merge($dayEntry, [
                                'checkout_time' => $checkoutTime,
                                'completed_tasks' => $submission['completed_tasks'] ?? '',
                                'pending_tasks' => $submission['pending_tasks'] ?? '',
                                'ongoing_tasks' => $submission['ongoing_tasks'] ?? '',
                            ]);
                        }

                        // History: days that actually have planned projects / notes (today + previous)
                        if ($hasPlannedOrNotes) {
                            $workHistory[] = [
                                'id' => $dayEntry['id'],
                                'submission_date' => $submissionDate,
                                'is_today' => $dayEntry['is_today'],
                                'check_in_time' => $rowCheckIn,
                                'hours_today' => $rowHours,
                                'planned_work' => $plannedWork,
                                'planned_work_status' => $plannedWorkStatus,
                                'planned_work_notes' => $plannedWorkNotes,
                                'planned_projects' => $projectIds,
                                'project_names' => $projectNames,
                                'notes' => $submission['notes'] ?? '',
                                'tasks' => [
                                    'upcoming' => $upcomingLines,
                                ],
                            ];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('getActivitySnapshot work lookup skipped: ' . $e->getMessage());
            }

            $user['check_in_time'] = $checkInTime;
            $user['checked_in_today'] = !empty($checkInTime);
            $user['today_hours_worked'] = $todayHours;
            $user['today_break_minutes'] = $breakMinutes;
            $user['checkout_time'] = $checkoutTime;

            $bugs = [];
            $fixes = [];
            $updates = [];
            $bugCount = 0;
            $fixCount = 0;
            $updateCount = 0;

            try {
                $countStmt = $this->conn->prepare("SELECT COUNT(id) as total FROM bugs WHERE reported_by = ?");
                $countStmt->execute([$userId]);
                $bugCount = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

                $bugsStmt = $this->conn->prepare(
                    "SELECT b.id, b.title, b.status, b.priority, b.created_at, b.updated_at, b.project_id, p.name as project_name
                     FROM bugs b
                     LEFT JOIN projects p ON p.id = b.project_id
                     WHERE b.reported_by = ?
                     ORDER BY b.created_at DESC
                     LIMIT 15"
                );
                $bugsStmt->execute([$userId]);
                $bugs = $bugsStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log('getActivitySnapshot bugs skipped: ' . $e->getMessage());
            }

            try {
                $bugCols = [];
                $bugColRes = $this->conn->query("SHOW COLUMNS FROM bugs");
                if ($bugColRes) {
                    while ($r = $bugColRes->fetch(PDO::FETCH_ASSOC)) {
                        $bugCols[] = $r['Field'];
                    }
                }
                $hasFixedBy = in_array('fixed_by', $bugCols, true);

                if ($hasFixedBy) {
                    $fixCountStmt = $this->conn->prepare(
                        "SELECT COUNT(id) as total FROM bugs
                         WHERE status = 'fixed' AND (fixed_by = ? OR (fixed_by IS NULL AND updated_by = ?))"
                    );
                    $fixCountStmt->execute([$userId, $userId]);
                    $fixCount = (int)($fixCountStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

                    $fixesStmt = $this->conn->prepare(
                        "SELECT b.id, b.title, b.status, b.priority, b.created_at, b.updated_at, b.project_id, p.name as project_name,
                                b.fixed_by, b.updated_by
                         FROM bugs b
                         LEFT JOIN projects p ON p.id = b.project_id
                         WHERE b.status = 'fixed' AND (b.fixed_by = ? OR (b.fixed_by IS NULL AND b.updated_by = ?))
                         ORDER BY COALESCE(b.updated_at, b.created_at) DESC
                         LIMIT 15"
                    );
                    $fixesStmt->execute([$userId, $userId]);
                } else {
                    $fixCountStmt = $this->conn->prepare(
                        "SELECT COUNT(id) as total FROM bugs WHERE status = 'fixed' AND updated_by = ?"
                    );
                    $fixCountStmt->execute([$userId]);
                    $fixCount = (int)($fixCountStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

                    $fixesStmt = $this->conn->prepare(
                        "SELECT b.id, b.title, b.status, b.priority, b.created_at, b.updated_at, b.project_id, p.name as project_name,
                                b.updated_by
                         FROM bugs b
                         LEFT JOIN projects p ON p.id = b.project_id
                         WHERE b.status = 'fixed' AND b.updated_by = ?
                         ORDER BY COALESCE(b.updated_at, b.created_at) DESC
                         LIMIT 15"
                    );
                    $fixesStmt->execute([$userId]);
                }
                $fixes = $fixesStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log('getActivitySnapshot fixes skipped: ' . $e->getMessage());
            }

            try {
                $updCountStmt = $this->conn->prepare("SELECT COUNT(id) as total FROM updates WHERE created_by = ?");
                $updCountStmt->execute([$userId]);
                $updateCount = (int)($updCountStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

                $updStmt = $this->conn->prepare(
                    "SELECT u.id, u.title, u.type, u.status, u.created_at, u.updated_at, u.project_id, p.name as project_name
                     FROM updates u
                     LEFT JOIN projects p ON p.id = u.project_id
                     WHERE u.created_by = ?
                     ORDER BY u.created_at DESC
                     LIMIT 15"
                );
                $updStmt->execute([$userId]);
                $updates = $updStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log('getActivitySnapshot updates skipped: ' . $e->getMessage());
            }

            $assignedProjects = [];
            try {
                $projCols = [];
                $projColRes = $this->conn->query("SHOW COLUMNS FROM projects");
                if ($projColRes) {
                    while ($r = $projColRes->fetch(PDO::FETCH_ASSOC)) {
                        $projCols[] = $r['Field'];
                    }
                }
                $hasIsActive = in_array('is_active', $projCols, true);
                $hasStatus = in_array('status', $projCols, true);

                $projectSelect = "p.id, p.name, pm.role as member_role";
                if ($hasIsActive) $projectSelect .= ", p.is_active";
                if ($hasStatus) $projectSelect .= ", p.status";

                $assignedStmt = $this->conn->prepare(
                    "SELECT $projectSelect
                     FROM project_members pm
                     INNER JOIN projects p ON p.id = pm.project_id
                     WHERE pm.user_id = ?
                     ORDER BY p.name ASC"
                );
                $assignedStmt->execute([$userId]);
                $assignedProjects = $assignedStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log('getActivitySnapshot assigned projects skipped: ' . $e->getMessage());
            }

            $this->sendJsonResponse(200, "Activity snapshot retrieved successfully", [
                'user' => $user,
                'work' => $work,
                'work_history' => $workHistory,
                'assigned_projects' => $assignedProjects,
                'bugs' => $bugs,
                'fixes' => $fixes,
                'updates' => $updates,
                'counts' => [
                    'bugs' => $bugCount,
                    'fixes' => $fixCount,
                    'updates' => $updateCount,
                    'projects' => count($assignedProjects),
                ],
            ]);
        } catch (PDOException $e) {
            error_log("Database error in getActivitySnapshot: " . $e->getMessage());
            $this->sendJsonResponse(500, "Database error occurred");
        } catch (Exception $e) {
            error_log("Error in getActivitySnapshot: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    /**
     * Profile portfolio: assigned projects with bugs/fixes/updates and status-duration timelines.
     * Accessible by the user themselves or by an admin.
     */
    public function getProfilePortfolio($userId) {
        try {
            try {
                $decoded = $this->validateToken();
            } catch (Exception $e) {
                $this->sendJsonResponse(401, "Authentication failed");
                return;
            }

            if (!$decoded || empty($decoded->user_id)) {
                $this->sendJsonResponse(401, "Authentication failed");
                return;
            }

            if (!$this->conn) {
                $this->sendJsonResponse(500, "Database connection failed");
                return;
            }

            if (!$userId || !$this->utils->isValidUUID($userId)) {
                $this->sendJsonResponse(400, "Invalid user ID format");
                return;
            }

            $viewerId = (string)$decoded->user_id;
            $viewerRole = strtolower((string)($decoded->role ?? ''));
            $isSelf = $viewerId === (string)$userId;
            $isAdmin = $viewerRole === 'admin';

            if (!$isSelf && !$isAdmin) {
                $this->sendJsonResponse(403, "You can only view your own project portfolio");
                return;
            }

            $userStmt = $this->conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $this->sendJsonResponse(404, "User not found");
                return;
            }

            $formatDuration = function ($seconds) {
                $seconds = max(0, (int)$seconds);
                if ($seconds < 60) {
                    return $seconds . 's';
                }
                $days = intdiv($seconds, 86400);
                $hours = intdiv($seconds % 86400, 3600);
                $mins = intdiv($seconds % 3600, 60);
                $parts = [];
                if ($days > 0) $parts[] = $days . 'd';
                if ($hours > 0) $parts[] = $hours . 'h';
                if ($mins > 0 || empty($parts)) $parts[] = $mins . 'm';
                return implode(' ', $parts);
            };

            $parseTs = function ($value) {
                if ($value === null || $value === '') {
                    return null;
                }
                $ts = strtotime((string)$value);
                return $ts === false ? null : $ts;
            };

            $buildTimeline = function (array $events, $currentStatus, $nowTs) use ($parseTs, $formatDuration) {
                usort($events, function ($a, $b) {
                    $ta = $a['at_ts'] ?? 0;
                    $tb = $b['at_ts'] ?? 0;
                    if ($ta === $tb) {
                        return ($a['seq'] ?? 0) <=> ($b['seq'] ?? 0);
                    }
                    return $ta <=> $tb;
                });

                $timeline = [];
                $count = count($events);
                for ($i = 0; $i < $count; $i++) {
                    $enteredAt = $events[$i]['at'] ?? null;
                    $enteredTs = $events[$i]['at_ts'] ?? null;
                    $status = $events[$i]['status'] ?? null;
                    if (!$status || $enteredTs === null) {
                        continue;
                    }

                    $exitedAt = null;
                    $exitedTs = null;
                    $isCurrent = ($i === $count - 1);
                    if (!$isCurrent) {
                        $exitedAt = $events[$i + 1]['at'] ?? null;
                        $exitedTs = $events[$i + 1]['at_ts'] ?? null;
                    } else {
                        $exitedTs = $nowTs;
                    }

                    $duration = ($exitedTs !== null && $enteredTs !== null)
                        ? max(0, $exitedTs - $enteredTs)
                        : null;

                    $timeline[] = [
                        'status' => $status,
                        'from_status' => $events[$i]['from_status'] ?? null,
                        'entered_at' => $enteredAt,
                        'exited_at' => $isCurrent ? null : $exitedAt,
                        'duration_seconds' => $duration,
                        'duration_label' => $duration !== null ? $formatDuration($duration) : null,
                        'is_current' => $isCurrent,
                        'source' => $events[$i]['source'] ?? 'activity',
                    ];
                }

                // Ensure current status is represented
                if (empty($timeline) && $currentStatus) {
                    return [[
                        'status' => $currentStatus,
                        'from_status' => null,
                        'entered_at' => null,
                        'exited_at' => null,
                        'duration_seconds' => null,
                        'duration_label' => null,
                        'is_current' => true,
                        'source' => 'current',
                    ]];
                }

                return $timeline;
            };

            $extractStatusEventsFromActivities = function (array $activities, $entityId, $fallbackStatus, $raisedAt, $updatedAt) use ($parseTs) {
                $events = [];
                $seq = 0;
                $raisedTs = $parseTs($raisedAt);
                if ($raisedTs !== null) {
                    $events[] = [
                        'status' => 'pending',
                        'from_status' => null,
                        'at' => $raisedAt,
                        'at_ts' => $raisedTs,
                        'seq' => $seq++,
                        'source' => 'raised',
                    ];
                }

                $lastStatus = 'pending';
                foreach ($activities as $activity) {
                    $relatedId = (string)($activity['related_id'] ?? '');
                    if ($relatedId !== (string)$entityId) {
                        continue;
                    }

                    $meta = [];
                    if (!empty($activity['metadata'])) {
                        $decodedMeta = json_decode($activity['metadata'], true);
                        if (is_array($decodedMeta)) {
                            $meta = $decodedMeta;
                        }
                    }

                    $type = (string)($activity['activity_type'] ?? '');
                    $at = $activity['created_at'] ?? null;
                    $atTs = $parseTs($at);
                    if ($atTs === null) {
                        continue;
                    }

                    $toStatus = null;
                    $fromStatus = null;

                    if ($type === 'bug_status_changed' || !empty($meta['from']) || !empty($meta['to'])) {
                        $fromStatus = isset($meta['from']) ? (string)$meta['from'] : null;
                        $toStatus = isset($meta['to']) ? (string)$meta['to'] : null;
                    }

                    if (!$toStatus && isset($meta['status']) && is_string($meta['status'])) {
                        $toStatus = (string)$meta['status'];
                        $fromStatus = $lastStatus;
                    }

                    if ($type === 'bug_fixed' || $type === 'fix_created') {
                        $toStatus = 'fixed';
                        $fromStatus = $fromStatus ?: $lastStatus;
                    }

                    if ($type === 'bug_created' || $type === 'bug_reported' || $type === 'update_created') {
                        $toStatus = $toStatus ?: 'pending';
                        $fromStatus = null;
                    }

                    if (!$toStatus || $toStatus === $lastStatus) {
                        continue;
                    }

                    $events[] = [
                        'status' => $toStatus,
                        'from_status' => $fromStatus ?: $lastStatus,
                        'at' => $at,
                        'at_ts' => $atTs,
                        'seq' => $seq++,
                        'source' => $type ?: 'activity',
                    ];
                    $lastStatus = $toStatus;
                }

                // If history never reached the current status, append using updated_at
                $current = $fallbackStatus ?: $lastStatus;
                if ($current && $current !== $lastStatus) {
                    $updatedTs = $parseTs($updatedAt) ?: $raisedTs;
                    if ($updatedTs !== null) {
                        $events[] = [
                            'status' => $current,
                            'from_status' => $lastStatus,
                            'at' => $updatedAt ?: $raisedAt,
                            'at_ts' => $updatedTs,
                            'seq' => $seq++,
                            'source' => 'inferred',
                        ];
                    }
                }

                // Deduplicate consecutive same-status events
                $deduped = [];
                foreach ($events as $event) {
                    $prev = end($deduped);
                    if ($prev && ($prev['status'] ?? null) === ($event['status'] ?? null)) {
                        continue;
                    }
                    $deduped[] = $event;
                }

                return $deduped;
            };

            $computeDurations = function (array $timeline, $raisedAt, $currentStatus) use ($parseTs) {
                $nowTs = time();
                $raisedTs = $parseTs($raisedAt);
                $fixedAt = null;
                $fixedTs = null;
                $inProgressAt = null;
                $inProgressTs = null;

                foreach ($timeline as $step) {
                    $status = strtolower((string)($step['status'] ?? ''));
                    $enteredTs = $parseTs($step['entered_at'] ?? null);
                    if ($status === 'in_progress' && $inProgressTs === null) {
                        $inProgressAt = $step['entered_at'] ?? null;
                        $inProgressTs = $enteredTs;
                    }
                    if (in_array($status, ['fixed', 'approved', 'completed', 'declined', 'rejected'], true)) {
                        $fixedAt = $step['entered_at'] ?? null;
                        $fixedTs = $enteredTs;
                    }
                }

                $isClosed = in_array(strtolower((string)$currentStatus), ['fixed', 'approved', 'completed', 'declined', 'rejected'], true);
                $endTs = $fixedTs ?: ($isClosed ? $parseTs($timeline[count($timeline) - 1]['entered_at'] ?? null) : $nowTs);

                $riseSeconds = ($raisedTs !== null && $endTs !== null) ? max(0, $endTs - $raisedTs) : null;
                $fixSeconds = null;
                if ($fixedTs !== null) {
                    $startFix = $inProgressTs ?: $raisedTs;
                    if ($startFix !== null) {
                        $fixSeconds = max(0, $fixedTs - $startFix);
                    }
                }

                return [
                    'raised_at' => $raisedAt,
                    'resolved_at' => $fixedAt,
                    'rise_duration_seconds' => $riseSeconds,
                    'fix_duration_seconds' => $fixSeconds,
                    'is_open' => !$isClosed,
                ];
            };

            // ---- Projects (membership + created-by) ----
            $projCols = [];
            $projColRes = $this->conn->query("SHOW COLUMNS FROM projects");
            if ($projColRes) {
                while ($r = $projColRes->fetch(PDO::FETCH_ASSOC)) {
                    $projCols[] = $r['Field'];
                }
            }
            $hasProjectStatus = in_array('status', $projCols, true);
            $hasIsActive = in_array('is_active', $projCols, true);

            $projectSelect = "p.id, p.name, p.created_at as project_created_at, p.created_by";
            if ($hasProjectStatus) $projectSelect .= ", p.status";
            if ($hasIsActive) $projectSelect .= ", p.is_active";

            // Only assigned projects (same source as Assign Projects / get_user_projects.php)
            $projectsStmt = $this->conn->prepare(
                "SELECT $projectSelect, pm.role AS member_role, pm.joined_at
                 FROM project_members pm
                 INNER JOIN projects p ON p.id = pm.project_id
                 WHERE pm.user_id = ?
                 ORDER BY pm.joined_at DESC, p.name ASC"
            );
            $projectsStmt->execute([$userId]);
            $projectRows = $projectsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $projectsById = [];
            foreach ($projectRows as $row) {
                $pid = (string)$row['id'];
                if (isset($projectsById[$pid])) {
                    continue;
                }
                $projectsById[$pid] = [
                    'id' => $pid,
                    'name' => $row['name'],
                    'status' => $row['status'] ?? (($hasIsActive && isset($row['is_active'])) ? ((int)$row['is_active'] === 1 ? 'active' : 'inactive') : null),
                    'is_active' => isset($row['is_active']) ? (int)$row['is_active'] : null,
                    'member_role' => $row['member_role'] ?? null,
                    'assigned_at' => $row['joined_at'] ?? null,
                    'is_member' => true,
                    'is_creator' => (string)($row['created_by'] ?? '') === (string)$userId,
                    'counts' => ['bugs' => 0, 'fixes' => 0, 'updates' => 0],
                    'bugs' => [],
                    'fixes' => [],
                    'updates' => [],
                ];
            }

            $projectIds = array_keys($projectsById);

            // ---- Bugs related to user ----
            $bugCols = [];
            $bugColRes = $this->conn->query("SHOW COLUMNS FROM bugs");
            if ($bugColRes) {
                while ($r = $bugColRes->fetch(PDO::FETCH_ASSOC)) {
                    $bugCols[] = $r['Field'];
                }
            }
            $hasFixedBy = in_array('fixed_by', $bugCols, true);

            $bugSelect = "b.id, b.title, b.status, b.priority, b.created_at, b.updated_at, b.project_id, b.reported_by, b.updated_by";
            if ($hasFixedBy) $bugSelect .= ", b.fixed_by";

            $bugsStmt = $this->conn->prepare(
                "SELECT $bugSelect, p.name AS project_name
                 FROM bugs b
                 LEFT JOIN projects p ON p.id = b.project_id
                 WHERE b.reported_by = ?
                    OR b.updated_by = ?
                    " . ($hasFixedBy ? " OR b.fixed_by = ?" : "") . "
                 ORDER BY b.created_at DESC
                 LIMIT 500"
            );
            $bugsStmt->execute($hasFixedBy ? [$userId, $userId, $userId] : [$userId, $userId]);
            $bugRows = $bugsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // ---- Updates created by user ----
            $updCols = [];
            $updColRes = $this->conn->query("SHOW COLUMNS FROM updates");
            if ($updColRes) {
                while ($r = $updColRes->fetch(PDO::FETCH_ASSOC)) {
                    $updCols[] = $r['Field'];
                }
            }
            $hasApprovedAt = in_array('approved_at', $updCols, true);
            $hasDeclinedAt = in_array('declined_at', $updCols, true);
            $hasCompletedAt = in_array('completed_at', $updCols, true);

            $updSelect = "u.id, u.title, u.type, u.status, u.created_at, u.updated_at, u.project_id, u.created_by";
            if ($hasApprovedAt) $updSelect .= ", u.approved_at";
            if ($hasDeclinedAt) $updSelect .= ", u.declined_at";
            if ($hasCompletedAt) $updSelect .= ", u.completed_at";

            $updStmt = $this->conn->prepare(
                "SELECT $updSelect, p.name AS project_name
                 FROM updates u
                 LEFT JOIN projects p ON p.id = u.project_id
                 WHERE u.created_by = ?
                 ORDER BY u.created_at DESC
                 LIMIT 300"
            );
            $updStmt->execute([$userId]);
            $updateRows = $updStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // ---- Activities for timeline reconstruction ----
            $entityIds = [];
            foreach ($bugRows as $b) {
                $entityIds[(string)$b['id']] = true;
            }
            foreach ($updateRows as $u) {
                $entityIds[(string)$u['id']] = true;
            }
            $entityIdList = array_keys($entityIds);

            $activitiesByEntity = [];
            if (!empty($entityIdList)) {
                // Chunk IN queries to avoid oversized packets
                $chunks = array_chunk($entityIdList, 100);
                foreach ($chunks as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $actStmt = $this->conn->prepare(
                        "SELECT related_id, activity_type, metadata, created_at, user_id
                         FROM project_activities
                         WHERE related_id IN ($placeholders)
                           AND activity_type IN (
                             'bug_created','bug_reported','bug_updated','bug_fixed','bug_status_changed',
                             'update_created','update_updated','fix_created','fix_updated'
                           )
                         ORDER BY created_at ASC
                         LIMIT 5000"
                    );
                    $actStmt->execute($chunk);
                    foreach ($actStmt->fetchAll(PDO::FETCH_ASSOC) as $act) {
                        $rid = (string)($act['related_id'] ?? '');
                        if ($rid === '') continue;
                        if (!isset($activitiesByEntity[$rid])) {
                            $activitiesByEntity[$rid] = [];
                        }
                        $activitiesByEntity[$rid][] = $act;
                    }
                }
            }

            $nowTs = time();
            $totalBugsRaised = 0;
            $totalFixes = 0;
            $totalUpdates = 0;
            $riseSum = 0;
            $riseCount = 0;
            $fixSum = 0;
            $fixCount = 0;

            foreach ($bugRows as $bug) {
                $bugId = (string)$bug['id'];
                $pid = (string)($bug['project_id'] ?? '');
                if ($pid === '' || !isset($projectsById[$pid])) {
                    continue;
                }

                $status = (string)($bug['status'] ?? 'pending');
                $isRaisedByUser = (string)($bug['reported_by'] ?? '') === (string)$userId;
                $isFixedByUser = $hasFixedBy
                    ? ((string)($bug['fixed_by'] ?? '') === (string)$userId
                        || ((string)($bug['fixed_by'] ?? '') === '' && (string)($bug['updated_by'] ?? '') === (string)$userId && $status === 'fixed'))
                    : ((string)($bug['updated_by'] ?? '') === (string)$userId && $status === 'fixed');

                $events = $extractStatusEventsFromActivities(
                    $activitiesByEntity[$bugId] ?? [],
                    $bugId,
                    $status,
                    $bug['created_at'] ?? null,
                    $bug['updated_at'] ?? null
                );
                $timeline = $buildTimeline($events, $status, $nowTs);
                $durations = $computeDurations($timeline, $bug['created_at'] ?? null, $status);

                $item = [
                    'id' => $bugId,
                    'title' => $bug['title'],
                    'status' => $status,
                    'priority' => $bug['priority'] ?? null,
                    'kind' => 'bug',
                    'raised_at' => $durations['raised_at'],
                    'resolved_at' => $durations['resolved_at'],
                    'rise_duration_seconds' => $durations['rise_duration_seconds'],
                    'rise_duration_label' => $durations['rise_duration_seconds'] !== null
                        ? $formatDuration($durations['rise_duration_seconds']) : null,
                    'fix_duration_seconds' => $durations['fix_duration_seconds'],
                    'fix_duration_label' => $durations['fix_duration_seconds'] !== null
                        ? $formatDuration($durations['fix_duration_seconds']) : null,
                    'is_open' => $durations['is_open'],
                    'reported_by_user' => $isRaisedByUser,
                    'fixed_by_user' => $isFixedByUser && $status === 'fixed',
                    'status_timeline' => $timeline,
                ];

                if ($isRaisedByUser) {
                    $projectsById[$pid]['bugs'][] = $item;
                    $projectsById[$pid]['counts']['bugs']++;
                    $totalBugsRaised++;
                    if ($durations['rise_duration_seconds'] !== null) {
                        $riseSum += $durations['rise_duration_seconds'];
                        $riseCount++;
                    }
                }

                if ($isFixedByUser && $status === 'fixed') {
                    $projectsById[$pid]['fixes'][] = $item;
                    $projectsById[$pid]['counts']['fixes']++;
                    $totalFixes++;
                    if ($durations['fix_duration_seconds'] !== null) {
                        $fixSum += $durations['fix_duration_seconds'];
                        $fixCount++;
                    }
                }
            }

            foreach ($updateRows as $update) {
                $updId = (string)$update['id'];
                $pid = (string)($update['project_id'] ?? '');
                if ($pid === '' || !isset($projectsById[$pid])) {
                    continue;
                }

                $status = (string)($update['status'] ?? 'pending');
                $events = $extractStatusEventsFromActivities(
                    $activitiesByEntity[$updId] ?? [],
                    $updId,
                    $status,
                    $update['created_at'] ?? null,
                    $update['updated_at'] ?? null
                );

                // Prefer explicit update timestamps when available
                $seq = count($events);
                $raisedTs = $parseTs($update['created_at'] ?? null);
                if ($hasApprovedAt && !empty($update['approved_at'])) {
                    $events[] = [
                        'status' => 'approved',
                        'from_status' => 'pending',
                        'at' => $update['approved_at'],
                        'at_ts' => $parseTs($update['approved_at']),
                        'seq' => $seq++,
                        'source' => 'approved_at',
                    ];
                }
                if ($hasDeclinedAt && !empty($update['declined_at'])) {
                    $events[] = [
                        'status' => 'declined',
                        'from_status' => 'pending',
                        'at' => $update['declined_at'],
                        'at_ts' => $parseTs($update['declined_at']),
                        'seq' => $seq++,
                        'source' => 'declined_at',
                    ];
                }
                if ($hasCompletedAt && !empty($update['completed_at'])) {
                    $events[] = [
                        'status' => 'completed',
                        'from_status' => $status === 'approved' ? 'approved' : 'pending',
                        'at' => $update['completed_at'],
                        'at_ts' => $parseTs($update['completed_at']),
                        'seq' => $seq++,
                        'source' => 'completed_at',
                    ];
                }

                // Deduplicate by status keeping earliest
                usort($events, function ($a, $b) {
                    return ($a['at_ts'] ?? 0) <=> ($b['at_ts'] ?? 0);
                });
                $deduped = [];
                foreach ($events as $event) {
                    $prev = end($deduped);
                    if ($prev && ($prev['status'] ?? null) === ($event['status'] ?? null)) {
                        continue;
                    }
                    $deduped[] = $event;
                }

                $timeline = $buildTimeline($deduped, $status, $nowTs);
                $durations = $computeDurations($timeline, $update['created_at'] ?? null, $status);

                $item = [
                    'id' => $updId,
                    'title' => $update['title'],
                    'status' => $status,
                    'type' => $update['type'] ?? null,
                    'kind' => 'update',
                    'raised_at' => $durations['raised_at'],
                    'resolved_at' => $durations['resolved_at'],
                    'rise_duration_seconds' => $durations['rise_duration_seconds'],
                    'rise_duration_label' => $durations['rise_duration_seconds'] !== null
                        ? $formatDuration($durations['rise_duration_seconds']) : null,
                    'fix_duration_seconds' => $durations['fix_duration_seconds'],
                    'fix_duration_label' => $durations['fix_duration_seconds'] !== null
                        ? $formatDuration($durations['fix_duration_seconds']) : null,
                    'is_open' => $durations['is_open'],
                    'status_timeline' => $timeline,
                ];

                $projectsById[$pid]['updates'][] = $item;
                $projectsById[$pid]['counts']['updates']++;
                $totalUpdates++;
            }

            // Sort: most bugs first, then most activity, then newest assignment
            $projects = array_values($projectsById);
            usort($projects, function ($a, $b) {
                $bugsA = (int)($a['counts']['bugs'] ?? 0);
                $bugsB = (int)($b['counts']['bugs'] ?? 0);
                if ($bugsA !== $bugsB) {
                    return $bugsB <=> $bugsA;
                }

                $activityA = $bugsA
                    + (int)($a['counts']['fixes'] ?? 0)
                    + (int)($a['counts']['updates'] ?? 0);
                $activityB = $bugsB
                    + (int)($b['counts']['fixes'] ?? 0)
                    + (int)($b['counts']['updates'] ?? 0);
                if ($activityA !== $activityB) {
                    return $activityB <=> $activityA;
                }

                $ta = !empty($a['assigned_at']) ? strtotime($a['assigned_at']) : 0;
                $tb = !empty($b['assigned_at']) ? strtotime($b['assigned_at']) : 0;
                if ($ta !== $tb) {
                    return $tb <=> $ta;
                }
                return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
            });

            $this->sendJsonResponse(200, "Profile portfolio retrieved successfully", [
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                ],
                'summary' => [
                    'projects' => count($projects),
                    'bugs_raised' => $totalBugsRaised,
                    'fixes' => $totalFixes,
                    'updates' => $totalUpdates,
                    'avg_rise_duration_seconds' => $riseCount > 0 ? (int)round($riseSum / $riseCount) : null,
                    'avg_rise_duration_label' => $riseCount > 0 ? $formatDuration((int)round($riseSum / $riseCount)) : null,
                    'avg_fix_duration_seconds' => $fixCount > 0 ? (int)round($fixSum / $fixCount) : null,
                    'avg_fix_duration_label' => $fixCount > 0 ? $formatDuration((int)round($fixSum / $fixCount)) : null,
                ],
                'projects' => $projects,
            ]);
        } catch (PDOException $e) {
            error_log("Database error in getProfilePortfolio: " . $e->getMessage());
            $this->sendJsonResponse(500, "Database error occurred");
        } catch (Exception $e) {
            error_log("Error in getProfilePortfolio: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    public function getConnection() {
        return $this->conn;
    }
}
