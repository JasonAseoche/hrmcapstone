<?php
header("Access-Control-Allow-Origin: *"); // Allow all origins (you should restrict this in production)
header("Access-Control-Allow-Methods: GET, OPTIONS"); // Allow GET and OPTIONS methods
header("Access-Control-Allow-Headers: Content-Type"); // Allow Content-Type header
header("Content-Type: application/json"); // Set response content type to JSON

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

try {
    // Prepare SQL statement to select only employees
    $sql = "SELECT id, firstName, lastName, email, role FROM useraccounts WHERE role = 'employee'";
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $employees = [];
    
    // Fetch all employees
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            'id' => $row['id'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'email' => $row['email'],
            'role' => $row['role']
            // Password is not included as requested
        ];
    }
    
    // Check if any employees were found
    if (count($employees) > 0) {
        echo json_encode([
            'success' => true,
            'employees' => $employees
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'employees' => [],
            'message' => 'No employees found.'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching employees: ' . $e->getMessage()
    ]);
} finally {
    // Close the connection
    if (isset($conn)) {
        $conn->close();
    }
}