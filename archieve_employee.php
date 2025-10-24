<?php
// archive_employee.php - Handle employee archiving operations

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

// Function to log debug information
function logDebug($message) {
    file_put_contents('archive_debug.log', date('Y-m-d H:i:s') . ": $message\n", FILE_APPEND);
}

logDebug("=== Archive Employee Script Started ===");
logDebug("Request Method: " . $_SERVER['REQUEST_METHOD']);

try {
    // Handle GET request - Fetch archived employees
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        logDebug("Fetching archived employees");
        
        $sortOrder = isset($_GET['sort']) ? $_GET['sort'] : 'latest';
        $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
        
        // Build the query
        $sql = "SELECT ua.id, ua.firstName, ua.lastName, ua.email, ua.updated_at as date_archived 
                FROM useraccounts ua 
                WHERE ua.role = 'archived'";
        
        // Add search filter if provided
        if (!empty($searchTerm)) {
            $sql .= " AND (ua.firstName LIKE ? OR ua.lastName LIKE ? OR ua.email LIKE ?)";
        }
        
        // Add sorting
        if ($sortOrder === 'oldest') {
            $sql .= " ORDER BY ua.updated_at ASC";
        } else {
            $sql .= " ORDER BY ua.updated_at DESC";
        }
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }
        
        // Bind search parameters if search term exists
        if (!empty($searchTerm)) {
            $searchParam = "%{$searchTerm}%";
            $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $archivedEmployees = [];
        while ($row = $result->fetch_assoc()) {
            $archivedEmployees[] = [
                'id' => $row['id'],
                'name' => $row['firstName'] . ' ' . $row['lastName'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'email' => $row['email'],
                'date_archived' => $row['date_archived']
            ];
        }
        
        logDebug("Found " . count($archivedEmployees) . " archived employees");
        
        echo json_encode([
            'success' => true,
            'data' => $archivedEmployees,
            'count' => count($archivedEmployees)
        ]);
        
        $stmt->close();
        exit();
    }
    
    // Handle POST request - Archive or Unarchive
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        logDebug("POST data received: " . print_r($data, true));
        
        if (!isset($data['action']) || !isset($data['emp_id'])) {
            throw new Exception("Missing required parameters: action and emp_id");
        }
        
        $action = $data['action'];
        $emp_id = intval($data['emp_id']);
        
        // Start transaction
        $conn->autocommit(FALSE);
        
        if ($action === 'archive') {
            logDebug("Archiving employee with ID: {$emp_id}");
            
            // First, check if employee exists in useraccounts
            $checkSql = "SELECT id, firstName, lastName, email, role FROM useraccounts WHERE id = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("i", $emp_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Employee not found in useraccounts");
            }
            
            $employee = $result->fetch_assoc();
            $checkStmt->close();
            
            // Update role to 'archived' in useraccounts
            $updateSql = "UPDATE useraccounts SET role = 'archived', updated_at = NOW() WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("i", $emp_id);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update user role: " . $updateStmt->error);
            }
            
            logDebug("Updated useraccounts role to archived");
            $updateStmt->close();
            
            // Delete from employeelist table
            $deleteSql = "DELETE FROM employeelist WHERE emp_id = ?";
            $deleteStmt = $conn->prepare($deleteSql);
            $deleteStmt->bind_param("i", $emp_id);
            
            if (!$deleteStmt->execute()) {
                throw new Exception("Failed to delete from employeelist: " . $deleteStmt->error);
            }
            
            $deletedRows = $deleteStmt->affected_rows;
            logDebug("Deleted {$deletedRows} rows from employeelist");
            $deleteStmt->close();
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Employee archived successfully',
                'employee' => [
                    'id' => $emp_id,
                    'name' => $employee['firstName'] . ' ' . $employee['lastName'],
                    'email' => $employee['email']
                ]
            ]);
            
        } elseif ($action === 'unarchive') {
            logDebug("Unarchiving employee with ID: {$emp_id}");
            
            // First, get employee details from useraccounts
            $checkSql = "SELECT id, firstName, lastName, email, role FROM useraccounts WHERE id = ? AND role = 'archived'";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("i", $emp_id);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Archived employee not found");
            }
            
            $employee = $result->fetch_assoc();
            $checkStmt->close();
            
            // Update role back to 'employee' in useraccounts
            $updateSql = "UPDATE useraccounts SET role = 'employee', updated_at = NOW() WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("i", $emp_id);
            
            if (!$updateStmt->execute()) {
                throw new Exception("Failed to update user role: " . $updateStmt->error);
            }
            
            logDebug("Updated useraccounts role to employee");
            $updateStmt->close();
            
            // Get the next unique ID for employeelist table
            $getMaxIdSql = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM employeelist";
            $maxIdResult = $conn->query($getMaxIdSql);
            $maxIdRow = $maxIdResult->fetch_assoc();
            $nextId = $maxIdRow['next_id'];
            
            // Re-add to employeelist table with default values
            $insertSql = "INSERT INTO employeelist (id, emp_id, firstName, lastName, email, role, position, work_days, status, address, number, workarrangement) VALUES (?, ?, ?, ?, ?, 'employee', 'Not Assigned', 'Monday-Friday', 'active', '', NULL, 'office')";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param("iisss", 
                $nextId,
                $emp_id, 
                $employee['firstName'], 
                $employee['lastName'], 
                $employee['email']
            );
            
            if (!$insertStmt->execute()) {
                throw new Exception("Failed to insert into employeelist: " . $insertStmt->error);
            }
            
            logDebug("Re-added employee to employeelist");
            $insertStmt->close();
            
            // Commit transaction
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Employee unarchived successfully',
                'employee' => [
                    'id' => $emp_id,
                    'name' => $employee['firstName'] . ' ' . $employee['lastName'],
                    'email' => $employee['email']
                ]
            ]);
            
        } else {
            throw new Exception("Invalid action. Must be 'archive' or 'unarchive'");
        }
        
        $conn->autocommit(TRUE);
        exit();
    }
    
    // Invalid request method
    throw new Exception("Invalid request method");
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        $conn->rollback();
        $conn->autocommit(TRUE);
    }
    
    logDebug("Error occurred: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    logDebug("=== Archive Employee Script Completed ===");
}
?>