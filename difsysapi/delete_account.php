<?php
// Include database connection
require_once 'db_connection.php';

// Set headers for API response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed']);
    exit();
}

try {
    // Validate required field
    if (!isset($_POST['id']) || empty(trim($_POST['id']))) {
        throw new Exception("Account ID is required");
    }
    
    // Sanitize input
    $id = $conn->real_escape_string(trim($_POST['id']));
    
    // Prepare and execute the delete query
    $sql = "DELETE FROM useraccounts WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                'success' => true, 
                'message' => "Account deleted successfully"
            ]);
        } else {
            // No rows were deleted (ID might not exist)
            throw new Exception("No account was deleted. Account ID may not exist.");
        }
    } else {
        throw new Exception("Failed to delete account: " . $stmt->error);
    }
    
    $stmt->close();
} catch (Exception $e) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    // Close the database connection
    $conn->close();
}
?>