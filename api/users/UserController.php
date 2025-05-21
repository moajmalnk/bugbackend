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

    public function delete($userId) {
        try {
            // Validate token (if your API requires it)
            try {
                $this->validateToken();
            } catch (Exception $e) {
                error_log("Token validation failed: " . $e->getMessage());
                $this->sendJsonResponse(401, "Authentication failed");
                return;
            }

            if (!$this->conn) {
                error_log("Database connection failed in delete");
                $this->sendJsonResponse(500, "Database connection failed");
                return;
            }

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

            // Delete user
            $query = "DELETE FROM users WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            if (!$stmt->execute([$userId])) {
                error_log("Failed to delete user: " . implode(", ", $stmt->errorInfo()));
                $this->sendJsonResponse(500, "Failed to delete user");
                return;
            }

            $this->sendJsonResponse(200, "User deleted successfully");
        } catch (Exception $e) {
            error_log("Error in delete user: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
}