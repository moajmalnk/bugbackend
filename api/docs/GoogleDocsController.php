<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../oauth/GoogleOAuthController.php';

class GoogleDocsController extends BaseAPI {
    private $oauthController;
    
    public function __construct() {
        parent::__construct();
        $this->oauthController = new GoogleOAuthController();
    }
    
    /**
     * Create a new Google Doc for a bug
     */
    public function createBugDocument($bugId, $userId) {
        try {
            error_log("Creating Google Doc for bug: " . $bugId . ", user: " . $userId);
            
            // Get user's refresh token from database
            $tokenData = $this->oauthController->getRefreshToken($userId);
            
            if (!$tokenData || empty($tokenData['refresh_token'])) {
                throw new Exception('Google account not linked. Please connect your Google account first.');
            }
            
            $refreshToken = $tokenData['refresh_token'];
            
            // Get fresh access token
            error_log("Refreshing access token...");
            $accessToken = $this->oauthController->getFreshAccessToken($refreshToken);
            
            // Initialize Google Docs service
            $client = $this->oauthController->getClient();
            $client->setAccessToken($accessToken);
            
            $docsService = new Google\Service\Docs($client);
            $driveService = new Google\Service\Drive($client);
            
            // Get bug details to name the document
            $bugDetails = $this->getBugDetails($bugId);
            if (!$bugDetails) {
                throw new Exception('Bug not found');
            }
            
            $documentName = "Bug - " . $bugDetails['title'] . " - " . $bugId;
            error_log("Creating document with name: " . $documentName);
            
            // Create a new Google Document
            $document = new Google\Service\Docs\Document([
                'title' => $documentName
            ]);
            
            $createdDoc = $docsService->documents->create($document);
            $docId = $createdDoc->getDocumentId();
            $docUrl = "https://docs.google.com/document/d/" . $docId . "/edit";
            
            error_log("Document created with ID: " . $docId);
            
            // Set default sharing permissions (Anyone with link - Editor)
            $this->setDefaultSharingPermissions($driveService, $docId);
            
            // Add initial content to the document
            $this->addInitialContent($docsService, $docId, $bugDetails);
            
            // Save document reference in database
            $this->saveBugDocument($bugId, $docId, $docUrl, $documentName, $userId);
            
            error_log("Bug document created successfully");
            
            return [
                'document_id' => $docId,
                'document_url' => $docUrl,
                'document_name' => $documentName
            ];
            
        } catch (Exception $e) {
            error_log("Error creating bug document: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Get bug details from database
     */
    private function getBugDetails($bugId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT id, title, description, priority, status, created_at 
                 FROM bugs 
                 WHERE id = ? 
                 LIMIT 1"
            );
            $stmt->execute([$bugId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching bug details: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Clean up bug description by removing debug data and formatting properly
     */
    private function cleanBugDescription($description) {
        // Remove debug data patterns
        $description = preg_replace('/Voice note debug:.*?}/s', '', $description);
        $description = preg_replace('/Screenshot container:.*?}/s', '', $description);
        $description = preg_replace('/Duration loaded for:.*?\.webm/s', '', $description);
        
        // Clean up any remaining debug artifacts
        $description = preg_replace('/\{[^}]*id[^}]*\}/', '', $description);
        $description = preg_replace('/apiBaseUrl[^,]*/', '', $description);
        $description = preg_replace('/audioUrl[^,]*/', '', $description);
        $description = preg_replace('/filePath[^,]*/', '', $description);
        
        // Clean up extra whitespace and newlines
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);
        
        // If description is empty or just contains debug remnants, provide a default
        if (empty($description) || strlen($description) < 10) {
            return "Bug reported through BugRicer system. Please add detailed description of the issue.";
        }
        
        return $description;
    }
    
    /**
     * Add initial content to the Google Doc
     */
    private function addInitialContent($docsService, $docId, $bugDetails) {
        try {
            $requests = [];
            
            // Clean up description - remove debug data and format properly
            $description = $this->cleanBugDescription($bugDetails['description'] ?? 'No description provided');
            
            // Prepare professional, industry-standard content
            $content = "BUG REPORT & INVESTIGATION DOCUMENT\n\n";
            $content .= "════════════════════════════════════════════════════════════\n\n";
            
            // Bug Overview Section
            $content .= "ISSUE OVERVIEW\n\n";
            $content .= "Bug Reference:  " . $bugDetails['id'] . "\n";
            $content .= "Title:          " . $bugDetails['title'] . "\n";
            $content .= "Severity:       " . strtoupper($bugDetails['priority']) . " PRIORITY\n";
            $content .= "Current Status: " . strtoupper($bugDetails['status']) . "\n";
            $content .= "Reported Date:  " . date('F j, Y', strtotime($bugDetails['created_at'])) . "\n";
            $content .= "Last Updated:   " . date('F j, Y', time()) . "\n\n";
            
            $content .= "════════════════════════════════════════════════════════════\n\n";
            
            // Issue Description
            $content .= "1. ISSUE DESCRIPTION\n\n";
            $content .= $description . "\n\n\n";
            
            // Reproduction Steps
            $content .= "2. STEPS TO REPRODUCE\n\n";
            $content .= "Please provide detailed steps to reproduce this issue:\n\n";
            $content .= "   Step 1:  \n";
            $content .= "   Step 2:  \n";
            $content .= "   Step 3:  \n";
            $content .= "   Step 4:  \n\n";
            $content .= "Frequency:     [ ] Always  [ ] Sometimes  [ ] Rarely\n";
            $content .= "Reproducible:  [ ] Yes     [ ] No        [ ] Intermittent\n\n\n";
            
            // Expected vs Actual
            $content .= "3. EXPECTED vs ACTUAL BEHAVIOR\n\n";
            $content .= "Expected Behavior:\n";
            $content .= "   • \n\n";
            $content .= "Actual Behavior:\n";
            $content .= "   • \n\n\n";
            
            // Environment Details
            $content .= "4. ENVIRONMENT & CONFIGURATION\n\n";
            $content .= "Platform Details:\n";
            $content .= "   • Operating System:    \n";
            $content .= "   • Browser/App Version: \n";
            $content .= "   • Device Model:        \n";
            $content .= "   • Screen Resolution:   \n";
            $content .= "   • Network Condition:   \n\n";
            $content .= "Additional Context:\n";
            $content .= "   • User Role/Permissions: \n";
            $content .= "   • Account Type:          \n";
            $content .= "   • Data/Test Case:        \n\n\n";
            
            // Impact Assessment
            $content .= "5. IMPACT ASSESSMENT\n\n";
            $content .= "Affected Users:      [ ] All Users  [ ] Specific Users  [ ] Admin Only\n";
            $content .= "Business Impact:     [ ] Critical   [ ] High           [ ] Medium  [ ] Low\n";
            $content .= "Workaround Available: [ ] Yes       [ ] No\n\n";
            $content .= "Impact Description:\n";
            $content .= "   \n\n\n";
            
            // Technical Analysis
            $content .= "6. TECHNICAL ANALYSIS\n\n";
            $content .= "Root Cause:\n";
            $content .= "   \n\n";
            $content .= "Affected Components:\n";
            $content .= "   • Frontend:  \n";
            $content .= "   • Backend:   \n";
            $content .= "   • Database:  \n";
            $content .= "   • API:       \n\n";
            $content .= "Error Messages/Logs:\n";
            $content .= "   \n\n\n";
            
            // Solution & Fix
            $content .= "7. PROPOSED SOLUTION\n\n";
            $content .= "Recommended Fix:\n";
            $content .= "   \n\n";
            $content .= "Alternative Approaches:\n";
            $content .= "   1. \n";
            $content .= "   2. \n\n";
            $content .= "Implementation Complexity: [ ] Low  [ ] Medium  [ ] High\n";
            $content .= "Estimated Time:            \n\n\n";
            
            // Testing Strategy
            $content .= "8. TESTING & VERIFICATION\n\n";
            $content .= "Test Cases:\n";
            $content .= "   ✓ Test Case 1: \n";
            $content .= "   ✓ Test Case 2: \n";
            $content .= "   ✓ Test Case 3: \n\n";
            $content .= "Regression Testing Required: [ ] Yes  [ ] No\n";
            $content .= "QA Sign-off:                 [ ] Pending  [ ] Approved\n\n\n";
            
            // Additional Information
            $content .= "9. ADDITIONAL INFORMATION\n\n";
            $content .= "Screenshots/Videos:\n";
            $content .= "   \n\n";
            $content .= "Related Bugs:\n";
            $content .= "   • \n\n";
            $content .= "Notes & Comments:\n";
            $content .= "   \n\n\n";
            
            // Sign-off Section
            $content .= "════════════════════════════════════════════════════════════\n\n";
            $content .= "STAKEHOLDER SIGN-OFF\n\n";
            $content .= "Developer:        ________________    Date: __________\n";
            $content .= "QA Engineer:      ________________    Date: __________\n";
            $content .= "Product Manager:  ________________    Date: __________\n\n";
            $content .= "════════════════════════════════════════════════════════════\n\n";
            $content .= "Document generated by BugRicer | " . date('F j, Y \a\t g:i A T') . "\n";
            
            error_log("Content length: " . strlen($content));
            error_log("Content preview: " . substr($content, 0, 200) . "...");
            
            // Insert text at the beginning of the document
            $requests[] = new Google\Service\Docs\Request([
                'insertText' => [
                    'location' => [
                        'index' => 1
                    ],
                    'text' => $content
                ]
            ]);
            
            // Add simple formatting for main title only
            $mainTitleLength = strlen('BUG REPORT & INVESTIGATION DOCUMENT');
            $requests[] = new Google\Service\Docs\Request([
                'updateTextStyle' => [
                    'range' => [
                        'startIndex' => 1,
                        'endIndex' => $mainTitleLength + 1
                    ],
                    'textStyle' => [
                        'bold' => true,
                        'fontSize' => [
                            'magnitude' => 16,
                            'unit' => 'PT'
                        ]
                    ],
                    'fields' => 'bold,fontSize'
                ]
            ]);
            
            // Execute batch update
            $batchUpdateRequest = new Google\Service\Docs\BatchUpdateDocumentRequest([
                'requests' => $requests
            ]);
            
            error_log("Executing batch update with " . count($requests) . " requests");
            $result = $docsService->documents->batchUpdate($docId, $batchUpdateRequest);
            error_log("Batch update result: " . json_encode($result));
            
            error_log("Initial content added to document successfully");
            
        } catch (Exception $e) {
            error_log("Error adding initial content: " . $e->getMessage());
            // Don't throw - document was created successfully
        }
    }
    
    /**
     * Save bug document reference in database
     */
    private function saveBugDocument($bugId, $docId, $docUrl, $documentName, $userId) {
        try {
            // Check if document already exists for this bug
            $stmt = $this->conn->prepare(
                "SELECT id FROM bug_documents WHERE bug_id = ? AND google_doc_id = ? LIMIT 1"
            );
            $stmt->execute([$bugId, $docId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update existing record
                $stmt = $this->conn->prepare(
                    "UPDATE bug_documents 
                     SET google_doc_url = ?, document_name = ?, updated_at = CURRENT_TIMESTAMP 
                     WHERE id = ?"
                );
                $stmt->execute([$docUrl, $documentName, $existing['id']]);
            } else {
                // Insert new record
                $stmt = $this->conn->prepare(
                    "INSERT INTO bug_documents (bug_id, google_doc_id, google_doc_url, document_name, created_by) 
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([$bugId, $docId, $docUrl, $documentName, $userId]);
            }
            
            // Clear cache
            $this->clearCache('bug_documents_' . $bugId);
            
        } catch (Exception $e) {
            error_log("Error saving bug document: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get all documents for a bug
     */
    public function getBugDocuments($bugId) {
        try {
            $stmt = $this->conn->prepare(
                "SELECT bd.*, u.username as created_by_name 
                 FROM bug_documents bd 
                 LEFT JOIN users u ON bd.created_by = u.id 
                 WHERE bd.bug_id = ? 
                 ORDER BY bd.created_at DESC"
            );
            $stmt->execute([$bugId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching bug documents: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Check if user has Google account linked
     */
    public function hasGoogleAccount($userId) {
        try {
            $tokenData = $this->oauthController->getRefreshToken($userId);
            return !empty($tokenData);
        } catch (Exception $e) {
            return false;
        }
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
