<?php
// fetch_employee.php - Updated with profile details functionality and shift update
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

try {
    // Handle GET requests (existing functionality)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        
        // Check if this is a request for a specific employee profile
        if (isset($_GET['id']) && !empty($_GET['id'])) {
            
            // Fetch detailed employee profile
            $employeeId = intval($_GET['id']);
            
            $sql = "SELECT 
                        u.id,
                        u.firstName, 
                        u.lastName, 
                        u.email,
                        u.profile_image,
                        u.status as account_status,
                        u.created_at,
                        e.position,
                        e.work_days,
                        e.rest_day,
                        e.status as employment_status,
                        e.workarrangement,
                        e.address as employee_address,
                        e.number as employee_phone,
                        e.total_leave_days,
                        e.used_leave_days,
                        e.remaining_leave_days,
                        e.overtime_rate,
                        e.max_overtime_hours,
                        p.middle_name,
                        p.address as profile_address,
                        p.contact_number,
                        p.date_of_birth,
                        p.civil_status,
                        p.gender,
                        p.citizenship,
                        p.height,
                        p.weight,
                        p.created_at as profile_created,
                        p.updated_at as profile_updated
                    FROM useraccounts u
                    LEFT JOIN employeelist e ON u.id = e.emp_id
                    LEFT JOIN user_profiles p ON u.id = p.user_id
                    WHERE u.id = ? AND u.role = 'employee'";
            
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Failed to prepare statement: " . $conn->error);
            }
            
            $stmt->bind_param("i", $employeeId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Employee not found.'
                ]);
                exit;
            }
            
            $row = $result->fetch_assoc();
            
            // Format the employee profile data
            $employee = [
                'id' => $row['id'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'middleName' => $row['middle_name'],
                'email' => $row['email'],
                'profileImage' => $row['profile_image'],
                'position' => $row['position'],
                'workDays' => $row['work_days'],
                'restDay' => $row['rest_day'],
                'status' => $row['employment_status'],
                'workarrangement' => $row['workarrangement'],
                'accountStatus' => $row['account_status'],
                'createdAt' => $row['created_at'],
                
                // Employee specific info
                'employeeAddress' => $row['employee_address'],
                'employeePhone' => $row['employee_phone'],
                'totalLeaveDays' => $row['total_leave_days'],
                'usedLeaveDays' => $row['used_leave_days'],
                'remainingLeaveDays' => $row['remaining_leave_days'],
                'overtimeRate' => $row['overtime_rate'],
                'maxOvertimeHours' => $row['max_overtime_hours'],
                
                // Profile info
                'address' => $row['profile_address'] ?: $row['employee_address'], // Use profile address first, fallback to employee address
                'contactNumber' => $row['contact_number'] ?: $row['employee_phone'], // Use profile contact first, fallback to employee phone
                'dateOfBirth' => $row['date_of_birth'],
                'civilStatus' => $row['civil_status'],
                'gender' => $row['gender'],
                'citizenship' => $row['citizenship'],
                'height' => $row['height'],
                'weight' => $row['weight'],
                'profileCreated' => $row['profile_created'],
                'profileUpdated' => $row['profile_updated']
            ];
            
            echo json_encode([
                'success' => true,
                'employee' => $employee
            ]);
            
            $stmt->close();
            
        } else {
            
            // Fetch all employees list (existing functionality)
            $sql = "SELECT 
                        e.id,
                        e.emp_id,
                        e.firstName, 
                        e.lastName, 
                        e.email, 
                        e.role,
                        e.position,
                        e.work_days,
                        e.rest_day,
                        e.status,
                        e.workarrangement,
                        u.profile_image
                    FROM employeelist e
                    LEFT JOIN useraccounts u ON e.emp_id = u.id
                    WHERE e.role = 'employee'
                    ORDER BY e.firstName, e.lastName";
            
            $result = $conn->query($sql);
            
            if (!$result) {
                throw new Exception("Query failed: " . $conn->error);
            }
            
            $employees = [];
            
            // Fetch all employees
            while ($row = $result->fetch_assoc()) {
                $employees[] = [
                    'id' => $row['emp_id'], // Use emp_id as the main identifier for consistency
                    'employee_list_id' => $row['id'], // The employeeList table ID
                    'firstName' => $row['firstName'],
                    'lastName' => $row['lastName'],
                    'email' => $row['email'],
                    'role' => $row['role'],
                    'position' => $row['position'],
                    'workDays' => $row['work_days'], // Note: your table uses work_days (underscore)
                    'restDay' => $row['rest_day'], // Add rest_day field
                    'status' => $row['status'],
                    'workarrangement' => $row['workarrangement'],
                    'profileImage' => $row['profile_image'] // Add profile image
                ];
            }
            
            // Check if any employees were found
            if (count($employees) > 0) {
                echo json_encode([
                    'success' => true,
                    'employees' => $employees
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'employees' => [],
                    'message' => 'No employees found.'
                ]);
            }
        }
        
    } else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // Handle POST request for updating employee shift
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Invalid JSON input');
        }

        $empId = isset($input['emp_id']) ? intval($input['emp_id']) : 0;
        $workDays = isset($input['work_days']) ? trim($input['work_days']) : '';
        $restDay = isset($input['rest_day']) ? trim($input['rest_day']) : '';

        if ($empId <= 0) {
            throw new Exception('Valid employee ID is required');
        }

        if (empty($workDays)) {
            throw new Exception('Work days are required');
        }

        if (empty($restDay)) {
            throw new Exception('Rest day is required');
        }

        // Update employee shift
        $sql = "UPDATE employeelist SET work_days = ?, rest_day = ? WHERE emp_id = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }

        $stmt->bind_param("ssi", $workDays, $restDay, $empId);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update employee shift: " . $stmt->error);
        }

        if ($stmt->affected_rows === 0) {
            throw new Exception("Employee not found or no changes made");
        }

        echo json_encode([
            'success' => true,
            'message' => 'Employee shift updated successfully',
            'data' => [
                'emp_id' => $empId,
                'work_days' => $workDays,
                'rest_day' => $restDay
            ]
        ]);

        $stmt->close();
        
    } else {
        throw new Exception('Method not allowed');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
} finally {
    // Close the connection
    if (isset($conn)) {
        $conn->close();
    }
}
?>