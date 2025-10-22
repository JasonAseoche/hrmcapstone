<?php
// get_applicant_status.php - Fetch applicant status and interview details from database

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your existing database connection
require_once 'db_connection.php';

try {
    // Check if connection exists
    if (!isset($conn)) {
        throw new Exception('Database connection not established');
    }
    
    // Get parameters from query
    $app_id = isset($_GET['app_id']) ? (int)$_GET['app_id'] : 0;
    $email = isset($_GET['email']) ? $_GET['email'] : '';
    
    $sql = '';
    $params = [];
    $types = '';
    
    if ($app_id > 0) {
        // Search by app_id first - include interview details
        $sql = "SELECT status, app_id, firstName, lastName, email, interview_date, interview_time 
                FROM applicantlist 
                WHERE app_id = ? 
                LIMIT 1";
        $params = [$app_id];
        $types = 'i';
    } elseif (!empty($email)) {
        // Search by email as fallback - include interview details
        $sql = "SELECT status, app_id, firstName, lastName, email, interview_date, interview_time 
                FROM applicantlist 
                WHERE email = ? 
                LIMIT 1";
        $params = [$email];
        $types = 's';
    } else {
        throw new Exception('Either app_id or email must be provided');
    }
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // Prepare response data
        $response = [
            'success' => true,
            'status' => $row['status'],
            'app_id' => $row['app_id'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'email' => $row['email'],
            'message' => 'Status fetched successfully',
            'search_method' => $app_id > 0 ? 'app_id' : 'email'
        ];
        
        // Add interview details if they exist
        if (!empty($row['interview_date']) && !empty($row['interview_time'])) {
            $response['interview_details'] = [
                'date' => $row['interview_date'],
                'time' => $row['interview_time'],
                'scheduled' => true
            ];
        } else {
            $response['interview_details'] = [
                'date' => null,
                'time' => null,
                'scheduled' => false
            ];
        }
        
        echo json_encode($response);
    } else {
        // No record found
        echo json_encode([
            'success' => false,
            'status' => 'pending', // Default to pending
            'message' => 'No applicant record found',
            'interview_details' => [
                'date' => null,
                'time' => null,
                'scheduled' => false
            ],
            'search_params' => [
                'app_id' => $app_id,
                'email' => $email
            ]
        ]);
    }
    
    $stmt->close();
    
} catch(Exception $e) {
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status' => 'pending', // Default to pending on error
        'message' => 'Error: ' . $e->getMessage(),
        'interview_details' => [
            'date' => null,
            'time' => null,
            'scheduled' => false
        ],
        'debug_info' => [
            'app_id' => isset($_GET['app_id']) ? $_GET['app_id'] : 'not_provided',
            'email' => isset($_GET['email']) ? $_GET['email'] : 'not_provided'
        ]
    ]);
} finally {
    // Close database connection if it exists
    if (isset($conn)) {
        $conn->close();
    }
}
?>