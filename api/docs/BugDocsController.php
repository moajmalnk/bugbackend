<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../oauth/GoogleAuthService.php';
require_once __DIR__ . '/../projects/ProjectMemberController.php';

class BugDocsController extends BaseAPI {
    private $authService;
    
    public function __construct() {
        parent::__construct();
        $this->authService = new GoogleAuthService();
    }
    
    /**
     * Create a bug-specific document from template
     * 
     * @param string $bugId Bug ID
     * @param string $userId User ID
     * @param string $bugTitle Bug title
     * @param string $templateName Template name (optional, defaults to 'Bug Report Template')
     * @return array Document details with URL
     */
    public function createBugDocument($bugId, $userId, $bugTitle, $templateName = 'Bug Report Template') {
        try {
            // Get authenticated client
            $client = $this->authService->getClientForUser($userId);
            $docsService = new Google\Service\Docs($client);
            $driveService = new Google\Service\Drive($client);
            
            // Get bug details
            $bugDetails = $this->getBugDetails($bugId);
            if (!$bugDetails) {
                throw new Exception('Bug not found');
            }
            
            // Get template if specified
            $template = $this->getTemplate($templateName);
            
            if ($template) {
                // Create from template
                $result = $this->createFromTemplate(
                    $driveService,
                    $docsService,
                    $template['google_doc_id'],
                    "Bug - {$bugDetails['title']} - {$bugId}",
                    $this->getBugPlaceholders($bugDetails)
                );
                $docId = $result['documentId'];
                $docUrl = $result['documentUrl'];
                $templateId = $template['id'];
            } else {
                // Create blank document with content
                $documentName = "Bug - {$bugDetails['title']} - {$bugId}";
                $document = new Google\Service\Docs\Document(['title' => $documentName]);
                $doc = $docsService->documents->create($document);
                $docId = $doc->getDocumentId();
                $docUrl = "https://docs.google.com/document/d/{$docId}/edit";
                $templateId = null;
                
                // Set default sharing permissions (Anyone with link - Editor)
                $this->setDefaultSharingPermissions($driveService, $docId);
                
                // Add initial content
                $this->addBugContent($docsService, $docId, $bugDetails);
            }
            
            // Save to bug_documents table
            $stmt = $this->conn->prepare(
                "INSERT INTO bug_documents 
                (bug_id, google_doc_id, google_doc_url, document_name, created_by, template_id) 
                VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $bugId,
                $docId,
                $docUrl,
                "Bug - {$bugDetails['title']} - {$bugId}",
                $userId,
                $templateId
            ]);
            
            error_log("Bug document created: {$docId}");
            
            return [
                'success' => true,
                'document_id' => $docId,
                'document_url' => $docUrl,
                'document_name' => "Bug - {$bugDetails['title']} - {$bugId}"
            ];
            
        } catch (Exception $e) {
            error_log("Error creating bug document: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Create a general user document
     * 
     * @param string $userId User ID
     * @param string $docTitle Document title
     * @param int|null $templateId Template ID (optional)
     * @param string $docType Document type (default: 'general')
     * @param string|null $projectId Project ID (optional)
     * @return array Document details with URL
     */
    public function createGeneralDocument($userId, $docTitle, $templateId = null, $docType = 'general', $projectId = null) {
        try {
            // Get authenticated client
            $client = $this->authService->getClientForUser($userId);
            $docsService = new Google\Service\Docs($client);
            $driveService = new Google\Service\Drive($client);
            
            $docId = null;
            $docUrl = null;
            
            if ($templateId) {
                // Get template details
                $template = $this->getTemplateById($templateId);
                if (!$template) {
                    throw new Exception('Template not found');
                }
                
                // Create from template
                $result = $this->createFromTemplate(
                    $driveService,
                    $docsService,
                    $template['google_doc_id'],
                    $docTitle,
                    $this->getGeneralPlaceholders($userId, $docTitle)
                );
                $docId = $result['documentId'];
                $docUrl = $result['documentUrl'];
            } else {
                // Create blank document
                $document = new Google\Service\Docs\Document(['title' => $docTitle]);
                $doc = $docsService->documents->create($document);
                $docId = $doc->getDocumentId();
                $docUrl = "https://docs.google.com/document/d/{$docId}/edit";
                
                // Set default sharing permissions (Anyone with link - Editor)
                $this->setDefaultSharingPermissions($driveService, $docId);
            }
            
            // Check if project_id column exists, if not, try to add it (graceful fallback)
            try {
                $checkColumn = $this->conn->query("SHOW COLUMNS FROM user_documents LIKE 'project_id'");
                if ($checkColumn->rowCount() == 0) {
                    $this->conn->exec("ALTER TABLE user_documents ADD COLUMN project_id VARCHAR(36) DEFAULT NULL COMMENT 'Reference to projects.id'");
                }
            } catch (Exception $e) {
                error_log("Note: project_id column check/add failed (may already exist): " . $e->getMessage());
            }
            
            // Save to user_documents table
            $stmt = $this->conn->prepare(
                "INSERT INTO user_documents 
                (doc_title, google_doc_id, google_doc_url, creator_user_id, template_id, doc_type, project_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$docTitle, $docId, $docUrl, $userId, $templateId, $docType, $projectId]);
            $insertId = $this->conn->lastInsertId();
            
            error_log("General document created: {$docId} for user: {$userId}");
            
            return [
                'success' => true,
                'id' => $insertId,
                'document_id' => $docId,
                'document_url' => $docUrl,
                'document_title' => $docTitle
            ];
            
        } catch (Exception $e) {
            error_log("Error creating general document: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * List all general documents for a user
     * 
     * @param string $userId User ID
     * @param bool $includeArchived Include archived documents (default: false)
     * @param string|null $projectId Filter by project ID (optional)
     * @return array List of documents
     */
    public function listUserDocuments($userId, $includeArchived = false, $projectId = null) {
        try {
            // Check if project_id column exists by trying to select it
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_documents WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                error_log("Note: Could not check for project_id column: " . $e->getMessage());
                // Try alternative method - just try to select the column
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_documents LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    error_log("Note: project_id column does not exist: " . $e2->getMessage());
                    $hasProjectColumn = false;
                }
            }
            
            $sql = "SELECT 
                        d.id,
                        d.doc_title,
                        d.google_doc_id,
                        d.google_doc_url,
                        d.doc_type,
                        d.is_archived,
                        d.created_at,
                        d.updated_at,
                        d.last_accessed_at,
                        t.template_name";
            
            if ($hasProjectColumn) {
                $sql .= ", d.project_id, COALESCE(p.name, '') as project_name";
            }
            
            $sql .= " FROM user_documents d
                    LEFT JOIN doc_templates t ON d.template_id = t.id";
            
            if ($hasProjectColumn) {
                $sql .= " LEFT JOIN projects p ON d.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci";
            }
            
            $sql .= " WHERE CONVERT(d.creator_user_id, CHAR) COLLATE utf8mb4_unicode_ci = ?";
            
            $params = [$userId];
            
            if (!$includeArchived) {
                $sql .= " AND d.is_archived = 0";
            }
            
            if ($projectId !== null && $hasProjectColumn) {
                $sql .= " AND CONVERT(d.project_id, CHAR) COLLATE utf8mb4_unicode_ci = ?";
                $params[] = $projectId;
            }
            
            $sql .= " ORDER BY d.created_at DESC";
            
            error_log("Executing SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            
            try {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $sqlError) {
                error_log("SQL execution failed: " . $sqlError->getMessage());
                // If query failed and we tried to use project_id, retry without it
                if ($hasProjectColumn) {
                    error_log("Retrying query without project columns...");
                    $sql = "SELECT 
                                d.id,
                                d.doc_title,
                                d.google_doc_id,
                                d.google_doc_url,
                                d.doc_type,
                                d.is_archived,
                                d.created_at,
                                d.updated_at,
                                d.last_accessed_at,
                                t.template_name
                            FROM user_documents d
                            LEFT JOIN doc_templates t ON d.template_id = t.id
                            WHERE d.creator_user_id = ?";
                    
                    $params = [$userId];
            
            if (!$includeArchived) {
                $sql .= " AND d.is_archived = 0";
            }
            
            $sql .= " ORDER BY d.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
                    $stmt->execute($params);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $hasProjectColumn = false; // Mark as not available
                } else {
                    throw $sqlError; // Re-throw if we weren't using project columns
                }
            }
            
            // Ensure project_id and project_name exist in results
            foreach ($documents as &$doc) {
                if (!isset($doc['project_id'])) {
                    $doc['project_id'] = null;
                }
                if (!isset($doc['project_name'])) {
                    $doc['project_name'] = null;
                }
            }
            unset($doc);
            
            return [
                'success' => true,
                'documents' => $documents,
                'count' => count($documents)
            ];
            
        } catch (Exception $e) {
            error_log("Error listing user documents: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * List all documents for admin users, grouped by project
     * 
     * @param string $userId User ID (should be admin)
     * @param bool $includeArchived Include archived documents (default: false)
     * @return array Documents grouped by project
     */
    public function listAllDocuments($userId, $includeArchived = false) {
        try {
            // Check if project_id column exists
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_documents WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_documents LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    $hasProjectColumn = false;
                }
            }
            
            $sql = "SELECT 
                        d.id,
                        d.doc_title,
                        d.google_doc_id,
                        d.google_doc_url,
                        d.doc_type,
                        d.is_archived,
                        d.created_at,
                        d.updated_at,
                        d.last_accessed_at,
                        d.creator_user_id,
                        u.username as creator_name,
                        t.template_name";
            
            if ($hasProjectColumn) {
                $sql .= ", d.project_id, COALESCE(p.name, '') as project_name";
            } else {
                $sql .= ", NULL as project_id, '' as project_name";
            }
            
            $sql .= " FROM user_documents d
                    LEFT JOIN doc_templates t ON d.template_id = t.id
                    LEFT JOIN users u ON d.creator_user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci";
            
            if ($hasProjectColumn) {
                $sql .= " LEFT JOIN projects p ON d.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci";
            }
            
            $sql .= " WHERE 1=1";
            
            if (!$includeArchived) {
                $sql .= " AND d.is_archived = 0";
            }
            
            $sql .= " ORDER BY " . ($hasProjectColumn ? "COALESCE(p.name, 'No Project'), " : "") . "d.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure project fields exist
            foreach ($documents as &$doc) {
                if (!isset($doc['project_id'])) {
                    $doc['project_id'] = null;
                }
                if (!isset($doc['project_name'])) {
                    $doc['project_name'] = null;
                }
                if (!isset($doc['creator_name'])) {
                    $doc['creator_name'] = null;
                }
            }
            unset($doc);
            
            // Group by project
            $grouped = [];
            foreach ($documents as $doc) {
                $projectId = $doc['project_id'] ?? 'no-project';
                $projectName = $doc['project_name'] ?? 'No Project';
                
                if (!isset($grouped[$projectId])) {
                    $grouped[$projectId] = [
                        'project_id' => $doc['project_id'],
                        'project_name' => $projectName,
                        'documents' => []
                    ];
                }
                
                $grouped[$projectId]['documents'][] = $doc;
            }
            
            return [
                'success' => true,
                'documents' => array_values($grouped),
                'count' => count($documents)
            ];
            
        } catch (Exception $e) {
            error_log("Error listing all documents: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * List shared documents for developers/testers from projects they're members of
     * 
     * @param string $userId User ID
     * @param bool $includeArchived Include archived documents (default: false)
     * @return array List of documents
     */
    public function listSharedDocuments($userId, $includeArchived = false) {
        try {
            $memberController = new ProjectMemberController();
            $userProjects = $memberController->getUserProjects($userId);
            
            if (empty($userProjects)) {
                return [
                    'success' => true,
                    'documents' => [],
                    'count' => 0
                ];
            }
            
            $projectIds = array_column($userProjects, 'project_id');
            
            // Check if project_id column exists
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_documents WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_documents LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    $hasProjectColumn = false;
                }
            }
            
            if (!$hasProjectColumn) {
                // If no project column, return empty (can't filter by project)
                return [
                    'success' => true,
                    'documents' => [],
                    'count' => 0
                ];
            }
            
            // Build placeholders for IN clause
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            
            $sql = "SELECT 
                        d.id,
                        d.doc_title,
                        d.google_doc_id,
                        d.google_doc_url,
                        d.doc_type,
                        d.is_archived,
                        d.created_at,
                        d.updated_at,
                        d.last_accessed_at,
                        d.creator_user_id,
                        u.username as creator_name,
                        t.template_name,
                        d.project_id,
                        COALESCE(p.name, '') as project_name
                    FROM user_documents d
                    LEFT JOIN doc_templates t ON d.template_id = t.id
                    LEFT JOIN users u ON d.creator_user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN projects p ON d.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                    WHERE CONVERT(d.project_id, CHAR) COLLATE utf8mb4_unicode_ci IN ($placeholders)";
            
            $params = $projectIds;
            
            if (!$includeArchived) {
                $sql .= " AND d.is_archived = 0";
            }
            
            $sql .= " ORDER BY d.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure project fields exist
            foreach ($documents as &$doc) {
                if (!isset($doc['project_id'])) {
                    $doc['project_id'] = null;
                }
                if (!isset($doc['project_name'])) {
                    $doc['project_name'] = null;
                }
                if (!isset($doc['creator_name'])) {
                    $doc['creator_name'] = null;
                }
            }
            unset($doc);
            
            return [
                'success' => true,
                'documents' => $documents,
                'count' => count($documents)
            ];
            
        } catch (Exception $e) {
            error_log("Error listing shared documents: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get documents for a specific project with access validation
     * 
     * @param string $projectId Project ID
     * @param string $userId User ID
     * @param bool $includeArchived Include archived documents (default: false)
     * @return array List of documents
     */
    public function getDocumentsByProject($projectId, $userId, $includeArchived = false) {
        try {
            // Validate project access
            $memberController = new ProjectMemberController();
            $hasAccess = $memberController->hasProjectAccess($userId, $projectId);
            
            if (!$hasAccess) {
                throw new Exception('Access denied to this project');
            }
            
            // Check if project_id column exists
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_documents WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_documents LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    $hasProjectColumn = false;
                }
            }
            
            if (!$hasProjectColumn) {
                return [
                    'success' => true,
                    'documents' => [],
                    'count' => 0,
                    'project_id' => $projectId,
                    'project_name' => null
                ];
            }
            
            // Get project name
            $projectStmt = $this->conn->prepare("SELECT name FROM projects WHERE id = ?");
            $projectStmt->execute([$projectId]);
            $project = $projectStmt->fetch(PDO::FETCH_ASSOC);
            $projectName = $project ? $project['name'] : null;
            
            $sql = "SELECT 
                        d.id,
                        d.doc_title,
                        d.google_doc_id,
                        d.google_doc_url,
                        d.doc_type,
                        d.is_archived,
                        d.created_at,
                        d.updated_at,
                        d.last_accessed_at,
                        d.creator_user_id,
                        u.username as creator_name,
                        t.template_name,
                        d.project_id,
                        COALESCE(p.name, '') as project_name
                    FROM user_documents d
                    LEFT JOIN doc_templates t ON d.template_id = t.id
                    LEFT JOIN users u ON d.creator_user_id COLLATE utf8mb4_unicode_ci = u.id COLLATE utf8mb4_unicode_ci
                    LEFT JOIN projects p ON d.project_id COLLATE utf8mb4_unicode_ci = p.id COLLATE utf8mb4_unicode_ci
                    WHERE CONVERT(d.project_id, CHAR) COLLATE utf8mb4_unicode_ci = ?";
            
            $params = [$projectId];
            
            if (!$includeArchived) {
                $sql .= " AND d.is_archived = 0";
            }
            
            $sql .= " ORDER BY d.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ensure all fields exist
            foreach ($documents as &$doc) {
                if (!isset($doc['project_id'])) {
                    $doc['project_id'] = null;
                }
                if (!isset($doc['project_name'])) {
                    $doc['project_name'] = $projectName;
                }
                if (!isset($doc['creator_name'])) {
                    $doc['creator_name'] = null;
                }
            }
            unset($doc);
            
            return [
                'success' => true,
                'documents' => $documents,
                'count' => count($documents),
                'project_id' => $projectId,
                'project_name' => $projectName
            ];
            
        } catch (Exception $e) {
            error_log("Error getting documents by project: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get projects with document counts for card display
     * 
     * @param string $userId User ID
     * @return array Projects with document counts
     */
    public function getProjectsWithDocumentCounts($userId) {
        try {
            $memberController = new ProjectMemberController();
            $userProjects = $memberController->getUserProjects($userId);
            
            // Get user role to determine if admin
            $userStmt = $this->conn->prepare("SELECT role FROM users WHERE id = ?");
            $userStmt->execute([$userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            $isAdmin = $user && strtolower($user['role']) === 'admin';
            
            // Check if project_id column exists
            $hasProjectColumn = false;
            try {
                $testStmt = $this->conn->query("SHOW COLUMNS FROM user_documents WHERE Field = 'project_id'");
                $hasProjectColumn = $testStmt->rowCount() > 0;
            } catch (Exception $e) {
                try {
                    $testStmt = $this->conn->query("SELECT project_id FROM user_documents LIMIT 1");
                    $hasProjectColumn = true;
                } catch (Exception $e2) {
                    $hasProjectColumn = false;
                }
            }
            
            if (!$hasProjectColumn) {
                // Return projects without counts
                $projectIds = array_column($userProjects, 'project_id');
                if (empty($projectIds) && !$isAdmin) {
                    return [
                        'success' => true,
                        'projects' => []
                    ];
                }
                
                if ($isAdmin) {
                    $projectStmt = $this->conn->query("SELECT id, name, description, status FROM projects");
                    $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
                    $projectStmt = $this->conn->prepare("SELECT id, name, description, status FROM projects WHERE id IN ($placeholders)");
                    $projectStmt->execute($projectIds);
                    $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                foreach ($projects as &$proj) {
                    $proj['document_count'] = 0;
                }
                unset($proj);
                
                return [
                    'success' => true,
                    'projects' => $projects
                ];
            }
            
            // Get projects
            if ($isAdmin) {
                $projectStmt = $this->conn->query("SELECT id, name, description, status FROM projects");
                $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $projectIds = array_column($userProjects, 'project_id');
                if (empty($projectIds)) {
                    return [
                        'success' => true,
                        'projects' => []
                    ];
                }
                
                $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
                $projectStmt = $this->conn->prepare("SELECT id, name, description, status FROM projects WHERE id IN ($placeholders)");
                $projectStmt->execute($projectIds);
                $projects = $projectStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Get document counts for each project
            foreach ($projects as &$project) {
                $countStmt = $this->conn->prepare(
                    "SELECT COUNT(*) as count FROM user_documents WHERE CONVERT(project_id, CHAR) COLLATE utf8mb4_unicode_ci = ? AND is_archived = 0"
                );
                $countStmt->execute([$project['id']]);
                $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
                $project['document_count'] = (int)$countResult['count'];
            }
            unset($project);
            
            // Also get count for "No Project" if admin
            if ($isAdmin) {
                $noProjectStmt = $this->conn->query(
                    "SELECT COUNT(*) as count FROM user_documents WHERE (project_id IS NULL OR project_id = '') AND is_archived = 0"
                );
                $noProjectCount = $noProjectStmt->fetch(PDO::FETCH_ASSOC);
                if ($noProjectCount && $noProjectCount['count'] > 0) {
                    $projects[] = [
                        'id' => 'no-project',
                        'name' => 'No Project',
                        'description' => 'Documents not associated with any project',
                        'status' => 'active',
                        'document_count' => (int)$noProjectCount['count']
                    ];
                }
            }
            
            return [
                'success' => true,
                'projects' => $projects
            ];
            
        } catch (Exception $e) {
            error_log("Error getting projects with document counts: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Delete a general document
     * 
     * @param int $documentId Document ID from user_documents table
     * @param string $userId User ID (for authorization)
     * @return array Success status
     */
    public function deleteDocument($documentId, $userId) {
        try {
            // Get document details and verify ownership
            $stmt = $this->conn->prepare(
                "SELECT google_doc_id, creator_user_id, doc_title 
                 FROM user_documents 
                 WHERE id = ? AND creator_user_id = ?"
            );
            $stmt->execute([$documentId, $userId]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$document) {
                throw new Exception('Document not found or access denied');
            }
            
            // Get authenticated client
            $client = $this->authService->getClientForUser($userId);
            $driveService = new Google\Service\Drive($client);
            
            // Delete from Google Drive
            try {
                $driveService->files->delete($document['google_doc_id']);
                error_log("Deleted Google Doc: {$document['google_doc_id']}");
            } catch (Exception $e) {
                error_log("Warning: Failed to delete from Google Drive: " . $e->getMessage());
                // Continue to delete from database even if Google Drive deletion fails
            }
            
            // Delete from database
            $stmt = $this->conn->prepare("DELETE FROM user_documents WHERE id = ?");
            $stmt->execute([$documentId]);
            
            return [
                'success' => true,
                'message' => "Document '{$document['doc_title']}' deleted successfully"
            ];
            
        } catch (Exception $e) {
            error_log("Error deleting document: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Archive/unarchive a document (soft delete)
     * 
     * @param int $documentId Document ID
     * @param string $userId User ID
     * @param bool $archive True to archive, false to unarchive
     * @return array Success status
     */
    public function archiveDocument($documentId, $userId, $archive = true) {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE user_documents 
                 SET is_archived = ?, updated_at = CURRENT_TIMESTAMP 
                 WHERE id = ? AND creator_user_id = ?"
            );
            $stmt->execute([$archive ? 1 : 0, $documentId, $userId]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Document not found or access denied');
            }
            
            return [
                'success' => true,
                'message' => $archive ? 'Document archived' : 'Document restored'
            ];
            
        } catch (Exception $e) {
            error_log("Error archiving document: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update last accessed timestamp
     * 
     * @param int $documentId Document ID
     * @param string $userId User ID
     */
    public function trackAccess($documentId, $userId) {
        try {
            $stmt = $this->conn->prepare(
                "UPDATE user_documents 
                 SET last_accessed_at = CURRENT_TIMESTAMP 
                 WHERE id = ? AND creator_user_id = ?"
            );
            $stmt->execute([$documentId, $userId]);
        } catch (Exception $e) {
            error_log("Error tracking access: " . $e->getMessage());
            // Non-critical, don't throw
        }
    }
    
    // ========================================================================
    // Template Methods
    // ========================================================================
    
    /**
     * Get all active templates
     * 
     * @param string|null $category Filter by category (optional)
     * @return array List of templates
     */
    public function listTemplates($category = null) {
        try {
            $sql = "SELECT * FROM doc_templates WHERE is_active = 1";
            $params = [];
            
            if ($category) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }
            
            $sql .= " ORDER BY category, template_name";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark templates with placeholder IDs as not ready
            foreach ($templates as &$template) {
                $template['is_configured'] = !$this->isPlaceholderTemplateId($template['google_doc_id']);
            }
            
            return [
                'success' => true,
                'templates' => $templates,
                'count' => count($templates)
            ];
            
        } catch (Exception $e) {
            error_log("Error listing templates: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get template by name
     */
    private function getTemplate($templateName) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM doc_templates WHERE template_name = ? AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([$templateName]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if template has a valid Google Doc ID (not a placeholder)
            if ($template && $this->isPlaceholderTemplateId($template['google_doc_id'])) {
                error_log("Template {$template['template_name']} has placeholder ID, skipping template");
                return null;
            }
            
            return $template;
        } catch (Exception $e) {
            error_log("Error getting template: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get template by ID
     */
    private function getTemplateById($templateId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM doc_templates WHERE id = ? AND is_active = 1 LIMIT 1"
            );
            $stmt->execute([$templateId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if template has a valid Google Doc ID (not a placeholder)
            if ($template && $this->isPlaceholderTemplateId($template['google_doc_id'])) {
                error_log("Template {$template['template_name']} has placeholder ID, skipping template");
                return null;
            }
            
            return $template;
        } catch (Exception $e) {
            error_log("Error getting template by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if a template ID is a placeholder
     */
    private function isPlaceholderTemplateId($docId) {
        // Check for common placeholder patterns
        $placeholders = [
            'TEMPLATE_',
            'YOUR_DOC_ID',
            'YOUR_ACTUAL_DOC_ID',
            'PLACEHOLDER',
            'REPLACE_ME',
            'CHANGE_THIS'
        ];
        
        foreach ($placeholders as $placeholder) {
            if (stripos($docId, $placeholder) !== false) {
                return true;
            }
        }
        
        // Check if it's too short (real Google Doc IDs are typically 44 characters)
        if (strlen($docId) < 20) {
            return true;
        }
        
        return false;
    }
    
    // ========================================================================
    // Helper Methods
    // ========================================================================
    
    /**
     * Create document from template using Drive API copy
     */
    private function createFromTemplate($driveService, $docsService, $templateDocId, $newTitle, $placeholders) {
        // Copy the template
        $copiedFile = new Google\Service\Drive\DriveFile();
        $copiedFile->setName($newTitle);
        
        $newFile = $driveService->files->copy($templateDocId, $copiedFile);
        $newDocId = $newFile->getId();
        
        // Set default sharing permissions (Anyone with link - Editor)
        $this->setDefaultSharingPermissions($driveService, $newDocId);
        
        // Replace placeholders
        if (!empty($placeholders)) {
            $this->replacePlaceholders($docsService, $newDocId, $placeholders);
        }
        
        return [
            'documentId' => $newDocId,
            'documentUrl' => "https://docs.google.com/document/d/{$newDocId}/edit"
        ];
    }
    
    /**
     * Replace placeholders in a document
     */
    private function replacePlaceholders($docsService, $docId, $placeholders) {
        $requests = [];
        
        foreach ($placeholders as $placeholder => $value) {
            $requests[] = new Google\Service\Docs\Request([
                'replaceAllText' => [
                    'containsText' => [
                        'text' => $placeholder,
                        'matchCase' => false
                    ],
                    'replaceText' => $value
                ]
            ]);
        }
        
        if (!empty($requests)) {
            $batchUpdateRequest = new Google\Service\Docs\BatchUpdateDocumentRequest([
                'requests' => $requests
            ]);
            $docsService->documents->batchUpdate($docId, $batchUpdateRequest);
        }
    }
    
    /**
     * Get bug details from database
     */
    private function getBugDetails($bugId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM bugs WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$bugId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting bug details: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get placeholders for bug documents
     */
    private function getBugPlaceholders($bugDetails) {
        return [
            '{{BUG_ID}}' => $bugDetails['id'],
            '{{BUG_TITLE}}' => $bugDetails['title'],
            '{{DESCRIPTION}}' => $this->cleanBugDescription($bugDetails['description'] ?? ''),
            '{{PRIORITY}}' => strtoupper($bugDetails['priority'] ?? 'MEDIUM'),
            '{{STATUS}}' => strtoupper($bugDetails['status'] ?? 'PENDING'),
            '{{CREATED_DATE}}' => date('F j, Y', strtotime($bugDetails['created_at'])),
            '{{CURRENT_DATE}}' => date('F j, Y')
        ];
    }
    
    /**
     * Get placeholders for general documents
     */
    private function getGeneralPlaceholders($userId, $docTitle) {
        return [
            '{{TITLE}}' => $docTitle,
            '{{USER_ID}}' => $userId,
            '{{DATE}}' => date('F j, Y'),
            '{{CURRENT_DATE}}' => date('F j, Y'),
            '{{TIMESTAMP}}' => date('F j, Y g:i A')
        ];
    }
    
    /**
     * Clean bug description (remove debug data)
     */
    private function cleanBugDescription($description) {
        $description = preg_replace('/Voice note debug:.*?}/s', '', $description);
        $description = preg_replace('/Screenshot container:.*?}/s', '', $description);
        $description = preg_replace('/Duration loaded for:.*?\.webm/s', '', $description);
        $description = preg_replace('/\{[^}]*id[^}]*\}/', '', $description);
        $description = preg_replace('/apiBaseUrl[^,]*/', '', $description);
        $description = preg_replace('/audioUrl[^,]*/', '', $description);
        $description = preg_replace('/filePath[^,]*/', '', $description);
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);
        
        if (empty($description) || strlen($description) < 10) {
            return "Bug reported through BugRicer system. Please add detailed description of the issue.";
        }
        
        return $description;
    }
    
    /**
     * Add content to bug document (for non-template creation)
     */
    private function addBugContent($docsService, $docId, $bugDetails) {
        $description = $this->cleanBugDescription($bugDetails['description'] ?? 'No description provided');
        
        $content = "BUG REPORT & INVESTIGATION DOCUMENT\n\n";
        $content .= "════════════════════════════════════════════════════════════\n\n";
        $content .= "ISSUE OVERVIEW\n\n";
        $content .= "Bug Reference:  " . $bugDetails['id'] . "\n";
        $content .= "Title:          " . $bugDetails['title'] . "\n";
        $content .= "Severity:       " . strtoupper($bugDetails['priority']) . " PRIORITY\n";
        $content .= "Current Status: " . strtoupper($bugDetails['status']) . "\n";
        $content .= "Reported Date:  " . date('F j, Y', strtotime($bugDetails['created_at'])) . "\n\n";
        $content .= "════════════════════════════════════════════════════════════\n\n";
        $content .= "DESCRIPTION\n\n" . $description . "\n\n";
        
        $requests = [
            new Google\Service\Docs\Request([
                'insertText' => [
                    'location' => ['index' => 1],
                    'text' => $content
                ]
            ])
        ];
        
        $batchUpdateRequest = new Google\Service\Docs\BatchUpdateDocumentRequest(['requests' => $requests]);
        $docsService->documents->batchUpdate($docId, $batchUpdateRequest);
    }
    
    /**
     * Set default sharing permissions for a document
     * Sets "Anyone with the link" to "Editor" access
     * 
     * @param Google\Service\Drive $driveService Drive service instance
     * @param string $docId Google Document ID
     */
    private function setDefaultSharingPermissions($driveService, $docId) {
        try {
            // Create permission for "Anyone with the link" to have "Editor" access
            $permission = new Google\Service\Drive\Permission([
                'type' => 'anyone',
                'role' => 'writer', // 'writer' = Editor access
                'allowFileDiscovery' => false // Only accessible via link, not searchable
            ]);
            
            // Apply the permission
            $driveService->permissions->create($docId, $permission);
            
            error_log("Set default sharing permissions for document: {$docId}");
            
        } catch (Exception $e) {
            error_log("Warning: Failed to set sharing permissions for document {$docId}: " . $e->getMessage());
            // Don't throw - document creation should still succeed even if sharing fails
        }
    }
}

