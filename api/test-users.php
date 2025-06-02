<?php
header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config/database.php';
    
    $database = new Database();
    $conn = $database->getConnection();
    
    $stmt = $conn->prepare("SELECT username, email, role FROM users LIMIT 5");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        "success" => true,
        "message" => "Users retrieved",
        "data" => $users
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Error: " . $e->getMessage()
    ]);
}
?> 