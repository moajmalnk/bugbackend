<?php
class Database {
    private $host = "localhost";
    private $db_name = "u262074081_bugfixer_db";  // This matches your XAMPP database name
    private $username = "root";  // Keep root for local development
    private $password = "";      // Keep empty for local XAMPP
    public $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8";
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            
            // Test the connection
            $this->conn->query("SELECT 1");
            return $this->conn;
            
        } catch(PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            error_log("Connection details: host=" . $this->host . ", db=" . $this->db_name . ", user=" . $this->username);
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                "success" => false,
                "message" => "Database connection failed: " . $e->getMessage()
            ]);
            exit();
        }
    }
}
?> 