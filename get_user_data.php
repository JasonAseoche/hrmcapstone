<?php
header("Access-Control-Allow-Origin: *"); // Allow all origins (you should restrict this in production)
header("Access-Control-Allow-Methods: POST, OPTIONS"); // Allow OPTIONS for preflight requests
header("Access-Control-Allow-Headers: Content-Type"); // Allow Content-Type header

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

// Get the raw POST data
$raw_data = file_get_contents("php://input");
error_log("Raw data received: " . $raw_data);

// Decode JSON data
$data = json_decode($raw_data, true);

// Log decoded data
error_log("Decoded data: " . print_r($data, true));

// Extract email
$email = $data['email'] ?? '';

// Basic input validation
if (empty($email)) {
    error_log("Validation failed: Email is empty");
    echo json_encode(["success" => false, "message" => "Email is required."]);
    exit;
}

try {
    // Prepare SQL statement (using prepared statements to prevent SQL injection)
    $sql = "SELECT firstName, lastName, role FROM useraccounts WHERE email=?";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        error_log("Prepare statement failed: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database error. Please try again later."]);
        exit;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    error_log("Query executed. Found rows: " . $result->num_rows);

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        error_log("User data fetched successfully for: " . $email);
        
        echo json_encode([
            "success" => true,
            "message" => "User data fetched successfully",
            "user" => [
                "firstName" => $user['firstName'],
                "lastName" => $user['lastName'],
                "role" => $user['role']
            ]
        ]);
    } else {
        error_log("User not found: " . $email);
        echo json_encode(["success" => false, "message" => "User not found."]);
    }

    $stmt->close();
} catch (Exception $e) {
    error_log("Exception occurred: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
} finally {
    // Close the connection
    if (isset($conn)) {
        $conn->close();
    }
}