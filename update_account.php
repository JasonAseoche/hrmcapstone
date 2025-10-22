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
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $firstName = isset($_POST['firstName']) ? trim($_POST['firstName']) : '';
    $lastName = isset($_POST['lastName']) ? trim($_POST['lastName']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $newRole = isset($_POST['role']) ? strtolower(trim($_POST['role'])) : '';

    // Validate required fields
    if (empty($id) || empty($firstName) || empty($lastName) || empty($email) || empty($newRole)) {
        throw new Exception('All required fields must be filled.');
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.');
    }

    // Check if email already exists for other users
    $checkEmailSql = "SELECT id FROM useraccounts WHERE email = ? AND id != ?";
    $checkStmt = $conn->prepare($checkEmailSql);
    $checkStmt->bind_param("si", $email, $id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        throw new Exception('Email address already exists for another user.');
    }
    $checkStmt->close();

    // Get current user data
    $getCurrentSql = "SELECT role FROM useraccounts WHERE id = ?";
    $getCurrentStmt = $conn->prepare($getCurrentSql);
    $getCurrentStmt->bind_param("i", $id);
    $getCurrentStmt->execute();
    $currentResult = $getCurrentStmt->get_result();
    
    if ($currentResult->num_rows === 0) {
        throw new Exception('User not found.');
    }
    
    $currentData = $currentResult->fetch_assoc();
    $currentRole = strtolower($currentData['role']);
    $getCurrentStmt->close();

    // Start transaction
    $conn->autocommit(FALSE);

    // Update useraccounts table
    if (!empty($password)) {
        // Update with new password
        $updateUserSql = "UPDATE useraccounts SET firstName = ?, lastName = ?, email = ?, password = ?, role = ? WHERE id = ?";
        $userStmt = $conn->prepare($updateUserSql);
        $userStmt->bind_param("sssssi", $firstName, $lastName, $email, $password, $newRole, $id);
    } else {
        // Update without changing password
        $updateUserSql = "UPDATE useraccounts SET firstName = ?, lastName = ?, email = ?, role = ? WHERE id = ?";
        $userStmt = $conn->prepare($updateUserSql);
        $userStmt->bind_param("ssssi", $firstName, $lastName, $email, $newRole, $id);
    }
    
    if (!$userStmt->execute()) {
        throw new Exception('Failed to update user account: ' . $userStmt->error);
    }
    $userStmt->close();

    // Handle role changes
    if ($currentRole !== $newRole) {
        // Remove from old role tables
        if ($currentRole === 'employee') {
            $deleteEmployeeSql = "DELETE FROM employeelist WHERE emp_id = ?";
            $deleteEmployeeStmt = $conn->prepare($deleteEmployeeSql);
            $deleteEmployeeStmt->bind_param("i", $id);
            $deleteEmployeeStmt->execute();
            $deleteEmployeeStmt->close();
        } elseif ($currentRole === 'applicant') {
            $deleteApplicantSql = "DELETE FROM applicantlist WHERE app_id = ?";
            $deleteApplicantStmt = $conn->prepare($deleteApplicantSql);
            $deleteApplicantStmt->bind_param("i", $id);
            $deleteApplicantStmt->execute();
            $deleteApplicantStmt->close();
        }

        // Add to new role tables
        if ($newRole === 'employee') {
            // Insert into employeelist table
            $insertEmployeeSql = "INSERT INTO employeelist (emp_id, firstName, lastName, email, role, position, work_days, status, address, number, workarrangement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $employeeStmt = $conn->prepare($insertEmployeeSql);
            
            // Set default values for employee
            $defaultPosition = 'Not Assigned';
            $defaultWorkDays = 'Monday-Friday';
            $defaultStatus = 'active';
            $defaultAddress = '';
            $defaultNumber = null;
            $defaultWorkArrangement = 'office';
            
            $employeeStmt->bind_param("issssssssss", 
                $id, 
                $firstName, 
                $lastName, 
                $email, 
                $newRole,
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
            
        } elseif ($newRole === 'applicant') {
            // Check if applicant record already exists
            $checkApplicantSql = "SELECT id FROM applicantlist WHERE app_id = ?";
            $checkApplicantStmt = $conn->prepare($checkApplicantSql);
            $checkApplicantStmt->bind_param("i", $id);
            $checkApplicantStmt->execute();
            $applicantResult = $checkApplicantStmt->get_result();
            
            if ($applicantResult->num_rows === 0) {
                // Insert into applicantlist table only if not exists
                $insertApplicantSql = "INSERT INTO applicantlist (app_id, firstName, lastName, email, role, address, position, number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $applicantStmt = $conn->prepare($insertApplicantSql);
                
                // Set default values for applicant
                $defaultAddress = '';
                $defaultPosition = 'Applied Position';
                $defaultNumber = '';
                $defaultStatus = 'pending';
                
                $applicantStmt->bind_param("issssssss", 
                    $id, 
                    $firstName, 
                    $lastName, 
                    $email, 
                    $newRole,
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
        }
    } else {
        // Role didn't change, just update existing records
        if ($newRole === 'employee') {
            $updateEmployeeSql = "UPDATE employeelist SET firstName = ?, lastName = ?, email = ? WHERE emp_id = ?";
            $updateEmployeeStmt = $conn->prepare($updateEmployeeSql);
            $updateEmployeeStmt->bind_param("sssi", $firstName, $lastName, $email, $id);
            $updateEmployeeStmt->execute();
            $updateEmployeeStmt->close();
        } elseif ($newRole === 'applicant') {
            $updateApplicantSql = "UPDATE applicantlist SET firstName = ?, lastName = ?, email = ? WHERE app_id = ?";
            $updateApplicantStmt = $conn->prepare($updateApplicantSql);
            $updateApplicantStmt->bind_param("sssi", $firstName, $lastName, $email, $id);
            $updateApplicantStmt->execute();
            $updateApplicantStmt->close();
        }
    }

    // Commit transaction
    $conn->commit();
    $conn->autocommit(TRUE);

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Account updated successfully!',
        'role_changed' => ($currentRole !== $newRole)
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