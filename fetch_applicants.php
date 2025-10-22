<?php
// fetch_applicants.php - Updated with app_id, profile images, and fixed status filter

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your existing database connection
require_once 'db_connection.php';

try {
    // Check if connection exists (assuming mysqli connection)
    if (!isset($conn)) {
        throw new Exception('Database connection not established');
    }
    
    // Fetch applicants with profile images from useraccounts table
    // Updated to include 'scheduled' and 'approved' status, and profile images
    $sql = "SELECT 
                a.id,
                a.app_id,
                a.firstName,
                a.lastName,
                a.email,
                a.role,
                a.address,
                a.position,
                a.number,
                a.status,
                a.created_at,
                u.profile_image
            FROM applicantlist a
            LEFT JOIN useraccounts u ON a.app_id = u.id
            WHERE a.status IN ('pending', 'approved', 'scheduled') 
            ORDER BY a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $applicants = [];
    while ($row = $result->fetch_assoc()) {
        $applicants[] = [
            'id' => $row['id'],
            'app_id' => $row['app_id'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'email' => $row['email'],
            'role' => $row['role'],
            'address' => $row['address'],
            'position' => $row['position'],
            'number' => $row['number'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'profileImage' => $row['profile_image'] // Add profile image
        ];
    }
    
    $stmt->close();
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'message' => 'Applicants fetched successfully',
        'applicants' => $applicants,
        'total_count' => count($applicants)
    ]);
    
} catch(Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'applicants' => [],
        'total_count' => 0
    ]);
}
?>