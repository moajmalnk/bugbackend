<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../BaseAPI.php';

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

class BroadcastAPI extends BaseAPI {
    public function broadcastNotification() {
        try {
            // Validate authentication
            $userData = $this->validateToken();
            if (!$userData) {
                $this->sendJsonResponse(401, 'Invalid token');
                return;
            }
            
            // Get request body
            $data = $this->getRequestData();
            
            if (!$data) {
                $this->sendJsonResponse(400, 'Invalid JSON');
                return;
            }
            
            // Validate required fields
            $requiredFields = ['type', 'title', 'message', 'bugTitle', 'createdBy'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $this->sendJsonResponse(400, "Missing required field: $field");
                    return;
                }
            }
            
            // For updates, bugId is optional (can be 0 or null)
            // For bugs, bugId should be provided but we'll default to 0 if not
            if (isset($data['bugId']) && $data['bugId'] !== '' && $data['bugId'] !== '0') {
                $bugId = (int)$data['bugId'];
            } else {
                // For updates, use 0 since they don't have a bug_id
                // For bugs, also default to 0 if not provided (though it should be provided)
                $bugId = 0;
            }
            
            // Create notifications table if it doesn't exist
            // Check if table exists and alter it if needed to make bug_id nullable
            $tableCheckSQL = "SHOW TABLES LIKE 'notifications'";
            $tableExists = $this->conn->query($tableCheckSQL)->rowCount() > 0;
            
            if (!$tableExists) {
                $createTableSQL = "
                    CREATE TABLE notifications (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        type ENUM('new_bug', 'status_change', 'new_update') NOT NULL,
                        title VARCHAR(255) NOT NULL,
                        message TEXT NOT NULL,
                        bug_id INT NULL DEFAULT 0,
                        bug_title VARCHAR(255) NOT NULL,
                        status VARCHAR(50) NULL,
                        created_by VARCHAR(100) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_created_at (created_at),
                        INDEX idx_type (type),
                        INDEX idx_bug_id (bug_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ";
                $this->conn->exec($createTableSQL);
            } else {
                // Alter existing table to make bug_id nullable if it's not already
                try {
                    $alterSQL = "ALTER TABLE notifications MODIFY bug_id INT NULL DEFAULT 0";
                    $this->conn->exec($alterSQL);
                } catch (Exception $e) {
                    // Column might already be nullable, ignore error
                    error_log('Note: Could not alter bug_id column (may already be correct): ' . $e->getMessage());
                }
                
                // CRITICAL: Ensure 'new_update' is in the ENUM before inserting
                // Check current ENUM values
                $checkEnumSQL = "SHOW COLUMNS FROM notifications WHERE Field = 'type'";
                $enumResult = $this->conn->query($checkEnumSQL);
                $enumHasNewUpdate = false;
                
                if ($enumResult) {
                    $enumRow = $enumResult->fetch(PDO::FETCH_ASSOC);
                    if ($enumRow) {
                        $currentType = $enumRow['Type'] ?? $enumRow['type'] ?? '';
                        if (!empty($currentType)) {
                            $enumHasNewUpdate = (stripos($currentType, 'new_update') !== false);
                            if (!$enumHasNewUpdate) {
                                error_log('ENUM does not contain new_update. Current: ' . $currentType);
                            }
                        }
                    }
                }
                
                // If inserting 'new_update' and ENUM doesn't have it, we MUST update the ENUM
                if ($data['type'] === 'new_update' && !$enumHasNewUpdate) {
                    // First, check if there are any rows with invalid type values
                    // MySQL won't allow ENUM change if existing data is invalid
                    try {
                        error_log('Checking for invalid type values in notifications table...');
                        $checkInvalidSQL = "SELECT id, type FROM notifications WHERE type NOT IN ('new_bug', 'status_change', 'new_update')";
                        $invalidResult = $this->conn->query($checkInvalidSQL);
                        $invalidRows = [];
                        
                        if ($invalidResult) {
                            $invalidRows = $invalidResult->fetchAll(PDO::FETCH_ASSOC);
                            if (count($invalidRows) > 0) {
                                error_log('Found ' . count($invalidRows) . ' rows with invalid type values');
                                // Update invalid types to a valid default (new_bug) or delete them
                                // For safety, we'll update them to 'new_bug' as a fallback
                                $updateInvalidSQL = "UPDATE notifications SET type = 'new_bug' WHERE type NOT IN ('new_bug', 'status_change', 'new_update')";
                                $this->conn->exec($updateInvalidSQL);
                                error_log('Updated ' . count($invalidRows) . ' invalid rows to type = new_bug');
                            }
                        }
                    } catch (Exception $e) {
                        error_log('Warning: Could not check/update invalid rows: ' . $e->getMessage());
                    }
                    
                    // Now try to alter the ENUM
                    $alterSuccess = false;
                    $alterAttempts = [
                        "ALTER TABLE notifications MODIFY COLUMN type ENUM('new_bug', 'status_change', 'new_update') NOT NULL",
                        "ALTER TABLE notifications CHANGE type type ENUM('new_bug', 'status_change', 'new_update') NOT NULL"
                    ];
                    
                    foreach ($alterAttempts as $index => $alterTypeSQL) {
                        try {
                            error_log("Attempting ALTER method " . ($index + 1) . ": " . $alterTypeSQL);
                            $this->conn->exec($alterTypeSQL);
                            error_log('ALTER TABLE executed successfully');
                            
                            // CRITICAL: Verify the update worked before proceeding
                            $verifyEnumSQL = "SHOW COLUMNS FROM notifications WHERE Field = 'type'";
                            $verifyResult = $this->conn->query($verifyEnumSQL);
                            
                            if ($verifyResult) {
                                $verifyRow = $verifyResult->fetch(PDO::FETCH_ASSOC);
                                $verifyType = $verifyRow['Type'] ?? $verifyRow['type'] ?? '';
                                $alterSuccess = (stripos($verifyType, 'new_update') !== false);
                                
                                if ($alterSuccess) {
                                    error_log('Verified: ENUM now contains new_update. Final ENUM: ' . $verifyType);
                                    break;
                                } else {
                                    error_log('ALTER method ' . ($index + 1) . ' failed verification. Current ENUM: ' . $verifyType);
                                }
                            }
                        } catch (Exception $e) {
                            $errorMsg = $e->getMessage();
                            error_log('ALTER method ' . ($index + 1) . ' failed: ' . $errorMsg);
                            
                            // If error mentions data truncation, there might still be invalid data
                            if (stripos($errorMsg, 'truncated') !== false || stripos($errorMsg, 'Data') !== false) {
                                error_log('Data truncation error detected. Checking for remaining invalid data...');
                                // Try to find and fix any remaining invalid data
                                try {
                                    $fixSQL = "UPDATE notifications SET type = 'new_bug' WHERE type NOT IN ('new_bug', 'status_change') OR type IS NULL";
                                    $this->conn->exec($fixSQL);
                                    error_log('Attempted to fix remaining invalid data');
                                } catch (Exception $fixEx) {
                                    error_log('Could not fix invalid data: ' . $fixEx->getMessage());
                                }
                            }
                            continue;
                        }
                    }
                    
                    if (!$alterSuccess) {
                        // Last resort: provide helpful error message with manual fix
                        error_log('ERROR: All ALTER attempts failed. ENUM does not support new_update type.');
                        $this->sendJsonResponse(500, 
                            'Database schema update failed. The notifications table does not support the new_update type. There may be invalid data in the table.',
                            [
                                'error' => 'ENUM column update failed',
                                'manual_fix' => "Please run these SQL commands manually in your database:\n1. UPDATE notifications SET type = 'new_bug' WHERE type NOT IN ('new_bug', 'status_change');\n2. ALTER TABLE notifications MODIFY type ENUM('new_bug', 'status_change', 'new_update') NOT NULL;"
                            ],
                            false
                        );
                        return;
                    }
                }
            }
            $sql = "
                INSERT INTO notifications (type, title, message, bug_id, bug_title, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                $errorInfo = $this->conn->errorInfo();
                error_log('Failed to prepare statement: ' . json_encode($errorInfo));
                $this->sendJsonResponse(500, 'Failed to prepare database statement');
                return;
            }
            
            try {
                $result = $stmt->execute([
                    $data['type'],
                    $data['title'],
                    $data['message'],
                    $bugId,
                    $data['bugTitle'],
                    $data['status'] ?? null,
                    $data['createdBy']
                ]);
                
                if ($result) {
                    $notificationId = $this->conn->lastInsertId();
                    
                    $this->sendJsonResponse(200, 'Notification broadcasted successfully', [
                        'notificationId' => $notificationId
                    ]);
                } else {
                    $errorInfo = $stmt->errorInfo();
                    $errorMsg = $errorInfo[2] ?? 'Unknown database error';
                    error_log('Failed to execute statement: ' . json_encode($errorInfo));
                    error_log('Data being inserted: ' . json_encode([
                        'type' => $data['type'],
                        'title' => $data['title'],
                        'bugId' => $bugId,
                        'bugTitle' => $data['bugTitle'],
                        'createdBy' => $data['createdBy']
                    ]));
                    $this->sendJsonResponse(500, 'Failed to broadcast notification: ' . $errorMsg);
                }
            } catch (PDOException $pdoEx) {
                $errorInfo = $stmt->errorInfo();
                $errorMsg = $pdoEx->getMessage();
                error_log('PDO Exception in execute: ' . $errorMsg);
                error_log('Error info: ' . json_encode($errorInfo));
                error_log('Data being inserted: ' . json_encode([
                    'type' => $data['type'],
                    'title' => $data['title'],
                    'bugId' => $bugId,
                    'bugTitle' => $data['bugTitle'],
                    'createdBy' => $data['createdBy']
                ]));
                $this->sendJsonResponse(500, 'Database error: ' . $errorMsg);
            }
            
        } catch (Exception $e) {
            error_log('Error in broadcastNotification: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $this->sendJsonResponse(500, 'Server error: ' . $e->getMessage());
        }
    }
}

// Create instance and handle request
$api = new BroadcastAPI();
$api->broadcastNotification(); 