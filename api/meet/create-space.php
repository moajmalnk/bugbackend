<?php
/**
 * Google Meet Space Creation Endpoint
 * Creates a new Google Meet space using the Google Meet REST API
 */

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../oauth/GoogleAuthService.php';
require_once __DIR__ . '/../../config/environment.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit();
}

try {
    // Initialize the API controller
    $api = new BaseAPI();
    
    // Debug: Log the Authorization header
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? 'NOT_FOUND';
    error_log("DEBUG: Authorization header: " . substr($authHeader, 0, 50) . "...");
    
    // Validate JWT token and get user data
    $userData = $api->validateToken();
    if (!$userData || !isset($userData->user_id)) {
        error_log("DEBUG: JWT validation failed - userData: " . json_encode($userData));
        throw new Exception('Invalid or missing authentication token');
    }
    
    $bugricerUserId = $userData->user_id;
    error_log("DEBUG: Authenticated user ID: " . $bugricerUserId);
    
    // Get request data
    $input = json_decode(file_get_contents('php://input'), true);
    $meetingTitle = $input['meeting_title'] ?? 'BugMeet Session';
    
    if (empty($meetingTitle)) {
        throw new Exception('Meeting title is required');
    }
    
    // Initialize Google Auth Service
    $googleAuth = new GoogleAuthService();
    
    // Check if user has connected Google account
    if (!$googleAuth->isUserConnected($bugricerUserId)) {
        throw new Exception('Please connect your Google account first to create meetings');
    }
    
    // Get authenticated Google Client for the user
    $googleClient = $googleAuth->getClientForUser($bugricerUserId);
    error_log("DEBUG: Google client created for user: " . $bugricerUserId);

    // DEBUG: Log current scopes and token info
    $currentScopes = $googleClient->getScopes();
    $accessToken = $googleClient->getAccessToken();
    error_log("DEBUG: Scopes in create-space.php: " . implode(', ', $currentScopes));
    error_log("DEBUG: Access token exists: " . (isset($accessToken['access_token']) ? 'YES' : 'NO'));
    
    // Add the Google Calendar scope to the client
    $googleClient->addScope('https://www.googleapis.com/auth/calendar');
    
    // Initialize Google Calendar Service
    $calendarService = new Google\Service\Calendar($googleClient);
    
    // Create a calendar event with Google Meet link
    $event = new Google\Service\Calendar\Event([
        'summary' => $meetingTitle,
        'description' => 'BugMeet Session - ' . $meetingTitle,
        'start' => [
            'dateTime' => date('c'), // Current time
            'timeZone' => 'UTC'
        ],
        'end' => [
            'dateTime' => date('c', strtotime('+1 hour')), // 1 hour from now
            'timeZone' => 'UTC'
        ],
        'conferenceData' => [
            'createRequest' => [
                'requestId' => uniqid(),
                'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
            ]
        ],
    ]);
    
    // Create the event with conference data
    $createdEvent = $calendarService->events->insert('primary', $event, ['conferenceDataVersion' => 1]);
    
    // Extract the meeting URI from conference data
    $meetingUri = $createdEvent->getConferenceData()->getEntryPoints()[0]->getUri();
    
    if (empty($meetingUri)) {
        throw new Exception('Failed to generate meeting link');
    }
    
    // Log the successful creation
    error_log("Google Meet space created successfully for user: " . $bugricerUserId . " - URI: " . $meetingUri);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'meetingUri' => $meetingUri,
        'eventId' => $createdEvent->getId(),
        'meetingCode' => extractMeetingCode($meetingUri)
    ]);
    
} catch (Exception $e) {
    error_log("Google Meet space creation failed: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Extract meeting code from Google Meet URI
 * @param string $meetingUri The full Google Meet URI
 * @return string The meeting code
 */
function extractMeetingCode($meetingUri) {
    // Extract code from URL like https://meet.google.com/abc-mnop-xyz
    if (preg_match('/meet\.google\.com\/([a-z0-9-]+)/i', $meetingUri, $matches)) {
        return strtoupper($matches[1]);
    }
    return '';
}
?>
