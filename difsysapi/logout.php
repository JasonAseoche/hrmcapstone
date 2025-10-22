<?php
// Set headers for API response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection if needed
require_once 'db_connection.php';

// Log request for debugging
error_log("Logout request received");

// Get the raw POST data
$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

// Extract user ID if available
$userId = isset($data['userId']) ? $data['userId'] : null;

// Log userId for debugging
if ($userId) {
    error_log("Logging out user ID: " . $userId);
}

try {
    // You can optionally update a 'last_logout' timestamp in your database
    // Or log the logout event for security tracking
    if ($userId && isset($conn)) {
        $currentDate = date('Y-m-d H:i:s');
        
        // Example: update last_logout time or record the logout in a log table
        $sql = "UPDATE useraccounts SET last_logout = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        
        if ($stmt) {
            $stmt->bind_param("ss", $currentDate, $userId);
            $stmt->execute();
            $stmt->close();
            error_log("Updated last_logout time for user ID: " . $userId);
        }
        
        // Optional: Log the logout in a separate audit log table
        // $logSql = "INSERT INTO user_activity_log (user_id, activity_type, activity_date) VALUES (?, 'logout', ?)";
        // $logStmt = $conn->prepare($logSql);
        // if ($logStmt) {
        //     $logStmt->bind_param("ss", $userId, $currentDate);
        //     $logStmt->execute();
        //     $logStmt->close();
        // }
    }
    
    // If you're using PHP sessions, destroy the session
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Logout error: " . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during logout process: ' . $e->getMessage()
    ]);
} finally {
    // Close database connection if it exists
    if (isset($conn)) {
        $conn->close();
    }
}
?>