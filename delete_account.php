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
    // Validate required field
    if (!isset($_POST['id']) || empty(trim($_POST['id']))) {
        throw new Exception("Account ID is required");
    }
    
    // Sanitize input
    $id = $conn->real_escape_string(trim($_POST['id']));
    
    // Start transaction for data consistency
    $conn->autocommit(false);
    
    $deletedRecords = [];
    
    // Step 1: Check if account exists in useraccounts
    $checkSql = "SELECT id, role FROM useraccounts WHERE id = ?";
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->bind_param("s", $id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Account ID does not exist");
    }
    
    $accountData = $result->fetch_assoc();
    $userRole = $accountData['role'];
    $checkStmt->close();
    
    // Step 2: Handle Employee-related deletions
    // Check if user exists in employeelist using the account ID as emp_id
    $empCheckSql = "SELECT emp_id FROM employeelist WHERE emp_id = ?";
    $empCheckStmt = $conn->prepare($empCheckSql);
    $empCheckStmt->bind_param("s", $id);
    $empCheckStmt->execute();
    $empResult = $empCheckStmt->get_result();
    
    if ($empResult->num_rows > 0) {
        // Delete from employee_files first (references emp_id)
        $deleteFilesSql = "DELETE FROM employee_files WHERE emp_id = ?";
        $deleteFilesStmt = $conn->prepare($deleteFilesSql);
        $deleteFilesStmt->bind_param("s", $id);
        if ($deleteFilesStmt->execute() && $deleteFilesStmt->affected_rows > 0) {
            $deletedRecords[] = "employee_files ({$deleteFilesStmt->affected_rows} records)";
        }
        $deleteFilesStmt->close();
        
        // Delete from employeelist
        $deleteEmpSql = "DELETE FROM employeelist WHERE emp_id = ?";
        $deleteEmpStmt = $conn->prepare($deleteEmpSql);
        $deleteEmpStmt->bind_param("s", $id);
        if ($deleteEmpStmt->execute() && $deleteEmpStmt->affected_rows > 0) {
            $deletedRecords[] = "employeelist (1 record)";
        }
        $deleteEmpStmt->close();
    }
    $empCheckStmt->close();
    
    // Step 3: Delete from useraccounts (main table)
    $deleteUserSql = "DELETE FROM useraccounts WHERE id = ?";
    $deleteUserStmt = $conn->prepare($deleteUserSql);
    $deleteUserStmt->bind_param("s", $id);
    
    if (!$deleteUserStmt->execute()) {
        throw new Exception("Failed to delete user account: " . $deleteUserStmt->error);
    }
    
    if ($deleteUserStmt->affected_rows > 0) {
        $deletedRecords[] = "useraccounts (1 record)";
    }
    $deleteUserStmt->close();
    
    // Commit transaction
    $conn->commit();
    
    // Prepare success message
    $message = "Account deleted successfully";
    if (!empty($deletedRecords)) {
        $message .= ". Deleted records from: " . implode(", ", $deletedRecords);
    }
    
    echo json_encode([
        'success' => true, 
        'message' => $message,
        'deleted_from' => $deletedRecords,
        'user_role' => $userRole
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    // Restore autocommit and close connection
    $conn->autocommit(true);
    $conn->close();
}
?>