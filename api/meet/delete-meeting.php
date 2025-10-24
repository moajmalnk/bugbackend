<?php
error_log("DEBUG: delete-meeting.php endpoint hit.");
/**
 * Delete a Google Meet Meeting
 * Allows admins to delete meetings from Google Calendar
 */

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../oauth/GoogleAuthService.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit();
}

try {
    // Initialize the API controller
    $api = new BaseAPI();
    
    // Validate JWT token and get user data
    $userData = $api->validateToken();
    if (!$userData || !isset($userData->user_id)) {
        throw new Exception('Invalid or missing authentication token');
    }
    
    $bugricerUserId = $userData->user_id;
    $userRole = $userData->role ?? null;
    
    error_log("DEBUG: Authenticated user ID in delete-meeting.php: " . $bugricerUserId);
    error_log("DEBUG: User role: " . $userRole);
    
    // Check if user is admin
    if ($userRole !== 'admin') {
        throw new Exception('Only admins can delete meetings');
    }
    
    // Get meeting ID from query parameters
    $meetingId = $_GET['meeting_id'] ?? null;
    if (!$meetingId) {
        throw new Exception('Meeting ID is required');
    }
    
    error_log("DEBUG: Deleting meeting with ID: " . $meetingId);
    
    // Initialize Google Auth Service
    $googleAuth = new GoogleAuthService();
    
    // Check if user has connected Google account
    if (!$googleAuth->isUserConnected($bugricerUserId)) {
        throw new Exception('Please connect your Google account first to delete meetings');
    }
    
    // Get authenticated Google Client for the user
    $googleClient = $googleAuth->getClientForUser($bugricerUserId);
    error_log("DEBUG: Google client created for user: " . $bugricerUserId);
    
    // Initialize Google Calendar Service
    $calendarService = new Google\Service\Calendar($googleClient);
    
    // Get the specific event first to verify it exists
    $event = $calendarService->events->get('primary', $meetingId);
    
    if (!$event) {
        throw new Exception('Meeting not found');
    }
    
    // Delete the event
    $calendarService->events->delete('primary', $meetingId);
    
    error_log("DEBUG: Meeting deleted successfully: " . $meetingId);
    
    echo json_encode([
        'success' => true,
        'message' => 'Meeting deleted successfully',
        'deletedMeetingId' => $meetingId,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    error_log("ERROR in delete-meeting.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
