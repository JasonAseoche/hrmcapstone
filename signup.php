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

// Check if email already exists in useraccounts
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

// Also check if email already exists in applicantList
$check_applicant_email_sql = "SELECT email FROM applicantlist WHERE email = ?";
logDebug("Preparing applicant email check query: $check_applicant_email_sql");

$check_applicant_stmt = $conn->prepare($check_applicant_email_sql);

if (!$check_applicant_stmt) {
    logDebug("Applicant email check prepare error: " . $conn->error);
    $response['message'] = 'Database prepare error: ' . $conn->error;
    http_response_code(500);
    echo json_encode($response);
    exit;
}

$check_applicant_stmt->bind_param("s", $data['email']);
$check_applicant_stmt->execute();
$applicant_result = $check_applicant_stmt->get_result();

if ($applicant_result->num_rows > 0) {
    logDebug("Email already exists in applicantList: " . $data['email']);
    $response['message'] = 'Email already exists';
    http_response_code(409);
    echo json_encode($response);
    $check_applicant_stmt->close();
    $conn->close();
    exit;
}
$check_applicant_stmt->close();

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

// Set the default role to "Applicant" and auth_status to "New Account"
$role = "Applicant";
$auth_status = "New Account";
logDebug("Setting default role to: $role and auth_status to: $auth_status");

// Start transaction
$conn->autocommit(FALSE);
logDebug("Started database transaction");

try {
    // Prepare and execute the insert statement for useraccounts table
    if ($user_id_exists) {
        $insert_sql = "INSERT INTO useraccounts (id, firstName, lastName, email, password, role, auth_status) VALUES (?, ?, ?, ?, ?, ?, ?)";
        logDebug("Preparing insert statement with user_id, role, and auth_status: $insert_sql");
        $insert_stmt = $conn->prepare($insert_sql);
        
        if (!$insert_stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        logDebug("Binding insert parameters with ID, role, and auth_status");
        $insert_stmt->bind_param("issssss", $next_id, $data['firstName'], $data['lastName'], $data['email'], $password, $role, $auth_status);
        $user_id_for_applicant = $next_id;
    } else {
        // Original query without user_id but with role and auth_status
        $insert_sql = "INSERT INTO useraccounts (firstName, lastName, email, password, role, auth_status) VALUES (?, ?, ?, ?, ?, ?)";
        logDebug("Preparing insert statement with role and auth_status: $insert_sql");
        $insert_stmt = $conn->prepare($insert_sql);
        
        if (!$insert_stmt) {
            throw new Exception("Database prepare error: " . $conn->error);
        }
        
        logDebug("Binding insert parameters with role and auth_status");
        $insert_stmt->bind_param("ssssss", $data['firstName'], $data['lastName'], $data['email'], $password, $role, $auth_status);
        $user_id_for_applicant = null; // Will be set after insert
    }

    logDebug("Executing useraccounts insert");
    if (!$insert_stmt->execute()) {
        throw new Exception("Insert failed: " . $insert_stmt->error);
    }
    
    logDebug("Useraccounts insert successful. Affected rows: " . $insert_stmt->affected_rows);
    
    // Get the user ID for applicantList table
    if (!$user_id_exists) {
        $user_id_for_applicant = $conn->insert_id;
        logDebug("Auto-generated user ID: " . $user_id_for_applicant);
    }
    
    $insert_stmt->close();
    
    // Now insert into applicantList table - UPDATED TO USE app_id
    $applicant_insert_sql = "INSERT INTO applicantlist (app_id, firstName, lastName, email, role, status) VALUES (?, ?, ?, ?, ?, ?)";
    logDebug("Preparing applicantList insert statement: $applicant_insert_sql");
    
    $applicant_stmt = $conn->prepare($applicant_insert_sql);
    if (!$applicant_stmt) {
        throw new Exception("ApplicantList prepare error: " . $conn->error);
    }
    
    $applicant_status = "pending"; // Default status for new applicants
    logDebug("Binding applicantList parameters including email");
    $applicant_stmt->bind_param("isssss", $user_id_for_applicant, $data['firstName'], $data['lastName'], $data['email'], $role, $applicant_status);
    
    logDebug("Executing applicantList insert with email: " . $data['email']);
    if (!$applicant_stmt->execute()) {
        throw new Exception("ApplicantList insert failed: " . $applicant_stmt->error);
    }
    
    logDebug("ApplicantList insert successful. Affected rows: " . $applicant_stmt->affected_rows);
    $applicant_stmt->close();
    
    // Commit transaction
    $conn->commit();
    logDebug("Transaction committed successfully");
    
    $response['success'] = true;
    $response['message'] = 'User registered successfully';
    $response['id'] = $user_id_for_applicant;
    $response['role'] = $role;
    $response['auth_status'] = $auth_status;
    $response['email'] = $data['email']; // Include email in response for confirmation
    
    http_response_code(201); // Created
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    logDebug("Transaction rolled back due to error: " . $e->getMessage());
    
    $response['message'] = 'Registration failed: ' . $e->getMessage();
    http_response_code(500);
}

// Restore autocommit
$conn->autocommit(TRUE);

// Close connection
$conn->close();
logDebug("Database connection closed");

// Return JSON response
logDebug("Sending response: " . json_encode($response));
echo json_encode($response);
logDebug("Script completed");
?>