<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connection.php';

class TeamOBAPI {
    private $conn;
    private $upload_dir = 'uploads/team_reports/';

    public function __construct($connection) {
        $this->conn = $connection;
        
        if (!file_exists($this->upload_dir)) {
            mkdir($this->upload_dir, 0777, true);
        }
    }

    public function getSupervisorInfo($sup_id) {
        try {
            $sql = "SELECT sup_id, firstName, lastName, position, email 
                    FROM supervisorlist 
                    WHERE sup_id = ? AND status = 'active'";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $sup_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                return [
                    'success' => true,
                    'supervisor' => $result->fetch_assoc()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Supervisor not found'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error fetching supervisor info: ' . $e->getMessage()
            ];
        }
    }

    public function getSupervisorReports($supervisor_id) {
        try {
            if (empty($supervisor_id)) {
                throw new Exception("Supervisor ID is required");
            }
            
            $sql = "SELECT 
                        tr.id,
                        tr.supervisor_id,
                        tr.report_title as title,
                        tr.report_type as type,
                        tr.description,
                        tr.file_path,
                        tr.file_name,
                        tr.file_size,
                        tr.status,
                        tr.date_submitted,
                        tr.date_reviewed,
                        tr.reviewed_by,
                        tr.remarks,
                        tr.created_at
                    FROM team_ob_reports tr
                    WHERE tr.supervisor_id = ?
                    ORDER BY tr.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $supervisor_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $reports = [];
            while ($row = $result->fetch_assoc()) {
                // If it's an OB Form, get the activities
                if ($row['type'] === 'OB Form') {
                    $activity_sql = "SELECT 
                                    entry_group,
                                    employee_name,
                                    designation,
                                    assign_task,
                                    time_duration,
                                    remarks
                                FROM team_activity_entries 
                                WHERE report_id = ? 
                                ORDER BY entry_group ASC, employee_name ASC";
                    
                    $act_stmt = $this->conn->prepare($activity_sql);
                    $act_stmt->bind_param("i", $row['id']);
                    $act_stmt->execute();
                    $act_result = $act_stmt->get_result();
                    
                    $activities = [];
                    while ($activity = $act_result->fetch_assoc()) {
                        $activities[] = $activity;
                    }
                    $row['activities'] = $activities;
                }
                
                $reports[] = $row;
            }
            
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

    public function submitTeamOB($data) {
        try {
            if (!isset($data['supervisor_id']) || empty($data['supervisor_id'])) {
                throw new Exception("Supervisor ID is required");
            }
            
            if (!isset($data['title']) || empty($data['title'])) {
                throw new Exception("Report title is required");
            }
            
            if (!isset($data['entries']) || empty($data['entries'])) {
                throw new Exception("Activity entries are required");
            }
            
            $supervisor_id = intval($data['supervisor_id']);
            $title = $data['title'];
            $entries = json_decode($data['entries'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in entries: " . json_last_error_msg());
            }
            
            if (empty($entries) || !is_array($entries)) {
                throw new Exception("Entries must be a non-empty array");
            }
            
            $this->conn->begin_transaction();
            
            $sql = "INSERT INTO team_ob_reports 
                    (supervisor_id, report_title, report_type, date_submitted) 
                    VALUES (?, ?, 'OB Form', NOW())";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("is", $supervisor_id, $title);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $report_id = $this->conn->insert_id;
            
            $entry_sql = "INSERT INTO team_activity_entries 
                         (report_id, employee_id, employee_name, designation, 
                          assign_task, time_duration, remarks, entry_group) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            $entry_stmt = $this->conn->prepare($entry_sql);
            
            if (!$entry_stmt) {
                throw new Exception("Entry prepare failed: " . $this->conn->error);
            }
            
            $entry_group = 1;
            foreach ($entries as $entry) {
                if (!isset($entry['employee_ids']) || !is_array($entry['employee_ids']) || 
                    empty($entry['employee_ids'])) {
                    throw new Exception("Employee IDs are required for each entry");
                }
                
                if (!isset($entry['designation']) || !isset($entry['assign_task']) || 
                    !isset($entry['time_duration'])) {
                    throw new Exception("Missing required fields in entry");
                }
                
                $employee_ids = $entry['employee_ids'];
                $placeholders = implode(',', array_fill(0, count($employee_ids), '?'));
                $name_sql = "SELECT emp_id, CONCAT(firstName, ' ', lastName) as name 
                            FROM employeelist 
                            WHERE emp_id IN ($placeholders)";
                
                $name_stmt = $this->conn->prepare($name_sql);
                $types = str_repeat('i', count($employee_ids));
                $name_stmt->bind_param($types, ...$employee_ids);
                $name_stmt->execute();
                $name_result = $name_stmt->get_result();
                
                while ($emp = $name_result->fetch_assoc()) {
                    $remarks = isset($entry['remarks']) ? $entry['remarks'] : null;
                    
                    $entry_stmt->bind_param(
                        "iisssssi",
                        $report_id,
                        $emp['emp_id'],
                        $emp['name'],
                        $entry['designation'],
                        $entry['assign_task'],
                        $entry['time_duration'],
                        $remarks,
                        $entry_group
                    );
                    
                    if (!$entry_stmt->execute()) {
                        throw new Exception("Entry execute failed: " . $entry_stmt->error);
                    }
                }
                
                $entry_group++;
            }
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Team OB Report submitted successfully',
                'report_id' => $report_id,
                'total_entries' => count($entries)
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function attachReport($data, $file) {
        try {
            if (!isset($data['supervisor_id']) || empty($data['supervisor_id'])) {
                throw new Exception("Supervisor ID is required");
            }
            
            if (!isset($data['title']) || empty($data['title'])) {
                throw new Exception("Title is required");
            }
            
            if (!isset($data['description']) || empty($data['description'])) {
                throw new Exception("Description is required");
            }
            
            if (!isset($file) || empty($file)) {
                throw new Exception("File attachment is required");
            }
            
            $supervisor_id = intval($data['supervisor_id']);
            $title = $data['title'];
            $description = $data['description'];
            
            $file_info = $this->handleFileUpload($file, $supervisor_id);
            
            if (!$file_info['success']) {
                throw new Exception($file_info['message']);
            }
            
            $sql = "INSERT INTO team_ob_reports 
                    (supervisor_id, report_title, report_type, description, file_path, file_name, 
                     file_size, date_submitted) 
                    VALUES (?, ?, 'Service Report', ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param(
                "issssi",
                $supervisor_id,
                $title,
                $description,
                $file_info['file_path'],
                $file_info['file_name'],
                $file_info['file_size']
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $report_id = $this->conn->insert_id;
            
            return [
                'success' => true,
                'message' => 'Service report attached successfully',
                'report_id' => $report_id
            ];
            
        } catch (Exception $e) {
            if (isset($file_info['file_path']) && file_exists($file_info['file_path'])) {
                unlink($file_info['file_path']);
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function handleFileUpload($file, $supervisor_id) {
        try {
            $allowed_types = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            
            $max_size = 5 * 1024 * 1024;
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'File upload error: ' . $file['error']];
            }
            
            if ($file['size'] > $max_size) {
                return ['success' => false, 'message' => 'File size exceeds 5MB limit'];
            }
            
            if (!in_array($file['type'], $allowed_types)) {
                return ['success' => false, 'message' => 'File type not allowed: ' . $file['type']];
            }
            
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $unique_name = 'team_report_' . $supervisor_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
            $file_path = $this->upload_dir . $unique_name;
            
            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                return ['success' => false, 'message' => 'Failed to move uploaded file'];
            }
            
            return [
                'success' => true,
                'file_path' => $file_path,
                'file_name' => $file['name'],
                'file_size' => $file['size']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'File upload error: ' . $e->getMessage()
            ];
        }
    }

    public function getReportDetails($report_id) {
        try {
            if (empty($report_id)) {
                throw new Exception("Report ID is required");
            }
            
            $sql = "SELECT * FROM team_ob_reports WHERE id = ?";
            
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
            
            // Group activities by entry_group
            $entry_sql = "SELECT 
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
            
            $entry_stmt = $this->conn->prepare($entry_sql);
            $entry_stmt->bind_param("i", $report_id);
            $entry_stmt->execute();
            $entry_result = $entry_stmt->get_result();
            
            $activities = [];
            while ($entry = $entry_result->fetch_assoc()) {
                $activities[] = $entry;
            }
            
            $report['activities'] = $activities;
            
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

    public function deleteReport($report_id) {
        try {
            if (empty($report_id)) {
                throw new Exception("Report ID is required");
            }
            
            $this->conn->begin_transaction();
            
            $delete_entries = "DELETE FROM team_activity_entries WHERE report_id = ?";
            $entry_stmt = $this->conn->prepare($delete_entries);
            
            if (!$entry_stmt) {
                throw new Exception("Entry prepare failed: " . $this->conn->error);
            }
            
            $entry_stmt->bind_param("i", $report_id);
            $entry_stmt->execute();
            
            $delete_report = "DELETE FROM team_ob_reports WHERE id = ?";
            $report_stmt = $this->conn->prepare($delete_report);
            
            if (!$report_stmt) {
                throw new Exception("Report prepare failed: " . $this->conn->error);
            }
            
            $report_stmt->bind_param("i", $report_id);
            
            if (!$report_stmt->execute()) {
                throw new Exception("Report execute failed: " . $report_stmt->error);
            }
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Report deleted successfully'
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

try {
    if (!isset($conn)) {
        throw new Exception("Database connection not established");
    }
    
    $api = new TeamOBAPI($conn);
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if (empty($action)) {
        throw new Exception("No action specified");
    }
    
    switch ($action) {
        case 'getSupervisorByEmpId':
            $emp_id = $_GET['emp_id'] ?? 0;
            
            // Strategy 1: Check if emp_id matches sup_id directly
            $sql = "SELECT sup_id, firstName, lastName, position, email 
                    FROM supervisorlist 
                    WHERE sup_id = ? AND status = 'active'";
            
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Prepare failed: ' . $conn->error
                ]);
                break;
            }
            
            $stmt->bind_param("i", $emp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                echo json_encode([
                    'success' => true,
                    'supervisor' => $result->fetch_assoc()
                ]);
            } else {
                // Strategy 2: Try to find supervisor by matching email or other field
                $sql2 = "SELECT s.sup_id, s.firstName, s.lastName, s.position, s.email 
                            FROM supervisorlist s
                            INNER JOIN employeelist e ON s.email = e.email
                            WHERE e.emp_id = ? AND s.status = 'active'";
                
                $stmt2 = $conn->prepare($sql2);
                
                if ($stmt2) {
                    $stmt2->bind_param("i", $emp_id);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    
                    if ($result2->num_rows > 0) {
                        echo json_encode([
                            'success' => true,
                            'supervisor' => $result2->fetch_assoc()
                        ]);
                    } else {
                        echo json_encode([
                            'success' => false,
                            'message' => 'Supervisor not found. emp_id: ' . $emp_id
                        ]);
                    }
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Supervisor not found for this employee ID'
                    ]);
                }
            }
            break;
            
        case 'getSupervisorInfo':
            $sup_id = $_GET['sup_id'] ?? 0;
            echo json_encode($api->getSupervisorInfo($sup_id));
            break;
            
        case 'getSupervisorReports':
            $supervisor_id = $_GET['supervisor_id'] ?? 0;
            echo json_encode($api->getSupervisorReports($supervisor_id));
            break;
            
        case 'submitTeamOB':
            echo json_encode($api->submitTeamOB($_POST));
            break;
            
        case 'attachReport':
            if (!isset($_FILES['attachment'])) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No file attachment provided'
                ]);
            } else {
                echo json_encode($api->attachReport($_POST, $_FILES['attachment']));
            }
            break;
            
        case 'getReportDetails':
            $report_id = $_GET['report_id'] ?? 0;
            echo json_encode($api->getReportDetails($report_id));
            break;
            
        case 'deleteReport':
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid JSON input: ' . json_last_error_msg()
                ]);
            } else {
                $report_id = $input['report_id'] ?? 0;
                echo json_encode($api->deleteReport($report_id));
            }
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