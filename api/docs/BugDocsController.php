<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../oauth/GoogleAuthService.php';

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
     * @return array Document details with URL
     */
    public function createGeneralDocument($userId, $docTitle, $templateId = null, $docType = 'general') {
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
            }
            
            // Save to user_documents table
            $stmt = $this->conn->prepare(
                "INSERT INTO user_documents 
                (doc_title, google_doc_id, google_doc_url, creator_user_id, template_id, doc_type) 
                VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$docTitle, $docId, $docUrl, $userId, $templateId, $docType]);
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
     * @return array List of documents
     */
    public function listUserDocuments($userId, $includeArchived = false) {
        try {
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
            
            if (!$includeArchived) {
                $sql .= " AND d.is_archived = 0";
            }
            
            $sql .= " ORDER BY d.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$userId]);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
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
}

