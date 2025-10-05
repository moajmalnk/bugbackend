<?php
// Debug script for send_otp.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Simulate the request
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'POST';

// Simulate JSON input by directly setting the data
$data = ['method' => 'mail', 'email' => 'moajmalnk@gmail.com'];

// Capture output
ob_start();

// Include the send_otp.php file
include 'api/auth/send_otp.php';

$output = ob_get_clean();
echo "Output: " . $output . "\n";
?>
