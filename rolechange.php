<?php
// rolechange.php - Convert applicants to employees with auto department assignment
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Only POST method allowed'
    ]);
    exit();
}

// Function to migrate files from applicant_files to employee_files
function migrateApplicantFiles($conn, $app_id, $emp_id) {
    try {
        error_log("Starting file migration for app_id: {$app_id} to emp_id: {$emp_id}");
        
        // Get all files for this applicant
        $select_sql = "SELECT file_type, file_content, file_name, file_size, mime_type, uploaded_at 
                       FROM applicant_files 
                       WHERE app_id = ?";
        
        $select_stmt = $conn->prepare($select_sql);
        $select_stmt->bind_param("i", $app_id);
        $select_stmt->execute();
        $result = $select_stmt->get_result();
        
        $migrated_count = 0;
        $files_found = $result->num_rows;
        
        error_log("Found {$files_found} files for app_id: {$app_id}");
        
        while ($file = $result->fetch_assoc()) {
            error_log("Migrating file: {$file['file_name']} of type: {$file['file_type']}");
            
            // Insert into employee_files
            $insert_sql = "INSERT INTO employee_files (emp_id, file_type, file_content, file_name, file_size, mime_type, uploaded_at, migrated_from_applicant, original_applicant_id) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
            
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("issssssi", 
                $emp_id,
                $file['file_type'],
                $file['file_content'],
                $file['file_name'],
                $file['file_size'],
                $file['file_type'],
                $file['uploaded_at'],
                $app_id
            );
            
            if ($insert_stmt->execute()) {
                $migrated_count++;
                error_log("Successfully migrated file: {$file['file_name']} with emp_id: {$emp_id}");
            } else {
                error_log("Failed to migrate file {$file['file_name']}: " . $insert_stmt->error);
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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!$input) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON input'
    ]);
    exit();
}

// Get position and work arrangement
$position = isset($input['position']) ? trim($input['position']) : '';
$work_arrangement = isset($input['work_arrangement']) ? trim($input['work_arrangement']) : '';

// Validate required fields
if (empty($position)) {
    echo json_encode([
        'success' => false,
        'message' => 'Position is required'
    ]);
    exit();
}

if (empty($work_arrangement)) {
    echo json_encode([
        'success' => false,
        'message' => 'Work arrangement is required'
    ]);
    exit();
}

// Validate applicant IDs
if (!isset($input['applicant_ids']) || !is_array($input['applicant_ids']) || empty($input['applicant_ids'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No applicant IDs provided or invalid format'
    ]);
    exit();
}

$applicant_ids = array_map('intval', $input['applicant_ids']);

// Debug log
error_log('Role change input: ' . print_r($input, true));

// Include database connection
require_once 'db_connection.php';

if (!$conn) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    exit();
}

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Create placeholders for IN clause
    $placeholders = str_repeat('?,', count($applicant_ids) - 1) . '?';
    
    // Get applicant details
    $sql = "SELECT 
                id,
                app_id,
                firstName,
                lastName,
                email,
                address,
                position as applicant_position,
                number,
                status
            FROM applicantlist 
            WHERE id IN ($placeholders) AND status IN ('pending', 'approved', 'qualified', 'scheduled')";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare applicant query: ' . $conn->error);
    }
    
    $types = str_repeat('i', count($applicant_ids));
    $stmt->bind_param($types, ...$applicant_ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $applicants = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    error_log('Found ' . count($applicants) . ' valid applicants');
    
    if (empty($applicants)) {
        throw new Exception('No valid applicants found with the provided IDs. They may have already been processed or have invalid status.');
    }
    
    $new_employees = [];
    $successfully_converted = [];
    $total_files_migrated = 0;
    
    // Process each applicant
    foreach ($applicants as $applicant) {
        try {
            // Check if this applicant is already an employee
            $checkSql = "SELECT id FROM employeelist WHERE emp_id = ? OR email = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param('is', $applicant['app_id'], $applicant['email']);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                error_log("Skipping applicant {$applicant['id']} - already exists as employee");
                $checkStmt->close();
                continue;
            }
            $checkStmt->close();
            
            // Insert into employeelist
            $insertSql = "INSERT INTO employeelist (
                emp_id,
                firstName,
                lastName,
                email,
                role,
                position,
                work_days,
                status,
                address,
                number,
                workarrangement
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $insertStmt = $conn->prepare($insertSql);
            if (!$insertStmt) {
                throw new Exception('Failed to prepare employee insert: ' . $conn->error);
            }
            
            $role = 'employee';
            $work_days = 'Monday-Friday';
            $emp_status = 'active';
            $address = $applicant['address'] ?? '';
            $number = !empty($applicant['number']) ? $applicant['number'] : null;
            
            $insertStmt->bind_param('issssssssss',
                $applicant['app_id'],
                $applicant['firstName'],
                $applicant['lastName'],
                $applicant['email'],
                $role,
                $position,
                $work_days,
                $emp_status,
                $address,
                $number,
                $work_arrangement
            );
            
            if (!$insertStmt->execute()) {
                error_log("Failed to insert applicant {$applicant['id']} into employeelist: " . $insertStmt->error);
                $insertStmt->close();
                continue;
            }
            
            $employee_list_id = $conn->insert_id;
            $insertStmt->close();
            
            error_log("Successfully created employee with ID {$employee_list_id} for applicant {$applicant['id']}");
            
            // Try to auto-assign to department based on position match
            $department_id = null;
            $position_check_sql = "SELECT id FROM department_list WHERE FIND_IN_SET(?, positions) > 0 AND status = 'active' LIMIT 1";
            $position_stmt = $conn->prepare($position_check_sql);
            $position_stmt->bind_param("s", $position);
            $position_stmt->execute();
            $position_result = $position_stmt->get_result();
            
            if ($position_row = $position_result->fetch_assoc()) {
                $department_id = $position_row['id'];
                
                // Update employee with department assignment
                $update_dept_sql = "UPDATE employeelist SET department_id = ? WHERE emp_id = ?";
                $update_dept_stmt = $conn->prepare($update_dept_sql);
                $update_dept_stmt->bind_param("ii", $department_id, $applicant['app_id']);
                $update_dept_stmt->execute();
                $update_dept_stmt->close();
                
                error_log("Auto-assigned employee {$applicant['app_id']} to department {$department_id} based on position match");
            } else {
                error_log("No department found for position '{$position}' - employee will remain unassigned");
            }
            $position_stmt->close();
            
            // Migrate files
            $files_migrated = migrateApplicantFiles($conn, $applicant['app_id'], $applicant['app_id']);
            $total_files_migrated += $files_migrated;
            
            // Update user role in useraccounts
            $updateUserSql = "UPDATE useraccounts SET role = 'employee' WHERE id = ?";
            $updateUserStmt = $conn->prepare($updateUserSql);
            $updateUserStmt->bind_param('i', $applicant['app_id']);
            
            if (!$updateUserStmt->execute()) {
                error_log("Failed to update user role for app_id {$applicant['app_id']}: " . $updateUserStmt->error);
            }
            $updateUserStmt->close();
            
            // Add to successful conversions
            $successfully_converted[] = $applicant['id'];
            
            // Add to response array
            $new_employees[] = [
                'id' => $applicant['app_id'],
                'employee_list_id' => (int)$employee_list_id,
                'firstName' => $applicant['firstName'],
                'lastName' => $applicant['lastName'],
                'email' => $applicant['email'],
                'role' => 'employee',
                'position' => $position,
                'workDays' => 'Monday-Friday',
                'status' => 'active',
                'address' => $address,
                'number' => $number,
                'workarrangement' => $work_arrangement,
                'department_id' => $department_id,
                'files_migrated' => $files_migrated
            ];
            
        } catch (Exception $e) {
            error_log("Error processing applicant {$applicant['id']}: " . $e->getMessage());
            // Continue with other applicants instead of failing completely
            continue;
        }
    }
    
    // Check if any employees were successfully created
    if (empty($new_employees)) {
        throw new Exception('No applicants were successfully converted to employees. Check error logs for details.');
    }
    
    // Delete successfully converted applicants from applicantlist
    if (!empty($successfully_converted)) {
        $delete_placeholders = str_repeat('?,', count($successfully_converted) - 1) . '?';
        $deleteSql = "DELETE FROM applicantlist WHERE id IN ($delete_placeholders)";
        
        $deleteStmt = $conn->prepare($deleteSql);
        if (!$deleteStmt) {
            throw new Exception('Failed to prepare applicant deletion: ' . $conn->error);
        }
        
        $delete_types = str_repeat('i', count($successfully_converted));
        $deleteStmt->bind_param($delete_types, ...$successfully_converted);
        
        if (!$deleteStmt->execute()) {
            throw new Exception('Failed to delete applicants from applicantlist: ' . $deleteStmt->error);
        }
        
        $deleted_count = $deleteStmt->affected_rows;
        $deleteStmt->close();
        
        error_log("Successfully deleted {$deleted_count} applicants from applicantlist");
    }
    
    // Commit transaction
    $conn->commit();
    
    error_log('Successfully converted ' . count($new_employees) . ' applicants to employees and migrated ' . $total_files_migrated . ' files');
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => count($new_employees) . ' applicant(s) successfully converted to employee(s)',
        'new_employees' => $new_employees,
        'converted_count' => count($new_employees),
        'total_files_migrated' => $total_files_migrated,
        'deleted_from_applicants' => true
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    error_log('Error in rolechange.php: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => 'Error converting applicants: ' . $e->getMessage(),
        'new_employees' => [],
        'debug_info' => [
            'provided_ids' => $applicant_ids,
            'found_applicants' => isset($applicants) ? count($applicants) : 0
        ]
    ]);
}

$conn->close();
?>