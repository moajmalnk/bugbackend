<?php
require_once __DIR__ . '/../../config/database.php';
require_once 'AuthController.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Create log file
$logFile = __DIR__ . '/../../logs/auth.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0777, true);
}

function logDebug($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Log the incoming request
logDebug("Login request received from: " . ($_SERVER['HTTP_HOST'] ?? 'unknown'));
logDebug("Request method: " . $_SERVER['REQUEST_METHOD']);
logDebug("Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? 'no origin'));
logDebug("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'no user agent'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logDebug("POST data: " . json_encode($_POST));
    logDebug("Raw input: " . file_get_contents("php://input"));
}

logDebug("Headers: " . json_encode(getallheaders()));

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$identifier = $data['identifier'] ?? '';
$password = $data['password'] ?? '';

if (!$identifier || !$password) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Identifier and password required']);
    exit;
}

$pdo = Database::getInstance()->getConnection();
$auth = new AuthController($pdo);
$result = $auth->loginWithIdentifier($identifier, $password);

if ($result['success']) {
    echo json_encode($result);
} else {
    http_response_code(401);
    echo json_encode($result);
}
?> 