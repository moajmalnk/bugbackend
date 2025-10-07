<?php
require_once __DIR__ . '/../BaseAPI.php';

class SharedTaskController extends BaseAPI {
    
    // Get all shared tasks for current user (assigned to or created by)
    public function getSharedTasks($userId, $status = null) {
        try {
            $query = "
                SELECT 
                    st.*,
                    creator.username as created_by_name,
                    assignee.username as assigned_to_name,
                    approver.username as approved_by_name,
                    completer.username as completed_by_name,
                    GROUP_CONCAT(DISTINCT stp.project_id) as project_ids,
                    GROUP_CONCAT(DISTINCT p.name) as project_names
                FROM shared_tasks st
                LEFT JOIN users creator ON st.created_by = creator.id
                LEFT JOIN users assignee ON st.assigned_to = assignee.id
                LEFT JOIN users approver ON st.approved_by = approver.id
                LEFT JOIN users completer ON st.completed_by = completer.id
                LEFT JOIN shared_task_projects stp ON st.id = stp.shared_task_id
                LEFT JOIN projects p ON stp.project_id = p.id
                WHERE (st.assigned_to = ? OR st.created_by = ?)
            ";
            
            $params = [$userId, $userId];
            
            if ($status) {
                $query .= " AND st.status = ?";
                $params[] = $status;
            }
            
            $query .= " GROUP BY st.id ORDER BY st.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process project IDs and names into arrays
            foreach ($tasks as &$task) {
                $task['project_ids'] = $task['project_ids'] ? explode(',', $task['project_ids']) : [];
                $task['project_names'] = $task['project_names'] ? explode(',', $task['project_names']) : [];
            }
            
            $this->sendJsonResponse(200, "Shared tasks retrieved successfully", $tasks);
        } catch (Exception $e) {
            error_log("Error in getSharedTasks: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve shared tasks");
        }
    }
    
    // Create a new shared task
    public function createSharedTask($data) {
        try {
            $this->conn->beginTransaction();
            
            $requiredFields = ['title', 'created_by', 'assigned_to'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $this->sendJsonResponse(400, "Missing required field: $field");
                    return;
                }
            }
            
            // Insert shared task
            $query = "INSERT INTO shared_tasks (
                title, description, created_by, assigned_to, 
                project_id, due_date, status, priority
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([
                $data['title'],
                $data['description'] ?? null,
                $data['created_by'],
                $data['assigned_to'],
                $data['project_id'] ?? null,
                $data['due_date'] ?? null,
                $data['status'] ?? 'pending',
                $data['priority'] ?? 'medium'
            ]);
            
            $taskId = $this->conn->lastInsertId();
            
            // Insert project associations if provided
            if (isset($data['project_ids']) && is_array($data['project_ids']) && count($data['project_ids']) > 0) {
                $projectQuery = "INSERT INTO shared_task_projects (shared_task_id, project_id) VALUES (?, ?)";
                $projectStmt = $this->conn->prepare($projectQuery);
                
                foreach ($data['project_ids'] as $projectId) {
                    if (!empty($projectId)) {
                        $projectStmt->execute([$taskId, $projectId]);
                    }
                }
            }
            
            $this->conn->commit();
            
            // Fetch the created task with all details
            $this->getSharedTaskById($taskId);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Error in createSharedTask: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to create shared task: " . $e->getMessage());
        }
    }
    
    // Get shared task by ID
    public function getSharedTaskById($taskId) {
        try {
            $query = "
                SELECT 
                    st.*,
                    creator.username as created_by_name,
                    assignee.username as assigned_to_name,
                    approver.username as approved_by_name,
                    completer.username as completed_by_name,
                    GROUP_CONCAT(DISTINCT stp.project_id) as project_ids,
                    GROUP_CONCAT(DISTINCT p.name) as project_names
                FROM shared_tasks st
                LEFT JOIN users creator ON st.created_by = creator.id
                LEFT JOIN users assignee ON st.assigned_to = assignee.id
                LEFT JOIN users approver ON st.approved_by = approver.id
                LEFT JOIN users completer ON st.completed_by = completer.id
                LEFT JOIN shared_task_projects stp ON st.id = stp.shared_task_id
                LEFT JOIN projects p ON stp.project_id = p.id
                WHERE st.id = ?
                GROUP BY st.id
            ";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$task) {
                $this->sendJsonResponse(404, "Shared task not found");
                return;
            }
            
            // Process project IDs and names into arrays
            $task['project_ids'] = $task['project_ids'] ? explode(',', $task['project_ids']) : [];
            $task['project_names'] = $task['project_names'] ? explode(',', $task['project_names']) : [];
            
            $this->sendJsonResponse(200, "Shared task retrieved successfully", $task);
        } catch (Exception $e) {
            error_log("Error in getSharedTaskById: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve shared task");
        }
    }
    
    // Update shared task
    public function updateSharedTask($taskId, $data, $userId) {
        try {
            $this->conn->beginTransaction();
            
            // Build update query dynamically
            $updates = [];
            $params = [];
            
            $allowedFields = ['title', 'description', 'due_date', 'status', 'priority', 'assigned_to'];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            // If status is completed, set completed_at and completed_by
            if (isset($data['status']) && $data['status'] === 'completed') {
                $updates[] = "completed_at = NOW()";
                $updates[] = "completed_by = ?";
                $params[] = $userId;
            }
            
            // If status is approved, set approved_by
            if (isset($data['status']) && $data['status'] === 'approved') {
                $updates[] = "approved_by = ?";
                $params[] = $userId;
            }
            
            if (empty($updates)) {
                $this->sendJsonResponse(400, "No fields to update");
                return;
            }
            
            $params[] = $taskId;
            
            $query = "UPDATE shared_tasks SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
            
            // Update project associations if provided
            if (isset($data['project_ids']) && is_array($data['project_ids'])) {
                // Remove existing associations
                $deleteQuery = "DELETE FROM shared_task_projects WHERE shared_task_id = ?";
                $deleteStmt = $this->conn->prepare($deleteQuery);
                $deleteStmt->execute([$taskId]);
                
                // Insert new associations
                if (count($data['project_ids']) > 0) {
                    $projectQuery = "INSERT INTO shared_task_projects (shared_task_id, project_id) VALUES (?, ?)";
                    $projectStmt = $this->conn->prepare($projectQuery);
                    
                    foreach ($data['project_ids'] as $projectId) {
                        if (!empty($projectId)) {
                            $projectStmt->execute([$taskId, $projectId]);
                        }
                    }
                }
            }
            
            $this->conn->commit();
            
            $this->getSharedTaskById($taskId);
            
        } catch (Exception $e) {
            $this->conn->rollBack();
            error_log("Error in updateSharedTask: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to update shared task: " . $e->getMessage());
        }
    }
    
    // Delete shared task
    public function deleteSharedTask($taskId, $userId) {
        try {
            // Check if user has permission (creator or admin)
            $query = "SELECT created_by FROM shared_tasks WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$task) {
                $this->sendJsonResponse(404, "Shared task not found");
                return;
            }
            
            // Check if current user is admin
            $userQuery = "SELECT role FROM users WHERE id = ?";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($task['created_by'] !== $userId && $user['role'] !== 'admin') {
                $this->sendJsonResponse(403, "You don't have permission to delete this task");
                return;
            }
            
            // Delete task (cascade will handle shared_task_projects)
            $deleteQuery = "DELETE FROM shared_tasks WHERE id = ?";
            $deleteStmt = $this->conn->prepare($deleteQuery);
            $deleteStmt->execute([$taskId]);
            
            $this->sendJsonResponse(200, "Shared task deleted successfully");
            
        } catch (Exception $e) {
            error_log("Error in deleteSharedTask: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to delete shared task");
        }
    }
}

