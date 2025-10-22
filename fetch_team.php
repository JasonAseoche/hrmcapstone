<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet();
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGet() {
    global $conn;
    
    try {
        $user_id = $_GET['supervisor_id'] ?? '';
        
        if (empty($user_id)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'User ID is required'
            ]);
            return;
        }
        
        // First, check if this user_id is a supervisor by checking if it exists in supervisorlist
        // The user_id from the frontend could be either:
        // 1. The sup_id directly (if stored as sup_id in localStorage)
        // 2. The useraccounts.id (if stored as userId in localStorage)
        
        $supervisorCheckSql = "SELECT 
                                s.sup_id,
                                s.firstName as supervisor_firstName,
                                s.lastName as supervisor_lastName,
                                s.position as supervisor_position,
                                s.email as supervisor_email,
                                s.status as supervisor_status,
                                d.id as department_id,
                                d.name as department_name,
                                d.work_arrangement as department_work_arrangement,
                                d.status as department_status,
                                d.created_at as department_created,
                                d.updated_at as department_updated
                              FROM supervisorlist s
                              LEFT JOIN department_list d ON s.sup_id = d.supervisor_id AND d.status = 'active'
                              WHERE s.status = 'active' 
                              AND (s.sup_id = ? OR s.sup_id = (SELECT id FROM useraccounts WHERE id = ? AND role = 'supervisor'))
                              ORDER BY d.created_at DESC
                              LIMIT 1";
        
        $stmt = $conn->prepare($supervisorCheckSql);
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $supervisorResult = $stmt->get_result();
        $supervisorInfo = $supervisorResult->fetch_assoc();
        
        // Enhanced error checking
        if (!$supervisorInfo) {
            echo json_encode([
                'success' => false,
                'error' => 'User with ID ' . $user_id . ' is not found as a supervisor in the system'
            ]);
            return;
        }
        
        if ($supervisorInfo['supervisor_status'] !== 'active') {
            echo json_encode([
                'success' => false,
                'error' => 'Supervisor account is not active. Status: ' . $supervisorInfo['supervisor_status']
            ]);
            return;
        }
        
        if (!$supervisorInfo['department_id']) {
            echo json_encode([
                'success' => false,
                'error' => 'Supervisor is not assigned to any active department. Please contact your administrator.'
            ]);
            return;
        }
        
        if ($supervisorInfo['department_status'] !== 'active') {
            echo json_encode([
                'success' => false,
                'error' => 'Department is not active. Status: ' . $supervisorInfo['department_status']
            ]);
            return;
        }
        
        // Use the actual sup_id for fetching team members
        $actual_supervisor_id = $supervisorInfo['sup_id'];
        $actual_department_id = $supervisorInfo['department_id'];
        
        // Get all team members (employees) under this supervisor's specific active department
        $teamSql = "SELECT 
                        e.id as employee_list_id,
                        e.emp_id,
                        e.firstName,
                        e.lastName,
                        e.email,
                        e.position,
                        e.work_days,
                        e.status,
                        e.workarrangement,
                        e.address as employee_address,
                        e.number as employee_phone,
                        u.profile_image,
                        u.created_at,
                        d.name as department_name,
                        d.work_arrangement as department_work_arrangement,
                        p.middle_name,
                        p.address as profile_address,
                        p.contact_number,
                        p.date_of_birth,
                        p.civil_status,
                        p.gender,
                        p.citizenship,
                        p.height,
                        p.weight
                    FROM employeelist e
                    INNER JOIN department_list d ON e.department_id = d.id
                    LEFT JOIN useraccounts u ON e.emp_id = u.id
                    LEFT JOIN user_profiles p ON e.emp_id = p.user_id
                    WHERE d.id = ?
                    AND d.supervisor_id = ? 
                    AND e.status = 'active' 
                    AND d.status = 'active'
                    ORDER BY e.firstName, e.lastName";
        
        $stmt = $conn->prepare($teamSql);
        $stmt->bind_param("ii", $actual_department_id, $actual_supervisor_id);
        $stmt->execute();
        $teamResult = $stmt->get_result();
        
        $teamMembers = [];
        while ($row = $teamResult->fetch_assoc()) {
            // Format the data to match your frontend expectations
            $member = [
                'emp_id' => $row['emp_id'],
                'employee_list_id' => $row['employee_list_id'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'middleName' => $row['middle_name'], // From user_profiles table
                'email' => $row['email'],
                'position' => $row['position'],
                'work_days' => $row['work_days'],
                'status' => $row['status'],
                'workarrangement' => $row['workarrangement'],
                'profileImage' => $row['profile_image'],
                'department_name' => $row['department_name'],
                'department_work_arrangement' => $row['department_work_arrangement'],
                
                // Employee specific info
                'employeeAddress' => $row['employee_address'],
                'employeePhone' => $row['employee_phone'],
                
                // Profile info (for the modal)
                'address' => $row['profile_address'] ?: $row['employee_address'],
                'contactNumber' => $row['contact_number'] ?: $row['employee_phone'],
                'dateOfBirth' => $row['date_of_birth'],
                'civilStatus' => $row['civil_status'],
                'gender' => $row['gender'],
                'citizenship' => $row['citizenship'],
                'height' => $row['height'],
                'weight' => $row['weight'],
                'created_at' => $row['created_at'] // From useraccounts table
            ];
            
            // Format the profile image path if it exists
            if ($member['profileImage'] && !empty($member['profileImage'])) {
                // Check if it's already a full URL
                if (!str_starts_with($member['profileImage'], 'http')) {
                    $member['profileImage'] = 'uploads/profiles/' . $member['profileImage'];
                }
            }
            
            $teamMembers[] = $member;
        }
        
        // Prepare supervisor info for response
        $supervisorResponse = [
            'supervisor_id' => $supervisorInfo['sup_id'],
            'supervisor_name' => $supervisorInfo['supervisor_firstName'] . ' ' . $supervisorInfo['supervisor_lastName'],
            'supervisor_position' => $supervisorInfo['supervisor_position'],
            'supervisor_email' => $supervisorInfo['supervisor_email'],
            'department_id' => $supervisorInfo['department_id'],
            'department_name' => $supervisorInfo['department_name'],
            'department_work_arrangement' => $supervisorInfo['department_work_arrangement'],
            'team_count' => count($teamMembers)
        ];
        
        echo json_encode([
            'success' => true,
            'supervisor_info' => $supervisorResponse,
            'team_members' => $teamMembers,
            'message' => 'Team members fetched successfully'
        ]);
        
    } catch (Exception $e) {
        error_log('Error in fetch_team.php: ' . $e->getMessage());
        throw new Exception('Error fetching team members: ' . $e->getMessage());
    }
}

// Additional helper function to get team member statistics
function getTeamStatistics($supervisor_id) {
    global $conn;
    
    try {
        $statsSql = "SELECT 
                        COUNT(*) as total_members,
                        COUNT(CASE WHEN e.status = 'active' THEN 1 END) as active_members,
                        COUNT(CASE WHEN e.status = 'inactive' THEN 1 END) as inactive_members,
                        COUNT(CASE WHEN e.status = 'on_leave' THEN 1 END) as on_leave_members,
                        COUNT(CASE WHEN e.workarrangement = 'On-site' THEN 1 END) as onsite_members,
                        COUNT(CASE WHEN e.workarrangement = 'Remote' THEN 1 END) as remote_members,
                        COUNT(CASE WHEN e.workarrangement = 'Hybrid' THEN 1 END) as hybrid_members
                     FROM employeelist e
                     INNER JOIN department_list d ON e.department_id = d.id
                     WHERE d.supervisor_id = ? AND d.status = 'active'";
        
        $stmt = $conn->prepare($statsSql);
        $stmt->bind_param("i", $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
        
    } catch (Exception $e) {
        error_log('Error getting team statistics: ' . $e->getMessage());
        return null;
    }
}

// Function to get team member details by ID (for future use)
function getTeamMemberDetails($employee_id, $supervisor_id) {
    global $conn;
    
    try {
        $detailsSql = "SELECT 
                          e.*,
                          d.name as department_name,
                          d.work_arrangement as department_work_arrangement,
                          s.firstName as supervisor_firstName,
                          s.lastName as supervisor_lastName
                       FROM employeelist e
                       INNER JOIN department_list d ON e.department_id = d.id
                       INNER JOIN supervisorlist s ON d.supervisor_id = s.sup_id
                       WHERE e.emp_id = ? 
                       AND d.supervisor_id = ? 
                       AND e.status = 'active' 
                       AND d.status = 'active'";
        
        $stmt = $conn->prepare($detailsSql);
        $stmt->bind_param("ii", $employee_id, $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $employee = $result->fetch_assoc();
        
        if ($employee && $employee['profileImage'] && !empty($employee['profileImage'])) {
            if (!str_starts_with($employee['profileImage'], 'http')) {
                $employee['profileImage'] = 'uploads/profiles/' . $employee['profileImage'];
            }
        }
        
        return $employee;
        
    } catch (Exception $e) {
        error_log('Error getting team member details: ' . $e->getMessage());
        return null;
    }
}

// Function to check if supervisor has permission to view/manage a specific employee
function checkSupervisorPermission($supervisor_id, $employee_id) {
    global $conn;
    
    try {
        $permissionSql = "SELECT COUNT(*) as has_permission
                         FROM employeelist e
                         INNER JOIN department_list d ON e.department_id = d.id
                         WHERE e.emp_id = ? 
                         AND d.supervisor_id = ? 
                         AND e.status = 'active' 
                         AND d.status = 'active'";
        
        $stmt = $conn->prepare($permissionSql);
        $stmt->bind_param("ii", $employee_id, $supervisor_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['has_permission'] > 0;
        
    } catch (Exception $e) {
        error_log('Error checking supervisor permission: ' . $e->getMessage());
        return false;
    }
}

?>