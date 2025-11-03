<?php
require_once __DIR__ . '/../BaseAPI.php';

class SharedTaskController extends BaseAPI {
    
    // Get all shared tasks for current user (assigned to or created by)
    public function getSharedTasks($userId, $status = null) {
        try {
            // First, get all task IDs that the user has access to
            $taskIdsQuery = "
                SELECT DISTINCT st.id 
                FROM shared_tasks st
                LEFT JOIN shared_task_assignees sta ON st.id = sta.shared_task_id
                WHERE (st.assigned_to = ? OR st.created_by = ? OR sta.assigned_to = ?)
            ";
            
            $taskIdsParams = [$userId, $userId, $userId];
            
            if ($status) {
                $taskIdsQuery .= " AND st.status = ?";
                $taskIdsParams[] = $status;
            }
            
            $taskIdsStmt = $this->conn->prepare($taskIdsQuery);
            $taskIdsStmt->execute($taskIdsParams);
            $taskIds = $taskIdsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($taskIds)) {
                $this->sendJsonResponse(200, "Shared tasks retrieved successfully", []);
                return;
            }
            
            // Now get all details for those tasks, including ALL assignees (not filtered by user)
            $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
            $query = "
                SELECT 
                    st.*,
                    creator.username as created_by_name,
                    assignee.username as assigned_to_name,
                    approver.username as approved_by_name,
                    completer.username as completed_by_name,
                    GROUP_CONCAT(DISTINCT stp.project_id) as project_ids,
                    GROUP_CONCAT(DISTINCT p.name) as project_names,
                    GROUP_CONCAT(DISTINCT sta.assigned_to) as assigned_to_ids,
                    GROUP_CONCAT(DISTINCT au.username) as assigned_to_names,
                    GROUP_CONCAT(DISTINCT CASE WHEN sta.completed_at IS NOT NULL THEN sta.assigned_to END) as completed_assignee_ids,
                    GROUP_CONCAT(DISTINCT CASE WHEN sta.completed_at IS NOT NULL THEN au.username END) as completed_assignee_names,
                    GROUP_CONCAT(DISTINCT CASE WHEN sta.completed_at IS NOT NULL THEN CONCAT(sta.assigned_to, ':', sta.completed_at) END) as completion_details_raw
                FROM shared_tasks st
                LEFT JOIN users creator ON st.created_by COLLATE utf8mb4_unicode_ci = creator.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN users assignee ON st.assigned_to COLLATE utf8mb4_unicode_ci = assignee.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN users approver ON st.approved_by COLLATE utf8mb4_unicode_ci = approver.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN users completer ON st.completed_by COLLATE utf8mb4_unicode_ci = completer.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN shared_task_projects stp ON st.id = stp.shared_task_id
                LEFT JOIN projects p ON stp.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN shared_task_assignees sta ON st.id = sta.shared_task_id
                LEFT JOIN users au ON sta.assigned_to COLLATE utf8mb4_unicode_ci = au.id COLLATE utf8mb4_unicode_ci
                WHERE st.id IN ($placeholders)
            ";
            
            $params = $taskIds;
            
            if ($status) {
                $query .= " AND st.status = ?";
                $params[] = $status;
            }
            
            $query .= " GROUP BY st.id ORDER BY st.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                $err = $this->conn->errorInfo();
                error_log("SharedTaskController:getSharedTasks prepare failed: " . implode(' | ', $err));
                $this->sendJsonResponse(500, "Database error occurred (prepare)");
                return;
            }
            if (!$stmt->execute($params)) {
                $err = $stmt->errorInfo();
                error_log("SharedTaskController:getSharedTasks execute failed: " . implode(' | ', $err));
                $this->sendJsonResponse(500, "Database error occurred (execute): " . ($err[2] ?? ''));
                return;
            }
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process project IDs and names into arrays
            foreach ($tasks as &$task) {
                $task['project_ids'] = $task['project_ids'] ? explode(',', $task['project_ids']) : [];
                $task['project_names'] = $task['project_names'] ? explode(',', $task['project_names']) : [];
                
                // Handle assigned_to_ids - explode and remove duplicates
                if ($task['assigned_to_ids']) {
                    $assignedIds = explode(',', $task['assigned_to_ids']);
                    $task['assigned_to_ids'] = array_values(array_unique($assignedIds));
                } else {
                    $task['assigned_to_ids'] = $task['assigned_to'] ? [$task['assigned_to']] : [];
                }
                
                // Handle assigned_to_names - explode and remove duplicates
                if ($task['assigned_to_names']) {
                    $assignedNames = explode(',', $task['assigned_to_names']);
                    $task['assigned_to_names'] = array_values(array_unique($assignedNames));
                } else {
                    $task['assigned_to_names'] = $task['assigned_to_name'] ? [$task['assigned_to_name']] : [];
                }
                
                // Handle completed_assignee_ids - explode and remove duplicates
                $task['completed_assignee_ids'] = $task['completed_assignee_ids'] ? array_values(array_unique(explode(',', $task['completed_assignee_ids']))) : [];
                
                // Handle completed_assignee_names - explode and remove duplicates
                $task['completed_assignee_names'] = $task['completed_assignee_names'] ? array_values(array_unique(explode(',', $task['completed_assignee_names']))) : [];
                
                // Parse completion_details into associative array: userId => completed_at timestamp
                $task['completion_details'] = [];
                if (!empty($task['completion_details_raw'])) {
                    $details = explode(',', $task['completion_details_raw']);
                    foreach ($details as $detail) {
                        if (strpos($detail, ':') !== false) {
                            list($userId, $timestamp) = explode(':', $detail, 2);
                            $task['completion_details'][$userId] = $timestamp;
                        }
                    }
                }
                unset($task['completion_details_raw']); // Remove raw field
            }
            
            $this->sendJsonResponse(200, "Shared tasks retrieved successfully", $tasks);
        } catch (Exception $e) {
            error_log("Error in getSharedTasks: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve shared tasks: " . $e->getMessage());
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

            // Insert assignees mapping (multi-assign support)
            $assigneeIds = [];
            if (isset($data['assigned_to_ids']) && is_array($data['assigned_to_ids']) && count($data['assigned_to_ids']) > 0) {
                $assigneeIds = $data['assigned_to_ids'];
            } elseif (isset($data['assigned_to']) && !empty($data['assigned_to'])) {
                $assigneeIds = [$data['assigned_to']];
            }
            // Remove duplicates before inserting
            $assigneeIds = array_values(array_unique($assigneeIds));
            if (count($assigneeIds) > 0) {
                $assigneeQuery = "INSERT INTO shared_task_assignees (shared_task_id, assigned_to) VALUES (?, ?)";
                $assigneeStmt = $this->conn->prepare($assigneeQuery);
                foreach ($assigneeIds as $aid) {
                    if (!empty($aid)) {
                        $assigneeStmt->execute([$taskId, $aid]);
                    }
                }
                // Keep primary assigned_to as first for backward compatibility
                $primary = $assigneeIds[0];
                $this->conn->prepare("UPDATE shared_tasks SET assigned_to = ? WHERE id = ?")->execute([$primary, $taskId]);
            }
            
            $this->conn->commit();
            
            // Send notifications to task assignees + project members
            try {
                require_once __DIR__ . '/../NotificationManager.php';
                $notificationManager = NotificationManager::getInstance();
                
                // Get project ID (use first project if multiple)
                $projectId = null;
                if (isset($data['project_ids']) && is_array($data['project_ids']) && count($data['project_ids']) > 0) {
                    $projectId = $data['project_ids'][0];
                } elseif (isset($data['project_id']) && !empty($data['project_id'])) {
                    $projectId = $data['project_id'];
                }
                
                $notificationManager->notifyTaskCreated(
                    $taskId,
                    $data['title'],
                    $projectId,
                    $assigneeIds,
                    $data['created_by']
                );
            } catch (Exception $e) {
                error_log("Failed to send task creation notification: " . $e->getMessage());
            }
            
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
                    GROUP_CONCAT(DISTINCT p.name) as project_names,
                    GROUP_CONCAT(DISTINCT sta.assigned_to) as assigned_to_ids,
                    GROUP_CONCAT(DISTINCT au.username) as assigned_to_names,
                    GROUP_CONCAT(DISTINCT CASE WHEN sta.completed_at IS NOT NULL THEN sta.assigned_to END) as completed_assignee_ids,
                    GROUP_CONCAT(DISTINCT CASE WHEN sta.completed_at IS NOT NULL THEN au.username END) as completed_assignee_names,
                    GROUP_CONCAT(DISTINCT CASE WHEN sta.completed_at IS NOT NULL THEN CONCAT(sta.assigned_to, ':', sta.completed_at) END) as completion_details_raw
                FROM shared_tasks st
                LEFT JOIN users creator ON st.created_by COLLATE utf8mb4_unicode_ci = creator.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN users assignee ON st.assigned_to COLLATE utf8mb4_unicode_ci = assignee.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN users approver ON st.approved_by COLLATE utf8mb4_unicode_ci = approver.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN users completer ON st.completed_by COLLATE utf8mb4_unicode_ci = completer.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN shared_task_projects stp ON st.id = stp.shared_task_id
                LEFT JOIN projects p ON stp.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                LEFT JOIN shared_task_assignees sta ON st.id = sta.shared_task_id
                LEFT JOIN users au ON sta.assigned_to COLLATE utf8mb4_unicode_ci = au.id COLLATE utf8mb4_unicode_ci
                WHERE st.id = ?
                GROUP BY st.id
            ";
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                $err = $this->conn->errorInfo();
                error_log("SharedTaskController:getSharedTaskById prepare failed: " . implode(' | ', $err));
                $this->sendJsonResponse(500, "Database error occurred (prepare)");
                return;
            }
            if (!$stmt->execute([$taskId])) {
                $err = $stmt->errorInfo();
                error_log("SharedTaskController:getSharedTaskById execute failed: " . implode(' | ', $err));
                $this->sendJsonResponse(500, "Database error occurred (execute): " . ($err[2] ?? ''));
                return;
            }
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$task) {
                $this->sendJsonResponse(404, "Shared task not found");
                return;
            }
            
            // Process project/assignee IDs and names into arrays
            $task['project_ids'] = $task['project_ids'] ? explode(',', $task['project_ids']) : [];
            $task['project_names'] = $task['project_names'] ? explode(',', $task['project_names']) : [];
            
            // Handle assigned_to_ids - explode and remove duplicates
            if ($task['assigned_to_ids']) {
                $assignedIds = explode(',', $task['assigned_to_ids']);
                $task['assigned_to_ids'] = array_values(array_unique($assignedIds));
            } else {
                $task['assigned_to_ids'] = $task['assigned_to'] ? [$task['assigned_to']] : [];
            }
            
            // Handle assigned_to_names - explode and remove duplicates
            if ($task['assigned_to_names']) {
                $assignedNames = explode(',', $task['assigned_to_names']);
                $task['assigned_to_names'] = array_values(array_unique($assignedNames));
            } else {
                $task['assigned_to_names'] = $task['assigned_to_name'] ? [$task['assigned_to_name']] : [];
            }
            
            // Handle completed_assignee_ids - explode and remove duplicates
            $task['completed_assignee_ids'] = $task['completed_assignee_ids'] ? array_values(array_unique(explode(',', $task['completed_assignee_ids']))) : [];
            
            // Handle completed_assignee_names - explode and remove duplicates
            $task['completed_assignee_names'] = $task['completed_assignee_names'] ? array_values(array_unique(explode(',', $task['completed_assignee_names']))) : [];
            
            // Parse completion_details into associative array: userId => completed_at timestamp
            $task['completion_details'] = [];
            if (!empty($task['completion_details_raw'])) {
                $details = explode(',', $task['completion_details_raw']);
                foreach ($details as $detail) {
                    if (strpos($detail, ':') !== false) {
                        list($userId, $timestamp) = explode(':', $detail, 2);
                        $task['completion_details'][$userId] = $timestamp;
                    }
                }
            }
            unset($task['completion_details_raw']); // Remove raw field
            
            $this->sendJsonResponse(200, "Shared task retrieved successfully", $task);
        } catch (Exception $e) {
            error_log("Error in getSharedTaskById: " . $e->getMessage());
            $this->sendJsonResponse(500, "Failed to retrieve shared task");
        }
    }
    
    // Update shared task
    public function updateSharedTask($taskId, $data, $userId) {
        try {
            // Check if user is the creator of the task
            $checkCreatorStmt = $this->conn->prepare("SELECT created_by FROM shared_tasks WHERE id = ?");
            $checkCreatorStmt->execute([$taskId]);
            $task = $checkCreatorStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$task || $task['created_by'] !== $userId) {
                $this->sendJsonResponse(403, "Only task creators can edit tasks");
                return;
            }
            
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
            
            // Update assignees mapping if provided
            if (isset($data['assigned_to_ids']) && is_array($data['assigned_to_ids'])) {
                // Remove duplicates before updating
                $assigneeIds = array_values(array_unique($data['assigned_to_ids']));
                $deleteAssignees = $this->conn->prepare("DELETE FROM shared_task_assignees WHERE shared_task_id = ?");
                $deleteAssignees->execute([$taskId]);
                if (count($assigneeIds) > 0) {
                    $ins = $this->conn->prepare("INSERT INTO shared_task_assignees (shared_task_id, assigned_to) VALUES (?, ?)");
                    foreach ($assigneeIds as $aid) {
                        if (!empty($aid)) {
                            $ins->execute([$taskId, $aid]);
                        }
                    }
                    // Keep primary assigned_to as first for backward compatibility
                    $primary = $assigneeIds[0];
                    $this->conn->prepare("UPDATE shared_tasks SET assigned_to = ? WHERE id = ?")->execute([$primary, $taskId]);
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
            
            // Only task creators can delete tasks
            if ($task['created_by'] !== $userId) {
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

    public function completeTaskForUser($taskId, $userId) {
        try {
            // Check if user is assigned to this task
            $checkStmt = $this->conn->prepare("
                SELECT 1 FROM shared_task_assignees 
                WHERE shared_task_id = ? AND assigned_to = ?
            ");
            $checkStmt->execute([$taskId, $userId]);
            
            if (!$checkStmt->fetch()) {
                throw new Exception("User is not assigned to this task");
            }

            // Mark user as completed
            $updateStmt = $this->conn->prepare("
                UPDATE shared_task_assignees 
                SET completed_at = NOW() 
                WHERE shared_task_id = ? AND assigned_to = ?
            ");
            
            if (!$updateStmt->execute([$taskId, $userId])) {
                throw new Exception("Failed to mark task as completed");
            }

            // Check if all assignees have completed
            $countStmt = $this->conn->prepare("
                SELECT 
                    COUNT(*) as total_assignees,
                    COUNT(completed_at) as completed_assignees
                FROM shared_task_assignees 
                WHERE shared_task_id = ?
            ");
            $countStmt->execute([$taskId]);
            $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

            // If all assignees completed, mark task as completed
            if ($counts && $counts['total_assignees'] > 0 && $counts['total_assignees'] == $counts['completed_assignees']) {
                $taskUpdateStmt = $this->conn->prepare("
                    UPDATE shared_tasks 
                    SET status = 'completed', completed_at = NOW(), completed_by = ?
                    WHERE id = ?
                ");
                $taskUpdateStmt->execute([$userId, $taskId]);
            }

            return ['success' => true, 'message' => "Task marked as completed successfully"];
        } catch (Exception $e) {
            error_log("Error in completeTaskForUser: " . $e->getMessage());
            throw new Exception("Failed to complete task: " . $e->getMessage());
        }
    }

    public function uncompleteTaskForUser($taskId, $userId) {
        try {
            // Mark user as not completed
            $updateStmt = $this->conn->prepare("
                UPDATE shared_task_assignees 
                SET completed_at = NULL 
                WHERE shared_task_id = ? AND assigned_to = ?
            ");
            
            if (!$updateStmt->execute([$taskId, $userId])) {
                throw new Exception("Failed to mark task as not completed");
            }

            // If task was completed, change it back to pending
            $taskUpdateStmt = $this->conn->prepare("
                UPDATE shared_tasks 
                SET status = 'pending', completed_at = NULL, completed_by = NULL
                WHERE id = ?
            ");
            $taskUpdateStmt->execute([$taskId]);

            return ['success' => true, 'message' => "Task marked as not completed successfully"];
        } catch (Exception $e) {
            error_log("Error in uncompleteTaskForUser: " . $e->getMessage());
            throw new Exception("Failed to uncomplete task: " . $e->getMessage());
        }
    }

    public function declineTask($taskId, $userId) {
        try {
            // Check if user is assigned to this task
            $checkStmt = $this->conn->prepare("
                SELECT 1 FROM shared_task_assignees 
                WHERE shared_task_id = ? AND assigned_to = ?
            ");
            $checkStmt->execute([$taskId, $userId]);
            
            if (!$checkStmt->fetch()) {
                throw new Exception("User is not assigned to this task");
            }

            // Remove user from assignees (decline)
            $deleteStmt = $this->conn->prepare("
                DELETE FROM shared_task_assignees 
                WHERE shared_task_id = ? AND assigned_to = ?
            ");
            
            if (!$deleteStmt->execute([$taskId, $userId])) {
                throw new Exception("Failed to decline task");
            }

            // If no more assignees, delete the task
            $countStmt = $this->conn->prepare("
                SELECT COUNT(*) as assignee_count FROM shared_task_assignees 
                WHERE shared_task_id = ?
            ");
            $countStmt->execute([$taskId]);
            $count = $countStmt->fetch(PDO::FETCH_ASSOC)['assignee_count'];

            if ($count == 0) {
                $deleteTaskStmt = $this->conn->prepare("DELETE FROM shared_tasks WHERE id = ?");
                $deleteTaskStmt->execute([$taskId]);
                return ['success' => true, 'message' => "Task declined and removed (no more assignees)"];
            } else {
                return ['success' => true, 'message' => "Task declined successfully"];
            }
        } catch (Exception $e) {
            error_log("Error in declineTask: " . $e->getMessage());
            throw new Exception("Failed to decline task: " . $e->getMessage());
        }
    }
}

