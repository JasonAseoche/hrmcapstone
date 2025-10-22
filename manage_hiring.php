<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_connection.php';

class HiringPositionManager {
    private $conn;
    private $uploadDir = 'uploads/hiring_positions/';
    
    public function __construct($connection) {
        $this->conn = $connection;
        $this->createUploadDirectory();
    }
    
    private function createUploadDirectory() {
        if (!file_exists($this->uploadDir)) {
            if (!mkdir($this->uploadDir, 0755, true)) {
                error_log("Failed to create upload directory: " . $this->uploadDir);
            }
        }
    }
    
    // GET - Fetch all active hiring positions
    public function getAllPositions() {
        try {
            $sql = "SELECT id, title, short_description, image_path, requirements, duties, status, created_at, updated_at 
                    FROM hiring_positions 
                    WHERE status = 'active' 
                    ORDER BY created_at DESC";
            
            $result = $this->conn->query($sql);
            
            if (!$result) {
                return $this->errorResponse('Database query failed: ' . $this->conn->error);
            }
            
            $positions = [];
            while ($row = $result->fetch_assoc()) {
                $row['requirements'] = $this->decodeJson($row['requirements']);
                $row['duties'] = $this->decodeJson($row['duties']);
                $positions[] = $row;
            }
            
            return $this->successResponse($positions);
            
        } catch (Exception $e) {
            return $this->errorResponse('Error fetching positions: ' . $e->getMessage());
        }
    }
    
    // GET - Fetch single position by ID
    public function getPositionById($id) {
        try {
            if (!$this->isValidId($id)) {
                return $this->errorResponse('Invalid position ID');
            }
            
            $sql = "SELECT * FROM hiring_positions WHERE id = ? AND status = 'active'";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                return $this->errorResponse('Database prepare failed: ' . $this->conn->error);
            }
            
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($position = $result->fetch_assoc()) {
                $position['requirements'] = $this->decodeJson($position['requirements']);
                $position['duties'] = $this->decodeJson($position['duties']);
                
                return $this->successResponse($position);
            } 
            
            return $this->errorResponse('Position not found');
            
        } catch (Exception $e) {
            return $this->errorResponse('Error fetching position: ' . $e->getMessage());
        }
    }
    
    // POST - Create new hiring position
    public function createPosition($data) {
        try {
            // Validate required fields
            $validation = $this->validatePositionData($data);
            if (!$validation['valid']) {
                return $this->errorResponse($validation['message']);
            }
            
            // Handle image upload
            $imagePath = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $this->handleImageUpload($_FILES['image']);
                if (!$uploadResult['success']) {
                    return $this->errorResponse($uploadResult['message']);
                }
                $imagePath = $uploadResult['path'];
            }
            
            // Prepare data
            $requirements = $this->encodeJson($data['requirements'] ?? []);
            $duties = $this->encodeJson($data['duties'] ?? []);
            
            $sql = "INSERT INTO hiring_positions (title, short_description, image_path, requirements, duties) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $this->errorResponse('Database prepare failed: ' . $this->conn->error);
            }
            
            $stmt->bind_param("sssss", 
                $data['title'], 
                $data['short_description'], 
                $imagePath, 
                $requirements, 
                $duties
            );
            
            if ($stmt->execute()) {
                $newId = $this->conn->insert_id;
                return $this->successResponse(['id' => $newId], 'Position created successfully');
            }
            
            return $this->errorResponse('Failed to create position: ' . $stmt->error);
            
        } catch (Exception $e) {
            return $this->errorResponse('Error creating position: ' . $e->getMessage());
        }
    }
    
    // PUT - Update hiring position
    public function updatePosition($id, $data) {
        try {
            if (!$this->isValidId($id)) {
                return $this->errorResponse('Invalid position ID');
            }
            
            // Check if position exists and get current image
            $currentPosition = $this->getCurrentPosition($id);
            if (!$currentPosition) {
                return $this->errorResponse('Position not found');
            }
            
            // Validate required fields
            $validation = $this->validatePositionData($data);
            if (!$validation['valid']) {
                return $this->errorResponse($validation['message']);
            }
            
            // Handle image upload
            $imagePath = $currentPosition['image_path'];
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadResult = $this->handleImageUpload($_FILES['image']);
                if (!$uploadResult['success']) {
                    return $this->errorResponse($uploadResult['message']);
                }
                
                // Delete old image if exists
                if ($imagePath && file_exists($imagePath)) {
                    unlink($imagePath);
                }
                $imagePath = $uploadResult['path'];
            }
            
            // Prepare data
            $requirements = $this->encodeJson($data['requirements'] ?? []);
            $duties = $this->encodeJson($data['duties'] ?? []);
            
            $sql = "UPDATE hiring_positions 
                    SET title = ?, short_description = ?, image_path = ?, requirements = ?, duties = ?, updated_at = CURRENT_TIMESTAMP 
                    WHERE id = ?";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                return $this->errorResponse('Database prepare failed: ' . $this->conn->error);
            }
            
            $stmt->bind_param("sssssi", 
                $data['title'], 
                $data['short_description'], 
                $imagePath, 
                $requirements, 
                $duties, 
                $id
            );
            
            if ($stmt->execute()) {
                return $this->successResponse(null, 'Position updated successfully');
            }
            
            return $this->errorResponse('Failed to update position: ' . $stmt->error);
            
        } catch (Exception $e) {
            return $this->errorResponse('Error updating position: ' . $e->getMessage());
        }
    }
    
    // DELETE - Soft delete hiring position
    public function deletePosition($id) {
        try {
            if (!$this->isValidId($id)) {
                return $this->errorResponse('Invalid position ID');
            }
            
            // Get position to check if exists and get image path
            $currentPosition = $this->getCurrentPosition($id);
            if (!$currentPosition) {
                return $this->errorResponse('Position not found');
            }
            
            // Soft delete - update status to inactive
            $sql = "UPDATE hiring_positions SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                return $this->errorResponse('Database prepare failed: ' . $this->conn->error);
            }
            
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                // Optionally delete image file
                if ($currentPosition['image_path'] && file_exists($currentPosition['image_path'])) {
                    unlink($currentPosition['image_path']);
                }
                
                return $this->successResponse(null, 'Position deleted successfully');
            }
            
            return $this->errorResponse('Failed to delete position: ' . $stmt->error);
            
        } catch (Exception $e) {
            return $this->errorResponse('Error deleting position: ' . $e->getMessage());
        }
    }
    
    // Helper method to get current position
    private function getCurrentPosition($id) {
        $sql = "SELECT * FROM hiring_positions WHERE id = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return null;
        
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }
    
    // Handle image upload with validation
    private function handleImageUpload($file) {
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        
        // Validate file type
        $fileType = strtolower($file['type']);
        if (!in_array($fileType, $allowedTypes)) {
            return [
                'success' => false,
                'message' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.'
            ];
        }
        
        // Validate file size
        if ($file['size'] > $maxSize) {
            return [
                'success' => false,
                'message' => 'File too large. Maximum size is 5MB.'
            ];
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('hire_') . '_' . time() . '.' . strtolower($extension);
        $filepath = $this->uploadDir . $filename;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            return [
                'success' => true,
                'path' => $filepath
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Failed to save uploaded file.'
        ];
    }
    
    // Validate position data
    private function validatePositionData($data) {
        if (empty(trim($data['title'] ?? ''))) {
            return ['valid' => false, 'message' => 'Position title is required'];
        }
        
        if (empty(trim($data['short_description'] ?? ''))) {
            return ['valid' => false, 'message' => 'Short description is required'];
        }
        
        if (strlen($data['title']) > 255) {
            return ['valid' => false, 'message' => 'Position title is too long (max 255 characters)'];
        }
        
        if (strlen($data['short_description']) > 1000) {
            return ['valid' => false, 'message' => 'Short description is too long (max 1000 characters)'];
        }
        
        return ['valid' => true, 'message' => 'Valid'];
    }
    
    // Validate ID
    private function isValidId($id) {
        return is_numeric($id) && $id > 0;
    }
    
    // JSON encode with error handling
    private function encodeJson($data) {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            }
        }
        
        if (!is_array($data)) {
            $data = [];
        }
        
        // Filter out empty strings
        $data = array_filter($data, function($item) {
            return !empty(trim($item));
        });
        
        return json_encode(array_values($data));
    }
    
    // JSON decode with error handling
    private function decodeJson($jsonString) {
        if (empty($jsonString)) {
            return [];
        }
        
        $decoded = json_decode($jsonString, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        
        return [];
    }
    
    // Success response helper
    private function successResponse($data = null, $message = 'Success') {
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        return $response;
    }
    
    // Error response helper
    private function errorResponse($message) {
        return ['success' => false, 'message' => $message];
    }
}

// Initialize the manager
try {
    $manager = new HiringPositionManager($conn);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get HTTP method and input
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Route requests
switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $response = $manager->getPositionById($_GET['id']);
        } else {
            $response = $manager->getAllPositions();
        }
        break;
        
    case 'POST':
        // Handle form data (for file uploads)
        $data = $_POST;
        
        // Parse JSON fields if they exist
        if (isset($data['requirements']) && is_string($data['requirements'])) {
            $data['requirements'] = json_decode($data['requirements'], true) ?: [];
        }
        if (isset($data['duties']) && is_string($data['duties'])) {
            $data['duties'] = json_decode($data['duties'], true) ?: [];
        }
        
        // Check if this is a PUT request (method override)
        if (isset($data['_method']) && $data['_method'] === 'PUT' && isset($_GET['id'])) {
            $response = $manager->updatePosition($_GET['id'], $data);
        } else {
            $response = $manager->createPosition($data);
        }
        break;
        
    case 'PUT':
        if (isset($_GET['id'])) {
            $response = $manager->updatePosition($_GET['id'], $input ?: []);
        } else {
            $response = ['success' => false, 'message' => 'Position ID is required for update'];
        }
        break;
        
    case 'DELETE':
        if (isset($_GET['id'])) {
            $response = $manager->deletePosition($_GET['id']);
        } else {
            $response = ['success' => false, 'message' => 'Position ID is required for deletion'];
        }
        break;
        
    default:
        $response = ['success' => false, 'message' => 'Method not allowed'];
        http_response_code(405);
        break;
}

// Set appropriate HTTP status code
if (isset($response['success'])) {
    http_response_code($response['success'] ? 200 : 400);
}

echo json_encode($response);
?>