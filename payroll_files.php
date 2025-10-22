<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_connection.php';

class PayrollFilesHandler {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Get payroll periods for an employee
     */
    public function getPayrollPeriods($emp_id) {
        try {
            $sql = "SELECT pp.id, pp.prp_id, pp.date_from, pp.date_to, 
                           CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display_period,
                           eps.pdf_filename as payslip_file,
                           eps.pdf_path as payslip_path,
                           eps.generated_at as payslip_generated_at,
                           eps.status as payslip_status
                    FROM payroll_periods pp
                    LEFT JOIN employee_payslip_pdfs eps ON pp.id = eps.payroll_period_id AND eps.emp_id = ?
                    ORDER BY pp.date_to DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $emp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $periods = [];
            while ($row = $result->fetch_assoc()) {
                $periods[] = [
                    'payrollId' => 'PR-' . date('Y', strtotime($row['date_from'])) . '-' . str_pad($row['id'], 3, '0', STR_PAD_LEFT),
                    'payrollPeriod' => $row['display_period'],
                    'dateRelease' => $row['payslip_generated_at'] ? date('F d, Y', strtotime($row['payslip_generated_at'])) : 'Not Released',
                    'status' => $row['payslip_file'] ? 'Released' : 'Pending',
                    'period_id' => $row['id'],
                    'prp_id' => $row['prp_id'],
                    'has_payslip' => $row['payslip_file'] ? true : false,
                    'payslip_file' => $row['payslip_file'],
                    'payslip_path' => $row['payslip_path']
                ];
            }
            
            return $periods;
        } catch (Exception $e) {
            throw new Exception("Error fetching payroll periods: " . $e->getMessage());
        }
    }
    
    /**
     * Get benefits records for an employee
     */
    public function getBenefitsRecords($emp_id) {
        try {
            // Fixed query to avoid duplicates - use subquery to count files
            $sql = "SELECT pp.id, pp.prp_id, pp.date_from, pp.date_to,
                           CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display_period,
                           eb.id as benefit_id,
                           eb.status as benefit_status,
                           eb.released_at as benefit_released_at,
                           eb.created_at as benefit_created_at,
                           (SELECT COUNT(*) FROM benefit_files bf WHERE bf.benefit_id = eb.id) as file_count
                    FROM payroll_periods pp
                    LEFT JOIN employee_benefits eb ON pp.id = eb.payroll_period_id AND eb.employee_id = ?
                    WHERE eb.id IS NOT NULL OR pp.id IN (SELECT DISTINCT payroll_period_id FROM employee_benefits WHERE employee_id = ?)
                    ORDER BY pp.date_to DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $emp_id, $emp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $benefits = [];
            $processed_periods = []; // Track processed periods to avoid duplicates
            
            while ($row = $result->fetch_assoc()) {
                // Skip if we've already processed this period
                if (in_array($row['id'], $processed_periods)) {
                    continue;
                }
                $processed_periods[] = $row['id'];
                
                $benefits[] = [
                    'benefitsId' => 'BN-' . date('Y', strtotime($row['date_from'])) . '-' . str_pad($row['id'], 3, '0', STR_PAD_LEFT),
                    'payrollPeriod' => $row['display_period'],
                    'dateRelease' => $row['benefit_released_at'] ? date('F d, Y', strtotime($row['benefit_released_at'])) : 'Not Released',
                    'status' => $row['benefit_status'] === 'completed' ? 'Released' : 'Pending',
                    'period_id' => $row['id'],
                    'prp_id' => $row['prp_id'],
                    'benefit_id' => $row['benefit_id'],
                    'has_benefits' => $row['file_count'] > 0,
                    'uploadedBenefits' => $row['file_count'] . '/4', // Show file count out of 4
                    'file_count' => $row['file_count']
                ];
            }
            
            return $benefits;
        } catch (Exception $e) {
            throw new Exception("Error fetching benefits records: " . $e->getMessage());
        }
    }
    
    /**
     * Get specific payslip file for an employee and period
     */
    public function getPayslipFile($emp_id, $period_id) {
        try {
            $sql = "SELECT eps.pdf_filename, eps.pdf_path, eps.file_size, eps.generated_at,
                           pp.prp_id, pp.date_from, pp.date_to,
                           CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display_period
                    FROM employee_payslip_pdfs eps
                    JOIN payroll_periods pp ON eps.payroll_period_id = pp.id
                    WHERE eps.emp_id = ? AND eps.payroll_period_id = ? AND eps.status = 'active'";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $emp_id, $period_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                return [
                    'found' => true,
                    'filename' => $row['pdf_filename'],
                    'path' => $row['pdf_path'],
                    'file_size' => $row['file_size'],
                    'generated_at' => $row['generated_at'],
                    'period' => $row['display_period'],
                    'prp_id' => $row['prp_id'],
                    'download_url' => 'https://difsysinc.com/difsysapi/' . $row['pdf_path']
                ];
            } else {
                return [
                    'found' => false,
                    'message' => 'Payslip not yet available for this period'
                ];
            }
        } catch (Exception $e) {
            throw new Exception("Error fetching payslip file: " . $e->getMessage());
        }
    }
    
    /**
     * Get specific benefits file for an employee and period
     */
    public function getBenefitsFile($emp_id, $period_id) {
        try {
            // First, get the benefit record info
            $benefitSql = "SELECT eb.id as benefit_id, eb.status as benefit_status,
                                 pp.prp_id, pp.date_from, pp.date_to,
                                 CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display_period
                          FROM employee_benefits eb
                          JOIN payroll_periods pp ON eb.payroll_period_id = pp.id
                          WHERE eb.employee_id = ? AND eb.payroll_period_id = ?";
            
            $stmt = $this->conn->prepare($benefitSql);
            $stmt->bind_param("ii", $emp_id, $period_id);
            $stmt->execute();
            $benefitResult = $stmt->get_result();
            $benefitInfo = $benefitResult->fetch_assoc();
            
            if (!$benefitInfo) {
                return [
                    'found' => false,
                    'message' => 'No benefit record found for this period'
                ];
            }
            
            // Now get all files for this benefit
            $filesSql = "SELECT bf.id, bf.file_name, bf.file_path, bf.original_name, 
                               bf.file_type, bf.file_size, bf.uploaded_at
                        FROM benefit_files bf
                        WHERE bf.benefit_id = ?
                        ORDER BY bf.uploaded_at ASC";
            
            $stmt = $this->conn->prepare($filesSql);
            $stmt->bind_param("i", $benefitInfo['benefit_id']);
            $stmt->execute();
            $filesResult = $stmt->get_result();
            
            $files = [];
            while ($row = $filesResult->fetch_assoc()) {
                $files[] = [
                    'id' => $row['id'],
                    'filename' => $row['file_name'],
                    'original_name' => $row['original_name'],
                    'file_type' => $row['file_type'],
                    'file_size' => $row['file_size'],
                    'file_path' => $row['file_path'],
                    'uploaded_at' => $row['uploaded_at'],
                    'download_url' => 'https://difsysinc.com/difsysapi/' . $row['file_path']
                ];
            }
            
            if (!empty($files)) {
                return [
                    'found' => true,
                    'files' => $files,
                    'period' => $benefitInfo['display_period'],
                    'prp_id' => $benefitInfo['prp_id'],
                    'status' => $benefitInfo['benefit_status'],
                    'benefit_id' => $benefitInfo['benefit_id']
                ];
            } else {
                return [
                    'found' => false,
                    'message' => 'No files uploaded for this benefit period',
                    'period' => $benefitInfo['display_period'],
                    'prp_id' => $benefitInfo['prp_id'],
                    'status' => $benefitInfo['benefit_status'],
                    'benefit_id' => $benefitInfo['benefit_id']
                ];
            }
        } catch (Exception $e) {
            throw new Exception("Error fetching benefits files: " . $e->getMessage());
        }
    }

    public function getBenefitsWithFiles($emp_id, $period_id) {
        try {
            // Get benefit record and all its files in one organized query
            $sql = "SELECT 
                        eb.id as benefit_id,
                        eb.status as benefit_status,
                        eb.released_at,
                        pp.prp_id,
                        pp.date_from,
                        pp.date_to,
                        CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display_period,
                        bf.id as file_id,
                        bf.file_name,
                        bf.original_name,
                        bf.file_type,
                        bf.file_size,
                        bf.file_path,
                        bf.uploaded_at
                    FROM employee_benefits eb
                    JOIN payroll_periods pp ON eb.payroll_period_id = pp.id
                    LEFT JOIN benefit_files bf ON eb.id = bf.benefit_id
                    WHERE eb.employee_id = ? AND eb.payroll_period_id = ?
                    ORDER BY bf.uploaded_at ASC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $emp_id, $period_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $benefitInfo = null;
            $files = [];
            
            while ($row = $result->fetch_assoc()) {
                // Store benefit info (will be the same for all rows)
                if (!$benefitInfo) {
                    $benefitInfo = [
                        'benefit_id' => $row['benefit_id'],
                        'status' => $row['benefit_status'],
                        'released_at' => $row['released_at'],
                        'period' => $row['display_period'],
                        'prp_id' => $row['prp_id']
                    ];
                }
                
                // Add file info if exists
                if ($row['file_id']) {
                    $files[] = [
                        'id' => $row['file_id'],
                        'filename' => $row['file_name'],
                        'original_name' => $row['original_name'],
                        'file_type' => $row['file_type'],
                        'file_size' => $row['file_size'],
                        'file_path' => $row['file_path'],
                        'uploaded_at' => $row['uploaded_at'],
                        'download_url' => 'https://difsysinc.com/difsysapi/' . $row['file_path']
                    ];
                }
            }
            
            if ($benefitInfo) {
                return [
                    'found' => true,
                    'benefit_info' => $benefitInfo,
                    'files' => $files,
                    'file_count' => count($files)
                ];
            } else {
                return [
                    'found' => false,
                    'message' => 'No benefit record found for this employee and period'
                ];
            }
        } catch (Exception $e) {
            throw new Exception("Error fetching benefits with files: " . $e->getMessage());
        }
    }
    
    /**
     * Get current active payroll period
     */
    public function getCurrentPayrollPeriod() {
        try {
            $sql = "SELECT id, prp_id, date_from, date_to,
                           CONCAT(DATE_FORMAT(date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(date_to, '%M %d, %Y')) as display_period
                    FROM payroll_periods 
                    WHERE CURDATE() BETWEEN date_from AND date_to
                    ORDER BY date_to DESC 
                    LIMIT 1";
            
            $result = $this->conn->query($sql);
            
            if ($row = $result->fetch_assoc()) {
                return [
                    'id' => $row['id'],
                    'prp_id' => $row['prp_id'],
                    'display' => $row['display_period'],
                    'date_from' => $row['date_from'],
                    'date_to' => $row['date_to']
                ];
            } else {
                // If no current period, get the latest one
                $sql = "SELECT id, prp_id, date_from, date_to,
                               CONCAT(DATE_FORMAT(date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(date_to, '%M %d, %Y')) as display_period
                        FROM payroll_periods 
                        ORDER BY date_to DESC 
                        LIMIT 1";
                
                $result = $this->conn->query($sql);
                if ($row = $result->fetch_assoc()) {
                    return [
                        'id' => $row['id'],
                        'prp_id' => $row['prp_id'],
                        'display' => $row['display_period'],
                        'date_from' => $row['date_from'],
                        'date_to' => $row['date_to']
                    ];
                }
                
                return null;
            }
        } catch (Exception $e) {
            throw new Exception("Error fetching current payroll period: " . $e->getMessage());
        }
    }
    
    /**
     * Get complete employee information including personal and payroll details
     */
    public function getEmployeeInfo($emp_id) {
        try {
            $sql = "SELECT 
                        e.emp_id, e.firstName, e.lastName, e.email, e.position, e.address as emp_address,
                        e.number as emp_number, e.workarrangement, e.status,
                        up.middle_name, up.address as profile_address, up.contact_number, 
                        up.date_of_birth, up.civil_status, up.gender, up.citizenship,
                        ep.basic_pay_monthly, ep.basic_pay_semi_monthly, ep.rate_per_day,
                        ep.sss_account, ep.phic_account, ep.hdmf_account, ep.tax_account,
                        ep.sss, ep.phic, ep.hdmf, ep.tax
                    FROM employeelist e
                    LEFT JOIN user_profiles up ON e.emp_id = up.user_id
                    LEFT JOIN employee_payroll ep ON e.emp_id = ep.emp_id
                    WHERE e.emp_id = ? AND e.status = 'active'";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $emp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                return [
                    'found' => true,
                    'employee_info' => [
                        'emp_id' => $row['emp_id'],
                        'firstName' => $row['firstName'],
                        'lastName' => $row['lastName'],
                        'middleName' => $row['middle_name'],
                        'email' => $row['email'],
                        'position' => $row['position'],
                        'address' => $row['profile_address'] ?: $row['emp_address'],
                        'contactNumber' => $row['contact_number'] ?: $row['emp_number'],
                        'dateOfBirth' => $row['date_of_birth'] ? date('F d, Y', strtotime($row['date_of_birth'])) : null,
                        'civilStatus' => $row['civil_status'],
                        'gender' => $row['gender'],
                        'citizenship' => $row['citizenship'],
                        'workArrangement' => $row['workarrangement'],
                        'profile_image' => null // You can add profile image logic here if needed
                    ],
                    'payroll_info' => [
                        'payPeriodType' => 'Semi-Monthly', // This could be made dynamic
                        'basicPayMonthly' => $row['basic_pay_monthly'],
                        'basicPaySemiMonthly' => $row['basic_pay_semi_monthly'],
                        'ratePerDay' => $row['rate_per_day'],
                        'sssAccount' => $row['sss_account'],
                        'philhealthAccount' => $row['phic_account'],
                        'pagibigAccount' => $row['hdmf_account'],
                        'tinNumber' => $row['tax_account'],
                        'sssContribution' => $row['sss'],
                        'philhealthContribution' => $row['phic'],
                        'pagibigContribution' => $row['hdmf'],
                        'taxContribution' => $row['tax']
                    ]
                ];
            } else {
                return [
                    'found' => false,
                    'message' => 'Employee not found or inactive'
                ];
            }
        } catch (Exception $e) {
            throw new Exception("Error fetching employee information: " . $e->getMessage());
        }
    }
    
    /**
     * Validate employee exists (simplified version)
     */
    public function validateEmployee($emp_id) {
        try {
            $sql = "SELECT emp_id, firstName, lastName, position FROM employeelist WHERE emp_id = ? AND status = 'active'";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $emp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            return $result->fetch_assoc();
        } catch (Exception $e) {
            throw new Exception("Error validating employee: " . $e->getMessage());
        }
    }
}

// Initialize the handler
try {
    $handler = new PayrollFilesHandler($conn);
    
    // Get request method and parameters
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $emp_id = $_GET['emp_id'] ?? '';
    $period_id = $_GET['period_id'] ?? '';
    
    // Validate required parameters
    if (empty($emp_id) && $action !== 'current_period') {
        throw new Exception("Employee ID is required");
    }
    
    // Validate employee exists (except for current_period action)
    if (!empty($emp_id)) {
        $employee = $handler->validateEmployee($emp_id);
        if (!$employee) {
            throw new Exception("Employee not found or inactive");
        }
    }
    
    $response = ['success' => false, 'data' => null, 'message' => ''];
    
    switch ($action) {
        case 'employee_info':
            $data = $handler->getEmployeeInfo($emp_id);
            $response = [
                'success' => true,
                'data' => $data,
                'message' => $data['found'] ? 'Employee information fetched successfully' : 'Employee not found'
            ];
            break;
            
        case 'payroll_periods':
            $data = $handler->getPayrollPeriods($emp_id);
            $response = [
                'success' => true,
                'data' => $data,
                'message' => 'Payroll periods fetched successfully'
            ];
            break;
            
        case 'benefits_records':
            $data = $handler->getBenefitsRecords($emp_id);
            $response = [
                'success' => true,
                'data' => $data,
                'message' => 'Benefits records fetched successfully'
            ];
            break;
            
        case 'view_payslip':
            if (empty($period_id)) {
                throw new Exception("Period ID is required for viewing payslip");
            }
            $data = $handler->getPayslipFile($emp_id, $period_id);
            $response = [
                'success' => true,
                'data' => $data,
                'message' => $data['found'] ? 'Payslip file found' : 'Payslip not available'
            ];
            break;
            
        case 'view_benefits':
            if (empty($period_id)) {
                throw new Exception("Period ID is required for viewing benefits");
            }
            $data = $handler->getBenefitsFile($emp_id, $period_id);
            $response = [
                'success' => true,
                'data' => $data,
                'message' => $data['found'] ? 'Benefits files found' : 'Benefits files not available'
            ];
            break;
            
        case 'current_period':
            $data = $handler->getCurrentPayrollPeriod();
            $response = [
                'success' => true,
                'data' => $data,
                'message' => $data ? 'Current payroll period found' : 'No active payroll period'
            ];
            break;
        case 'benefits_with_files':
                if (empty($period_id)) {
                    throw new Exception("Period ID is required for viewing benefits with files");
                }
                $data = $handler->getBenefitsWithFiles($emp_id, $period_id);
                $response = [
                    'success' => true,
                    'data' => $data,
                    'message' => $data['found'] ? 'Benefits with files fetched successfully' : 'No benefits found'
                ];
                break;
            
        default:
            throw new Exception("Invalid action specified");
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'data' => null,
        'message' => $e->getMessage()
    ]);
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>