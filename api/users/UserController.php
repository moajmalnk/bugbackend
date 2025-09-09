<?php
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../../utils/send_email.php';

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

            // Check if phone column exists
            $checkPhoneColumn = $this->conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
            $phoneColumnExists = $checkPhoneColumn->rowCount() > 0;

            // Get all users with phone field if it exists
            if ($phoneColumnExists) {
                $query = "SELECT id, username, email, phone, role, created_at, updated_at FROM users ORDER BY created_at DESC";
            } else {
                $query = "SELECT id, username, email, role, created_at, updated_at FROM users ORDER BY created_at DESC";
            }
            
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
            
            // Add name field and ensure phone field exists
            foreach ($users as &$user) {
                $user['name'] = $user['username']; // Use username as name
                if (!isset($user['phone'])) {
                    $user['phone'] = null; // Set phone to null if column doesn't exist
                }
            }

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

            // Prepare and execute query
            $query = "SELECT id, username, email, phone, role, created_at, updated_at FROM users WHERE id = ?";
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
            $query = "SELECT id, username, email, phone, role, created_at, updated_at 
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
            $username = $data['username'] ?? '';
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';
            $role = $data['role'] ?? '';
            $phone = $data['phone'] ?? null;

            // Validate required fields
            if (!$username || !$email || !$password || !$role) {
                $this->sendJsonResponse(400, "All fields are required.");
                return;
            }

            // Check if username, email, or phone exists
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR phone = ?");
            $stmt->execute([$username, $email, $phone]);
            if ($stmt->rowCount() > 0) {
                $this->sendJsonResponse(400, "Username, email, or phone already exists");
                return;
            }

            // Generate UUID for id
            $id = $this->utils->generateUUID(); // Make sure you have a UUID generator in your utils

            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Insert user (add id column)
            $query = "INSERT INTO users (id, username, email, phone, password, role) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            if (!$stmt->execute([$id, $username, $email, $phone, $hashedPassword, $role])) {
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

            // If user created successfully, send welcome email
            $emailSent = false;
            if ($role === 'developer' || $role === 'tester') {
                // Generate role-based login URL
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                
                // Determine if we're in development or production
                if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
                    // Development - use localhost with role-based routing
                    $loginLink = "http://localhost:8080/login";
                } else {
                    // Production - use the bug tracker domain with role-based routing
                    $loginLink = "https://bugs.moajmalnk.in/login";
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
                                <p style=\"margin: 5px 0 0 0;\">&copy; " . date('Y') . " Bug Ricer. All rights reserved.</p>
                            </div>
                        </div>
                    </div>
                ";
                $emailSent = sendWelcomeEmail($email, $subject, $body);
            }

            $message = "User '{$username}' created successfully";
            if ($role === 'developer' || $role === 'tester') {
                if ($emailSent) {
                    $message .= " and a welcome email has been sent.";
                } else {
                    $message .= ", but the welcome email could not be sent.";
                }
            }

            $this->sendJsonResponse(201, $message, [
                "id" => $id,
                "username" => $username,
                "email" => $email,
                "phone" => $phone,
                "role" => $role
            ]);
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function updateUser($id, $data) {
        try {
            $conn = $this->getConnection();
            $fields = [];
            $params = [];
            if (isset($data['username'])) {
                $fields[] = "username = ?";
                $params[] = $data['username'];
            }
            if (isset($data['email'])) {
                $fields[] = "email = ?";
                $params[] = $data['email'];
            }
            if (isset($data['role'])) {
                $fields[] = "role = ?";
                $params[] = $data['role'];
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
            // Add more fields as needed

            if (empty($fields)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }

            $params[] = $id;
            $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt->execute($params)) {
                $this->sendJsonResponse(200, "User updated successfully");
            } else {
                $this->sendJsonResponse(500, "Failed to update user");
            }
        } catch (Exception $e) {
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getConnection() {
        return $this->conn;
    }
}