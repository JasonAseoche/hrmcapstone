<?php
// Enable error reporting and logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'leave_management_errors.log');

require_once 'db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Log all incoming requests
file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Request: " . $_SERVER['REQUEST_METHOD'] . " " . $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Input: " . file_get_contents('php://input') . "\n", FILE_APPEND);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class LeaveManagement {
    private $conn;
    
    public function __construct() {
        global $conn;
        if (!$conn) {
            throw new Exception("Database connection not available");
        }
        $this->conn = $conn;
    }
    
    // Generate unique leave ID
    private function generateLeaveId() {
        try {
            $year = date('Y');
            $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM leave_requests WHERE YEAR(created_at) = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            $stmt->bind_param("s", $year);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $count = $result['count'] + 1;
            return "LV" . $year . str_pad($count, 4, '0', STR_PAD_LEFT);
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - generateLeaveId Error: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }
    }
    
    // Calculate business days between two dates
    private function calculateBusinessDays($startDate, $endDate) {
        try {
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            $end->modify('+1 day'); // Include end date
            
            $days = 0;
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($start, $interval, $end);
            
            foreach ($period as $date) {
                $dayOfWeek = $date->format('w');
                if ($dayOfWeek != 0 && $dayOfWeek != 6) { // Exclude weekends
                    $days++;
                }
            }
            
            return $days;
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - calculateBusinessDays Error: " . $e->getMessage() . "\n", FILE_APPEND);
            return 1; // Default to 1 day if calculation fails
        }
    }

    // Check if employee has enough leave credits
    private function checkLeaveCredits($empId, $leaveType, $daysRequested) {
        try {
            $year = date('Y');
            $stmt = $this->conn->prepare("
                SELECT vacation_credits, sick_credits, used_vacation, used_sick 
                FROM leave_credits 
                WHERE emp_id = ? AND year = ?
            ");
            $stmt->bind_param("ii", $empId, $year);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // Create default credits if not exists
                $this->createDefaultLeaveCredits($empId, $year);
                return $this->checkLeaveCredits($empId, $leaveType, $daysRequested);
            }
            
            $credits = $result->fetch_assoc();
            
            if ($leaveType === 'Vacation Leave') {
                $available = $credits['vacation_credits'] - $credits['used_vacation'];
                return [
                    'has_credits' => $available >= $daysRequested,
                    'available' => $available,
                    'requested' => $daysRequested
                ];
            } elseif ($leaveType === 'Sick Leave') {
                $available = $credits['sick_credits'] - $credits['used_sick'];
                return [
                    'has_credits' => $available >= $daysRequested,
                    'available' => $available,
                    'requested' => $daysRequested
                ];
            }
            
            // For other leave types, assume unlimited
            return ['has_credits' => true, 'available' => 999, 'requested' => $daysRequested];
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - checkLeaveCredits Error: " . $e->getMessage() . "\n", FILE_APPEND);
            return ['has_credits' => false, 'available' => 0, 'requested' => $daysRequested];
        }
    }

    // Create default leave credits
    private function createDefaultLeaveCredits($empId, $year) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO leave_credits (emp_id, vacation_credits, sick_credits, year) 
                VALUES (?, 15, 10, ?)
            ");
            $stmt->bind_param("ii", $empId, $year);
            $stmt->execute();
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - createDefaultLeaveCredits Error: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    // Get employee's supervisor through department
    private function getEmployeeSupervisor($empId) {
        try {
            $stmt = $this->conn->prepare("
                SELECT d.supervisor_id, s.firstName, s.lastName 
                FROM employeelist e
                JOIN department_list d ON e.department_id = d.id
                JOIN supervisorlist s ON d.supervisor_id = s.sup_id
                WHERE e.emp_id = ? AND e.status = 'active' AND d.status = 'active'
            ");
            $stmt->bind_param("i", $empId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                return $result->fetch_assoc();
            }
            
            return null;
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - getEmployeeSupervisor Error: " . $e->getMessage() . "\n", FILE_APPEND);
            return null;
        }
    }

    // Helper function to determine overall status
    private function getOverallStatus($hrStatus, $supervisorStatus) {
        if ($hrStatus === 'Rejected' || $supervisorStatus === 'Rejected') {
            return 'Rejected';
        }
        if ($hrStatus === 'Approved' && $supervisorStatus === 'Approved') {
            return 'Approved';
        }
        if ($hrStatus === 'Approved' && $supervisorStatus === 'Pending') {
            return 'HR Approved - Pending Supervisor';
        }
        return 'Pending';
    }
    
    // Submit leave request (Employee)
    public function submitLeaveRequest($data) {
        try {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - submitLeaveRequest Data: " . json_encode($data) . "\n", FILE_APPEND);
            
            // Validate required fields
            $requiredFields = ['emp_id', 'leave_type', 'start_date', 'end_date', 'reason'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    throw new Exception("Required field '$field' is missing or empty");
                }
            }
            
            $leaveId = $this->generateLeaveId();
            $daysRequested = $this->calculateBusinessDays($data['start_date'], $data['end_date']);
            
            // Check leave credits for vacation and sick leave
            if (in_array($data['leave_type'], ['Vacation Leave', 'Sick Leave'])) {
                $creditsCheck = $this->checkLeaveCredits($data['emp_id'], $data['leave_type'], $daysRequested);
                if (!$creditsCheck['has_credits']) {
                    throw new Exception("Insufficient leave credits. Available: {$creditsCheck['available']}, Requested: {$creditsCheck['requested']}");
                }
            }
            
            // Get employee info
            $stmt = $this->conn->prepare("
                SELECT ua.firstName, ua.lastName, ua.email, 
                       COALESCE(el.position, 'Not Assigned') as position,
                       COALESCE(dl.name, 'Not Assigned') as department
                FROM useraccounts ua 
                LEFT JOIN employeelist el ON ua.id = el.emp_id 
                LEFT JOIN department_list dl ON el.department_id = dl.id
                WHERE ua.id = ? AND ua.role = 'employee'
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $data['emp_id']);
            $stmt->execute();
            $employee = $stmt->get_result()->fetch_assoc();
            
            if (!$employee) {
                throw new Exception("Employee not found with ID: " . $data['emp_id']);
            }
            
            $employeeName = $employee['firstName'] . ' ' . $employee['lastName'];
            
            // Get employee's supervisor
            $supervisor = $this->getEmployeeSupervisor($data['emp_id']);
            if (!$supervisor) {
                throw new Exception("No supervisor assigned to this employee. Please contact HR.");
            }
            
            $stmt = $this->conn->prepare("
                INSERT INTO leave_requests (
                    leave_id, emp_id, employee_name, employee_email, employee_position,
                    leave_type, start_date, end_date, reason, days_requested,
                    hr_status, supervisor_status, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Pending', 'Pending')
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("sisssssssi", 
                $leaveId, 
                $data['emp_id'], 
                $employeeName, 
                $employee['email'], 
                $employee['position'], 
                $data['leave_type'], 
                $data['start_date'], 
                $data['end_date'], 
                $data['reason'], 
                $daysRequested
            );
            
            if ($stmt->execute()) {
                $requestId = $this->conn->insert_id;
                
                file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Leave request inserted with ID: " . $requestId . "\n", FILE_APPEND);
                
                return [
                    'success' => true,
                    'message' => 'Leave request submitted successfully',
                    'leave_id' => $leaveId,
                    'days_requested' => $daysRequested
                ];
            } else {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - submitLeaveRequest Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Get leave requests for employee
    public function getEmployeeLeaveRequests($empId) {
        try {
            $stmt = $this->conn->prepare("
                SELECT lr.*, la.original_filename, la.file_path,
                       lc.vacation_credits, lc.sick_credits, lc.used_vacation, lc.used_sick,
                       dl.name as department
                FROM leave_requests lr
                LEFT JOIN leave_attachments la ON lr.id = la.leave_request_id
                LEFT JOIN leave_credits lc ON lr.emp_id = lc.emp_id AND lc.year = YEAR(CURDATE())
                LEFT JOIN employeelist el ON lr.emp_id = el.emp_id
                LEFT JOIN department_list dl ON el.department_id = dl.id
                WHERE lr.emp_id = ?
                ORDER BY lr.created_at DESC
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $empId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $overallStatus = $this->getOverallStatus($row['hr_status'], $row['supervisor_status']);
                
                $requests[] = [
                    'id' => $row['leave_id'],
                    'subject' => $row['leave_type'],
                    'dateSubmitted' => $row['created_at'],
                    'status' => $overallStatus,
                    'hr_status' => $row['hr_status'],
                    'supervisor_status' => $row['supervisor_status'],
                    'description' => $row['reason'],
                    'startDate' => $row['start_date'],
                    'endDate' => $row['end_date'],
                    'daysRequested' => $row['days_requested'],
                    'attachments' => $row['original_filename'] ? [$row['original_filename']] : [],
                    'customSubject' => '',
                    'leaveType' => $row['leave_type'],
                    'comments' => $row['comments'],
                    'hr_comments' => $row['hr_comments'],
                    'supervisor_comments' => $row['supervisor_comments'],
                    'department' => $row['department'],
                    'vacation_credits' => $row['vacation_credits'] ?: 15,
                    'sick_credits' => $row['sick_credits'] ?: 10,
                    'used_vacation' => $row['used_vacation'] ?: 0,
                    'used_sick' => $row['used_sick'] ?: 0
                ];
            }
            
            return [
                'success' => true,
                'data' => $requests
            ];
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - getEmployeeLeaveRequests Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Get all leave requests for HR
    public function getAllLeaveRequests($status = null) {
        try {
            $query = "
                SELECT lr.*, la.original_filename, la.file_path,
                       dl.name as department
                FROM leave_requests lr
                LEFT JOIN leave_attachments la ON lr.id = la.leave_request_id
                LEFT JOIN employeelist el ON lr.emp_id = el.emp_id
                LEFT JOIN department_list dl ON el.department_id = dl.id
            ";
            
            $params = [];
            $types = "";
            
            if ($status) {
                if ($status === 'hr_pending') {
                    $query .= " WHERE lr.hr_status = 'Pending'";
                } else {
                    $query .= " WHERE lr.status = ?";
                    $params[] = $status;
                    $types .= "s";
                }
            }
            
            $query .= " ORDER BY lr.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $overallStatus = $this->getOverallStatus($row['hr_status'], $row['supervisor_status']);
                
                $requests[] = [
                    'id' => $row['id'],
                    'leave_id' => $row['leave_id'],
                    'emp_id' => $row['emp_id'],
                    'name' => $row['employee_name'],
                    'email' => $row['employee_email'],
                    'role' => $row['employee_position'],
                    'avatar' => $this->getInitials($row['employee_name']),
                    'leaveDate' => date('M d, Y', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date'])),
                    'leaveType' => $row['leave_type'],
                    'reason' => $row['reason'],
                    'status' => $overallStatus,
                    'hr_status' => $row['hr_status'],
                    'supervisor_status' => $row['supervisor_status'],
                    'daysRequested' => $row['days_requested'],
                    'dateSubmitted' => $row['created_at'],
                    'startDate' => $row['start_date'],
                    'endDate' => $row['end_date'],
                    'attachments' => $row['original_filename'] ? [$row['original_filename']] : [],
                    'comments' => $row['comments'],
                    'hr_comments' => $row['hr_comments'],
                    'supervisor_comments' => $row['supervisor_comments'],
                    'department' => $row['department']
                ];
            }
            
            return [
                'success' => true,
                'data' => $requests
            ];
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - getAllLeaveRequests Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Get leave requests for supervisor (only their team members)
    public function getSupervisorLeaveRequests($supervisorId, $status = null) {
        try {
            $query = "
                SELECT lr.*, la.original_filename, la.file_path,
                       dl.name as department
                FROM leave_requests lr
                LEFT JOIN leave_attachments la ON lr.id = la.leave_request_id
                JOIN employeelist el ON lr.emp_id = el.emp_id
                JOIN department_list dl ON el.department_id = dl.id
                WHERE dl.supervisor_id = ? AND lr.hr_status = 'Approved'
            ";
            
            $params = [$supervisorId];
            $types = "i";
            
            if ($status && $status !== 'all') {
                if ($status === 'supervisor_pending') {
                    $query .= " AND lr.supervisor_status = 'Pending'";
                } else {
                    $query .= " AND lr.supervisor_status = ?";
                    $params[] = $status;
                    $types .= "s";
                }
            }
            
            $query .= " ORDER BY lr.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $overallStatus = $this->getOverallStatus($row['hr_status'], $row['supervisor_status']);
                
                $requests[] = [
                    'id' => $row['id'],
                    'leave_id' => $row['leave_id'],
                    'emp_id' => $row['emp_id'],
                    'name' => $row['employee_name'],
                    'email' => $row['employee_email'],
                    'role' => $row['employee_position'],
                    'avatar' => $this->getInitials($row['employee_name']),
                    'leaveDate' => date('M d, Y', strtotime($row['start_date'])) . ' - ' . date('M d, Y', strtotime($row['end_date'])),
                    'leaveType' => $row['leave_type'],
                    'reason' => $row['reason'],
                    'status' => $overallStatus,
                    'hr_status' => $row['hr_status'],
                    'supervisor_status' => $row['supervisor_status'],
                    'daysRequested' => $row['days_requested'],
                    'dateSubmitted' => $row['created_at'],
                    'startDate' => $row['start_date'],
                    'endDate' => $row['end_date'],
                    'attachments' => $row['original_filename'] ? [$row['original_filename']] : [],
                    'comments' => $row['comments'],
                    'hr_comments' => $row['hr_comments'],
                    'supervisor_comments' => $row['supervisor_comments'],
                    'department' => $row['department']
                ];
            }
            
            return [
                'success' => true,
                'data' => $requests
            ];
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - getSupervisorLeaveRequests Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Get leave request details
    public function getLeaveRequestDetails($leaveId, $empId = null, $role = null) {
        try {
            $query = "
                SELECT lr.*, la.original_filename, la.file_path,
                       dl.name as department
                FROM leave_requests lr
                LEFT JOIN leave_attachments la ON lr.id = la.leave_request_id
                LEFT JOIN employeelist el ON lr.emp_id = el.emp_id
                LEFT JOIN department_list dl ON el.department_id = dl.id
                WHERE lr.leave_id = ?
            ";
            
            $params = [$leaveId];
            $types = "s";
            
            // If employee ID is provided, ensure they can only see their own requests
            if ($empId !== null && $role === 'employee') {
                $query .= " AND lr.emp_id = ?";
                $params[] = $empId;
                $types .= "i";
            }
            
            $stmt = $this->conn->prepare($query);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $overallStatus = $this->getOverallStatus($row['hr_status'], $row['supervisor_status']);
                
                return [
                    'success' => true,
                    'data' => [
                        'id' => $row['leave_id'],
                        'subject' => $row['leave_type'],
                        'leaveType' => $row['leave_type'],
                        'startDate' => $row['start_date'],
                        'endDate' => $row['end_date'],
                        'reason' => $row['reason'],
                        'status' => $overallStatus,
                        'hr_status' => $row['hr_status'],
                        'supervisor_status' => $row['supervisor_status'],
                        'daysRequested' => $row['days_requested'],
                        'dateSubmitted' => $row['created_at'],
                        'employeeName' => $row['employee_name'],
                        'employeeEmail' => $row['employee_email'],
                        'employeePosition' => $row['employee_position'],
                        'attachments' => $row['original_filename'] ? [$row['original_filename']] : [],
                        'comments' => $row['comments'],
                        'hr_comments' => $row['hr_comments'],
                        'supervisor_comments' => $row['supervisor_comments'],
                        'department' => $row['department']
                    ]
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Leave request not found'
            ];
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - getLeaveRequestDetails Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Update leave credits when leave is fully approved
    // Update leave credits when leave is fully approved
private function updateLeaveCredits($empId, $leaveType, $daysRequested, $action = 'deduct') {
    try {
        file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - updateLeaveCredits called: empId=$empId, leaveType=$leaveType, days=$daysRequested, action=$action\n", FILE_APPEND);
        
        $year = date('Y');
        
        // Ensure leave credits exist
        $stmt = $this->conn->prepare("
            SELECT id, vacation_credits, sick_credits, used_vacation, used_sick FROM leave_credits WHERE emp_id = ? AND year = ?
        ");
        $stmt->bind_param("ii", $empId, $year);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - No leave credits found, creating default\n", FILE_APPEND);
            $this->createDefaultLeaveCredits($empId, $year);
        } else {
            $currentCredits = $result->fetch_assoc();
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Current credits: " . json_encode($currentCredits) . "\n", FILE_APPEND);
        }
        
        if ($leaveType === 'Vacation Leave') {
            if ($action === 'deduct') {
                $stmt = $this->conn->prepare("
                    UPDATE leave_credits 
                    SET used_vacation = used_vacation + ? 
                    WHERE emp_id = ? AND year = ?
                ");
                file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Updating vacation: adding $daysRequested to used_vacation\n", FILE_APPEND);
            } else {
                $stmt = $this->conn->prepare("
                    UPDATE leave_credits 
                    SET used_vacation = GREATEST(0, used_vacation - ?) 
                    WHERE emp_id = ? AND year = ?
                ");
            }
            $stmt->bind_param("iii", $daysRequested, $empId, $year);
            if ($stmt->execute()) {
                file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Vacation update successful, affected rows: " . $stmt->affected_rows . "\n", FILE_APPEND);
            } else {
                file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Vacation update failed: " . $stmt->error . "\n", FILE_APPEND);
            }
        } elseif ($leaveType === 'Sick Leave') {
            if ($action === 'deduct') {
                $stmt = $this->conn->prepare("
                    UPDATE leave_credits 
                    SET used_sick = used_sick + ? 
                    WHERE emp_id = ? AND year = ?
                ");
                file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Updating sick: adding $daysRequested to used_sick\n", FILE_APPEND);
            } else {
                $stmt = $this->conn->prepare("
                    UPDATE leave_credits 
                    SET used_sick = GREATEST(0, used_sick - ?) 
                    WHERE emp_id = ? AND year = ?
                ");
            }
            $stmt->bind_param("iii", $daysRequested, $empId, $year);
            if ($stmt->execute()) {
                file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Sick update successful, affected rows: " . $stmt->affected_rows . "\n", FILE_APPEND);
            } else {
                file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Sick update failed: " . $stmt->error . "\n", FILE_APPEND);
            }
        }
        
    } catch (Exception $e) {
        file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - updateLeaveCredits Error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}
    
    // Update leave request status (HR)
   // Update leave request status (HR)
// Update leave request status (HR)
public function updateHRStatus($leaveId, $status, $comments = null, $hrId = null, $isPaid = 'No') {
    try {
        $validStatuses = ['Pending', 'Approved', 'Rejected'];
        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid status");
        }
        
        $stmt = $this->conn->prepare("
            UPDATE leave_requests 
            SET hr_status = ?, hr_comments = ?, hr_approved_by = ?, hr_approved_at = NOW(), is_paid = ?
            WHERE leave_id = ?
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->conn->error);
        }
        
        $stmt->bind_param("ssiss", $status, $comments, $hrId, $isPaid, $leaveId);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // If HR rejects, update overall status to rejected
            if ($status === 'Rejected') {
                $stmt = $this->conn->prepare("UPDATE leave_requests SET status = 'Rejected' WHERE leave_id = ?");
                $stmt->bind_param("s", $leaveId);
                $stmt->execute();
            }
            
            return [
                'success' => true,
                'message' => "Leave request {$status} by HR successfully"
            ];
        }
        
        throw new Exception("Failed to update leave request or no changes made");
        
    } catch (Exception $e) {
        file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - updateHRStatus Exception: " . $e->getMessage() . "\n", FILE_APPEND);
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

    // Update leave request status (Supervisor)
    // Update leave request status (Supervisor)
public function updateSupervisorStatus($leaveId, $status, $comments = null, $supervisorId = null) {
    try {
        $validStatuses = ['Pending', 'Approved', 'Rejected'];
        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid status");
        }
        
        // Get leave request details first
        $stmt = $this->conn->prepare("
            SELECT lr.emp_id, lr.leave_type, lr.days_requested, lr.hr_status, lr.supervisor_status, lr.start_date, lr.end_date, lr.is_paid
            FROM leave_requests lr
            WHERE lr.leave_id = ?
        ");
        $stmt->bind_param("s", $leaveId);
        $stmt->execute();
        $result = $stmt->get_result();
        $leaveData = $result->fetch_assoc();

        if (!$leaveData) {
            throw new Exception("Leave request not found");
        }

        if ($leaveData['hr_status'] !== 'Approved') {
            throw new Exception("Leave request must be approved by HR first");
        }

        $stmt = $this->conn->prepare("
            UPDATE leave_requests 
            SET supervisor_status = ?, supervisor_comments = ?, supervisor_approved_by = ?, supervisor_approved_at = NOW() 
            WHERE leave_id = ?
        ");

        if (!$stmt) {
            throw new Exception("Prepare failed: " . $this->conn->error);
        }

        $stmt->bind_param("ssis", $status, $comments, $supervisorId, $leaveId);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Update overall status based on both HR and supervisor approval
            if ($status === 'Approved') {
                // Both HR and supervisor approved - create attendance records and set final status
                $recordsCreated = $this->createLeaveAttendanceRecords(
                    $leaveData['emp_id'], 
                    $leaveData['start_date'], 
                    $leaveData['end_date'], 
                    $leaveData['is_paid'] ?? 'No'
                );
                
                file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Created $recordsCreated attendance records for leave period\n", FILE_APPEND);
                
                // Deduct leave credits and set final status
                $this->updateLeaveCredits($leaveData['emp_id'], $leaveData['leave_type'], $leaveData['days_requested'], 'deduct');
                $stmt = $this->conn->prepare("UPDATE leave_requests SET status = 'Approved' WHERE leave_id = ?");
                $stmt->bind_param("s", $leaveId);
                $stmt->execute();
            } else if ($status === 'Rejected') {
                // Supervisor rejected - set overall status to rejected
                $stmt = $this->conn->prepare("UPDATE leave_requests SET status = 'Rejected' WHERE leave_id = ?");
                $stmt->bind_param("s", $leaveId);
                $stmt->execute();
            }
                
            return [
                'success' => true,
                'message' => "Leave request {$status} by supervisor successfully"
            ];
        }
        
        throw new Exception("Failed to update leave request or no changes made");
        
    } catch (Exception $e) {
        file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - updateSupervisorStatus Exception: " . $e->getMessage() . "\n", FILE_APPEND);
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
    
    // Update leave request (Employee)
    public function updateLeaveRequest($leaveId, $data, $empId) {
        try {
            // Check if request belongs to employee and is still pending
            $stmt = $this->conn->prepare("SELECT hr_status, supervisor_status FROM leave_requests WHERE leave_id = ? AND emp_id = ?");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("si", $leaveId, $empId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if (!$result->num_rows) {
                throw new Exception("Leave request not found");
            }
            
            $request = $result->fetch_assoc();
            if ($request['hr_status'] !== 'Pending' || $request['supervisor_status'] !== 'Pending') {
                throw new Exception("Cannot modify a leave request that has been processed");
            }
            
            $daysRequested = $this->calculateBusinessDays($data['start_date'], $data['end_date']);
            
            // Check leave credits for vacation and sick leave
            if (in_array($data['leave_type'], ['Vacation Leave', 'Sick Leave'])) {
                $creditsCheck = $this->checkLeaveCredits($empId, $data['leave_type'], $daysRequested);
                if (!$creditsCheck['has_credits']) {
                    throw new Exception("Insufficient leave credits. Available: {$creditsCheck['available']}, Requested: {$creditsCheck['requested']}");
                }
            }
            
            $stmt = $this->conn->prepare("
                UPDATE leave_requests 
                SET leave_type = ?, start_date = ?, end_date = ?, reason = ?, 
                    days_requested = ?, updated_at = NOW()
                WHERE leave_id = ? AND emp_id = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("ssssisd", 
                $data['leave_type'], 
                $data['start_date'], 
                $data['end_date'], 
                $data['reason'], 
                $daysRequested, 
                $leaveId, 
                $empId
            );
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                return [
                    'success' => true,
                    'message' => 'Leave request updated successfully'
                ];
            }
            
            throw new Exception("Failed to update leave request or no changes made");
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - updateLeaveRequest Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Cancel leave request (Employee)
    public function cancelLeaveRequest($leaveId, $empId) {
        try {
            // Get current status first
            $stmt = $this->conn->prepare("
                SELECT hr_status, supervisor_status, status, emp_id, leave_type, days_requested
                FROM leave_requests 
                WHERE leave_id = ? AND emp_id = ?
            ");
            $stmt->bind_param("si", $leaveId, $empId);
            $stmt->execute();
            $result = $stmt->get_result();
            $leaveData = $result->fetch_assoc();
            
            if (!$leaveData) {
                throw new Exception("Leave request not found");
            }
            
            // If leave was fully approved, restore leave credits
            if ($leaveData['status'] === 'Approved') {
                $this->updateLeaveCredits($leaveData['emp_id'], $leaveData['leave_type'], $leaveData['days_requested'], 'restore');
            }
            
            $stmt = $this->conn->prepare("
                UPDATE leave_requests 
                SET status = 'Cancelled', hr_status = 'Cancelled', supervisor_status = 'Cancelled'
                WHERE leave_id = ? AND emp_id = ?
            ");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("si", $leaveId, $empId);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                return [
                    'success' => true,
                    'message' => 'Leave request cancelled successfully'
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Cannot cancel this leave request'
            ];
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - cancelLeaveRequest Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Leave Credits Management
    public function getLeaveCredits($empId = null, $year = null) {
        try {
            $year = $year ?: date('Y');
            
            $query = "
                SELECT ua.id as emp_id, ua.firstName, ua.lastName, el.position,
                       COALESCE(lc.vacation_credits, 15) as vacation_credits,
                       COALESCE(lc.sick_credits, 10) as sick_credits,
                       COALESCE(lc.used_vacation, 0) as used_vacation,
                       COALESCE(lc.used_sick, 0) as used_sick,
                       (COALESCE(lc.vacation_credits, 15) - COALESCE(lc.used_vacation, 0)) as remaining_vacation,
                       (COALESCE(lc.sick_credits, 10) - COALESCE(lc.used_sick, 0)) as remaining_sick,
                       COALESCE(lc.year, ?) as year
                FROM useraccounts ua
                LEFT JOIN employeelist el ON ua.id = el.emp_id
                LEFT JOIN leave_credits lc ON ua.id = lc.emp_id AND lc.year = ?
                WHERE ua.role = 'employee' AND ua.status = 'active'
            ";
            
            $params = [$year, $year];
            $types = "ii";
            
            if ($empId) {
                $query .= " AND ua.id = ?";
                $params[] = $empId;
                $types .= "i";
            }
            
            $query .= " ORDER BY ua.firstName, ua.lastName";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $credits = [];
            while ($row = $result->fetch_assoc()) {
                $credits[] = [
                    'id' => $row['emp_id'],
                    'emp_id' => $row['emp_id'],
                    'name' => $row['firstName'] . ' ' . $row['lastName'],
                    'position' => $row['position'],
                    'vacation_credits' => (int)$row['vacation_credits'],
                    'sick_credits' => (int)$row['sick_credits'],
                    'used_vacation' => (int)$row['used_vacation'],
                    'used_sick' => (int)$row['used_sick'],
                    'remaining_vacation' => (int)$row['remaining_vacation'],
                    'remaining_sick' => (int)$row['remaining_sick'],
                    'year' => (int)$row['year']
                ];
            }
            
            return [
                'success' => true,
                'data' => $credits
            ];
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - getLeaveCredits Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Create attendance records for leave period
private function createLeaveAttendanceRecords($empId, $startDate, $endDate, $isPaid) {
    try {
        $currentDate = new DateTime($startDate);
        $endDateObj = new DateTime($endDate);
        
        // Get employee info
        $stmt = $this->conn->prepare("
            SELECT firstName, lastName FROM useraccounts WHERE id = ?
        ");
        $stmt->bind_param("i", $empId);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        
        if (!$employee) {
            return false;
        }
        
        $recordsCreated = 0;
        
        while ($currentDate <= $endDateObj) {
            $dateStr = $currentDate->format('Y-m-d');
            
            // Check if attendance record already exists for this date
            $checkStmt = $this->conn->prepare("
                SELECT id FROM attendancelist WHERE emp_id = ? AND date = ?
            ");
            $checkStmt->bind_param("is", $empId, $dateStr);
            $checkStmt->execute();
            $existing = $checkStmt->get_result();
            
            if ($existing->num_rows == 0) {
                // Create attendance record
                if ($isPaid === 'Yes') {
                    // Paid leave - only on_leave = 1
                    $insertStmt = $this->conn->prepare("
                        INSERT INTO attendancelist 
                        (emp_id, firstName, lastName, date, status, present, absent, on_leave, late, late_minutes, total_workhours, overtime, undertime_minutes, late_undertime) 
                        VALUES (?, ?, ?, ?, 'On Leave', 0, 0, 1, 0, 0, 0, 0, 0, 0)
                    ");
                } else {
                    // Unpaid leave - on_leave = 1 and absent = 1
                    $insertStmt = $this->conn->prepare("
                        INSERT INTO attendancelist 
                        (emp_id, firstName, lastName, date, status, present, absent, on_leave, late, late_minutes, total_workhours, overtime, undertime_minutes, late_undertime) 
                        VALUES (?, ?, ?, ?, 'On Leave', 0, 1, 1, 0, 0, 0, 0, 0, 0)
                    ");
                }
                
                $insertStmt->bind_param("isss", $empId, $employee['firstName'], $employee['lastName'], $dateStr);
                
                if ($insertStmt->execute()) {
                    $recordsCreated++;
                }
            }
            
            $currentDate->modify('+1 day');
        }
        
        return $recordsCreated;
        
    } catch (Exception $e) {
        file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - createLeaveAttendanceRecords Error: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

    public function updateLeaveCreditsManual($empId, $vacationCredits, $sickCredits, $year = null) {
        try {
            $year = $year ?: date('Y');
            
            // Check if credits exist
            $stmt = $this->conn->prepare("
                SELECT id FROM leave_credits WHERE emp_id = ? AND year = ?
            ");
            $stmt->bind_param("ii", $empId, $year);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing and reset used credits
                $stmt = $this->conn->prepare("
                    UPDATE leave_credits 
                    SET vacation_credits = ?, sick_credits = ?, used_vacation = 0, used_sick = 0, updated_at = NOW()
                    WHERE emp_id = ? AND year = ?
                ");
                $stmt->bind_param("iiii", $vacationCredits, $sickCredits, $empId, $year);
            } else {
                // Create new
                $stmt = $this->conn->prepare("
                    INSERT INTO leave_credits (emp_id, vacation_credits, sick_credits, used_vacation, used_sick, year) 
                    VALUES (?, ?, ?, 0, 0, ?)
                ");
                $stmt->bind_param("iiii", $empId, $vacationCredits, $sickCredits, $year);
            }
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Leave credits updated and used credits reset successfully'
                ];
            }
            
            throw new Exception("Failed to update leave credits");
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - updateLeaveCreditsManual Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function resetLeaveCredits($empId, $year = null) {
        try {
            $year = $year ?: date('Y');
            
            $stmt = $this->conn->prepare("
                UPDATE leave_credits 
                SET used_vacation = 0, used_sick = 0, updated_at = NOW()
                WHERE emp_id = ? AND year = ?
            ");
            $stmt->bind_param("ii", $empId, $year);
            
            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => 'Leave credits reset successfully'
                ];
            }
            
            throw new Exception("Failed to reset leave credits");
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - resetLeaveCredits Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Get leave types
    public function getLeaveTypes() {
        try {
            $stmt = $this->conn->prepare("SELECT * FROM leave_types WHERE is_active = 1 ORDER BY type_name");
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $types = [];
            while ($row = $result->fetch_assoc()) {
                $types[] = $row;
            }
            
            return [
                'success' => true,
                'data' => $types
            ];
            
        } catch (Exception $e) {
            file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - getLeaveTypes Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Get initials from name
    private function getInitials($name) {
        $words = explode(' ', $name);
        $initials = '';
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= strtoupper($word[0]);
            }
        }
        return substr($initials, 0, 2);
    }
}

// Handle API requests
try {
    $leaveManagement = new LeaveManagement();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Method: $method, Action: $action\n", FILE_APPEND);

    switch ($method) {
        case 'GET':
            switch ($action) {
                case 'employee_requests':
                    $empId = $_GET['emp_id'] ?? null;
                    if (!$empId) {
                        echo json_encode(['success' => false, 'message' => 'Employee ID required']);
                        break;
                    }
                    echo json_encode($leaveManagement->getEmployeeLeaveRequests($empId));
                    break;
                    
                case 'all_requests':
                    $status = $_GET['status'] ?? null;
                    echo json_encode($leaveManagement->getAllLeaveRequests($status));
                    break;

                case 'supervisor_requests':
                    $supervisorId = $_GET['supervisor_id'] ?? null;
                    $status = $_GET['status'] ?? null;
                    if (!$supervisorId) {
                        echo json_encode(['success' => false, 'message' => 'Supervisor ID required']);
                        break;
                    }
                    echo json_encode($leaveManagement->getSupervisorLeaveRequests($supervisorId, $status));
                    break;
                    
                case 'request_details':
                    $leaveId = $_GET['leave_id'] ?? null;
                    $empId = $_GET['emp_id'] ?? null;
                    $role = $_GET['role'] ?? null;
                    if (!$leaveId) {
                        echo json_encode(['success' => false, 'message' => 'Leave ID required']);
                        break;
                    }
                    echo json_encode($leaveManagement->getLeaveRequestDetails($leaveId, $empId, $role));
                    break;
                    
                case 'leave_types':
                    echo json_encode($leaveManagement->getLeaveTypes());
                    break;

                case 'leave_credits':
                    $empId = $_GET['emp_id'] ?? null;
                    $year = $_GET['year'] ?? null;
                    echo json_encode($leaveManagement->getLeaveCredits($empId, $year));
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
            }
            break;
            
        case 'POST':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
                break;
            }
            
            switch ($action) {
                case 'submit':
                    echo json_encode($leaveManagement->submitLeaveRequest($data));
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
            }
            break;
            
        case 'PUT':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
                break;
            }
            
            switch ($action) {
                case 'update_hr_status':
                    $leaveId = $data['leave_id'] ?? null;
                    $status = $data['status'] ?? null;
                    $comments = $data['comments'] ?? null;
                    $hrId = $data['hr_id'] ?? null;
                    $isPaid = $data['is_paid'] ?? 'No';
                    
                    if (!$leaveId || !$status) {
                        echo json_encode(['success' => false, 'message' => 'Leave ID and status required']);
                        break;
                    }
                    
                    echo json_encode($leaveManagement->updateHRStatus($leaveId, $status, $comments, $hrId, $isPaid));
                    break;

                case 'update_supervisor_status':
                    $leaveId = $data['leave_id'] ?? null;
                    $status = $data['status'] ?? null;
                    $comments = $data['comments'] ?? null;
                    $supervisorId = $data['supervisor_id'] ?? null;
                    
                    if (!$leaveId || !$status) {
                        echo json_encode(['success' => false, 'message' => 'Leave ID and status required']);
                        break;
                    }
                    
                    echo json_encode($leaveManagement->updateSupervisorStatus($leaveId, $status, $comments, $supervisorId));
                    break;
                    
                case 'update_request':
                    $leaveId = $data['leave_id'] ?? null;
                    $empId = $data['emp_id'] ?? null;
                    
                    if (!$leaveId || !$empId) {
                        echo json_encode(['success' => false, 'message' => 'Leave ID and Employee ID required']);
                        break;
                    }
                    
                    echo json_encode($leaveManagement->updateLeaveRequest($leaveId, $data, $empId));
                    break;
                    
                case 'cancel':
                    $leaveId = $data['leave_id'] ?? null;
                    $empId = $data['emp_id'] ?? null;
                    
                    if (!$leaveId || !$empId) {
                        echo json_encode(['success' => false, 'message' => 'Leave ID and Employee ID required']);
                        break;
                    }
                    
                    echo json_encode($leaveManagement->cancelLeaveRequest($leaveId, $empId));
                    break;

                    case 'update_leave_credits':
                        $empId = $data['emp_id'] ?? null;
                        $vacationCredits = $data['vacation_credits'] ?? null;
                        $sickCredits = $data['sick_credits'] ?? null;
                        $year = $data['year'] ?? null;
                        
                        if (!$empId || $vacationCredits === null || $sickCredits === null) {
                            echo json_encode(['success' => false, 'message' => 'Employee ID, vacation credits, and sick credits required']);
                            break;
                        }
                        
                        echo json_encode($leaveManagement->updateLeaveCreditsManual($empId, $vacationCredits, $sickCredits, $year));
                        break;

                case 'reset_leave_credits':
                    $empId = $data['emp_id'] ?? null;
                    $year = $data['year'] ?? null;
                    
                    if (!$empId) {
                        echo json_encode(['success' => false, 'message' => 'Employee ID required']);
                        break;
                    }
                    
                    echo json_encode($leaveManagement->resetLeaveCredits($empId, $year));
                    break;
                    
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Method not allowed: ' . $method]);
    }
    
} catch (Exception $e) {
    file_put_contents('api_debug.log', date('Y-m-d H:i:s') . " - Main Exception: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>