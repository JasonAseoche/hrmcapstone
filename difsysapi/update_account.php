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
    // Validate required fields
    if (!isset($_POST['id']) || empty(trim($_POST['id']))) {
        throw new Exception("Account ID is required");
    }
    
    $requiredFields = ['firstName', 'lastName', 'email', 'role'];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new Exception("Required field $field is missing");
        }
    }
    
    // Sanitize input
    $id = $conn->real_escape_string(trim($_POST['id']));
    $firstName = $conn->real_escape_string(trim($_POST['firstName']));
    $lastName = $conn->real_escape_string(trim($_POST['lastName']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $password = isset($_POST['password']) ? trim($_POST['password']) : ''; // Optional for updates
    $role = $conn->real_escape_string(trim($_POST['role']));
    
    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format");
    }
    
    // Role validation - UPDATED to include all roles
    $validRoles = ['user', 'admin', 'hr', 'accountant', 'employee', 'applicant'];
    if (!in_array($role, $validRoles)) {
        throw new Exception("Invalid role specified. Valid roles are: " . implode(', ', $validRoles));
    }
    
    // Check if email already exists (and it's not the current user's email)
    $checkSql = "SELECT COUNT(*) as count FROM useraccounts WHERE email = ? AND id != ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("ss", $email, $id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        throw new Exception("An account with this email already exists");
    }
    $checkStmt->close();
    
    // Prepare the update query
    if (!empty($password)) {
        // Password validation if provided
        if (strlen($password) < 6) {
            throw new Exception("Password must be at least 6 characters");
        }
        
        // Update with password (without hashing)
        $sql = "UPDATE useraccounts SET firstName = ?, lastName = ?, email = ?, password = ?, role = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssss", $firstName, $lastName, $email, $password, $role, $id);
    } else {
        // Update without changing the password
        $sql = "UPDATE useraccounts SET firstName = ?, lastName = ?, email = ?, role = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssss", $firstName, $lastName, $email, $role, $id);
    }
    
    // Execute the update
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                'success' => true, 
                'message' => "Account for $firstName $lastName updated successfully"
            ]);
        } else {
            // No rows were updated (ID might not exist)
            throw new Exception("No account was updated. Account ID may not exist.");
        }
    } else {
        throw new Exception("Failed to update account: " . $stmt->error);
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