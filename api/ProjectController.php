<?php
require_once __DIR__ . '/BaseAPI.php';

class ProjectController extends BaseAPI {
    private $conn;
    private $table = 'projects';

    public function __construct($db) {
        parent::__construct();
        $this->conn = $db;
    }

    public function getProjects() {
        try {
            $query = "SELECT id, name, description, status, created_at, updated_at 
                     FROM " . $this->table . " 
                     WHERE status != 'archived' 
                     ORDER BY created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $projects = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $projects[] = [
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];
            }
            
            return $projects;
        } catch (PDOException $e) {
            throw new Exception("Error fetching projects: " . $e->getMessage());
        }
    }
} 