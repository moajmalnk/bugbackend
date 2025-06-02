<?php
header('Content-Type: application/json');
echo json_encode([
    "success" => true,
    "message" => "Bug route test successful",
    "endpoint" => "test-route",
    "timestamp" => date('Y-m-d H:i:s')
]);
?> 