<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. POST required.');
    }

    // Get form data
    $firstName = isset($_POST['firstName']) ? trim($_POST['firstName']) : '';
    $lastName = isset($_POST['lastName']) ? trim($_POST['lastName']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $role = isset($_POST['role']) ? strtolower(trim($_POST['role'])) : 'user';

    // Validate required fields
    if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
        throw new Exception('All fields are required.');
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.');
    }

    // Validate password length
    if (strlen($password) < 6) {
        throw new Exception('Password must be at least 6 characters long.');
    }

    // Check if email already exists
    $checkEmailSql = "SELECT id FROM useraccounts WHERE email = ?";
    $checkStmt = $conn->prepare($checkEmailSql);
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        throw new Exception('Email address already exists.');
    }
    $checkStmt->close();

    // Start transaction
    $conn->autocommit(FALSE);

    // Get the next unique ID for useraccounts table
    $getMaxIdSql = "SELECT COALESCE(MAX(id), 100) + 1 as next_id FROM useraccounts";
    $maxIdResult = $conn->query($getMaxIdSql);
    $maxIdRow = $maxIdResult->fetch_assoc();
    $nextUserId = $maxIdRow['next_id'];

    // Insert into useraccounts table with explicit ID
    $insertUserSql = "INSERT INTO useraccounts (id, firstName, lastName, email, password, role) VALUES (?, ?, ?, ?, ?, ?)";
    $userStmt = $conn->prepare($insertUserSql);
    $userStmt->bind_param("isssss", $nextUserId, $firstName, $lastName, $email, $password, $role);
    
    if (!$userStmt->execute()) {
        throw new Exception('Failed to create user account: ' . $userStmt->error);
    }

    // Use the explicitly set user ID
    $userId = $nextUserId;
    $userStmt->close();

    // Insert into appropriate table based on role
    if ($role === 'employee') {
        // Get the next unique ID for employeelist table
        $getMaxEmpIdSql = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM employeelist";
        $maxEmpIdResult = $conn->query($getMaxEmpIdSql);
        $maxEmpIdRow = $maxEmpIdResult->fetch_assoc();
        $nextEmpId = $maxEmpIdRow['next_id'];

        // Insert into employeelist table with explicit ID
        $insertEmployeeSql = "INSERT INTO employeelist (id, emp_id, firstName, lastName, email, role, position, work_days, status, address, number, workarrangement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $employeeStmt = $conn->prepare($insertEmployeeSql);
        
        // Set default values for employee
        $defaultPosition = 'Not Assigned';
        $defaultWorkDays = 'Monday-Friday';
        $defaultStatus = 'active';
        $defaultAddress = '';
        $defaultNumber = null;
        $defaultWorkArrangement = 'office';
        
        $employeeStmt->bind_param("iissssssssss", 
            $nextEmpId,
            $userId, 
            $firstName, 
            $lastName, 
            $email, 
            $role,
            $defaultPosition,
            $defaultWorkDays,
            $defaultStatus,
            $defaultAddress,
            $defaultNumber,
            $defaultWorkArrangement
        );
        
        if (!$employeeStmt->execute()) {
            throw new Exception('Failed to create employee record: ' . $employeeStmt->error);
        }
        $employeeStmt->close();
        
    } elseif ($role === 'applicant') {
        // First, check if there's already an applicant record for this user
        $checkApplicantSql = "SELECT id FROM applicantlist WHERE app_id = ?";
        $checkApplicantStmt = $conn->prepare($checkApplicantSql);
        $checkApplicantStmt->bind_param("i", $userId);
        $checkApplicantStmt->execute();
        $applicantResult = $checkApplicantStmt->get_result();
        
        if ($applicantResult->num_rows === 0) {
            // Get the next unique ID for applicantlist table
            $getMaxAppIdSql = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM applicantlist";
            $maxAppIdResult = $conn->query($getMaxAppIdSql);
            $maxAppIdRow = $maxAppIdResult->fetch_assoc();
            $nextAppId = $maxAppIdRow['next_id'];

            // Insert into applicantlist table with explicit ID
            $insertApplicantSql = "INSERT INTO applicantlist (id, app_id, firstName, lastName, email, role, address, position, number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $applicantStmt = $conn->prepare($insertApplicantSql);
            
            // Set default values for applicant
            $defaultAddress = '';
            $defaultPosition = 'Applied Position';
            $defaultNumber = '';
            $defaultStatus = 'pending';
            
            $applicantStmt->bind_param("iissssssss", 
                $nextAppId,
                $userId, 
                $firstName, 
                $lastName, 
                $email, 
                $role,
                $defaultAddress,
                $defaultPosition,
                $defaultNumber,
                $defaultStatus
            );
            
            if (!$applicantStmt->execute()) {
                throw new Exception('Failed to create applicant record: ' . $applicantStmt->error);
            }
            $applicantStmt->close();
        }
        $checkApplicantStmt->close();
        
    } elseif ($role === 'supervisor') {
        // Get the next unique ID for supervisorlist table
        $getMaxSupIdSql = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM supervisorlist";
        $maxSupIdResult = $conn->query($getMaxSupIdSql);
        $maxSupIdRow = $maxSupIdResult->fetch_assoc();
        $nextSupId = $maxSupIdRow['next_id'];
    
        // Insert into supervisorlist table with explicit ID
        $insertSupervisorSql = "INSERT INTO supervisorlist (id, sup_id, firstName, lastName, email, role, position, work_days, status, address, number, workarrangement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $supervisorStmt = $conn->prepare($insertSupervisorSql);
        
        // Set default values for supervisor
        $defaultPosition = 'Department Supervisor';
        $defaultWorkDays = 'Monday-Friday';
        $defaultStatus = 'active';
        $defaultAddress = '';
        $defaultNumber = null;
        $defaultWorkArrangement = 'office';
        
        $supervisorStmt->bind_param("iissssssssss", 
            $nextSupId,
            $userId, 
            $firstName, 
            $lastName, 
            $email, 
            $role,
            $defaultPosition,
            $defaultWorkDays,
            $defaultStatus,
            $defaultAddress,
            $defaultNumber,
            $defaultWorkArrangement
        );
        
        if (!$supervisorStmt->execute()) {
            throw new Exception('Failed to create supervisor record: ' . $supervisorStmt->error);
        }
        $supervisorStmt->close();
    }

    

    // Commit transaction
    $conn->commit();
    $conn->autocommit(TRUE);

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully!',
        'user_id' => $userId,
        'role' => $role
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        $conn->rollback();
        $conn->autocommit(TRUE);
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    // Close the connection
    if (isset($conn)) {
        $conn->close();
    }
}
?>