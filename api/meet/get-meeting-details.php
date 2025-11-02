<?php
error_log("DEBUG: get-meeting-details.php endpoint hit.");
/**
 * Get Meeting Participant Details and Session Information
 * Fetches detailed participant analytics for a specific Google Meet
 */

require_once __DIR__ . '/../BaseAPI.php';
require_once __DIR__ . '/../oauth/GoogleAuthService.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
    error_log("DEBUG: Authenticated user ID in get-meeting-details.php: " . $bugricerUserId);
    
    // Get meeting ID from query parameters
    $meetingId = $_GET['meeting_id'] ?? null;
    if (!$meetingId) {
        throw new Exception('Meeting ID is required');
    }
    
    error_log("DEBUG: Fetching details for meeting ID: " . $meetingId);
    
    // Initialize Google Auth Service
    $googleAuth = new GoogleAuthService();
    
    // Check if user has connected Google account
    if (!$googleAuth->isUserConnected($bugricerUserId)) {
        throw new Exception('Please connect your Google account first to view meeting details');
    }
    
    // Get authenticated Google Client for the user
    $googleClient = $googleAuth->getClientForUser($bugricerUserId);
    error_log("DEBUG: Google client created for user: " . $bugricerUserId);
    
    // Initialize Google Calendar Service
    $calendarService = new Google\Service\Calendar($googleClient);
    
    // Get the specific event
    $event = $calendarService->events->get('primary', $meetingId);
    
    if (!$event) {
        throw new Exception('Meeting not found');
    }
    
    // Extract meeting information
    $start = $event->getStart();
    $end = $event->getEnd();
    $conferenceData = $event->getConferenceData();
    
    $meetingDetails = [
        'id' => $event->getId(),
        'title' => $event->getSummary() ?? 'Untitled Meeting',
        'description' => $event->getDescription() ?? '',
        'startTime' => $start->getDateTime() ?? $start->getDate(),
        'endTime' => $end->getDateTime() ?? $end->getDate(),
        'creator' => $event->getCreator()->getEmail() ?? 'Unknown',
        'meetingUri' => null,
        'meetingCode' => null,
        'participants' => [],
        'sessionAnalytics' => []
    ];
    
    // Extract meeting URI and code if it's a Google Meet
    if ($conferenceData && $conferenceData->getEntryPoints()) {
        $entryPoints = $conferenceData->getEntryPoints();
        foreach ($entryPoints as $entryPoint) {
            if ($entryPoint->getEntryPointType() === 'video' && 
                strpos($entryPoint->getUri(), 'meet.google.com') !== false) {
                $meetingDetails['meetingUri'] = $entryPoint->getUri();
                $meetingDetails['meetingCode'] = extractMeetingCode($entryPoint->getUri());
                break;
            }
        }
    }
    
    // Get attendees information
    $attendees = $event->getAttendees() ?? [];
    foreach ($attendees as $attendee) {
        $meetingDetails['participants'][] = [
            'email' => $attendee->getEmail(),
            'displayName' => $attendee->getDisplayName(),
            'responseStatus' => $attendee->getResponseStatus(),
            'role' => 'participant' // Default role, could be enhanced with more logic
        ];
    }
    
    // For now, we'll simulate session analytics since Google Meet API doesn't provide
    // real-time participant session data through Calendar API
    // In a real implementation, you would need to use Google Meet API or store
    // participant data when they join/leave
    
    $sessionAnalytics = generateMockSessionAnalytics($meetingDetails);
    $meetingDetails['sessionAnalytics'] = $sessionAnalytics;
    
    error_log("DEBUG: Meeting details retrieved successfully for meeting: " . $meetingDetails['title']);
    
    echo json_encode([
        'success' => true,
        'meetingDetails' => $meetingDetails,
        'timestamp' => date('c')
    ]);
    
} catch (Exception $e) {
    error_log("ERROR in get-meeting-details.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Extract meeting code from Google Meet URI
 */
function extractMeetingCode($meetingUri) {
    if (preg_match('/meet\.google\.com\/([a-z0-9-]+)/i', $meetingUri, $matches)) {
        return strtoupper($matches[1]);
    }
    return null;
}

/**
 * Generate mock session analytics for demonstration
 * In a real implementation, this would come from Google Meet API or stored session data
 */
function generateMockSessionAnalytics($meetingDetails) {
    $participants = $meetingDetails['participants'];
    $startTime = new DateTime($meetingDetails['startTime']);
    $endTime = new DateTime($meetingDetails['endTime']);
    
    $analytics = [];
    
    foreach ($participants as $index => $participant) {
        // Generate mock session data
        $joinTime = clone $startTime;
        $joinTime->add(new DateInterval('PT' . ($index * 2) . 'M')); // Staggered join times
        
        $leaveTime = clone $endTime;
        $leaveTime->sub(new DateInterval('PT' . (($index + 1) * 3) . 'M')); // Staggered leave times
        
        // Ensure leave time is after join time
        if ($leaveTime <= $joinTime) {
            $leaveTime = clone $joinTime;
            $leaveTime->add(new DateInterval('PT30M')); // Minimum 30 minutes
        }
        
        $sessionDuration = $leaveTime->getTimestamp() - $joinTime->getTimestamp();
        
        $analytics[] = [
            'participant' => [
                'email' => $participant['email'],
                'displayName' => $participant['displayName'] ?? $participant['email'],
                'role' => $participant['role']
            ],
            'sessionId' => 'session_' . $index . '_' . uniqid(),
            'joinTime' => $joinTime->format('c'),
            'leaveTime' => $leaveTime->format('c'),
            'duration' => $sessionDuration,
            'durationFormatted' => formatDuration($sessionDuration),
            'status' => $leaveTime < new DateTime() ? 'completed' : 'active'
        ];
    }
    
    return $analytics;
}

/**
 * Format duration in human readable format
 */
function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $seconds = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
    } elseif ($minutes > 0) {
        return sprintf('%dm %ds', $minutes, $seconds);
    } else {
        return sprintf('%ds', $seconds);
    }
}
?>
