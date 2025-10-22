<?php
require_once 'db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Get action from URL parameter
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($action);
            break;
        case 'POST':
            handlePost($action, $input);
            break;
        case 'PUT':
            handlePut($action, $input);
            break;
        case 'DELETE':
            handleDelete($action, $input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGet($action) {
    switch ($action) {
        case 'employees':
            getEmployees();
            break;
        case 'payroll_periods':
            getPayrollPeriods();
            break;
        case 'employee_benefits':
            getEmployeeBenefits($_GET['employee_id'] ?? null, $_GET['period_id'] ?? null);
            break;
        case 'benefit_files':
            getBenefitFiles($_GET['benefit_id'] ?? null);
            break;
        case 'pending_employees':
            getPendingEmployees($_GET['period_id'] ?? null);
            break;
        case 'completed_employees':
            getCompletedEmployees($_GET['period_id'] ?? null);
            break;
        case 'employee_benefit_details':
            getEmployeeBenefitDetails($_GET['employee_id'] ?? null, $_GET['period_id'] ?? null);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePost($action, $input) {
    switch ($action) {
        case 'upload_file':
            uploadBenefitFile();
            break;
        case 'release_payslip':
            releasePayslip($input);
            break;
        case 'create_benefit_record':
            createBenefitRecord($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePut($action, $input) {
    switch ($action) {
        case 'update_benefit_status':
            updateBenefitStatus($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handleDelete($action, $input) {
    switch ($action) {
        case 'delete_file':
            deleteBenefitFile($input['file_id'] ?? null);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

// Get all employees with profile images
function getEmployees() {
    global $conn;
    
    $sql = "SELECT 
                e.id as employee_list_id,
                e.emp_id,
                e.firstName, 
                e.lastName, 
                e.email, 
                e.role,
                e.position,
                e.work_days,
                e.status,
                e.workarrangement,
                u.profile_image
            FROM employeelist e
            LEFT JOIN useraccounts u ON e.emp_id = u.id
            WHERE e.role = 'employee'
            ORDER BY e.firstName, e.lastName";
    
    $result = $conn->query($sql);
    $employees = [];
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            'id' => (int)$row['emp_id'],
            'employee_list_id' => (int)$row['employee_list_id'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'email' => $row['email'],
            'role' => $row['role'],
            'position' => $row['position'],
            'workDays' => $row['work_days'],
            'status' => $row['status'],
            'workarrangement' => $row['workarrangement'],
            'profileImage' => $row['profile_image']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $employees]);
}

// Get payroll periods
function getPayrollPeriods() {
    global $conn;
    
    $sql = "SELECT * FROM payroll_periods ORDER BY date_from DESC";
    $result = $conn->query($sql);
    $periods = [];
    
    while ($row = $result->fetch_assoc()) {
        $periods[] = [
            'id' => (int)$row['id'],
            'prpId' => $row['prp_id'],
            'dateFrom' => $row['date_from'],
            'dateTo' => $row['date_to'],
            'createdAt' => $row['created_at']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $periods]);
}

// Get employees with pending status for a specific payroll period
function getPendingEmployees($period_id) {
    global $conn;
    
    if (!$period_id) {
        echo json_encode(['error' => 'Period ID is required']);
        return;
    }
    
    $sql = "SELECT 
                e.emp_id,
                e.firstName, 
                e.lastName, 
                e.email,
                e.position,
                u.profile_image,
                pp.prp_id,
                pp.date_from,
                pp.date_to,
                COALESCE(eb.status, 'pending') as benefit_status,
                eb.id as benefit_id
            FROM employeelist e
            LEFT JOIN useraccounts u ON e.emp_id = u.id
            CROSS JOIN payroll_periods pp
            LEFT JOIN employee_benefits eb ON e.emp_id = eb.employee_id AND pp.id = eb.payroll_period_id
            WHERE e.role = 'employee' 
            AND pp.id = ?
            AND (eb.status IS NULL OR eb.status = 'pending')
            ORDER BY e.firstName, e.lastName";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            'id' => (int)$row['emp_id'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'email' => $row['email'],
            'position' => $row['position'],
            'profileImage' => $row['profile_image'],
            'payrollPeriod' => $row['prp_id'] . ' (' . $row['date_from'] . ' - ' . $row['date_to'] . ')',
            'status' => $row['benefit_status'],
            'benefitId' => $row['benefit_id'] ? (int)$row['benefit_id'] : null
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $employees]);
}

// Get employees with completed status (history) - Alternative efficient approach
function getCompletedEmployees($period_id) {
    global $conn;
    
    $where_clause = "";
    $params = [];
    $types = "";
    
    if ($period_id) {
        $where_clause = "AND pp.id = ?";
        $params[] = $period_id;
        $types = "i";
    }
    
    $sql = "SELECT 
                e.emp_id,
                e.firstName, 
                e.lastName, 
                e.email,
                e.position,
                u.profile_image,
                pp.prp_id,
                pp.date_from,
                pp.date_to,
                eb.status,
                eb.id as benefit_id,
                eb.released_at,
                COALESCE(file_count.uploaded_files, 0) as uploaded_files
            FROM employeelist e
            LEFT JOIN useraccounts u ON e.emp_id = u.id
            INNER JOIN employee_benefits eb ON e.emp_id = eb.employee_id
            INNER JOIN payroll_periods pp ON eb.payroll_period_id = pp.id
            LEFT JOIN (
                SELECT benefit_id, COUNT(*) as uploaded_files 
                FROM benefit_files 
                GROUP BY benefit_id
            ) file_count ON eb.id = file_count.benefit_id
            WHERE e.role = 'employee' 
            AND eb.status = 'completed'
            $where_clause
            ORDER BY eb.released_at DESC, e.firstName, e.lastName";
    
    if ($params) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            'id' => (int)$row['emp_id'],
            'firstName' => $row['firstName'],
            'lastName' => $row['lastName'],
            'email' => $row['email'],
            'position' => $row['position'],
            'profileImage' => $row['profile_image'],
            'payrollPeriod' => $row['prp_id'] . ' (' . $row['date_from'] . ' - ' . $row['date_to'] . ')',
            'status' => $row['status'],
            'benefitId' => (int)$row['benefit_id'],
            'releasedAt' => $row['released_at'],
            'uploadedFiles' => (int)$row['uploaded_files']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $employees]);
}

// Get employee benefit details with files
function getEmployeeBenefitDetails($employee_id, $period_id) {
    global $conn;
    
    if (!$employee_id || !$period_id) {
        echo json_encode(['error' => 'Employee ID and Period ID are required']);
        return;
    }
    
    // Get employee and benefit info
    $sql = "SELECT 
                e.emp_id,
                e.firstName, 
                e.lastName, 
                e.email,
                e.position,
                e.work_days,
                e.workarrangement,
                u.profile_image,
                pp.prp_id,
                pp.date_from,
                pp.date_to,
                eb.id as benefit_id,
                eb.status,
                eb.created_at,
                eb.released_at
            FROM employeelist e
            LEFT JOIN useraccounts u ON e.emp_id = u.id
            LEFT JOIN employee_benefits eb ON e.emp_id = eb.employee_id 
            LEFT JOIN payroll_periods pp ON eb.payroll_period_id = pp.id
            WHERE e.emp_id = ? AND (pp.id = ? OR pp.id IS NULL)
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $employee_id, $period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee = $result->fetch_assoc();
    
    if (!$employee) {
        echo json_encode(['error' => 'Employee not found']);
        return;
    }
    
    // Get uploaded files if benefit record exists
    $files = [];
    if ($employee['benefit_id']) {
        $file_sql = "SELECT * FROM benefit_files WHERE benefit_id = ? ORDER BY uploaded_at ASC";
        $file_stmt = $conn->prepare($file_sql);
        $file_stmt->bind_param("i", $employee['benefit_id']);
        $file_stmt->execute();
        $file_result = $file_stmt->get_result();
        
        while ($file_row = $file_result->fetch_assoc()) {
            $files[] = [
                'id' => (int)$file_row['id'],
                'fileName' => $file_row['file_name'],
                'originalName' => $file_row['original_name'],
                'fileType' => $file_row['file_type'],
                'fileSize' => (int)$file_row['file_size'],
                'filePath' => $file_row['file_path'],
                'uploadedAt' => $file_row['uploaded_at']
            ];
        }
    }
    
    // Get payroll period info if not in benefit record
    if (!$employee['prp_id']) {
        $period_sql = "SELECT * FROM payroll_periods WHERE id = ?";
        $period_stmt = $conn->prepare($period_sql);
        $period_stmt->bind_param("i", $period_id);
        $period_stmt->execute();
        $period_result = $period_stmt->get_result();
        $period = $period_result->fetch_assoc();
        
        if ($period) {
            $employee['prp_id'] = $period['prp_id'];
            $employee['date_from'] = $period['date_from'];
            $employee['date_to'] = $period['date_to'];
        }
    }
    
    $response = [
        'id' => (int)$employee['emp_id'],
        'firstName' => $employee['firstName'],
        'lastName' => $employee['lastName'],
        'email' => $employee['email'],
        'position' => $employee['position'],
        'workDays' => $employee['work_days'],
        'workarrangement' => $employee['workarrangement'],
        'profileImage' => $employee['profile_image'],
        'payrollPeriod' => [
            'id' => $period_id,
            'prpId' => $employee['prp_id'],
            'dateFrom' => $employee['date_from'],
            'dateTo' => $employee['date_to']
        ],
        'benefitId' => $employee['benefit_id'] ? (int)$employee['benefit_id'] : null,
        'status' => $employee['status'] ?? 'pending',
        'files' => $files,
        'createdAt' => $employee['created_at'],
        'releasedAt' => $employee['released_at']
    ];
    
    echo json_encode(['success' => true, 'data' => $response]);
}

// Create benefit record for employee
function createBenefitRecord($input) {
    global $conn;
    
    $employee_id = $input['employee_id'] ?? null;
    $period_id = $input['period_id'] ?? null;
    
    if (!$employee_id || !$period_id) {
        echo json_encode(['error' => 'Employee ID and Period ID are required']);
        return;
    }
    
    // Check if record already exists
    $check_sql = "SELECT id FROM employee_benefits WHERE employee_id = ? AND payroll_period_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $employee_id, $period_id);
    $check_stmt->execute();
    $existing = $check_stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        echo json_encode(['success' => true, 'benefit_id' => (int)$existing['id'], 'message' => 'Record already exists']);
        return;
    }
    
    // Create new benefit record
    $sql = "INSERT INTO employee_benefits (employee_id, payroll_period_id, status) VALUES (?, ?, 'pending')";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $employee_id, $period_id);
    
    if ($stmt->execute()) {
        $benefit_id = $conn->insert_id;
        echo json_encode(['success' => true, 'benefit_id' => $benefit_id]);
    } else {
        echo json_encode(['error' => 'Failed to create benefit record']);
    }
}

// Upload benefit file
function uploadBenefitFile() {
    global $conn;
    
    if (!isset($_FILES['file']) || !isset($_POST['benefit_id'])) {
        echo json_encode(['error' => 'File and benefit ID are required']);
        return;
    }
    
    $benefit_id = (int)$_POST['benefit_id'];
    $file = $_FILES['file'];
    
    // Validate file type
    $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $file_type = $file['type'];
    
    if (!in_array($file_type, $allowed_types)) {
        echo json_encode(['error' => 'Invalid file type. Only PDF, Word documents, and images are allowed.']);
        return;
    }
    
    // Check file count limit (4 files max)
    $count_sql = "SELECT COUNT(*) as file_count FROM benefit_files WHERE benefit_id = ?";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("i", $benefit_id);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    
    if ($count_row['file_count'] >= 4) {
        echo json_encode(['error' => 'Maximum of 4 files allowed per employee']);
        return;
    }
    
    // Create upload directory if it doesn't exist
    $upload_dir = 'uploads/benefits/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Generate unique filename
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
    $file_path = $upload_dir . $unique_filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $file_path)) {
        // Save file info to database
        $sql = "INSERT INTO benefit_files (benefit_id, file_name, original_name, file_type, file_size, file_path) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isssis", $benefit_id, $unique_filename, $file['name'], $file_type, $file['size'], $file_path);
        
        if ($stmt->execute()) {
            $file_id = $conn->insert_id;
            echo json_encode([
                'success' => true, 
                'file_id' => $file_id,
                'file_name' => $unique_filename,
                'original_name' => $file['name'],
                'file_size' => $file['size']
            ]);
        } else {
            // Delete uploaded file if database insert fails
            unlink($file_path);
            echo json_encode(['error' => 'Failed to save file information']);
        }
    } else {
        echo json_encode(['error' => 'Failed to upload file']);
    }
}

// Release payslip (change status to completed)
function releasePayslip($input) {
    global $conn;
    
    $benefit_id = $input['benefit_id'] ?? null;
    
    if (!$benefit_id) {
        echo json_encode(['error' => 'Benefit ID is required']);
        return;
    }
    
    // Check if there are uploaded files
    $file_count_sql = "SELECT COUNT(*) as file_count FROM benefit_files WHERE benefit_id = ?";
    $file_count_stmt = $conn->prepare($file_count_sql);
    $file_count_stmt->bind_param("i", $benefit_id);
    $file_count_stmt->execute();
    $file_count_result = $file_count_stmt->get_result();
    $file_count_row = $file_count_result->fetch_assoc();
    
    if ($file_count_row['file_count'] == 0) {
        echo json_encode(['error' => 'Cannot release payslip without uploaded files']);
        return;
    }
    
    // Update status to completed
    $sql = "UPDATE employee_benefits SET status = 'completed', released_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $benefit_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Payslip released successfully']);
    } else {
        echo json_encode(['error' => 'Failed to release payslip']);
    }
}

// Update benefit status
function updateBenefitStatus($input) {
    global $conn;
    
    $benefit_id = $input['benefit_id'] ?? null;
    $status = $input['status'] ?? null;
    
    if (!$benefit_id || !$status) {
        echo json_encode(['error' => 'Benefit ID and status are required']);
        return;
    }
    
    $sql = "UPDATE employee_benefits SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $status, $benefit_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
    } else {
        echo json_encode(['error' => 'Failed to update status']);
    }
}

// Delete benefit file
function deleteBenefitFile($file_id) {
    global $conn;
    
    if (!$file_id) {
        echo json_encode(['error' => 'File ID is required']);
        return;
    }
    
    // Get file info first
    $sql = "SELECT file_path FROM benefit_files WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $file_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $file = $result->fetch_assoc();
    
    if (!$file) {
        echo json_encode(['error' => 'File not found']);
        return;
    }
    
    // Delete from database
    $delete_sql = "DELETE FROM benefit_files WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $file_id);
    
    if ($delete_stmt->execute()) {
        // Delete physical file
        if (file_exists($file['file_path'])) {
            unlink($file['file_path']);
        }
        echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
    } else {
        echo json_encode(['error' => 'Failed to delete file']);
    }
}

// Get benefit files for a specific benefit
function getBenefitFiles($benefit_id) {
    global $conn;
    
    if (!$benefit_id) {
        echo json_encode(['error' => 'Benefit ID is required']);
        return;
    }
    
    $sql = "SELECT * FROM benefit_files WHERE benefit_id = ? ORDER BY uploaded_at ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $benefit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $files = [];
    while ($row = $result->fetch_assoc()) {
        $files[] = [
            'id' => (int)$row['id'],
            'fileName' => $row['file_name'],
            'originalName' => $row['original_name'],
            'fileType' => $row['file_type'],
            'fileSize' => (int)$row['file_size'],
            'filePath' => $row['file_path'],
            'uploadedAt' => $row['uploaded_at']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $files]);
}

// Get employee benefits for specific employee and period
function getEmployeeBenefits($employee_id, $period_id) {
    global $conn;
    
    if (!$employee_id || !$period_id) {
        echo json_encode(['error' => 'Employee ID and Period ID are required']);
        return;
    }
    
    $sql = "SELECT 
                eb.*,
                pp.prp_id,
                pp.date_from,
                pp.date_to,
                e.firstName,
                e.lastName
            FROM employee_benefits eb
            INNER JOIN payroll_periods pp ON eb.payroll_period_id = pp.id
            INNER JOIN employeelist e ON eb.employee_id = e.emp_id
            WHERE eb.employee_id = ? AND eb.payroll_period_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $employee_id, $period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $benefit = $result->fetch_assoc();
    
    if ($benefit) {
        echo json_encode([
            'success' => true, 
            'data' => [
                'id' => (int)$benefit['id'],
                'employeeId' => (int)$benefit['employee_id'],
                'payrollPeriodId' => (int)$benefit['payroll_period_id'],
                'status' => $benefit['status'],
                'createdAt' => $benefit['created_at'],
                'releasedAt' => $benefit['released_at'],
                'employeeName' => $benefit['firstName'] . ' ' . $benefit['lastName'],
                'payrollPeriod' => $benefit['prp_id'] . ' (' . $benefit['date_from'] . ' - ' . $benefit['date_to'] . ')'
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No benefit record found']);
    }
}
?>