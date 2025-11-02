<?php
error_log("DEBUG: get-running-meets.php endpoint hit.");
/**
 * Get Currently Running Google Meet Sessions
 * Fetches active Google Meet events from the user's calendar
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
    error_log("DEBUG: Authenticated user ID in get-running-meets.php: " . $bugricerUserId);
    
    // Initialize Google Auth Service
    $googleAuth = new GoogleAuthService();
    
    // Check if user has connected Google account
    if (!$googleAuth->isUserConnected($bugricerUserId)) {
        throw new Exception('Please connect your Google account first to view meetings');
    }
    
    // Get authenticated Google Client for the user
    $googleClient = $googleAuth->getClientForUser($bugricerUserId);
    error_log("DEBUG: Google client created for user: " . $bugricerUserId);
    
    // Initialize Google Calendar Service
    $calendarService = new Google\Service\Calendar($googleClient);
    
    // Get time range: 7 days ago to 24 hours from now
    $now = new DateTime();
    $startTime = clone $now;
    $startTime->sub(new DateInterval('P7D')); // 7 days ago
    $endTime = clone $now;
    $endTime->add(new DateInterval('PT24H')); // 24 hours from now
    
    error_log("DEBUG: Querying Google Calendar from " . $startTime->format('c') . " to " . $endTime->format('c'));
    
    // Query for all events in the time range (search doesn't work reliably for Meet links)
    $events = $calendarService->events->listEvents('primary', [
        'timeMin' => $startTime->format('c'),
        'timeMax' => $endTime->format('c'),
        'singleEvents' => true,
        'orderBy' => 'startTime'
    ]);
    
    error_log("DEBUG: Google Calendar API returned " . count($events->getItems()) . " events.");
    
    $runningMeets = [];
    $completedMeets = [];
    
    foreach ($events->getItems() as $event) {
        $start = $event->getStart();
        $end = $event->getEnd();
        $conferenceData = $event->getConferenceData();
        
        // Check if this is a Google Meet event
        if ($conferenceData && $conferenceData->getEntryPoints()) {
            $entryPoints = $conferenceData->getEntryPoints();
            foreach ($entryPoints as $entryPoint) {
                if ($entryPoint->getEntryPointType() === 'video' && 
                    strpos($entryPoint->getUri(), 'meet.google.com') !== false) {
                    
                    // Extract meeting code from URI
                    $meetingUri = $entryPoint->getUri();
                    $meetingCode = extractMeetingCode($meetingUri);
                    error_log("DEBUG: Found event with Meet link: " . $event->getSummary() . " (URI: " . $meetingUri . ")");
                    
                    // Check meeting status
                    $startTime = new DateTime($start->getDateTime() ?? $start->getDate());
                    $endTime = new DateTime($end->getDateTime() ?? $end->getDate());
                    
                    $isActive = $now >= $startTime && $now <= $endTime;
                    $isCompleted = $now > $endTime;
                    $isUpcoming = $now < $startTime;
                    
                    $meetingData = [
                        'id' => $event->getId(),
                        'title' => $event->getSummary() ?? 'Untitled Meeting',
                        'description' => $event->getDescription() ?? '',
                        'meetingUri' => $meetingUri,
                        'meetingCode' => $meetingCode,
                        'startTime' => $startTime->format('c'),
                        'endTime' => $endTime->format('c'),
                        'isActive' => $isActive,
                        'isCompleted' => $isCompleted,
                        'isUpcoming' => $isUpcoming,
                        'creator' => $event->getCreator()->getEmail() ?? 'Unknown',
                        'attendees' => array_map(function($attendee) {
                            return [
                                'email' => $attendee->getEmail(),
                                'displayName' => $attendee->getDisplayName(),
                                'responseStatus' => $attendee->getResponseStatus()
                            ];
                        }, $event->getAttendees() ?? [])
                    ];
                    
                    if ($isActive || $isUpcoming) {
                        $runningMeets[] = $meetingData;
                    } elseif ($isCompleted) {
                        $completedMeets[] = $meetingData;
                    }
                    
                    break; // Only process first Meet link per event
                }
            }
        }
    }
    
    // Sort by start time, active meetings first
    usort($runningMeets, function($a, $b) {
        if ($a['isActive'] && !$b['isActive']) return -1;
        if (!$a['isActive'] && $b['isActive']) return 1;
        return strcmp($a['startTime'], $b['startTime']);
    });
    
    // Sort completed meetings by end time (most recent first)
    usort($completedMeets, function($a, $b) {
        return strcmp($b['endTime'], $a['endTime']); // Reverse order for most recent first
    });
    
    error_log("DEBUG: Final running meetings count: " . count($runningMeets));
    error_log("DEBUG: Final completed meetings count: " . count($completedMeets));
    
    echo json_encode([
        'success' => true,
        'runningMeetings' => $runningMeets,
        'completedMeetings' => $completedMeets,
        'runningCount' => count($runningMeets),
        'completedCount' => count($completedMeets),
        'timestamp' => $now->format('c')
    ]);
    
} catch (Exception $e) {
    error_log("ERROR in get-running-meets.php: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'runningMeetings' => [],
        'completedMeetings' => [],
        'runningCount' => 0,
        'completedCount' => 0
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
?>
