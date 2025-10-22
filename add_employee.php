<?php
// add_employee.php - Updated with emp_id and file migration

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST requests are allowed'
    ]);
    exit();
}

// Include database connection
include 'db_connection.php';

// Function to migrate files from applicant_files to employee_files
function migrateApplicantFiles($conn, $app_id, $emp_id) {
    try {
        // Get all files for this applicant
        $select_sql = "SELECT file_type, file_content, file_name, file_size, mime_type, uploaded_at 
                       FROM applicant_files 
                       WHERE app_id = ?";
        
        $select_stmt = $conn->prepare($select_sql);
        $select_stmt->bind_param("i", $app_id);
        $select_stmt->execute();
        $result = $select_stmt->get_result();
        
        $migrated_count = 0;
        
        while ($file = $result->fetch_assoc()) {
            // Insert into employee_files using emp_id (which matches app_id)
            $insert_sql = "INSERT INTO employee_files (emp_id, file_type, file_content, file_name, file_size, mime_type, uploaded_at, migrated_from_applicant, original_applicant_id) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
            
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("issssssi", 
                $emp_id,  // Use emp_id which matches app_id
                $file['file_type'],
                $file['file_content'],
                $file['file_name'],
                $file['file_size'],
                $file['mime_type'],
                $file['uploaded_at'],
                $app_id  // Keep original app_id for reference
            );
            
            if ($insert_stmt->execute()) {
                $migrated_count++;
            } else {
                error_log("Failed to migrate file: " . $insert_stmt->error);
            }
            
            $insert_stmt->close();
        }
        
        $select_stmt->close();
        
        // Delete files from applicant_files after successful migration
        if ($migrated_count > 0) {
            $delete_sql = "DELETE FROM applicant_files WHERE app_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $app_id);
            
            if ($delete_stmt->execute()) {
                $deleted_count = $delete_stmt->affected_rows;
                error_log("Migrated {$migrated_count} files and deleted {$deleted_count} from applicant_files for app_id {$app_id}");
            } else {
                error_log("Failed to delete applicant files: " . $delete_stmt->error);
            }
            
            $delete_stmt->close();
        }
        
        return $migrated_count;
        
    } catch (Exception $e) {
        error_log("Error migrating files: " . $e->getMessage());
        return 0;
    }
}

try {
    // Start transaction
    $conn->autocommit(FALSE);
    
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Validate required fields
    $required_fields = ['firstName', 'lastName', 'email', 'position', 'workDays'];
    $missing_fields = [];
    
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            $missing_fields[] = $field;
        }
    }
    
    if (!empty($missing_fields)) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: ' . implode(', ', $missing_fields)
        ]);
        exit();
    }
    
    // Validate email format
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email format'
        ]);
        exit();
    }
    
    // Check if email already exists in useraccounts
    $check_sql = "SELECT id FROM useraccounts WHERE email = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("s", $data['email']);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'User with this email already exists'
        ]);
        exit();
    }
    
    // Set default values
    $role = 'employee';
    $status = isset($data['status']) && !empty($data['status']) ? $data['status'] : 'active';
    $password = password_hash('defaultpassword123', PASSWORD_DEFAULT);
    $work_arrangement = isset($data['workArrangement']) ? $data['workArrangement'] : 'On-Site';
    
    // First, insert into useraccounts table
    $user_sql = "INSERT INTO useraccounts (firstName, lastName, email, password, role) VALUES (?, ?, ?, ?, ?)";
    $user_stmt = $conn->prepare($user_sql);
    
    if (!$user_stmt) {
        throw new Exception("Prepare user statement failed: " . $conn->error);
    }
    
    $user_stmt->bind_param("sssss", 
        $data['firstName'], 
        $data['lastName'], 
        $data['email'], 
        $password,
        $role
    );
    
    if (!$user_stmt->execute()) {
        throw new Exception("Execute user statement failed: " . $user_stmt->error);
    }
    
    // Get the newly inserted user ID
    $user_id = $conn->insert_id;
    
    // Then, insert into employeeList table with all the data
    $employee_sql = "INSERT INTO employeelist (emp_id, firstName, lastName, email, role, position, work_days, status, workarrangement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $employee_stmt = $conn->prepare($employee_sql);
    
    if (!$employee_stmt) {
        throw new Exception("Prepare employee statement failed: " . $conn->error);
    }
    
    $employee_stmt->bind_param("issssssss", 
        $user_id,
        $data['firstName'], 
        $data['lastName'], 
        $data['email'], 
        $role,
        $data['position'],
        $data['workDays'], 
        $status,
        $work_arrangement
    );
    
    if (!$employee_stmt->execute()) {
        throw new Exception("Execute employee statement failed: " . $employee_stmt->error);
    }
    
    $employee_list_id = $conn->insert_id;
    
    // Check if this user was previously an applicant and migrate their files
    $migrated_files = 0;
    if (isset($data['app_id']) && !empty($data['app_id'])) {
        // Use the user_id (which matches app_id) as emp_id in employee_files
        $migrated_files = migrateApplicantFiles($conn, $data['app_id'], $user_id);
    }
    
    // Commit transaction
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Employee added successfully',
        'employee' => [
            'id' => $user_id,
            'employee_list_id' => $employee_list_id,
            'firstName' => $data['firstName'],
            'lastName' => $data['lastName'],
            'email' => $data['email'],
            'role' => $role,
            'position' => $data['position'],
            'work_days' => $data['workDays'],
            'status' => $status,
            'workarrangement' => $work_arrangement
        ],
        'files_migrated' => $migrated_files
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    echo json_encode([
        'success' => false,
        'message' => 'Error adding employee: ' . $e->getMessage()
    ]);
} finally {
    // Close statements and connection
    if (isset($check_stmt)) {
        $check_stmt->close();
    }
    if (isset($user_stmt)) {
        $user_stmt->close();
    }
    if (isset($employee_stmt)) {
        $employee_stmt->close();
    }
    if (isset($conn)) {
        $conn->autocommit(TRUE);
        $conn->close();
    }
}
?>