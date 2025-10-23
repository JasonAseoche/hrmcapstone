<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connection.php';

class ServiceApprovalAPI {
    private $conn;

    public function __construct($connection) {
        $this->conn = $connection;
    }

    // Get all reports from both employee and supervisor sources
    public function getAllReports($filters = []) {
        try {
            $reports = [];
            
            // Get employee reports
            $employeeQuery = "SELECT 
                                sr.id as report_id,
                                sr.employee_id,
                                sr.report_type,
                                sr.title,
                                sr.date_submitted,
                                sr.status,
                                sr.reviewed_by,
                                sr.date_reviewed,
                                CONCAT(e.firstName, ' ', e.lastName) as submitted_by,
                                e.position,
                                'employee' as source
                            FROM service_reports sr
                            LEFT JOIN employeelist e ON sr.employee_id = e.emp_id
                            WHERE 1=1";
            
            // Get supervisor reports
            $supervisorQuery = "SELECT 
                                tr.id as report_id,
                                tr.supervisor_id,
                                tr.report_type,
                                tr.report_title as title,
                                tr.date_submitted,
                                tr.status,
                                tr.reviewed_by,
                                tr.date_reviewed,
                                CONCAT(s.firstName, ' ', s.lastName) as submitted_by,
                                s.position,
                                'supervisor' as source
                            FROM team_ob_reports tr
                            LEFT JOIN supervisorlist s ON tr.supervisor_id = s.sup_id
                            WHERE 1=1";
            
            // Apply filters if provided
            if (isset($filters['status']) && !empty($filters['status'])) {
                $employeeQuery .= " AND sr.status = ?";
                $supervisorQuery .= " AND tr.status = ?";
            }
            
            if (isset($filters['start_date']) && !empty($filters['start_date'])) {
                $employeeQuery .= " AND DATE(sr.date_submitted) >= ?";
                $supervisorQuery .= " AND DATE(tr.date_submitted) >= ?";
            }
            
            if (isset($filters['end_date']) && !empty($filters['end_date'])) {
                $employeeQuery .= " AND DATE(sr.date_submitted) <= ?";
                $supervisorQuery .= " AND DATE(tr.date_submitted) <= ?";
            }
            
            $employeeQuery .= " ORDER BY sr.date_submitted DESC";
            $supervisorQuery .= " ORDER BY tr.date_submitted DESC";
            
            // Execute employee query
            $stmt = $this->conn->prepare($employeeQuery);
            if (!$stmt) {
                throw new Exception("Employee prepare failed: " . $this->conn->error);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $reports[] = $row;
            }
            
            // Execute supervisor query
            $stmt2 = $this->conn->prepare($supervisorQuery);
            if (!$stmt2) {
                throw new Exception("Supervisor prepare failed: " . $this->conn->error);
            }
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            while ($row = $result2->fetch_assoc()) {
                $reports[] = $row;
            }
            
            // Sort by date submitted
            usort($reports, function($a, $b) {
                return strtotime($b['date_submitted']) - strtotime($a['date_submitted']);
            });
            
            return [
                'success' => true,
                'reports' => $reports
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching reports: ' . $e->getMessage()
            ];
        }
    }

    // Get detailed report information
    public function getReportDetails($report_id, $source) {
        try {
            if (empty($report_id) || empty($source)) {
                throw new Exception("Report ID and source are required");
            }
            
            if ($source === 'employee') {
                // Get employee report details
                $sql = "SELECT 
                            sr.*,
                            CONCAT(e.firstName, ' ', e.lastName) as submitted_by,
                            CONCAT(e.firstName, ' ', e.lastName) as employee_name,
                            e.position
                        FROM service_reports sr
                        LEFT JOIN employeelist e ON sr.employee_id = e.emp_id
                        WHERE sr.id = ?";
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $this->conn->error);
                }
                
                $stmt->bind_param("i", $report_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    return [
                        'success' => false,
                        'message' => 'Report not found'
                    ];
                }
                
                $report = $result->fetch_assoc();
                
                // If it's an OB Form, get the entries with total_hours
                if ($report['report_type'] === 'OB Form') {
                    $entry_sql = "SELECT *, total_hours FROM ob_form_entries 
                                 WHERE report_id = ? 
                                 ORDER BY entry_order ASC";
                    
                    $entry_stmt = $this->conn->prepare($entry_sql);
                    $entry_stmt->bind_param("i", $report_id);
                    $entry_stmt->execute();
                    $entry_result = $entry_stmt->get_result();
                    
                    $entries = [];
                    $totalHours = 0;
                    while ($entry = $entry_result->fetch_assoc()) {
                        $entries[] = $entry;
                        if ($entry['total_hours']) {
                            $totalHours += $entry['total_hours'];
                        }
                    }
                    
                    $report['ob_entries'] = $entries;
                    $report['calculated_total_hours'] = $totalHours;
                }
                
            } else {
                // Get supervisor report details
                $sql = "SELECT 
                            tr.*,
                            tr.report_title as title,
                            CONCAT(s.firstName, ' ', s.lastName) as submitted_by,
                            s.position
                        FROM team_ob_reports tr
                        LEFT JOIN supervisorlist s ON tr.supervisor_id = s.sup_id
                        WHERE tr.id = ?";
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $this->conn->error);
                }
                
                $stmt->bind_param("i", $report_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    return [
                        'success' => false,
                        'message' => 'Report not found'
                    ];
                }
                
                $report = $result->fetch_assoc();
                
                // If it's an OB Form, get the activities
                if ($report['report_type'] === 'OB Form') {
                    $activity_sql = "SELECT 
                                        entry_group,
                                        GROUP_CONCAT(employee_name SEPARATOR ', ') as employee_name,
                                        designation,
                                        assign_task,
                                        time_duration,
                                        remarks
                                     FROM team_activity_entries 
                                     WHERE report_id = ? 
                                     GROUP BY entry_group, designation, assign_task, time_duration, remarks
                                     ORDER BY entry_group ASC";
                    
                    $act_stmt = $this->conn->prepare($activity_sql);
                    $act_stmt->bind_param("i", $report_id);
                    $act_stmt->execute();
                    $act_result = $act_stmt->get_result();
                    
                    $activities = [];
                    while ($activity = $act_result->fetch_assoc()) {
                        $activities[] = $activity;
                    }
                    $report['activities'] = $activities;
                }
            }
            
            return [
                'success' => true,
                'report' => $report
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching report details: ' . $e->getMessage()
            ];
        }
    }

    // Approve a report
    public function approveReport($data) {
        try {
            if (!isset($data['report_id']) || empty($data['report_id'])) {
                throw new Exception("Report ID is required");
            }
            
            if (!isset($data['source']) || empty($data['source'])) {
                throw new Exception("Source is required");
            }
            
            if (!isset($data['reviewer_id']) || empty($data['reviewer_id'])) {
                throw new Exception("Reviewer ID is required");
            }
            
            $report_id = intval($data['report_id']);
            $source = $data['source'];
            $reviewer_id = intval($data['reviewer_id']);
            
            $this->conn->begin_transaction();
            
            if ($source === 'employee') {
                // For employee reports, update hours if provided
                $google_hours = isset($data['google_hours']) ? $data['google_hours'] : null;
                $total_hours = isset($data['total_hours']) ? intval($data['total_hours']) : null;
                
                // Update service_reports table
                $sql = "UPDATE service_reports 
                        SET status = 'Approved', 
                            reviewed_by = ?, 
                            date_reviewed = NOW(),
                            google_hours = ?,
                            total_hours = ?
                        WHERE id = ?";
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $this->conn->error);
                }
                
                $stmt->bind_param("isii", $reviewer_id, $google_hours, $total_hours, $report_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
                
                // Update total_hours in ob_form_entries if total_hours is provided
                if ($total_hours !== null) {
                    // Get all entries for this report
                    $entry_sql = "SELECT id, departure_time, arrival_time FROM ob_form_entries WHERE report_id = ?";
                    $entry_stmt = $this->conn->prepare($entry_sql);
                    $entry_stmt->bind_param("i", $report_id);
                    $entry_stmt->execute();
                    $entry_result = $entry_stmt->get_result();
                    
                    // Calculate hours for each entry
                    while ($entry = $entry_result->fetch_assoc()) {
                        $departure = $entry['departure_time'];
                        $arrival = $entry['arrival_time'];
                        
                        if ($departure && $arrival) {
                            list($dep_hour, $dep_min) = explode(':', $departure);
                            list($arr_hour, $arr_min) = explode(':', $arrival);
                            
                            $total_minutes = (intval($arr_hour) * 60 + intval($arr_min)) - 
                                           (intval($dep_hour) * 60 + intval($dep_min));
                            
                            if ($total_minutes < 0) {
                                $total_minutes += 24 * 60; // Handle overnight
                            }
                            
                            $hours_only = floor($total_minutes / 60); // Minutes excluded
                            
                            // Update the entry with calculated hours
                            $update_sql = "UPDATE ob_form_entries SET total_hours = ? WHERE id = ?";
                            $update_stmt = $this->conn->prepare($update_sql);
                            $update_stmt->bind_param("ii", $hours_only, $entry['id']);
                            $update_stmt->execute();
                        }
                    }
                }
                
            } else {
                // For supervisor reports
                $sql = "UPDATE team_ob_reports 
                        SET status = 'Approved', 
                            reviewed_by = ?, 
                            date_reviewed = NOW()
                        WHERE id = ?";
                
                $stmt = $this->conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $this->conn->error);
                }
                
                $stmt->bind_param("ii", $reviewer_id, $report_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed: " . $stmt->error);
                }
            }
            
            $this->conn->commit();

            // Insert travel time to attendancelist
if ($source === 'employee' && $total_hours !== null && $total_hours > 0) {
    // Get employee ID and OB dates
    $emp_sql = "SELECT employee_id FROM service_reports WHERE id = ?";
    $emp_stmt = $this->conn->prepare($emp_sql);
    $emp_stmt->bind_param("i", $report_id);
    $emp_stmt->execute();
    $emp_result = $emp_stmt->get_result();
    $emp_data = $emp_result->fetch_assoc();
    $employee_id = $emp_data['employee_id'];
    
    // Get all OB entry dates for this report
    $dates_sql = "SELECT DISTINCT ob_date FROM ob_form_entries WHERE report_id = ?";
    $dates_stmt = $this->conn->prepare($dates_sql);
    $dates_stmt->bind_param("i", $report_id);
    $dates_stmt->execute();
    $dates_result = $dates_stmt->get_result();
    
    $ob_dates = [];
    while ($date_row = $dates_result->fetch_assoc()) {
        $ob_dates[] = $date_row['ob_date'];
    }
    
    // Distribute travel time evenly across all OB dates
    $hours_per_date = $total_hours / count($ob_dates);
    
    foreach ($ob_dates as $ob_date) {
        // Check if attendance record exists
        $check_sql = "SELECT id, travel_time FROM attendancelist 
                     WHERE emp_id = ? AND date = ?";
        $check_stmt = $this->conn->prepare($check_sql);
        $check_stmt->bind_param("is", $employee_id, $ob_date);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing record - add to existing travel_time
            $existing = $check_result->fetch_assoc();
            $new_travel_time = $existing['travel_time'] + $hours_per_date;
            
            $update_sql = "UPDATE attendancelist 
                          SET travel_time = ? 
                          WHERE id = ?";
            $update_stmt = $this->conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $new_travel_time, $existing['id']);
            $update_stmt->execute();
        } else {
            // Create new attendance record
            $emp_info_sql = "SELECT firstName, lastName FROM employeelist WHERE emp_id = ?";
            $emp_info_stmt = $this->conn->prepare($emp_info_sql);
            $emp_info_stmt->bind_param("i", $employee_id);
            $emp_info_stmt->execute();
            $emp_info_result = $emp_info_stmt->get_result();
            $emp_info = $emp_info_result->fetch_assoc();
            
            $insert_sql = "INSERT INTO attendancelist 
                          (emp_id, date, firstName, lastName, travel_time) 
                          VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = $this->conn->prepare($insert_sql);
            $insert_stmt->bind_param("isssi", 
                $employee_id, 
                $ob_date, 
                $emp_info['firstName'], 
                $emp_info['lastName'], 
                $hours_per_date
            );
            $insert_stmt->execute();
        }
    }
}
            
            return [
                'success' => true,
                'message' => 'Report approved successfully'
            ];
            
        } catch (Exception $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Reject a report
    public function rejectReport($data) {
        try {
            if (!isset($data['report_id']) || empty($data['report_id'])) {
                throw new Exception("Report ID is required");
            }
            
            if (!isset($data['source']) || empty($data['source'])) {
                throw new Exception("Source is required");
            }
            
            if (!isset($data['reviewer_id']) || empty($data['reviewer_id'])) {
                throw new Exception("Reviewer ID is required");
            }
            
            if (!isset($data['remarks']) || empty($data['remarks'])) {
                throw new Exception("Remarks are required for rejection");
            }
            
            $report_id = intval($data['report_id']);
            $source = $data['source'];
            $reviewer_id = intval($data['reviewer_id']);
            $remarks = $data['remarks'];
            
            if ($source === 'employee') {
                $sql = "UPDATE service_reports 
                        SET status = 'Rejected', 
                            reviewed_by = ?, 
                            remarks = ?,
                            date_reviewed = NOW()
                        WHERE id = ?";
            } else {
                $sql = "UPDATE team_ob_reports 
                        SET status = 'Rejected', 
                            reviewed_by = ?, 
                            remarks = ?,
                            date_reviewed = NOW()
                        WHERE id = ?";
            }
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("isi", $reviewer_id, $remarks, $report_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            return [
                'success' => true,
                'message' => 'Report rejected successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

// Initialize API
try {
    if (!isset($conn)) {
        throw new Exception("Database connection not established");
    }
    
    $api = new ServiceApprovalAPI($conn);
    
    // Handle requests
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if (empty($action)) {
        throw new Exception("No action specified");
    }
    
    switch ($action) {
        case 'getAllReports':
            $filters = $_GET;
            unset($filters['action']);
            echo json_encode($api->getAllReports($filters));
            break;
            
        case 'getReportDetails':
            $report_id = $_GET['report_id'] ?? 0;
            $source = $_GET['source'] ?? '';
            echo json_encode($api->getReportDetails($report_id, $source));
            break;
            
        case 'approveReport':
            $data = $_POST;
            echo json_encode($api->approveReport($data));
            break;
            
        case 'rejectReport':
            $data = $_POST;
            echo json_encode($api->rejectReport($data));
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => 'Invalid action: ' . $action
            ]);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>