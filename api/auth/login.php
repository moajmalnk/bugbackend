<?php
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

$controller = new AuthController();
$controller->login();
?> 