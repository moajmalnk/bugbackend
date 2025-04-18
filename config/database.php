<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Check if running locally
        if ($_SERVER['HTTP_HOST'] === 'localhost' || $_SERVER['SERVER_NAME'] === 'localhost') {
            // Local database configuration
            $this->host = "localhost";
            $this->db_name = "u262074081_bugfixer_db";
            $this->username = "root";
            $this->password = "";
        } else {
            // Production database configuration
            $this->host = "auth-db1555.hstgr.io";
            $this->db_name = "u262074081_bugfixer";
            $this->username = "u262074081_bugfixer";
            $this->password = "CodoMail@8848";
        }
    }

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
