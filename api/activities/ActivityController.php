<?php
require_once __DIR__ . '/../BaseAPI.php';

class ActivityController extends BaseAPI {
    protected $conn;

    public function __construct() {
        parent::__construct();
        $this->conn = $this->getConnection();
    }

    public function handleRequest($callback) {
        try {
            // Validate token
            $this->validateToken();

            // Get activities with pagination
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

            // Ensure limit and offset are non-negative integers
            $limit = max(1, $limit);
            $offset = max(0, $offset);

            $query = "SELECT a.*, u.name as user_name 
                     FROM activities a 
                     LEFT JOIN users u ON a.user_id = u.id 
                     ORDER BY a.created_at DESC
                     LIMIT $limit OFFSET $offset";

            $stmt = $this->conn->prepare($query);
            if (!$stmt->execute()) {
                throw new Exception("Failed to execute query");
            }
            
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM activities";
            $total = $this->conn->query($countQuery)->fetch(PDO::FETCH_ASSOC)['total'];

            // Send response
            $this->sendJsonResponse(200, [
                'success' => true,
                'data' => $activities,
                'total' => $total
            ]);
            
        } catch (Exception $e) {
            $this->handleError($e->getMessage());
        }
    }

    private function handleError($message, $code = 500) {
        $this->sendJsonResponse($code, [
            'success' => false,
            'error' => $message
        ]);
        exit;
    }
}