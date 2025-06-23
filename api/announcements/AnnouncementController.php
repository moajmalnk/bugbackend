<?php
require_once __DIR__ . '/../BaseAPI.php';

class AnnouncementController extends BaseAPI {

    public function __construct() {
        parent::__construct();
    }

    public function getLatestActive() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $this->validateToken(); // All authenticated users can see announcements

            $query = "SELECT * FROM announcements 
                      WHERE is_active = 1 
                      AND (expiry_date IS NULL OR expiry_date > NOW())
                      ORDER BY created_at DESC 
                      LIMIT 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $announcement = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$announcement) {
                return $this->sendJsonResponse(200, "No active announcements.", null);
            }

            $this->sendJsonResponse(200, "Latest announcement retrieved successfully", $announcement);

        } catch (Exception $e) {
            error_log("Error fetching latest announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function getAll() {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized to perform this action.");
            }

            $query = "SELECT * FROM announcements ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->sendJsonResponse(200, "Announcements retrieved successfully", $announcements);

        } catch (Exception $e) {
            error_log("Error fetching announcements: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }

    public function create() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized to perform this action.");
            }

            $data = $this->getRequestData();

            if (!isset($data['title']) || !isset($data['content'])) {
                return $this->sendJsonResponse(400, "Title and content are required");
            }

            $query = "INSERT INTO announcements (title, content, is_active, expiry_date) VALUES (?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);

            $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 0;
            $expiryDate = isset($data['expiry_date']) ? $data['expiry_date'] : null;

            $stmt->execute([
                $data['title'],
                $data['content'],
                $isActive,
                $expiryDate
            ]);
            
            $lastInsertId = $this->conn->lastInsertId();
            $announcement = [
                'id' => $lastInsertId,
                'title' => $data['title'],
                'content' => $data['content'],
                'is_active' => $isActive,
                'expiry_date' => $expiryDate,
            ];

            $this->sendJsonResponse(201, "Announcement created successfully", $announcement);

        } catch (Exception $e) {
            error_log("Error creating announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    
    public function update($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'POST') { // Allow POST for updates
            return $this->sendJsonResponse(405, "Method not allowed");
        }
    
        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized.");
            }
    
            $data = $this->getRequestData();
    
            $updateFields = [];
            $params = [];
    
            if (isset($data['title'])) {
                $updateFields[] = "title = ?";
                $params[] = $data['title'];
            }
            if (isset($data['content'])) {
                $updateFields[] = "content = ?";
                $params[] = $data['content'];
            }
            if (isset($data['is_active'])) {
                $updateFields[] = "is_active = ?";
                $params[] = (int)$data['is_active'];
            }
            if (array_key_exists('expiry_date', $data)) { // Allow setting expiry_date to null
                $updateFields[] = "expiry_date = ?";
                $params[] = $data['expiry_date'];
            }
    
            if (empty($updateFields)) {
                return $this->sendJsonResponse(400, "No fields to update.");
            }
    
            $query = "UPDATE announcements SET " . implode(", ", $updateFields) . " WHERE id = ?";
            $params[] = $id;
    
            $stmt = $this->conn->prepare($query);
            $stmt->execute($params);
    
            if ($stmt->rowCount() === 0) {
                return $this->sendJsonResponse(404, "Announcement not found or no changes made.");
            }
    
            $this->sendJsonResponse(200, "Announcement updated successfully.");
    
        } catch (Exception $e) {
            error_log("Error updating announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
    

    public function delete($id) {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return $this->sendJsonResponse(405, "Method not allowed");
        }

        try {
            $decoded = $this->validateToken();
            if ($decoded->role !== 'admin') {
                return $this->sendJsonResponse(403, "Forbidden: You are not authorized.");
            }

            $query = "DELETE FROM announcements WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                return $this->sendJsonResponse(404, "Announcement not found.");
            }

            $this->sendJsonResponse(200, "Announcement deleted successfully.");

        } catch (Exception $e) {
            error_log("Error deleting announcement: " . $e->getMessage());
            $this->sendJsonResponse(500, "Server error: " . $e->getMessage());
        }
    }
} 