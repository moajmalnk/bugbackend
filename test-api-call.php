<?php
/**
 * Test the exact API call that the frontend makes
 */

// Simulate the API call
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer YOUR_JWT_TOKEN_HERE'; // You'll need to replace this

// Set the input data
$input = json_encode(['meeting_title' => 'Test Meeting']);
file_put_contents('php://input', $input);

// Include the create-space.php file
include __DIR__ . '/api/meet/create-space.php';
?>
