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
    $requiredFields = ['firstName', 'lastName', 'email', 'password', 'role'];
    foreach ($requiredFields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new Exception("Required field $field is missing");
        }
    }
    
    // Sanitize input
    $firstName = $conn->real_escape_string(trim($_POST['firstName']));
    $lastName = $conn->real_escape_string(trim($_POST['lastName']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $password = trim($_POST['password']); // No hashing anymore
    $role = $conn->real_escape_string(trim($_POST['role']));
    
    // Email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format");
    }
    
    // Password validation
    if (strlen($password) < 6) {
        throw new Exception("Password must be at least 6 characters");
    }
    
    // Role validation - UPDATED to include all roles
    $validRoles = ['user', 'admin', 'hr', 'accountant', 'employee', 'applicant'];
    if (!in_array($role, $validRoles)) {
        throw new Exception("Invalid role specified. Valid roles are: " . implode(', ', $validRoles));
    }
    
    // Check if email already exists
    $checkSql = "SELECT COUNT(*) as count FROM useraccounts WHERE email = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        throw new Exception("An account with this email already exists");
    }
    $checkStmt->close();
    
    // Get the last ID and increment by 1
    $idSql = "SELECT MAX(id) as max_id FROM useraccounts";
    $idResult = $conn->query($idSql);
    $idRow = $idResult->fetch_assoc();
    $newId = ($idRow['max_id'] ? $idRow['max_id'] : 0) + 1;
    
    // Prepare and execute the insert query with custom ID
    $sql = "INSERT INTO useraccounts (id, firstName, lastName, email, password, role) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssss", $newId, $firstName, $lastName, $email, $password, $role);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => "Account for $firstName $lastName created successfully with ID: $newId"
        ]);
    } else {
        throw new Exception("Failed to create account: " . $stmt->error);
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