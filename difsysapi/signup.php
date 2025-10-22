<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create log function
function logDebug($message) {
    file_put_contents('signup_debug.log', date('Y-m-d H:i:s') . ": $message\n", FILE_APPEND);
}

// Log start of script execution
logDebug("Script started");

// Set headers to allow cross-origin requests and specify content type
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    logDebug("OPTIONS request received");
    exit(0);
}

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logDebug("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get the raw POST data
$json_data = file_get_contents('php://input');
logDebug("Raw input data: " . $json_data);

$data = json_decode($json_data, true);

// Initialize response array
$response = ['success' => false, 'message' => ''];

// Check if data was properly decoded
if ($data === null) {
    logDebug("JSON decode error: " . json_last_error_msg());
    $response['message'] = 'Invalid JSON data: ' . json_last_error_msg();
    http_response_code(400);
    echo json_encode($response);
    exit;
}

logDebug("Decoded data: " . print_r($data, true));

// Validate required fields
$required_fields = ['firstName', 'lastName', 'email', 'password', 'confirmPassword'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        logDebug("Missing required field: $field");
        $response['message'] = ucfirst($field) . ' is required';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }
}

// Validate email format
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    logDebug("Invalid email format: " . $data['email']);
    $response['message'] = 'Invalid email format';
    http_response_code(400);
    echo json_encode($response);
    exit;
}

// Check if passwords match
if ($data['password'] !== $data['confirmPassword']) {
    logDebug("Passwords do not match");
    $response['message'] = 'Passwords do not match';
    http_response_code(400);
    echo json_encode($response);
    exit;
}

// Include database connection
logDebug("Including database connection file");
require_once 'db_connection.php';
logDebug("Database connection successful");

// Check if email already exists
$check_email_sql = "SELECT email FROM useraccounts WHERE email = ?";
logDebug("Preparing email check query: $check_email_sql");

$check_stmt = $conn->prepare($check_email_sql);

if (!$check_stmt) {
    logDebug("Database prepare error: " . $conn->error);
    $response['message'] = 'Database prepare error: ' . $conn->error;
    http_response_code(500);
    echo json_encode($response);
    exit;
}

logDebug("Binding email parameter: " . $data['email']);
$check_stmt->bind_param("s", $data['email']);

logDebug("Executing email check");
$check_stmt->execute();
$result = $check_stmt->get_result();

logDebug("Email check result: " . $result->num_rows . " rows found");
if ($result->num_rows > 0) {
    logDebug("Email already exists: " . $data['email']);
    $response['message'] = 'Email already exists';
    http_response_code(409); // Conflict
    echo json_encode($response);
    $check_stmt->close();
    $conn->close();
    exit;
}
$check_stmt->close();

// First check if user_id column exists in the table
$check_column_sql = "SHOW COLUMNS FROM useraccounts LIKE 'id'";
logDebug("Checking if id column exists: $check_column_sql");
$column_result = $conn->query($check_column_sql);

$user_id_exists = ($column_result && $column_result->num_rows > 0);
logDebug("id column exists: " . ($user_id_exists ? "Yes" : "No"));

$next_id = 101; // Default starting ID

if ($user_id_exists) {
    // Get the next unique ID (starting from 101)
    $get_last_id_sql = "SELECT MAX(id) as max_id FROM useraccounts";
    logDebug("Getting last used ID: $get_last_id_sql");
    $id_result = $conn->query($get_last_id_sql);

    if ($id_result && $id_row = $id_result->fetch_assoc()) {
        $next_id = ($id_row['max_id'] !== null) ? intval($id_row['max_id']) + 1 : 101;
    }
    logDebug("Next ID to be assigned: $next_id");
}

// Removed password hashing - using the plain password directly now
$password = $data['password'];

// Set the default role to "Applicant"
$role = "Applicant";
logDebug("Setting default role to: $role");

// Prepare and execute the insert statement with or without user_id
if ($user_id_exists) {
    $insert_sql = "INSERT INTO useraccounts (id, firstName, lastName, email, password, role) VALUES (?, ?, ?, ?, ?, ?)";
    logDebug("Preparing insert statement with user_id and role: $insert_sql");
    $insert_stmt = $conn->prepare($insert_sql);
    
    if (!$insert_stmt) {
        logDebug("Insert prepare error: " . $conn->error);
        $response['message'] = 'Database prepare error: ' . $conn->error;
        http_response_code(500);
        echo json_encode($response);
        $conn->close();
        exit;
    }
    
    logDebug("Binding insert parameters with ID and role");
    $insert_stmt->bind_param("isssss", $next_id, $data['firstName'], $data['lastName'], $data['email'], $password, $role);
} else {
    // Original query without user_id but with role
    $insert_sql = "INSERT INTO useraccounts (firstName, lastName, email, password, role) VALUES (?, ?, ?, ?, ?)";
    logDebug("Preparing insert statement with role: $insert_sql");
    $insert_stmt = $conn->prepare($insert_sql);
    
    if (!$insert_stmt) {
        logDebug("Insert prepare error: " . $conn->error);
        $response['message'] = 'Database prepare error: ' . $conn->error;
        http_response_code(500);
        echo json_encode($response);
        $conn->close();
        exit;
    }
    
    logDebug("Binding insert parameters with role");
    $insert_stmt->bind_param("sssss", $data['firstName'], $data['lastName'], $data['email'], $password, $role);
}

logDebug("Executing insert");
if ($insert_stmt->execute()) {
    logDebug("Insert successful. Affected rows: " . $insert_stmt->affected_rows);
    $response['success'] = true;
    $response['message'] = 'User registered successfully';
    
    if ($user_id_exists) {
        $response['id'] = $next_id; // Include the assigned ID in the response
    } else {
        // If user_id column doesn't exist, we can try to get the auto-increment ID
        $response['id'] = $conn->insert_id;
    }
    
    // Include the role in the response
    $response['role'] = $role;
    
    http_response_code(201); // Created
} else {
    logDebug("Insert failed: " . $insert_stmt->error);
    $response['message'] = 'Error: ' . $insert_stmt->error;
    http_response_code(500);
}

// Close statement and connection
$insert_stmt->close();
$conn->close();
logDebug("Database connection closed");

// Return JSON response
logDebug("Sending response: " . json_encode($response));
echo json_encode($response);
logDebug("Script completed");
?>