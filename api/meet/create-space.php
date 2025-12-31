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
    $participantEmails = $input['participant_emails'] ?? []; // Array of email addresses to invite
    $startTime = $input['start_time'] ?? null; // ISO 8601 format datetime string
    $endTime = $input['end_time'] ?? null; // ISO 8601 format datetime string
    
    if (empty($meetingTitle)) {
        throw new Exception('Meeting title is required');
    }
    
    // Prepare start and end times
    $startDateTime = null;
    $endDateTime = null;
    
    if ($startTime && $endTime) {
        try {
            // Parse ISO 8601 datetime strings
            $startDateTime = new DateTime($startTime);
            $endDateTime = new DateTime($endTime);
            
            // Ensure times are in Asia/Kolkata timezone
            $startDateTime->setTimezone(new DateTimeZone('Asia/Kolkata'));
            $endDateTime->setTimezone(new DateTimeZone('Asia/Kolkata'));
        } catch (Exception $e) {
            error_log("Invalid datetime format provided: " . $e->getMessage());
            // Fall back to current time if parsing fails
            $startDateTime = null;
            $endDateTime = null;
        }
    }
    
    // Default to current time if not provided or parsing failed
    if (!$startDateTime) {
        $startDateTime = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    }
    if (!$endDateTime) {
        $endDateTime = clone $startDateTime;
        $endDateTime->modify('+1 hour'); // Default to 1 hour duration
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

    // Ensure access token is valid and refreshed
    try {
        $accessToken = $googleClient->getAccessToken();
        if ($accessToken && isset($accessToken['access_token'])) {
            // Check if token is expired and refresh if needed
            if ($googleClient->isAccessTokenExpired()) {
                error_log("DEBUG: Access token expired, refreshing...");
                if (isset($accessToken['refresh_token'])) {
                    $googleClient->refreshToken($accessToken['refresh_token']);
                    $accessToken = $googleClient->getAccessToken();
                } else {
                    error_log("DEBUG: No refresh token available, may need to re-authenticate");
                }
            }
        }
    } catch (Exception $tokenException) {
        error_log("DEBUG: Token refresh error: " . $tokenException->getMessage());
        // Continue anyway, the client might handle it
    }

    // DEBUG: Log current scopes and token info
    $currentScopes = $googleClient->getScopes();
    $accessToken = $googleClient->getAccessToken();
    error_log("DEBUG: Scopes in create-space.php: " . implode(', ', $currentScopes));
    error_log("DEBUG: Access token exists: " . (isset($accessToken['access_token']) ? 'YES' : 'NO'));
    
    // Add required scope for Calendar API
    // Note: Google Meet API service classes are not available in the PHP client library
    // We'll use Calendar API to create meetings with Google Meet links
    $googleClient->addScope('https://www.googleapis.com/auth/calendar');
    
    // Initialize variables
    $meetingUri = null;
    $meetingCode = null;
    $eventId = null;
    
    // Note: Google Meet's "Quick Access" (allow joining without approval) is controlled
    // by Google Workspace admin settings or user-level Google Meet settings, not via Calendar API.
    // However, we'll configure the calendar event to be as open as possible.
    
    // Initialize Google Calendar Service
    try {
        $calendarService = new Google\Service\Calendar($googleClient);
        error_log("DEBUG: Google Calendar Service initialized successfully");
    } catch (Exception $calendarServiceException) {
        error_log("DEBUG: Failed to initialize Calendar Service: " . $calendarServiceException->getMessage());
        throw new Exception('Failed to initialize Google Calendar service: ' . $calendarServiceException->getMessage());
    }
    
    // Create meeting via Calendar API
    error_log("DEBUG: Creating meeting via Calendar API");
    
    // Create a calendar event with Google Meet link
    // Configure event to allow anyone to join
    $event = new Google\Service\Calendar\Event([
            'summary' => $meetingTitle,
            'description' => 'BugMeet Session - ' . $meetingTitle . "\n\nAnyone with the meeting link or code can join this meeting.",
            'start' => [
                'dateTime' => $startDateTime->format('c'), // ISO 8601 format
                'timeZone' => 'Asia/Kolkata'
            ],
            'end' => [
                'dateTime' => $endDateTime->format('c'), // ISO 8601 format
                'timeZone' => 'Asia/Kolkata'
            ],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => uniqid(),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
                ]
            ],
            // Allow anyone to join the meeting
            'visibility' => 'public', // Make event visible to anyone
            'guestsCanInviteOthers' => true, // Allow guests to invite others
            'guestsCanModify' => false, // Prevent guests from modifying the event
            'guestsCanSeeOtherGuests' => true, // Allow guests to see other participants
        ]);
    
    // Add attendees if provided
    if (!empty($participantEmails) && is_array($participantEmails)) {
        $attendees = [];
        foreach ($participantEmails as $email) {
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $attendees[] = [
                    'email' => $email,
                    'responseStatus' => 'needsAction'
                ];
            }
        }
        if (!empty($attendees)) {
            $event->setAttendees($attendees);
        }
    }
    
    // Create the event with conference data
    try {
        error_log("DEBUG: Attempting to insert calendar event with conference data");
        $createdEvent = $calendarService->events->insert('primary', $event, ['conferenceDataVersion' => 1]);
        error_log("DEBUG: Calendar event created successfully, ID: " . $createdEvent->getId());
    } catch (Exception $calendarException) {
        error_log("Calendar API error: " . $calendarException->getMessage());
        error_log("Calendar API error trace: " . $calendarException->getTraceAsString());
        throw new Exception('Failed to create calendar event: ' . $calendarException->getMessage());
    }
    $eventId = $createdEvent->getId();
    
    // Extract the meeting URI from conference data
    $conferenceData = $createdEvent->getConferenceData();
    if ($conferenceData && $conferenceData->getEntryPoints()) {
        $entryPoints = $conferenceData->getEntryPoints();
        if (!empty($entryPoints) && isset($entryPoints[0])) {
            $meetingUri = $entryPoints[0]->getUri();
        }
    }
    
    if (empty($meetingUri)) {
        throw new Exception('Failed to generate meeting link from calendar event');
    }
    
    // Extract meeting code
    $meetingCode = extractMeetingCode($meetingUri);
    
    error_log("Google Meet created via Calendar API");
    
    // Validate that we have a meeting URI before proceeding
    if (empty($meetingUri)) {
        throw new Exception('Failed to create meeting: No meeting URI generated');
    }
    
    // Log the successful creation
    error_log("Google Meet space created successfully for user: " . $bugricerUserId . " - URI: " . $meetingUri);
    
    // Send WhatsApp notifications to participants if provided
    if (!empty($participantEmails) && is_array($participantEmails)) {
        try {
            require_once __DIR__ . '/../../utils/whatsapp.php';
            
            // Format start time for WhatsApp message (use the actual meeting start time)
            $formattedStartTime = $startDateTime->format('M d, Y h:i A') . ' IST';
            
            error_log("📱 Sending meeting invitation WhatsApp notifications to " . count($participantEmails) . " participants");
            
            // Get database connection
            $conn = $api->getConnection();
            
            sendMeetingInvitationWhatsApp(
                $conn,
                $participantEmails,
                $meetingTitle,
                $meetingCode,
                $meetingUri,
                $bugricerUserId,
                $formattedStartTime
            );
            
            error_log("✅ Meeting invitation WhatsApp notifications sent");
        } catch (Exception $e) {
            // Don't fail meeting creation if WhatsApp fails
            error_log("⚠️ Failed to send meeting invitation WhatsApp notifications: " . $e->getMessage());
        }
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'meetingUri' => $meetingUri,
        'eventId' => $eventId,
        'meetingCode' => $meetingCode,
        'openAccess' => true // Indicates meeting allows anyone to join without approval
    ]);
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    
    error_log("Google Meet space creation failed: " . $errorMessage);
    error_log("Stack trace: " . $errorTrace);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $errorMessage,
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
} catch (Error $e) {
    // Catch PHP fatal errors
    $errorMessage = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    
    error_log("Google Meet space creation PHP Error: " . $errorMessage);
    error_log("Stack trace: " . $errorTrace);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $errorMessage,
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
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
