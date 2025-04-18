<?php

require_once __DIR__ . '/../BaseAPI.php';

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

            // Get all users
            $query = "SELECT id, username, email, role, created_at, updated_at FROM users ORDER BY created_at DESC";
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
            $query = "SELECT id, username, email, role, created_at, updated_at FROM users WHERE id = ?";
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
            $query = "SELECT id, username, email, role, created_at, updated_at 
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

    public function createUser($data) {
        try {
            // Validate required fields
            $requiredFields = ['username', 'email', 'password', 'role'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $this->sendJsonResponse(400, "Missing required field: {$field}");
                    return;
                }
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->sendJsonResponse(400, "Invalid email format");
                return;
            }

            // Check if username or email already exists
            $checkQuery = "SELECT id FROM users WHERE username = ? OR email = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$data['username'], $data['email']]);
            
            if ($checkStmt->rowCount() > 0) {
                $this->sendJsonResponse(409, "Username or email already exists");
                return;
            }

            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

            // Generate UUID
            $userId = $this->utils->generateUUID();

            // Insert new user
            $query = "INSERT INTO users (id, username, email, password, role) VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt->execute([$userId, $data['username'], $data['email'], $hashedPassword, $data['role']])) {
                throw new Exception("Failed to create user");
            }

            // Get the created user
            $newUser = [
                'id' => $userId,
                'username' => $data['username'],
                'email' => $data['email'],
                'role' => $data['role'],
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->sendJsonResponse(201, "User created successfully", $newUser);
        } catch (PDOException $e) {
            error_log("Database error in createUser: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to create user");
        } catch (Exception $e) {
            error_log("Error in createUser: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    public function updateUser($userId, $data) {
        try {
            // Validate user ID
            if (!$userId || !$this->utils->isValidUUID($userId)) {
                $this->sendJsonResponse(400, "Invalid user ID format");
                return;
            }

            // Check if user exists
            $checkQuery = "SELECT id FROM users WHERE id = ?";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->execute([$userId]);
            
            if ($checkStmt->rowCount() === 0) {
                $this->sendJsonResponse(404, "User not found");
                return;
            }

            // Validate input data
            $allowedFields = ['username', 'email', 'role', 'password'];
            $updates = [];
            $params = [];

            foreach ($allowedFields as $field) {
                if (isset($data[$field]) && !empty($data[$field])) {
                    if ($field === 'password') {
                        $updates[] = "$field = ?";
                        $params[] = password_hash($data[$field], PASSWORD_DEFAULT);
                    } else {
                        $updates[] = "$field = ?";
                        $params[] = $data[$field];
                    }
                }
            }

            if (empty($updates)) {
                $this->sendJsonResponse(400, "No valid fields to update");
                return;
            }

            // Add userId to params array
            $params[] = $userId;

            // Update user
            $query = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt->execute($params)) {
                throw new Exception("Failed to update user");
            }

            // Get updated user data
            $query = "SELECT id, username, email, role, created_at, updated_at FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$userId]);
            $updatedUser = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->sendJsonResponse(200, "User updated successfully", $updatedUser);
        } catch (PDOException $e) {
            error_log("Database error in updateUser: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update user");
        } catch (Exception $e) {
            error_log("Error in updateUser: " . $e->getMessage());
            $this->sendJsonResponse(500, "An unexpected error occurred");
        }
    }

    public function delete($userId) {
        try {
            $decoded = $this->validateToken();

            // Validate user ID format
            if (!$userId || !$this->utils->isValidUUID($userId)) {
                $this->sendJsonResponse(400, "Invalid user ID format", null, false);
                return;
            }

            $this->conn->beginTransaction();

            // Check if user exists
            $checkQuery = "SELECT id FROM users WHERE id = :id";
            $checkStmt = $this->conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $userId);
            $checkStmt->execute();

            if (!$checkStmt->fetch()) {
                $this->conn->rollBack();
                $this->sendJsonResponse(404, "User not found", null, false);
                return;
            }

            try {
                // 1. First delete from bug_attachments (has FK to bugs and users)
                $query = "DELETE FROM bug_attachments WHERE uploaded_by = :user_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->execute();

                // 2. Delete from project_members (has FK to users)
                $query = "DELETE FROM project_members WHERE user_id = :user_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->execute();

                // 3. Delete from activity_log (has FK to users)
                $query = "DELETE FROM activity_log WHERE user_id = :user_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->execute();

                // 4. Set reported_by to NULL in bugs table
                $query = "UPDATE bugs SET reported_by = NULL WHERE reported_by = :user_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->execute();

                // 5. Set created_by to NULL in projects table
                $query = "UPDATE projects SET created_by = NULL WHERE created_by = :user_id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':user_id', $userId);
                $stmt->execute();

                // 6. Delete from activities (has ON DELETE CASCADE)
                // This will be handled automatically by MySQL

                // 7. Finally delete the user
                $query = "DELETE FROM users WHERE id = :id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':id', $userId);
                
                if (!$stmt->execute()) {
                    throw new Exception("Failed to delete user record");
                }

                $this->conn->commit();
                $this->sendJsonResponse(200, "User deleted successfully", null, true);
                return;

            } catch (Exception $e) {
                $this->conn->rollBack();
                error_log("Error in delete user transaction: " . $e->getMessage());
                $this->sendJsonResponse(500, "Failed to delete user: " . $e->getMessage(), null, false);
                return;
            }

        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error in delete user: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage(), null, false);
        }
    }
} 