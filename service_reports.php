<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connection.php';

class ServiceReportAPI {
    private $conn;
    private $upload_dir = 'uploads/service_reports/';

    public function __construct($connection) {
        $this->conn = $connection;
        
        // Create upload directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            mkdir($this->upload_dir, 0777, true);
        }
    }

    // Get all reports for a specific employee
    public function getUserReports($employee_id) {
        try {
            $sql = "SELECT 
                        sr.id,
                        sr.employee_id,
                        sr.report_type as type,
                        sr.title,
                        sr.description,
                        sr.file_path,
                        sr.file_name,
                        sr.file_size,
                        sr.status,
                        sr.date_submitted,
                        sr.date_reviewed,
                        sr.reviewed_by,
                        sr.remarks,
                        sr.created_at
                    FROM service_reports sr
                    WHERE sr.employee_id = ?
                    ORDER BY sr.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $reports = [];
            while ($row = $result->fetch_assoc()) {
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

    // Submit OB Form
    public function submitOBForm($data) {
        try {
            // Validate required fields
            if (!isset($data['employee_id']) || empty($data['employee_id'])) {
                throw new Exception("Employee ID is required");
            }
            
            if (!isset($data['ob_entries']) || empty($data['ob_entries'])) {
                throw new Exception("OB entries are required");
            }
            
            $employee_id = intval($data['employee_id']);
            $ob_entries = json_decode($data['ob_entries'], true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON in ob_entries: " . json_last_error_msg());
            }
            
            if (empty($ob_entries) || !is_array($ob_entries)) {
                throw new Exception("OB entries must be a non-empty array");
            }
            
            // Start transaction
            $this->conn->begin_transaction();
            
            // Insert one main service report record for all entries
            $sql = "INSERT INTO service_reports 
                    (employee_id, report_type, title, description, status, date_submitted) 
                    VALUES (?, 'OB Form', ?, ?, 'Pending', NOW())";
            
            $title = "Official Business Form - " . date('Y-m-d');
            $description = "OB Form with " . count($ob_entries) . " entries";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("iss", $employee_id, $title, $description);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            $report_id = $this->conn->insert_id;
            
            // Insert OB form entries
            $entry_sql = "INSERT INTO ob_form_entries 
                         (report_id, employee_id, ob_date, destination_from, destination_to, 
                          departure_time, arrival_time, purpose, entry_order) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $entry_stmt = $this->conn->prepare($entry_sql);
            
            if (!$entry_stmt) {
                throw new Exception("Entry prepare failed: " . $this->conn->error);
            }
            
            foreach ($ob_entries as $index => $entry) {
                // Validate each entry
                if (!isset($entry['date']) || !isset($entry['destinationFrom']) || 
                    !isset($entry['destinationTo']) || !isset($entry['departure']) || 
                    !isset($entry['arrival']) || !isset($entry['purpose'])) {
                    throw new Exception("Missing required fields in entry " . ($index + 1));
                }
                
                $entry_order = $index + 1;
                // Type string: i = integer, s = string
                // 9 parameters: report_id(i), employee_id(i), date(s), from(s), to(s), departure(s), arrival(s), purpose(s), order(i)
                $entry_stmt->bind_param(
                    "iissssssi",
                    $report_id,
                    $employee_id,
                    $entry['date'],
                    $entry['destinationFrom'],
                    $entry['destinationTo'],
                    $entry['departure'],
                    $entry['arrival'],
                    $entry['purpose'],
                    $entry_order
                );
                
                if (!$entry_stmt->execute()) {
                    throw new Exception("Entry execute failed: " . $entry_stmt->error);
                }
            }
            
            // Commit transaction
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'OB Form submitted successfully',
                'report_id' => $report_id,
                'total_entries' => count($ob_entries)
            ];
            
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($this->conn->inTransaction()) {
                $this->conn->rollback();
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Attach Service Report with file
    public function attachReport($data, $file) {
        try {
            // Validate required fields
            if (!isset($data['employee_id']) || empty($data['employee_id'])) {
                throw new Exception("Employee ID is required");
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
            
            $employee_id = intval($data['employee_id']);
            $title = $data['title'];
            $description = $data['description'];
            
            // Handle file upload
            $file_info = $this->handleFileUpload($file, $employee_id);
            
            if (!$file_info['success']) {
                throw new Exception($file_info['message']);
            }
            
            // Insert service report record
            $sql = "INSERT INTO service_reports 
                    (employee_id, report_type, title, description, file_path, file_name, 
                     file_size, status, date_submitted) 
                    VALUES (?, 'Service Report', ?, ?, ?, ?, ?, 'Pending', NOW())";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param(
                "issssi",
                $employee_id,
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
            // Delete uploaded file if database insert fails
            if (isset($file_info['file_path']) && file_exists($file_info['file_path'])) {
                unlink($file_info['file_path']);
            }
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Handle file upload
    private function handleFileUpload($file, $employee_id) {
        try {
            $allowed_types = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'File upload error: ' . $file['error']];
            }
            
            if ($file['size'] > $max_size) {
                return ['success' => false, 'message' => 'File size exceeds 5MB limit'];
            }
            
            if (!in_array($file['type'], $allowed_types)) {
                return ['success' => false, 'message' => 'File type not allowed: ' . $file['type']];
            }
            
            // Generate unique filename
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $unique_name = 'report_' . $employee_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
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

    // Get report details by ID
    public function getReportDetails($report_id) {
        try {
            if (empty($report_id)) {
                throw new Exception("Report ID is required");
            }
            
            $sql = "SELECT sr.*
                    FROM service_reports sr
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
            
            // If it's an OB Form, get the entries
            if ($report['report_type'] === 'OB Form') {
                $entry_sql = "SELECT * FROM ob_form_entries 
                             WHERE report_id = ? 
                             ORDER BY entry_order ASC";
                
                $entry_stmt = $this->conn->prepare($entry_sql);
                $entry_stmt->bind_param("i", $report_id);
                $entry_stmt->execute();
                $entry_result = $entry_stmt->get_result();
                
                $entries = [];
                while ($entry = $entry_result->fetch_assoc()) {
                    $entries[] = $entry;
                }
                
                $report['ob_entries'] = $entries;
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

    // Update report status (for HR/Admin)
    public function updateReportStatus($data) {
        try {
            if (!isset($data['report_id']) || empty($data['report_id'])) {
                throw new Exception("Report ID is required");
            }
            
            if (!isset($data['status']) || empty($data['status'])) {
                throw new Exception("Status is required");
            }
            
            if (!isset($data['reviewed_by']) || empty($data['reviewed_by'])) {
                throw new Exception("Reviewer ID is required");
            }
            
            $report_id = intval($data['report_id']);
            $status = $data['status'];
            $reviewed_by = intval($data['reviewed_by']);
            $remarks = $data['remarks'] ?? '';
            
            $sql = "UPDATE service_reports 
                    SET status = ?, 
                        reviewed_by = ?, 
                        remarks = ?,
                        date_reviewed = NOW() 
                    WHERE id = ?";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("sisi", $status, $reviewed_by, $remarks, $report_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Execute failed: " . $stmt->error);
            }
            
            return [
                'success' => true,
                'message' => 'Report status updated successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    // Get all reports (for HR/Admin)
    public function getAllReports($filters = []) {
        try {
            $sql = "SELECT 
                        sr.id,
                        sr.employee_id,
                        sr.report_type as type,
                        sr.title,
                        sr.description,
                        sr.status,
                        sr.date_submitted,
                        sr.date_reviewed,
                        sr.reviewed_by,
                        sr.remarks,
                        sr.created_at
                    FROM service_reports sr
                    WHERE 1=1";
            
            $params = [];
            $types = "";
            
            // Add filters
            if (isset($filters['status']) && !empty($filters['status'])) {
                $sql .= " AND sr.status = ?";
                $params[] = $filters['status'];
                $types .= "s";
            }
            
            if (isset($filters['report_type']) && !empty($filters['report_type'])) {
                $sql .= " AND sr.report_type = ?";
                $params[] = $filters['report_type'];
                $types .= "s";
            }
            
            if (isset($filters['start_date']) && !empty($filters['start_date'])) {
                $sql .= " AND DATE(sr.date_submitted) >= ?";
                $params[] = $filters['start_date'];
                $types .= "s";
            }
            
            if (isset($filters['end_date']) && !empty($filters['end_date'])) {
                $sql .= " AND DATE(sr.date_submitted) <= ?";
                $params[] = $filters['end_date'];
                $types .= "s";
            }
            
            $sql .= " ORDER BY sr.created_at DESC";
            
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $reports = [];
            while ($row = $result->fetch_assoc()) {
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

    // Delete report
    public function deleteReport($report_id) {
        try {
            if (empty($report_id)) {
                throw new Exception("Report ID is required");
            }
            
            $this->conn->begin_transaction();
            
            // Get file path before deletion
            $sql = "SELECT file_path FROM service_reports WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $report = $result->fetch_assoc();
            
            // Delete OB form entries if exists
            $delete_entries = "DELETE FROM ob_form_entries WHERE report_id = ?";
            $entry_stmt = $this->conn->prepare($delete_entries);
            
            if (!$entry_stmt) {
                throw new Exception("Entry prepare failed: " . $this->conn->error);
            }
            
            $entry_stmt->bind_param("i", $report_id);
            $entry_stmt->execute();
            
            // Delete service report
            $delete_report = "DELETE FROM service_reports WHERE id = ?";
            $report_stmt = $this->conn->prepare($delete_report);
            
            if (!$report_stmt) {
                throw new Exception("Report prepare failed: " . $this->conn->error);
            }
            
            $report_stmt->bind_param("i", $report_id);
            
            if (!$report_stmt->execute()) {
                throw new Exception("Report execute failed: " . $report_stmt->error);
            }
            
            $this->conn->commit();
            
            // Delete file if exists
            if (isset($report['file_path']) && !empty($report['file_path']) && file_exists($report['file_path'])) {
                unlink($report['file_path']);
            }
            
            return [
                'success' => true,
                'message' => 'Report deleted successfully'
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
}

// Initialize API
try {
    if (!isset($conn)) {
        throw new Exception("Database connection not established");
    }
    
    $api = new ServiceReportAPI($conn);
    
    // Handle requests
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    if (empty($action)) {
        throw new Exception("No action specified");
    }
    
    switch ($action) {
        case 'getUserReports':
            $employee_id = $_GET['employee_id'] ?? 0;
            error_log("Getting reports for employee: " . $employee_id);
            $result = $api->getUserReports($employee_id);
            error_log("Result: " . json_encode($result));
            echo json_encode($result);
            break;
            
        case 'submitOBForm':
            echo json_encode($api->submitOBForm($_POST));
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
            
        case 'updateReportStatus':
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid JSON input: ' . json_last_error_msg()
                ]);
            } else {
                echo json_encode($api->updateReportStatus($input));
            }
            break;
            
        case 'getAllReports':
            $filters = $_GET;
            unset($filters['action']);
            echo json_encode($api->getAllReports($filters));
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