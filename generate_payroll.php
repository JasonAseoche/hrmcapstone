<?php
// generate_payroll.php - Fixed Payroll Generation Backend API
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// Include database connection
include 'db_connection.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Also check for action in POST data for file uploads
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
}

error_log("DEBUG: Method=$method, Action=$action");

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($action);
            break;
        case 'POST':
            handlePostRequest($action);
            break;
        case 'PUT':
            handlePutRequest($action);
            break;
        case 'DELETE':
            handleDeleteRequest($action);
            break;
        default:
            throw new Exception("Method not allowed", 405);
    }
} catch (Exception $e) {
    error_log("Payroll API Error: " . $e->getMessage());
    
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode() ?: 500
    ]);
} catch (Error $e) {
    error_log("PHP Fatal Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'error_code' => 500
    ]);
}

function handleGetRequest($action) {
    switch ($action) {
        case 'get_current_payroll_period':
            getCurrentPayrollPeriod();
            break;
        case 'get_employees_for_payroll':
            getEmployeesForPayroll();
            break;
        case 'get_payroll_history':
            getPayrollHistory();
            break;
        case 'get_payslip_history':
            getPayslipHistory();
            break;
        case 'get_employee_payroll_details':
            getEmployeePayrollDetails();
            break;
        case 'get_all_employees_payroll':
            getAllEmployeesPayroll();
            break;
        case 'get_available_payroll_periods':
            getAvailablePayrollPeriods();
            break;
        case 'get_selected_employees_payroll':
            getSelectedEmployeesPayroll();
            break;        
        default:
        throw new Exception("Invalid GET action: '$action'. Valid actions are: get_current_payroll_period, get_employees_for_payroll, get_payroll_history, get_payslip_history, get_employee_payroll_details, get_all_employees_payroll, get_available_payroll_periods", 400);
    }
}

function handlePostRequest($action) {
    // Handle file uploads separately (they don't use JSON)
    if ($action === 'upload_payslip_pdf' || !empty($_FILES)) {
        uploadPayslipPDF();
        return;
    }
    
    // For other POST actions, handle JSON input
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        throw new Exception("No input data provided", 400);
    }
    
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data: " . json_last_error_msg(), 400);
    }
    
    switch ($action) {
        case 'generate_payroll':
            generatePayroll($input);
            break;
        case 'regenerate_payroll':
            regeneratePayroll($input);
            break;
        case 'release_payslip':
            releasePayslip($input);
            break;
        case 'update_payroll_data':
            updatePayrollData($input);
            break;
        default:
            throw new Exception("Invalid POST action", 400);
    }
}

function handlePutRequest($action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'update_employee_payroll':
            updateEmployeePayroll($input);
            break;
            case 'update_employee_payroll_data':  // NEW ENDPOINT
                updateEmployeePayrollData($input);
                break;
            default:
                throw new Exception("Invalid PUT action", 400);
        }
    }

function handleDeleteRequest($action) {
    switch ($action) {
        case 'delete_payroll_record':
            deletePayrollRecord();
            break;
        default:
            throw new Exception("Invalid DELETE action", 400);
    }
}

function getCurrentPayrollPeriod() {
    global $conn;
    
    try {
        $today = date('Y-m-d');
        
        $sql = "SELECT pp.*, 
                       COUNT(CASE WHEN h.holiday_type = 'Regular' THEN 1 END) as regular_holidays,
                       COUNT(CASE WHEN h.holiday_type = 'Special' THEN 1 END) as special_holidays,
                       CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display_period
                FROM payroll_periods pp
                LEFT JOIN payroll_period_holidays pph ON pp.id = pph.payroll_period_id
                LEFT JOIN holidays h ON pph.holiday_id = h.id
                WHERE DATE(pp.date_from) <= DATE(?) AND DATE(pp.date_to) >= DATE(?)
                GROUP BY pp.id
                ORDER BY pp.date_from DESC
                LIMIT 1";
                
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $today, $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $period = $result->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'current_period' => $period
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error getting current payroll period: ' . $e->getMessage());
    }
}

function getEmployeesForPayroll() {
    global $conn;
    
    try {
        // Get current payroll period
        $currentPeriod = getCurrentPayrollPeriodData();
        
        if (!$currentPeriod) {
            echo json_encode([
                'success' => false,
                'message' => 'No active payroll period found for today'
            ]);
            return;
        }
        
        $sql = "SELECT 
                    e.emp_id,
                    CONCAT('DIF', LPAD(e.emp_id, 3, '0')) as employee_id,
                    CONCAT(e.firstName, ' ', e.lastName) as employee_name,
                    e.firstName,
                    e.lastName,
                    e.position,
                    e.workarrangement,
                    u.profile_image,
                    COALESCE(pr.status, 'Pending') as status,
                    ? as payroll_period
                FROM employeelist e
                LEFT JOIN useraccounts u ON e.emp_id = u.id
                LEFT JOIN payroll_records pr ON e.emp_id = pr.emp_id AND pr.payroll_period_id = ?
                WHERE e.role = 'employee' AND e.status = 'active'
                ORDER BY e.firstName, e.lastName";
                
        $stmt = $conn->prepare($sql);
        $payrollPeriodText = $currentPeriod['display_period'];
        $stmt->bind_param("si", $payrollPeriodText, $currentPeriod['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        
        // Calculate stats
        $stats = [
            'total_employees' => count($employees),
            'pending_payroll' => count(array_filter($employees, fn($emp) => $emp['status'] === 'Pending')),
            'generated_payroll' => count(array_filter($employees, fn($emp) => $emp['status'] === 'Generated')),
            'released_payroll' => count(array_filter($employees, fn($emp) => $emp['status'] === 'Released')),
            'current_payroll_period' => $currentPeriod['display_period']
        ];
        
        echo json_encode([
            'success' => true,
            'employees' => $employees,
            'current_period' => $currentPeriod,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error getting employees for payroll: ' . $e->getMessage());
    }
}

function getPayrollHistory() {
    global $conn;
    
    try {
        $sql = "SELECT 
                    pgh.id,
                    CONCAT('PAY', LPAD(pgh.id, 3, '0')) as payroll_id,
                    CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as payroll_period,
                    pp.date_from,
                    pp.date_to,
                    pgh.total_employees,
                    pgh.total_amount,
                    pgh.created_at,
                    'Completed' as status
                FROM payroll_generation_history pgh
                INNER JOIN payroll_periods pp ON pgh.payroll_period_id = pp.id
                WHERE pgh.generation_status = 'Completed'
                ORDER BY pgh.created_at DESC
                LIMIT 50";
                
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $history = [];
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'history' => $history
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error getting payroll history: ' . $e->getMessage());
    }
}

function getPayslipHistory() {
    global $conn;
    
    try {
        $emp_id = $_GET['emp_id'] ?? null;
        $payroll_period_id = $_GET['payroll_period_id'] ?? null;
        
        $sql = "SELECT 
                    pr.id,
                    pr.emp_id,
                    CONCAT('DIF', LPAD(pr.emp_id, 3, '0')) as employee_id,
                    pr.employee_name,
                    CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as payroll_period,
                    pr.status,
                    pr.gross_pay,
                    pr.net_pay,
                    pr.total_deductions,
                    pr.created_at,
                    pr.updated_at
                FROM payroll_records pr
                INNER JOIN payroll_periods pp ON pr.payroll_period_id = pp.id
                WHERE pr.status = 'Released'";
        
        $params = [];
        $types = "";
        
        if ($emp_id) {
            $sql .= " AND pr.emp_id = ?";
            $params[] = $emp_id;
            $types .= "i";
        }
        
        if ($payroll_period_id) {
            $sql .= " AND pr.payroll_period_id = ?";
            $params[] = $payroll_period_id;
            $types .= "i";
        }
        
        $sql .= " ORDER BY pr.updated_at DESC";
        
        $stmt = $conn->prepare($sql);
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $payslips = [];
        while ($row = $result->fetch_assoc()) {
            $payslips[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'payslips' => $payslips
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error getting payslip history: ' . $e->getMessage());
    }
}

function getAvailablePayrollPeriods() {
    global $conn;
    
    try {
        $sql = "SELECT 
                    pp.id,
                    pp.prp_id,
                    CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display_period,
                    pp.date_from,
                    pp.date_to
                FROM payroll_periods pp
                ORDER BY pp.date_from DESC";
                
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $periods = [];
        while ($row = $result->fetch_assoc()) {
            $periods[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'periods' => $periods
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error getting available payroll periods: ' . $e->getMessage());
    }
}

function getEmployeePayrollDetails() {
    global $conn;
    
    try {
        $empId = $_GET['emp_id'] ?? null;
        $payrollPeriodId = $_GET['payroll_period_id'] ?? null;
        
        if (!$empId) {
            throw new Exception('Employee ID required');
        }
        
        // Use current period if not specified
        if (!$payrollPeriodId) {
            $currentPeriod = getCurrentPayrollPeriodData();
            $payrollPeriodId = $currentPeriod['id'];
        }
        
        // Get employee details
        $empSql = "SELECT 
                       e.emp_id,
                       CONCAT('DIF', LPAD(e.emp_id, 3, '0')) as employee_id,
                       e.firstName,
                       e.lastName,
                       e.position,
                       e.address,
                       u.profile_image,
                       COALESCE(ep.sss_account, '') as sss_account,
                       COALESCE(ep.phic_account, '') as phic_account,
                       COALESCE(ep.hdmf_account, '') as hdmf_account,
                       COALESCE(ep.tax_account, '') as tax_account
                   FROM employeelist e
                   LEFT JOIN useraccounts u ON e.emp_id = u.id
                   LEFT JOIN employee_payroll ep ON e.emp_id = ep.emp_id
                   WHERE e.emp_id = ?";
                   
        $empStmt = $conn->prepare($empSql);
        $empStmt->bind_param("i", $empId);
        $empStmt->execute();
        $empResult = $empStmt->get_result();
        $employee = $empResult->fetch_assoc();
        
        if (!$employee) {
            throw new Exception('Employee not found');
        }
        
        // Get payroll period info
        $periodSql = "SELECT 
                          CONCAT(DATE_FORMAT(date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(date_to, '%M %d, %Y')) as display_period,
                          date_from,
                          date_to
                      FROM payroll_periods 
                      WHERE id = ?";
        $periodStmt = $conn->prepare($periodSql);
        $periodStmt->bind_param("i", $payrollPeriodId);
        $periodStmt->execute();
        $periodResult = $periodStmt->get_result();
        $period = $periodResult->fetch_assoc();
        
        // Get or create payroll computation
        $computation = computeEmployeePayroll($empId, $payrollPeriodId);
        
        $employee['payroll_period'] = $period['display_period'];
        $employee['computation'] = $computation;
        
        echo json_encode([
            'success' => true,
            'employee' => $employee
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error getting employee payroll details: ' . $e->getMessage());
    }
}

function getAllEmployeesPayroll() {
    global $conn;
    
    try {
        $payrollPeriodId = $_GET['payroll_period_id'] ?? null;
        
        // Use current period if not specified
        if (!$payrollPeriodId) {
            $currentPeriod = getCurrentPayrollPeriodData();
            $payrollPeriodId = $currentPeriod['id'];
        }
        
        $sql = "SELECT 
                    e.emp_id,
                    CONCAT('DIF', LPAD(e.emp_id, 3, '0')) as employee_id,
                    CONCAT(e.firstName, ' ', e.lastName) as employee_name,
                    e.firstName,
                    e.lastName,
                    e.position,
                    u.profile_image
                FROM employeelist e
                LEFT JOIN useraccounts u ON e.emp_id = u.id
                WHERE e.role = 'employee' AND e.status = 'active'
                ORDER BY e.firstName, e.lastName";
                
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $allPayrolls = [];
        while ($row = $result->fetch_assoc()) {
            $computation = computeEmployeePayroll($row['emp_id'], $payrollPeriodId);
            $row['computation'] = $computation;
            $allPayrolls[] = $row;
        }
        
        // Get period info
        $periodSql = "SELECT CONCAT(DATE_FORMAT(date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(date_to, '%M %d, %Y')) as display_period FROM payroll_periods WHERE id = ?";
        $periodStmt = $conn->prepare($periodSql);
        $periodStmt->bind_param("i", $payrollPeriodId);
        $periodStmt->execute();
        $periodResult = $periodStmt->get_result();
        $period = $periodResult->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'employees' => $allPayrolls,
            'payroll_period' => $period['display_period']
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error getting all employees payroll: ' . $e->getMessage());
    }
}

function generatePayroll($input) {
    if (!is_array($input)) {
        throw new Exception('Invalid input data', 400);
    }
    global $conn;
    
    try {
        $selectedEmployees = $input['selected_employees'] ?? [];
        $regenerate = $input['regenerate'] ?? false; // Add regenerate flag
        
        if (empty($selectedEmployees)) {
            throw new Exception('No employees selected for payroll generation');
        }
        
        $currentPeriod = getCurrentPayrollPeriodData();
        if (!$currentPeriod) {
            throw new Exception('No active payroll period found');
        }
        
        $conn->begin_transaction();
        
        $generatedCount = 0;
        $totalAmount = 0;
        
        // Create or update generation history record
        if ($regenerate) {
            $deleteSql = "DELETE FROM payroll_generation_history WHERE payroll_period_id = ?";
            $deleteStmt = $conn->prepare($deleteSql);
            $deleteStmt->bind_param("i", $currentPeriod['id']);
            $deleteStmt->execute();
        }
        
        // Create or update generation history record
        $historySql = "INSERT INTO payroll_generation_history (payroll_period_id, total_employees, generation_status, created_at) 
                      VALUES (?, ?, 'Processing', NOW())";
        $historyStmt = $conn->prepare($historySql);
        $totalEmployees = count($selectedEmployees);
        $historyStmt->bind_param("ii", $currentPeriod['id'], $totalEmployees);
        $historyStmt->execute();
        $historyId = $conn->insert_id ?: getGenerationHistoryId($currentPeriod['id']);
        
        foreach ($selectedEmployees as $empId) {
            // Get employee info
            $empSql = "SELECT firstName, lastName FROM employeelist WHERE emp_id = ?";
            $empStmt = $conn->prepare($empSql);
            $empStmt->bind_param("i", $empId);
            $empStmt->execute();
            $empResult = $empStmt->get_result();
            $employee = $empResult->fetch_assoc();
            
            if (!$employee) continue;
            
            $employeeName = $employee['firstName'] . ' ' . $employee['lastName'];
            $computation = computeEmployeePayroll($empId, $currentPeriod['id']);
            
            // Extract values to variables for bind_param
            $grossPay = floatval($computation['gross_pay']);
            $netPay = floatval($computation['net_pay']);
            $totalDeductions = floatval($computation['total_deductions']);
            $payrollPeriodId = intval($currentPeriod['id']);
            
            // Always allow regeneration - this will update existing records or create new ones
            $recordSql = "INSERT INTO payroll_records (payroll_period_id, emp_id, employee_name, status, gross_pay, net_pay, total_deductions, created_at, updated_at)
                         VALUES (?, ?, ?, 'Generated', ?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE
                         status = 'Generated',
                         gross_pay = VALUES(gross_pay),
                         net_pay = VALUES(net_pay),
                         total_deductions = VALUES(total_deductions),
                         updated_at = NOW()";
                         
            $recordStmt = $conn->prepare($recordSql);
            $recordStmt->bind_param("iisddd", 
                $payrollPeriodId, 
                $empId, 
                $employeeName, 
                $grossPay, 
                $netPay, 
                $totalDeductions
            );
            $recordStmt->execute();
            
            // FIX 1: INCREMENT THE GENERATED COUNT
            $generatedCount++;
            
            // FIX 2: ADD TO TOTAL AMOUNT
            $totalAmount += $grossPay;
            
            $recordId = $conn->insert_id ?: getPayrollRecordId($currentPeriod['id'], $empId);
            
            // Save detailed computation
            savePayrollComputation($recordId, $empId, $currentPeriod['id'], $computation);
            
            // FIXED VERSION - Extract values to variables first:
            // FIXED VERSION - Extract values to variables first:
            try {
                $updateRatesSql = "UPDATE employee_payroll SET 
                    basic_pay_monthly = ?, basic_pay_semi_monthly = ?, rate_per_day = ?, rate_per_hour = ?, rate_per_minute = ?,
                    site_allowance = ?, transportation_allowance = ?,
                    regular_ot_rate = ?, regular_ot_nd = ?, 
                    rest_day_ot = ?, rest_day_ot_plus_ot = ?, rest_day_nd = ?,
                    rh_rate = ?, rh_ot_rate = ?, rh_nd_rate = ?, rh_rot_ot_rate = ?,
                    sh_rate = ?, sh_ot_rate = ?, sh_nd_rate = ?, sh_rot = ?, sh_rot_ot_rate = ?, sh_rot_ot_plus_ot_rate = ?, sh_rot_nd = ?,
                    sss = ?, phic = ?, hdmf = ?, tax = ?,
                    sss_loan = ?, hdmf_loan = ?, teed = ?, staff_house = ?, cash_advance = ?
                    WHERE emp_id = ?";
            
                $updateRatesStmt = $conn->prepare($updateRatesSql);
                
                if ($updateRatesStmt) {
                    // Extract all values
                    $basic_pay_monthly = floatval($computation['basic_pay_monthly'] ?? 0);
                    $basic_pay_semi_monthly = floatval($computation['basic_pay_semi_monthly'] ?? 0);
                    $rate_per_day = floatval($computation['rate_per_day'] ?? 0);
                    $rate_per_hour = floatval($computation['rate_per_hour'] ?? 0);
                    $rate_per_minute = floatval($computation['rate_per_minute'] ?? 0);
                    $site_allowance = floatval($computation['site_allowance'] ?? 0);
                    $transportation_allowance = floatval($computation['transportation_allowance'] ?? 0);
                    $regular_ot_rate = floatval($computation['regular_ot_rate'] ?? 0);
                    $regular_ot_nd_rate = floatval($computation['regular_ot_nd_rate'] ?? 0);
                    $rest_day_ot_rate = floatval($computation['rest_day_ot_rate'] ?? 0);
                    $rest_day_ot_plus_ot_rate = floatval($computation['rest_day_ot_plus_ot_rate'] ?? 0);
                    $rest_day_nd_rate = floatval($computation['rest_day_nd_rate'] ?? 0);
                    $regular_holiday_rate = floatval($computation['regular_holiday_rate'] ?? 0);
                    $regular_holiday_ot_rate = floatval($computation['regular_holiday_ot_rate'] ?? 0);
                    $regular_holiday_nd_rate = floatval($computation['regular_holiday_nd_rate'] ?? 0);
                    $regular_holiday_rot_ot_rate = floatval($computation['regular_holiday_rot_ot_rate'] ?? 0);
                    $special_holiday_rate = floatval($computation['special_holiday_rate'] ?? 0);
                    $special_holiday_ot_rate = floatval($computation['special_holiday_ot_rate'] ?? 0);
                    $special_holiday_nd_rate = floatval($computation['special_holiday_nd_rate'] ?? 0);
                    $special_holiday_rot_rate = floatval($computation['special_holiday_rot_rate'] ?? 0); // NEW
                    $special_holiday_rot_ot_rate = floatval($computation['special_holiday_rot_ot_rate'] ?? 0);
                    $special_holiday_rot_ot_plus_ot_rate = floatval($computation['special_holiday_rot_ot_plus_ot_rate'] ?? 0);
                    $special_holiday_rot_nd_rate = floatval($computation['special_holiday_rot_nd_rate'] ?? 0);
                    $sss_contribution = floatval($computation['sss_contribution'] ?? 0);
                    $phic_contribution = floatval($computation['phic_contribution'] ?? 0);
                    $hdmf_contribution = floatval($computation['hdmf_contribution'] ?? 0);
                    $tax_amount = floatval($computation['tax_amount'] ?? 0);
                    $sss_loan = floatval($computation['sss_loan'] ?? 0);
                    $hdmf_loan = floatval($computation['hdmf_loan'] ?? 0);
                    $teed = floatval($computation['teed'] ?? 0);
                    $staff_house = floatval($computation['staff_house'] ?? 0);
                    $cash_advance = floatval($computation['cash_advance'] ?? 0);
                    
                    $updateRatesStmt->bind_param("ddddddddddddddddddddddddddddddddi",
                        $basic_pay_monthly, $basic_pay_semi_monthly, $rate_per_day, $rate_per_hour, $rate_per_minute,
                        $site_allowance, $transportation_allowance,
                        $regular_ot_rate, $regular_ot_nd_rate,
                        $rest_day_ot_rate, $rest_day_ot_plus_ot_rate, $rest_day_nd_rate,
                        $regular_holiday_rate, $regular_holiday_ot_rate, $regular_holiday_nd_rate, $regular_holiday_rot_ot_rate,
                        $special_holiday_rate, $special_holiday_ot_rate, $special_holiday_nd_rate, $special_holiday_rot_rate,
                        $special_holiday_rot_ot_rate, $special_holiday_rot_ot_plus_ot_rate, $special_holiday_rot_nd_rate,
                        $sss_contribution, $phic_contribution, $hdmf_contribution, $tax_amount,
                        $sss_loan, $hdmf_loan, $teed, $staff_house, $cash_advance,
                        $empId
                    );
                    
                    if (!$updateRatesStmt->execute()) {
                        error_log("Failed to update employee_payroll rates for emp_id: $empId - " . $updateRatesStmt->error);
                    }
                }
            } catch (Exception $e) {
                error_log("Error updating employee_payroll rates: " . $e->getMessage());
            }    
            
        }
        
        // Update generation history
        $updateHistorySql = "UPDATE payroll_generation_history 
                            SET generation_status = 'Completed', 
                                total_amount = ?,
                                completed_at = NOW() 
                            WHERE id = ?";
        $updateHistoryStmt = $conn->prepare($updateHistorySql);
        $updateHistoryStmt->bind_param("di", $totalAmount, $historyId);
        $updateHistoryStmt->execute();
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Payroll generated successfully for {$generatedCount} employees",
            'generated_count' => $generatedCount,
            'total_amount' => $totalAmount,
            'export_data' => prepareExportData($selectedEmployees, $currentPeriod)
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Error generating payroll: ' . $e->getMessage());
    }
}

function regeneratePayroll($input) {
    global $conn;
    
    try {
        error_log("Regenerate Payroll - Start");
        error_log("Input: " . json_encode($input));
        
        $conn->begin_transaction();
        
        // Get current period
        $currentPeriod = getCurrentPayrollPeriodData();
        if (!$currentPeriod) {
            throw new Exception('No active payroll period found');
        }
        
        $payrollPeriodId = $currentPeriod['id'];
        $selectedEmployees = $input['selected_employees'] ?? [];
        
        if (empty($selectedEmployees)) {
            throw new Exception('No employees selected for regeneration');
        }
        
        // Delete existing history for this period to prevent duplicates
        $deleteSql = "DELETE FROM payroll_generation_history WHERE payroll_period_id = ?";
        $deleteStmt = $conn->prepare($deleteSql);
        $deleteStmt->bind_param("i", $payrollPeriodId);
        $deleteStmt->execute();
        
        // Initialize counters
        $totalAmount = 0;
        $generatedCount = 0;
        
        // Process each employee
        foreach ($selectedEmployees as $empId) {
            // Get employee info
            $empSql = "SELECT firstName, lastName FROM employeelist WHERE emp_id = ?";
            $empStmt = $conn->prepare($empSql);
            $empStmt->bind_param("i", $empId);
            $empStmt->execute();
            $empResult = $empStmt->get_result();
            $employee = $empResult->fetch_assoc();
            
            if (!$employee) continue;
            
            $employeeName = $employee['firstName'] . ' ' . $employee['lastName'];
            
            // Compute payroll with fresh calculations
            $computation = computeEmployeePayroll($empId, $payrollPeriodId);
            
            // Extract values to variables for bind_param
            $grossPay = floatval($computation['gross_pay']);
            $netPay = floatval($computation['net_pay']);
            $totalDeductions = floatval($computation['total_deductions']);
            
            // Insert or update payroll record
            $recordSql = "INSERT INTO payroll_records (payroll_period_id, emp_id, employee_name, status, gross_pay, net_pay, total_deductions, created_at, updated_at)
                         VALUES (?, ?, ?, 'Generated', ?, ?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE
                         status = 'Generated',
                         gross_pay = VALUES(gross_pay),
                         net_pay = VALUES(net_pay),
                         total_deductions = VALUES(total_deductions),
                         updated_at = NOW()";
                         
            $recordStmt = $conn->prepare($recordSql);
            $recordStmt->bind_param("iisddd", 
                $payrollPeriodId, 
                $empId, 
                $employeeName, 
                $grossPay, 
                $netPay, 
                $totalDeductions
            );
            $recordStmt->execute();
            
            $recordId = $conn->insert_id ?: getPayrollRecordId($payrollPeriodId, $empId);
            
            // Save detailed computation
            savePayrollComputation($recordId, $empId, $payrollPeriodId, $computation);
            
            // Update employee_payroll rates
            try {
                $updateRatesSql = "UPDATE employee_payroll SET 
                    basic_pay_monthly = ?, basic_pay_semi_monthly = ?, rate_per_day = ?, rate_per_hour = ?, rate_per_minute = ?,
                    site_allowance = ?, transportation_allowance = ?,
                    regular_ot_rate = ?, regular_ot_nd = ?, 
                    rest_day_ot = ?, rest_day_ot_plus_ot = ?, rest_day_nd = ?,
                    rh_rate = ?, rh_ot_rate = ?, rh_nd_rate = ?, rh_rot_ot_rate = ?,
                    sh_rate = ?, sh_ot_rate = ?, sh_nd_rate = ?, sh_rot = ?, sh_rot_ot_rate = ?, sh_rot_ot_plus_ot_rate = ?, sh_rot_nd = ?,
                    sss = ?, phic = ?, hdmf = ?, tax = ?,
                    sss_loan = ?, hdmf_loan = ?, teed = ?, staff_house = ?, cash_advance = ?
                    WHERE emp_id = ?";
            
                $updateRatesStmt = $conn->prepare($updateRatesSql);
                
                if ($updateRatesStmt) {
                    // Extract all values
                    $basic_pay_monthly = floatval($computation['basic_pay_monthly'] ?? 0);
                    $basic_pay_semi_monthly = floatval($computation['basic_pay_semi_monthly'] ?? 0);
                    $rate_per_day = floatval($computation['rate_per_day'] ?? 0);
                    $rate_per_hour = floatval($computation['rate_per_hour'] ?? 0);
                    $rate_per_minute = floatval($computation['rate_per_minute'] ?? 0);
                    $site_allowance = floatval($computation['site_allowance'] ?? 0);
                    $transportation_allowance = floatval($computation['transportation_allowance'] ?? 0);
                    $regular_ot_rate = floatval($computation['regular_ot_rate'] ?? 0);
                    $regular_ot_nd_rate = floatval($computation['regular_ot_nd_rate'] ?? 0);
                    $rest_day_ot_rate = floatval($computation['rest_day_ot_rate'] ?? 0);
                    $rest_day_ot_plus_ot_rate = floatval($computation['rest_day_ot_plus_ot_rate'] ?? 0);
                    $rest_day_nd_rate = floatval($computation['rest_day_nd_rate'] ?? 0);
                    $regular_holiday_rate = floatval($computation['regular_holiday_rate'] ?? 0);
                    $regular_holiday_ot_rate = floatval($computation['regular_holiday_ot_rate'] ?? 0);
                    $regular_holiday_nd_rate = floatval($computation['regular_holiday_nd_rate'] ?? 0);
                    $regular_holiday_rot_ot_rate = floatval($computation['regular_holiday_rot_ot_rate'] ?? 0);
                    $special_holiday_rate = floatval($computation['special_holiday_rate'] ?? 0);
                    $special_holiday_ot_rate = floatval($computation['special_holiday_ot_rate'] ?? 0);
                    $special_holiday_nd_rate = floatval($computation['special_holiday_nd_rate'] ?? 0);
                    $special_holiday_rot_rate = floatval($computation['special_holiday_rot_rate'] ?? 0);
                    $special_holiday_rot_ot_rate = floatval($computation['special_holiday_rot_ot_rate'] ?? 0);
                    $special_holiday_rot_ot_plus_ot_rate = floatval($computation['special_holiday_rot_ot_plus_ot_rate'] ?? 0);
                    $special_holiday_rot_nd_rate = floatval($computation['special_holiday_rot_nd_rate'] ?? 0);
                    $sss_contribution = floatval($computation['sss_contribution'] ?? 0);
                    $phic_contribution = floatval($computation['phic_contribution'] ?? 0);
                    $hdmf_contribution = floatval($computation['hdmf_contribution'] ?? 0);
                    $tax_amount = floatval($computation['tax_amount'] ?? 0);
                    $sss_loan = floatval($computation['sss_loan'] ?? 0);
                    $hdmf_loan = floatval($computation['hdmf_loan'] ?? 0);
                    $teed = floatval($computation['teed'] ?? 0);
                    $staff_house = floatval($computation['staff_house'] ?? 0);
                    $cash_advance = floatval($computation['cash_advance'] ?? 0);
                    
                    $updateRatesStmt->bind_param("ddddddddddddddddddddddddddddddddi",
                        $basic_pay_monthly, $basic_pay_semi_monthly, $rate_per_day, $rate_per_hour, $rate_per_minute,
                        $site_allowance, $transportation_allowance,
                        $regular_ot_rate, $regular_ot_nd_rate,
                        $rest_day_ot_rate, $rest_day_ot_plus_ot_rate, $rest_day_nd_rate,
                        $regular_holiday_rate, $regular_holiday_ot_rate, $regular_holiday_nd_rate, $regular_holiday_rot_ot_rate,
                        $special_holiday_rate, $special_holiday_ot_rate, $special_holiday_nd_rate, $special_holiday_rot_rate,
                        $special_holiday_rot_ot_rate, $special_holiday_rot_ot_plus_ot_rate, $special_holiday_rot_nd_rate,
                        $sss_contribution, $phic_contribution, $hdmf_contribution, $tax_amount,
                        $sss_loan, $hdmf_loan, $teed, $staff_house, $cash_advance,
                        $empId
                    );
                    
                    if (!$updateRatesStmt->execute()) {
                        error_log("Failed to update employee_payroll rates for emp_id: $empId - " . $updateRatesStmt->error);
                    }
                }
            } catch (Exception $e) {
                error_log("Error updating employee_payroll rates: " . $e->getMessage());
            }
            
            $totalAmount += floatval($computation['net_pay'] ?? 0);
            $generatedCount++;
        }
        
        // Insert new history record
        $historySql = "INSERT INTO payroll_generation_history 
                       (payroll_period_id, total_employees, total_amount, generation_status, created_at, completed_at) 
                       VALUES (?, ?, ?, 'Completed', NOW(), NOW())";
        $historyStmt = $conn->prepare($historySql);
        $historyStmt->bind_param("iid", $payrollPeriodId, $generatedCount, $totalAmount);
        $historyStmt->execute();
        $historyId = $conn->insert_id;
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Payroll regenerated successfully',
            'generated_count' => $generatedCount,
            'history_id' => $historyId,
            'total_amount' => $totalAmount,
            'export_data' => prepareExportData($selectedEmployees, $currentPeriod)
        ]);
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        error_log("Regenerate Error: " . $e->getMessage());
        throw $e;
    }
}

function releasePayslip($input) {
    global $conn;
    
    try {
        $selectedEmployees = $input['selected_employees'] ?? [];
        
        if (empty($selectedEmployees)) {
            throw new Exception('No employees selected for payslip release');
        }
        
        $currentPeriod = getCurrentPayrollPeriodData();
        if (!$currentPeriod) {
            throw new Exception('No active payroll period found');
        }
        
        $conn->begin_transaction();
        
        $releasedCount = 0;
        
        foreach ($selectedEmployees as $empId) {
            // Update payroll record status
            $updateSql = "UPDATE payroll_records 
                         SET status = 'Released', updated_at = NOW() 
                         WHERE payroll_period_id = ? AND emp_id = ? AND status IN ('Generated', 'Released')";
            $updateStmt = $conn->prepare($updateSql);
            $updateStmt->bind_param("ii", $currentPeriod['id'], $empId);
            $updateStmt->execute();
            
            if ($updateStmt->affected_rows > 0) {
                // Get payroll record ID and employee name
                $recordSql = "SELECT pr.id, pr.employee_name 
                             FROM payroll_records pr 
                             WHERE pr.payroll_period_id = ? AND pr.emp_id = ?";
                $recordStmt = $conn->prepare($recordSql);
                $recordStmt->bind_param("ii", $currentPeriod['id'], $empId);
                $recordStmt->execute();
                $recordResult = $recordStmt->get_result();
                $record = $recordResult->fetch_assoc();
                
                if ($record) {
                    // Insert into payslip_releases table
                    $releaseSql = "INSERT INTO payslip_releases 
                                  (payroll_record_id, emp_id, payroll_period_id, employee_name, excel_generated, pdf_generated) 
                                  VALUES (?, ?, ?, ?, 1, 0)
                                  ON DUPLICATE KEY UPDATE 
                                  release_date = NOW(), excel_generated = 1";
                    $releaseStmt = $conn->prepare($releaseSql);
                    $releaseStmt->bind_param("iiis", $record['id'], $empId, $currentPeriod['id'], $record['employee_name']);
                    $releaseStmt->execute();
                    
                    $releasedCount++;
                }
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Payslips released successfully for {$releasedCount} employees",
            'released_count' => $releasedCount
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Error releasing payslips: ' . $e->getMessage());
    }
}

function updateEmployeePayrollData($input) {
    global $conn;
    
    try {
        $empId = $input['emp_id'] ?? null;
        $payrollPeriodId = $input['payroll_period_id'] ?? null;
        $updates = $input['updates'] ?? [];
        
        error_log("=== PAYROLL UPDATE DEBUG ===");
        error_log("Employee ID: " . $empId);
        error_log("Payroll Period ID: " . $payrollPeriodId);
        error_log("Updates: " . json_encode($updates));
        
        if (!$empId || !$payrollPeriodId || empty($updates)) {
            throw new Exception('Employee ID, Payroll Period ID, and updates are required');
        }
        
        $conn->begin_transaction();
        $updateCount = 0;
        
        // Check if employee_payroll record exists, if not create it
        $checkSql = "SELECT emp_id FROM employee_payroll WHERE emp_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $empId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            error_log("Creating new employee_payroll record for emp_id: $empId");
            $createSql = "INSERT INTO employee_payroll (emp_id) VALUES (?)";
            $createStmt = $conn->prepare($createSql);
            $createStmt->bind_param("i", $empId);
            $createStmt->execute();
        }
        
        // Process each update individually
        foreach ($updates as $field => $value) {
            $floatValue = floatval($value);
            error_log("Processing field: $field = $floatValue");
            
            // Update employee_payroll table fields
            switch ($field) {
                case 'basic_pay_monthly':
                    $sql = "UPDATE employee_payroll SET basic_pay_monthly = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    error_log("Updated basic_pay_monthly to: $floatValue");
                    break;
                    
                case 'basic_pay_semi_monthly':
                    $sql = "UPDATE employee_payroll SET basic_pay_semi_monthly = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    error_log("Updated basic_pay_semi_monthly to: $floatValue");
                    break;
                    
                case 'site_allowance':
                    $sql = "UPDATE employee_payroll SET site_allowance = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    error_log("Updated site_allowance to: $floatValue (affected rows: " . $stmt->affected_rows . ")");
                    break;

                    
                case 'transportation_allowance':
                    $sql = "UPDATE employee_payroll SET transportation_allowance = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    error_log("Updated transportation_allowance to: $floatValue");
                    break;
                    
                case 'training_days':
                    $sql = "UPDATE employee_payroll SET number_of_training_days = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $intValue = intval($value);
                    $stmt->bind_param("ii", $intValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    error_log("Updated training_days to: $intValue");
                    break;

                    case 'travel_time_hours':
                        updateAttendanceField($empId, $payrollPeriodId, 'travel_time', intval($value));
                        $updateCount++;
                        error_log("Updated travel_time_hours to: " . intval($value));
                        break;
                    
                case 'sss_contribution':
                    $sql = "UPDATE employee_payroll SET sss = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                case 'phic_contribution':
                    $sql = "UPDATE employee_payroll SET phic = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                case 'hdmf_contribution':
                    $sql = "UPDATE employee_payroll SET hdmf = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                case 'tax_amount':
                    $sql = "UPDATE employee_payroll SET tax = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                case 'sss_loan':
                    $sql = "UPDATE employee_payroll SET sss_loan = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                case 'hdmf_loan':
                    $sql = "UPDATE employee_payroll SET hdmf_loan = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                case 'teed':
                    $sql = "UPDATE employee_payroll SET teed = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                case 'staff_house':
                    $sql = "UPDATE employee_payroll SET staff_house = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                case 'cash_advance':
                    $sql = "UPDATE employee_payroll SET cash_advance = ? WHERE emp_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("di", $floatValue, $empId);
                    $stmt->execute();
                    $updateCount++;
                    break;
                    
                // Handle hours fields - update attendancelist table
                case 'regular_holiday_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'regular_holiday', intval($value * 60));
                    $updateCount++;
                    break;
                case 'regular_holiday_ot_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'regular_holiday_ot', intval($value * 60));
                    $updateCount++;
                    break;
                case 'regular_holiday_nd_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'regular_holiday_nd', intval($value * 60));
                    $updateCount++;
                    break;
                case 'regular_holiday_rot_ot_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'regular_holiday_rot_ot', intval($value * 60));
                    $updateCount++;
                    break;
                case 'special_holiday_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'special_holiday', intval($value * 60));
                    $updateCount++;
                    break;
                case 'special_holiday_ot_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'special_holiday_ot', intval($value * 60));
                    $updateCount++;
                    break;
                case 'special_holiday_nd_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'special_holiday_nd', intval($value * 60));
                    $updateCount++;
                    break;
                case 'special_holiday_rot_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'special_holiday_rot', intval($value * 60));
                    $updateCount++;
                    break;
                case 'special_holiday_rot_ot_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'special_holiday_rot_ot', intval($value * 60));
                    $updateCount++;
                    break;
                case 'special_holiday_rot_nd_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'special_holiday_rot_nd', intval($value * 60));
                    $updateCount++;
                    break;
                case 'regular_ot_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'regular_ot', intval($value * 60));
                    $updateCount++;
                    break;
                case 'regular_ot_nd_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'regular_ot_nd', intval($value * 60));
                    $updateCount++;
                    break;
                case 'rest_day_ot_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'rest_day_ot', intval($value * 60));
                    $updateCount++;
                    break;
                case 'rest_day_ot_plus_ot_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'rest_day_ot_plus_ot', intval($value * 60));
                    $updateCount++;
                    break;
                case 'rest_day_nd_hours':
                    updateAttendanceField($empId, $payrollPeriodId, 'rest_day_nd', intval($value * 60));
                    $updateCount++;
                    break;
                case 'late_undertime_minutes':
                    updateAttendanceField($empId, $payrollPeriodId, 'late_undertime', intval($value));
                    $updateCount++;
                    break;
                case 'absences_days':
                    updateAbsencesDays($empId, $payrollPeriodId, intval($value));
                    $updateCount++;
                    break;
                    
                default:
                    error_log("Unknown field: $field");
                    break;
            }
        }
        
        // Regenerate payroll computation after updates
        $computation = computeEmployeePayroll($empId, $payrollPeriodId);
        error_log("Recomputed payroll - Gross: {$computation['gross_pay']}, Net: {$computation['net_pay']}, Site Allowance: {$computation['site_allowance']}");
        
        // Update payroll_records table with new totals
        $recordSql = "UPDATE payroll_records 
                     SET gross_pay = ?, net_pay = ?, total_deductions = ?, updated_at = NOW() 
                     WHERE emp_id = ? AND payroll_period_id = ?";
        $recordStmt = $conn->prepare($recordSql);
        $grossPay = floatval($computation['gross_pay']);
        $netPay = floatval($computation['net_pay']);
        $totalDeductions = floatval($computation['total_deductions']);
        $recordStmt->bind_param("dddii", $grossPay, $netPay, $totalDeductions, $empId, $payrollPeriodId);
        
        if (!$recordStmt->execute()) {
            error_log("Error updating payroll_records: " . $recordStmt->error);
        } else {
            error_log("Successfully updated payroll_records");
        }
        
        $conn->commit();
        error_log("Transaction committed successfully");
        
        echo json_encode([
            'success' => true,
            'message' => "Employee payroll data updated successfully ($updateCount fields)",
            'updated_computation' => $computation,
            'debug_info' => [
                'updates_processed' => $updateCount
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error in updateEmployeePayrollData: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'error_details' => $e->getTraceAsString()
        ]);
    }
}

function updateAttendanceField($empId, $payrollPeriodId, $fieldName, $value) {
    global $conn;
    
    // Get period dates
    $periodSql = "SELECT date_from, date_to FROM payroll_periods WHERE id = ?";
    $periodStmt = $conn->prepare($periodSql);
    $periodStmt->bind_param("i", $payrollPeriodId);
    $periodStmt->execute();
    $periodResult = $periodStmt->get_result();
    $period = $periodResult->fetch_assoc();
    
    if (!$period) {
        error_log("Period not found for ID: $payrollPeriodId");
        return;
    }
    
    // Find or create attendance record for this employee in the period
    $checkAttendanceSql = "SELECT id FROM attendancelist 
                          WHERE emp_id = ? AND date >= ? AND date <= ? 
                          ORDER BY date ASC LIMIT 1";
    $checkAttendanceStmt = $conn->prepare($checkAttendanceSql);
    $checkAttendanceStmt->bind_param("iss", $empId, $period['date_from'], $period['date_to']);
    $checkAttendanceStmt->execute();
    $checkAttendanceResult = $checkAttendanceStmt->get_result();
    $attendanceRecord = $checkAttendanceResult->fetch_assoc();
    
    if (!$attendanceRecord) {
        // Create a new attendance record
        $createAttendanceSql = "INSERT INTO attendancelist (emp_id, date, firstName, lastName) 
                               SELECT ?, ?, firstName, lastName FROM employeelist WHERE emp_id = ?";
        $createAttendanceStmt = $conn->prepare($createAttendanceSql);
        $createAttendanceStmt->bind_param("isi", $empId, $period['date_from'], $empId);
        $createAttendanceStmt->execute();
        $attendanceId = $conn->insert_id;
        error_log("Created new attendance record with ID: $attendanceId");
    } else {
        $attendanceId = $attendanceRecord['id'];
    }
    
    // Update the attendance record
    $sql = "UPDATE attendancelist SET $fieldName = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $value, $attendanceId);
    
    if (!$stmt->execute()) {
        error_log("Error updating attendance field $fieldName: " . $stmt->error);
    } else {
        error_log("Successfully updated $fieldName to $value for attendance ID: $attendanceId");
    }
}

function updateAbsencesDays($empId, $payrollPeriodId, $absentDays) {
    global $conn;
    
    // Get period dates
    $periodSql = "SELECT date_from, date_to FROM payroll_periods WHERE id = ?";
    $periodStmt = $conn->prepare($periodSql);
    $periodStmt->bind_param("i", $payrollPeriodId);
    $periodStmt->execute();
    $periodResult = $periodStmt->get_result();
    $period = $periodResult->fetch_assoc();
    
    if (!$period) return;
    
    // Reset all absent flags for this employee in the period
    $resetSql = "UPDATE attendancelist SET absent = 0 WHERE emp_id = ? AND date >= ? AND date <= ?";
    $resetStmt = $conn->prepare($resetSql);
    $resetStmt->bind_param("iss", $empId, $period['date_from'], $period['date_to']);
    $resetStmt->execute();
    
    // Set absent flags for the specified number of days
    if ($absentDays > 0) {
        $setAbsentSql = "UPDATE attendancelist SET absent = 1 
                        WHERE emp_id = ? AND date >= ? AND date <= ? 
                        ORDER BY date ASC LIMIT ?";
        $setAbsentStmt = $conn->prepare($setAbsentSql);
        $setAbsentStmt->bind_param("issi", $empId, $period['date_from'], $period['date_to'], $absentDays);
        $setAbsentStmt->execute();
    }
    
    error_log("Updated absences for emp_id $empId: $absentDays days");
}

function uploadPayslipPDF() {
    global $conn;
    
    try {
        if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No PDF file uploaded or upload error occurred');
        }
        
        $empId = $_POST['emp_id'] ?? null;
        $payrollPeriodId = $_POST['payroll_period_id'] ?? null;
        
        if (!$empId || !$payrollPeriodId) {
            throw new Exception('Employee ID and Payroll Period ID required');
        }
        
        // Validate employee exists
        $empCheckSql = "SELECT emp_id FROM employeelist WHERE emp_id = ?";
        $empCheckStmt = $conn->prepare($empCheckSql);
        $empCheckStmt->bind_param("i", $empId);
        $empCheckStmt->execute();
        $empCheckResult = $empCheckStmt->get_result();
        
        if ($empCheckResult->num_rows === 0) {
            throw new Exception('Employee not found');
        }
        
        // Validate payroll period exists
        $periodCheckSql = "SELECT id FROM payroll_periods WHERE id = ?";
        $periodCheckStmt = $conn->prepare($periodCheckSql);
        $periodCheckStmt->bind_param("i", $payrollPeriodId);
        $periodCheckStmt->execute();
        $periodCheckResult = $periodCheckStmt->get_result();
        
        if ($periodCheckResult->num_rows === 0) {
            throw new Exception('Payroll period not found');
        }
        
        // Create directory if it doesn't exist
        $uploadDir = __DIR__ . '/pdfs/payslips/';
        if (!file_exists($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }
        
        // Generate filename
        $empIdFormatted = 'DIF' . str_pad($empId, 3, '0', STR_PAD_LEFT);
        $periodFormatted = str_pad($payrollPeriodId, 3, '0', STR_PAD_LEFT);
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "payslip_{$empIdFormatted}_period_{$periodFormatted}_{$timestamp}.pdf";
        $destinationPath = $uploadDir . $filename;
        $relativePath = 'pdfs/payslips/' . $filename;
        
        // Validate file type
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($fileInfo, $_FILES['pdf']['tmp_name']);
        finfo_close($fileInfo);
        
        if ($mimeType !== 'application/pdf') {
            throw new Exception('Invalid file type. Only PDF files are allowed');
        }
        
        // Move uploaded file
        if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $destinationPath)) {
            throw new Exception('Failed to save PDF file');
        }
        
        // Get file size
        $fileSize = filesize($destinationPath);
        if ($fileSize === false) {
            throw new Exception('Failed to get file size');
        }
        
        // Store in database with proper error handling
        $sql = "INSERT INTO employee_payslip_pdfs 
                (emp_id, payroll_period_id, pdf_filename, pdf_path, file_size, generated_at, status) 
                VALUES (?, ?, ?, ?, ?, NOW(), 'active')
                ON DUPLICATE KEY UPDATE 
                pdf_filename = VALUES(pdf_filename),
                pdf_path = VALUES(pdf_path),
                file_size = VALUES(file_size),
                generated_at = NOW(),
                status = 'active'";
                
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Database prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("iissi", $empId, $payrollPeriodId, $filename, $relativePath, $fileSize);
        
        if (!$stmt->execute()) {
            // If database insert fails, remove the uploaded file
            unlink($destinationPath);
            throw new Exception('Database insert failed: ' . $stmt->error);
        }
        
        $insertId = $conn->insert_id;
        
        // Log successful upload
        error_log("PDF uploaded successfully: $filename, DB ID: $insertId, Size: $fileSize bytes");
        
        echo json_encode([
            'success' => true,
            'message' => 'PDF uploaded and stored successfully',
            'filename' => $filename,
            'file_size' => $fileSize,
            'file_path' => $relativePath,
            'database_id' => $insertId
        ]);
        
    } catch (Exception $e) {
        error_log("PDF upload error: " . $e->getMessage());
        
        // Clean up file if it was uploaded but database failed
        if (isset($destinationPath) && file_exists($destinationPath)) {
            unlink($destinationPath);
        }
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'error_code' => 'UPLOAD_FAILED'
        ]);
    }
}

function updateEmployeePayroll($input) {
    global $conn;
    
    try {
        $empId = $input['emp_id'] ?? null;
        $payrollPeriodId = $input['payroll_period_id'] ?? null;
        $computationData = $input['computation'] ?? [];
        
        if (!$empId || !$payrollPeriodId) {
            throw new Exception('Employee ID and Payroll Period ID required');
        }
        
        // Get or create payroll record
        $recordId = getPayrollRecordId($payrollPeriodId, $empId);
        if (!$recordId) {
            // Create new record
            $empSql = "SELECT CONCAT(firstName, ' ', lastName) as name FROM employeelist WHERE emp_id = ?";
            $empStmt = $conn->prepare($empSql);
            $empStmt->bind_param("i", $empId);
            $empStmt->execute();
            $empResult = $empStmt->get_result();
            $empData = $empResult->fetch_assoc();
            
            $insertSql = "INSERT INTO payroll_records (payroll_period_id, emp_id, employee_name, status) VALUES (?, ?, ?, 'Generated')";
            $insertStmt = $conn->prepare($insertSql);
            $insertStmt->bind_param("iis", $payrollPeriodId, $empId, $empData['name']);
            $insertStmt->execute();
            $recordId = $conn->insert_id;
        }
        
        // Update computation
        savePayrollComputation($recordId, $empId, $payrollPeriodId, $computationData);
        
        // Extract values for bind_param
        $grossPay = floatval($computationData['gross_pay'] ?? 0);
        $netPay = floatval($computationData['net_pay'] ?? 0);
        $totalDeductions = floatval($computationData['total_deductions'] ?? 0);
        
        // Update payroll record totals
        $updateSql = "UPDATE payroll_records 
                     SET gross_pay = ?, net_pay = ?, total_deductions = ?, updated_at = NOW() 
                     WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->bind_param("dddi", 
            $grossPay, 
            $netPay, 
            $totalDeductions, 
            $recordId
        );
        $updateStmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Employee payroll updated successfully'
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error updating employee payroll: ' . $e->getMessage());
    }
}

// Helper Functions

function getCurrentPayrollPeriodData() {
    global $conn;
    
    $today = date('Y-m-d');
    
    $sql = "SELECT pp.*, 
                   CONCAT(DATE_FORMAT(pp.date_from, '%M %d, %Y'), ' - ', DATE_FORMAT(pp.date_to, '%M %d, %Y')) as display_period
            FROM payroll_periods pp
            WHERE DATE(pp.date_from) <= DATE(?) AND DATE(pp.date_to) >= DATE(?)
            ORDER BY pp.date_from DESC
            LIMIT 1";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $today, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

function getSelectedEmployeesPayroll() {
    global $conn;
    
    try {
        $empIds = $_GET['emp_ids'] ?? '';
        if (empty($empIds)) {
            throw new Exception('Employee IDs required');
        }
        
        $empIdArray = explode(',', $empIds);
        $placeholders = str_repeat('?,', count($empIdArray) - 1) . '?';
        
        $currentPeriod = getCurrentPayrollPeriodData();
        if (!$currentPeriod) {
            throw new Exception('No active payroll period found');
        }
        
        $sql = "SELECT 
                    e.emp_id,
                    CONCAT('DIF', LPAD(e.emp_id, 3, '0')) as employee_id,
                    CONCAT(e.firstName, ' ', e.lastName) as employee_name,
                    e.firstName,
                    e.lastName,
                    e.position,
                    u.profile_image,
                    COALESCE(ep.sss_account, '') as sss_account,
                    COALESCE(ep.phic_account, '') as phic_account,
                    COALESCE(ep.hdmf_account, '') as hdmf_account,
                    COALESCE(ep.tax_account, '') as tax_account
                FROM employeelist e
                LEFT JOIN useraccounts u ON e.emp_id = u.id
                LEFT JOIN employee_payroll ep ON e.emp_id = ep.emp_id
                WHERE e.emp_id IN ($placeholders)
                AND e.role = 'employee' AND e.status = 'active'
                ORDER BY e.firstName, e.lastName";
                
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($empIdArray)), ...$empIdArray);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $selectedPayrolls = [];
        while ($row = $result->fetch_assoc()) {
            $computation = computeEmployeePayroll($row['emp_id'], $currentPeriod['id']);
            $row['computation'] = $computation;
            $selectedPayrolls[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'employees' => $selectedPayrolls,
            'payroll_period' => $currentPeriod['display_period']
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error getting selected employees payroll: ' . $e->getMessage());
    }
}



function computeEmployeePayroll($empId, $payrollPeriodId) {
    global $conn;
    
    try {
        // Get employee payroll settings
        $payrollSql = "SELECT * FROM employee_payroll WHERE emp_id = ?";
        $payrollStmt = $conn->prepare($payrollSql);
        $payrollStmt->bind_param("i", $empId);
        $payrollStmt->execute();
        $payrollResult = $payrollStmt->get_result();
        $payrollData = $payrollResult->fetch_assoc();
        
        // Get payroll period dates
        $periodSql = "SELECT date_from, date_to FROM payroll_periods WHERE id = ?";
        $periodStmt = $conn->prepare($periodSql);
        $periodStmt->bind_param("i", $payrollPeriodId);
        $periodStmt->execute();
        $periodResult = $periodStmt->get_result();
        $period = $periodResult->fetch_assoc();
        
        if (!$period) {
            throw new Exception('Payroll period not found');
        }
        
        // Get attendance data using YOUR ACTUAL COLUMN NAMES
        $attendanceSql = "SELECT 
                SUM(COALESCE(total_workhours, 0)) as total_regular_minutes,
                SUM(COALESCE(overtime, 0)) as total_overtime_minutes,
                SUM(COALESCE(regular_ot, 0)) as total_regular_ot_minutes,
                SUM(COALESCE(regular_ot_nd, 0)) as total_regular_ot_nd_minutes,
                SUM(COALESCE(rest_day_ot, 0)) as total_rest_day_ot_minutes,
                SUM(COALESCE(rest_day_ot_plus_ot, 0)) as total_rest_day_ot_plus_ot_minutes,
                SUM(COALESCE(rest_day_nd, 0)) as total_rest_day_nd_minutes,
                SUM(COALESCE(regular_holiday, 0)) as total_regular_holiday_minutes,
                SUM(COALESCE(regular_holiday_ot, 0)) as total_regular_holiday_ot_minutes,
                SUM(COALESCE(regular_holiday_nd, 0)) as total_regular_holiday_nd_minutes,
                SUM(COALESCE(regular_holiday_rot_ot, 0)) as total_regular_holiday_rot_ot_minutes,
                SUM(COALESCE(special_holiday, 0)) as total_special_holiday_minutes,
                SUM(COALESCE(special_holiday_ot, 0)) as total_special_holiday_ot_minutes,
                SUM(COALESCE(special_holiday_nd, 0)) as total_special_holiday_nd_minutes,
                SUM(COALESCE(special_holiday_rot, 0)) as total_special_holiday_rot_minutes,
                SUM(COALESCE(special_holiday_rot_ot, 0)) as total_special_holiday_rot_ot_minutes,
                SUM(COALESCE(special_holiday_rot_nd, 0)) as total_special_holiday_rot_nd_minutes,
                SUM(COALESCE(late_undertime, 0)) as total_late_undertime_minutes,
                SUM(COALESCE(travel_time, 0)) as total_travel_time_hours,
                COUNT(CASE WHEN absent = 1 THEN 1 END) as absent_days
            FROM attendancelist 
            WHERE emp_id = ? 
            AND date >= ? 
            AND date <= ?";
                         
        $attendanceStmt = $conn->prepare($attendanceSql);
        $attendanceStmt->bind_param("iss", $empId, $period['date_from'], $period['date_to']);
        $attendanceStmt->execute();
        $attendanceResult = $attendanceStmt->get_result();
        $attendance = $attendanceResult->fetch_assoc();
        
        // Initialize computation with all values - SET EVERYTHING TO 0 BY DEFAULT
        $computation = [
            // Basic Pay
            'basic_pay_monthly' => floatval($payrollData['basic_pay_monthly'] ?? 0),
            'basic_pay_semi_monthly' => floatval($payrollData['basic_pay_semi_monthly'] ?? 0),
            'rate_per_day' => floatval($payrollData['rate_per_day'] ?? 0),
            'rate_per_hour' => floatval($payrollData['rate_per_hour'] ?? 0),
            'rate_per_minute' => floatval($payrollData['rate_per_minute'] ?? 0),
            
            // Allowances
            'site_allowance' => floatval($payrollData['site_allowance'] ?? 0),
            'transportation_allowance' => floatval($payrollData['transportation_allowance'] ?? 0),
            'total_allowances' => floatval($payrollData['site_allowance'] ?? 0) + floatval($payrollData['transportation_allowance'] ?? 0),
            
            // Training Days
            'training_days' => intval($payrollData['number_of_training_days'] ?? 0),

            'travel_time_hours' => intval($attendance['total_travel_time_hours'] ?? 0),
            'travel_time_amount' => 0, // Will be calculated by formula
            
            // Regular Hours
            'regular_hours' => floor(floatval($attendance['total_regular_minutes'] ?? 0) / 60),
            'regular_hours_amount' => 0,
            
            // Regular Overtime
            'regular_ot_hours' => floor(floatval($attendance['total_regular_ot_minutes'] ?? 0) / 60),
            'regular_ot_amount' => 0,
            'regular_ot_rate' => 0,
            'regular_ot_nd_hours' => floor(floatval($attendance['total_regular_ot_nd_minutes'] ?? 0) / 60),
            'regular_ot_nd_amount' => 0,
            'regular_ot_nd_rate' => 0,
            
            // Rest Day
            'rest_day_ot_hours' => floor(floatval($attendance['total_rest_day_ot_minutes'] ?? 0) / 60),
            'rest_day_ot_amount' => 0,
            'rest_day_ot_rate' => 0,
            'rest_day_ot_plus_ot_hours' => floor(floatval($attendance['total_rest_day_ot_plus_ot_minutes'] ?? 0) / 60),
            'rest_day_ot_plus_ot_amount' => 0,
            'rest_day_ot_plus_ot_rate' => 0,
            'rest_day_nd_hours' => floor(floatval($attendance['total_rest_day_nd_minutes'] ?? 0) / 60),
            'rest_day_nd_amount' => 0,
            'rest_day_nd_rate' => 0,
            
            // Regular Holiday Pay
            'regular_holiday_hours' => floor(floatval($attendance['total_regular_holiday_minutes'] ?? 0) / 60),
            'regular_holiday_amount' => 0,
            'regular_holiday_rate' => 0,
            'regular_holiday_ot_hours' => floor(floatval($attendance['total_regular_holiday_ot_minutes'] ?? 0) / 60),
            'regular_holiday_ot_amount' => 0,
            'regular_holiday_ot_rate' => 0,
            'regular_holiday_nd_hours' => floor(floatval($attendance['total_regular_holiday_nd_minutes'] ?? 0) / 60),
            'regular_holiday_nd_amount' => 0,
            'regular_holiday_nd_rate' => 0,
            'regular_holiday_rot_hours' => 0,
            'regular_holiday_rot_amount' => 0,
            'regular_holiday_rot_rate' => 0,
            'regular_holiday_rot_ot_hours' => floor(floatval($attendance['total_regular_holiday_rot_ot_minutes'] ?? 0) / 60),
            'regular_holiday_rot_ot_amount' => 0,
            'regular_holiday_rot_ot_rate' => 0,
            
            // Special Holiday Pay
            'special_holiday_hours' => floor(floatval($attendance['total_special_holiday_minutes'] ?? 0) / 60),
            'special_holiday_amount' => 0,
            'special_holiday_rate' => 0,
            'special_holiday_ot_hours' => floor(floatval($attendance['total_special_holiday_ot_minutes'] ?? 0) / 60),
            'special_holiday_ot_amount' => 0,
            'special_holiday_ot_rate' => 0,
            'special_holiday_nd_hours' => floor(floatval($attendance['total_special_holiday_nd_minutes'] ?? 0) / 60),
            'special_holiday_nd_amount' => 0,
            'special_holiday_nd_rate' => 0,
            'special_holiday_rot_hours' => floor(floatval($attendance['total_special_holiday_rot_minutes'] ?? 0) / 60),
            'special_holiday_rot_amount' => 0,
            'special_holiday_rot_rate' => floatval($payrollData['sh_rot'] ?? 0),
            'special_holiday_rot_ot_hours' => floor(floatval($attendance['total_special_holiday_rot_ot_minutes'] ?? 0) / 60),
            'special_holiday_rot_ot_amount' => 0,
            'special_holiday_rot_ot_rate' => 0,
            'special_holiday_rot_nd_hours' => floor(floatval($attendance['total_special_holiday_rot_nd_minutes'] ?? 0) / 60),
            'special_holiday_rot_nd_amount' => 0,
            'special_holiday_rot_nd_rate' => 0,
            'special_holiday_rot_ot_plus_ot_hours' => 0,
            'special_holiday_rot_ot_plus_ot_amount' => 0,
            'special_holiday_rot_ot_plus_ot_rate' => 0,
            
            // Deductions - FORCED TO 0
            'late_undertime_minutes' => floatval($attendance['total_late_undertime_minutes'] ?? 0),
            'late_undertime_amount' => 0, // FORCED TO 0 - ONLY FROM FORMULA
            'absences_days' => floatval($attendance['absent_days'] ?? 0),
            'absences_amount' => 0, // FORCED TO 0 - ONLY FROM FORMULA
            
            // Government Contributions
            'sss_contribution' => floatval($payrollData['sss'] ?? 0),
            'phic_contribution' => floatval($payrollData['phic'] ?? 0),
            'hdmf_contribution' => floatval($payrollData['hdmf'] ?? 0),
            'tax_amount' => floatval($payrollData['tax'] ?? 0),
            
            // Loans and Other Deductions
            'sss_loan' => floatval($payrollData['sss_loan'] ?? 0),
            'hdmf_loan' => floatval($payrollData['hdmf_loan'] ?? 0),
            'teed' => floatval($payrollData['teed'] ?? 0),
            'staff_house' => floatval($payrollData['staff_house'] ?? 0),
            'cash_advance' => floatval($payrollData['cash_advance'] ?? 0)
        ];
        
        // Get pay component formulas and apply them
        $formulas = getPayComponentFormulas();
        
        // DEBUG: Log what formulas we found
        error_log("Available formulas: " . print_r(array_keys($formulas), true));
        
        // Create base rates array for formula calculation
        $baseRates = [
            'Rate Per Hour' => $computation['rate_per_hour'],
            'Rate Per Day' => $computation['rate_per_day'], 
            'Rate Per Minute' => $computation['rate_per_minute'],
            'Basic Pay Monthly' => $computation['basic_pay_monthly'],
            'Basic Pay Semi-Monthly' => $computation['basic_pay_semi_monthly'],
            'Basic Pay Semi Monthly' => $computation['basic_pay_semi_monthly'],
            'Basic pay-Monthly' => $computation['basic_pay_monthly'],
            'Basic Pay-Semi-Monthly' => $computation['basic_pay_semi_monthly'],
            'Special Holiday + ROT' => floatval($payrollData['sh_rot'] ?? 0), // ADD THIS LINE
        ];
        
        // DEBUG: Log what's in base rates
        error_log("Base rates array: " . print_r($baseRates, true));
        error_log("=== DEBUGGING YOUR FORMULA ===");
        error_log("Base Pay Semi-Monthly value: " . $computation['basic_pay_semi_monthly']);
        error_log("Available base rates: " . print_r(array_keys($baseRates), true));

        // Test your formula manually
        if ($computation['basic_pay_semi_monthly'] > 0) {
            $testResult = $computation['basic_pay_semi_monthly'] / 261 * 12;
            error_log("Manual calculation test: {$computation['basic_pay_semi_monthly']} / 261 * 12 = $testResult");
        }

        // Also debug what's in the formulas array
        error_log("Available pay component formulas: " . print_r(array_keys($formulas), true));

        // Check if your specific Rate Per Day formula exists
        if (isset($formulas['Rate Per Day'])) {
            error_log("Rate Per Day formula found: " . print_r($formulas['Rate Per Day'], true));
        } else {
            error_log("Rate Per Day formula NOT found!");
        }
        error_log("=== END DEBUG ===");


        $rateOnlyFormulas = ['Rate Per Day', 'Rate Per Hour', 'Rate Per Min'];

        foreach ($rateOnlyFormulas as $rateType) {
            if (isset($formulas[$rateType])) {
                $rateFormula = $formulas[$rateType]['rate_formula'];
                
                if (!empty($rateFormula)) {
                    $calculatedRate = applyRateFormula($rateFormula, $baseRates);
                    
                    switch ($rateType) {
                        case 'Rate Per Day':
                            $computation['rate_per_day'] = $calculatedRate;
                            error_log("Applied Rate Per Day formula: $rateFormula = $calculatedRate");
                            break;
                        case 'Rate Per Hour':
                            $computation['rate_per_hour'] = $calculatedRate;
                            error_log("Applied Rate Per Hour formula: $rateFormula = $calculatedRate");
                            break;
                        case 'Rate Per Min':
                            $computation['rate_per_minute'] = $calculatedRate;
                            error_log("Applied Rate Per Minute formula: $rateFormula = $calculatedRate");
                            break;
                    }
                }
            }
        }
        
        // Apply ONLY basic regular hours calculation (this is standard)
        $computation['regular_hours_amount'] = $computation['regular_hours'] * $computation['rate_per_hour'];
        
        // Apply formulas ONLY if they exist
        if (isset($formulas['Regular Holiday'])) {
            $rateFormula = $formulas['Regular Holiday']['rate_formula'];
            $amountFormula = $formulas['Regular Holiday']['amount_formula'];
            
            if (!empty($rateFormula)) {  // ADD THIS CHECK
                $computation['regular_holiday_rate'] = applyRateFormula($rateFormula, $baseRates);
                $computation['regular_holiday_amount'] = applyAmountFormula(
                    $amountFormula, 
                    $computation['regular_holiday_rate'], 
                    $computation['regular_holiday_hours']
                );
                error_log("Applied Regular Holiday formula - Rate: " . $computation['regular_holiday_rate'] . ", Amount: " . $computation['regular_holiday_amount']);
            }
        }
        
        if (isset($formulas['Regular Holiday OT'])) {
            $rateFormula = $formulas['Regular Holiday OT']['rate_formula'];
            $amountFormula = $formulas['Regular Holiday OT']['amount_formula'];
            
            $computation['regular_holiday_ot_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['regular_holiday_ot_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['regular_holiday_ot_rate'], 
                $computation['regular_holiday_ot_hours']
            );
        }
        
        if (isset($formulas['Regular Holiday + Night Diff'])) {
            $rateFormula = $formulas['Regular Holiday + Night Diff']['rate_formula'];
            $amountFormula = $formulas['Regular Holiday + Night Diff']['amount_formula'];
            
            $computation['regular_holiday_nd_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['regular_holiday_nd_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['regular_holiday_nd_rate'], 
                $computation['regular_holiday_nd_hours']
            );
        }
        
        // TRY MULTIPLE POSSIBLE NAMES FOR THE REGULAR HOLIDAY + ROT + OT
        $rotOtFormula = null;
        if (isset($formulas['Regular Holiday + ROT + OT'])) {
            $rotOtFormula = $formulas['Regular Holiday + ROT + OT'];
            error_log("Found formula with name: Regular Holiday + ROT + OT");
        } elseif (isset($formulas['Regular Holiday + OT + ROT'])) {
            $rotOtFormula = $formulas['Regular Holiday + OT + ROT'];
            error_log("Found formula with name: Regular Holiday + OT + ROT");
        } elseif (isset($formulas['Regular Holiday+ROT+OT'])) {
            $rotOtFormula = $formulas['Regular Holiday+ROT+OT'];
            error_log("Found formula with name: Regular Holiday+ROT+OT");
        }
        
        if ($rotOtFormula) {
            $rateFormula = $rotOtFormula['rate_formula'];
            $amountFormula = $rotOtFormula['amount_formula'];
            
            $computation['regular_holiday_rot_ot_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['regular_holiday_rot_ot_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['regular_holiday_rot_ot_rate'], 
                $computation['regular_holiday_rot_ot_hours']
            );
            error_log("Applied ROT+OT formula, rate: " . $computation['regular_holiday_rot_ot_rate'] . ", amount: " . $computation['regular_holiday_rot_ot_amount']);
        } else {
            error_log("No ROT+OT formula found. Available: " . print_r(array_keys($formulas), true));
        }
        
        if (isset($formulas['Special Holiday'])) {
            $rateFormula = $formulas['Special Holiday']['rate_formula'];
            $amountFormula = $formulas['Special Holiday']['amount_formula'];
            
            $computation['special_holiday_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['special_holiday_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['special_holiday_rate'], 
                $computation['special_holiday_hours']
            );
        }
        
        if (isset($formulas['Special Holiday OT'])) {
            $rateFormula = $formulas['Special Holiday OT']['rate_formula'];
            $amountFormula = $formulas['Special Holiday OT']['amount_formula'];
            
            $computation['special_holiday_ot_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['special_holiday_ot_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['special_holiday_ot_rate'], 
                $computation['special_holiday_ot_hours']
            );
        }
        
        if (isset($formulas['Special Holiday + Night Diff'])) {
            $rateFormula = $formulas['Special Holiday + Night Diff']['rate_formula'];
            $amountFormula = $formulas['Special Holiday + Night Diff']['amount_formula'];
            
            $computation['special_holiday_nd_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['special_holiday_nd_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['special_holiday_nd_rate'], 
                $computation['special_holiday_nd_hours']
            );
        }
        
        if (isset($formulas['Special Holiday + ROT + OT'])) {
            $rateFormula = $formulas['Special Holiday + ROT + OT']['rate_formula'];
            $amountFormula = $formulas['Special Holiday + ROT + OT']['amount_formula'];
            
            $computation['special_holiday_rot_ot_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['special_holiday_rot_ot_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['special_holiday_rot_ot_rate'], 
                $computation['special_holiday_rot_ot_hours']
            );
        }
        
        if (isset($formulas['Special Holiday + ROT'])) {
            $rateFormula = $formulas['Special Holiday + ROT']['rate_formula'];
            $amountFormula = $formulas['Special Holiday + ROT']['amount_formula'];
            
            $computation['special_holiday_rot_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['special_holiday_rot_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['special_holiday_rot_rate'], 
                $computation['special_holiday_rot_hours']
            );
        }

        if (isset($formulas['Special Holiday + ROT + ND'])) {
            $rateFormula = $formulas['Special Holiday + ROT + ND']['rate_formula'];
            $amountFormula = $formulas['Special Holiday + ROT + ND']['amount_formula'];
            
            $computation['special_holiday_rot_nd_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['special_holiday_rot_nd_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['special_holiday_rot_nd_rate'], 
                $computation['special_holiday_rot_nd_hours']
            );
        }
        
        if (isset($formulas['Regular Overtime'])) {
            $rateFormula = $formulas['Regular Overtime']['rate_formula'];
            $amountFormula = $formulas['Regular Overtime']['amount_formula'];
            
            $computation['regular_ot_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['regular_ot_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['regular_ot_rate'], 
                $computation['regular_ot_hours']
            );
        }

         // TRAVEL TIME CALCULATION
         if (isset($formulas['Travel Time'])) {
            $rateFormula = $formulas['Travel Time']['rate_formula'];
            $amountFormula = $formulas['Travel Time']['amount_formula'];
            
            if (!empty($rateFormula)) {
                $computation['travel_time_rate'] = applyRateFormula($rateFormula, $baseRates);
                $computation['travel_time_amount'] = applyAmountFormula(
                    $amountFormula, 
                    $computation['travel_time_rate'], 
                    $computation['travel_time_hours']
                );
                error_log("Applied Travel Time formula - Rate: " . $computation['travel_time_rate'] . ", Amount: " . $computation['travel_time_amount'] . ", Hours: " . $computation['travel_time_hours']);
            }
        } else {
            error_log("No Travel Time formula found - amount stays 0");
    }
        
        if (isset($formulas['Regular OT+ND'])) {
            $rateFormula = $formulas['Regular OT+ND']['rate_formula'];
            $amountFormula = $formulas['Regular OT+ND']['amount_formula'];
            
            $computation['regular_ot_nd_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['regular_ot_nd_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['regular_ot_nd_rate'], 
                $computation['regular_ot_nd_hours']
            );
        }
        
        if (isset($formulas['Rest Day OT'])) {
            $rateFormula = $formulas['Rest Day OT']['rate_formula'];
            $amountFormula = $formulas['Rest Day OT']['amount_formula'];
            
            $computation['rest_day_ot_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['rest_day_ot_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['rest_day_ot_rate'], 
                $computation['rest_day_ot_hours']
            );
        }

        if (isset($formulas['Rest Day OT+OT'])) {
            $rateFormula = $formulas['Rest Day OT+OT']['rate_formula'];
            $amountFormula = $formulas['Rest Day OT+OT']['amount_formula'];
            
            $computation['rest_day_ot_plus_ot_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['rest_day_ot_plus_ot_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['rest_day_ot_plus_ot_rate'], 
                $computation['rest_day_ot_plus_ot_hours']
            );
        }

        
        if (isset($formulas['Rest Day ND'])) {
            $rateFormula = $formulas['Rest Day ND']['rate_formula'];
            $amountFormula = $formulas['Rest Day ND']['amount_formula'];
            
            $computation['rest_day_nd_rate'] = applyRateFormula($rateFormula, $baseRates);
            $computation['rest_day_nd_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['rest_day_nd_rate'], 
                $computation['rest_day_nd_hours']
            );
        }

        
        // ONLY calculate late/undertime if formula exists - TRY BOTH POSSIBLE NAMES
        $undertimeFormula = null;
        if (isset($formulas['Undertime/Late'])) {
            $undertimeFormula = $formulas['Undertime/Late'];
            error_log("Found formula with name: Undertime/Late");
        } elseif (isset($formulas['Late/Undertime'])) {
            $undertimeFormula = $formulas['Late/Undertime'];
            error_log("Found formula with name: Late/Undertime");
        }
        
        if ($undertimeFormula) {
            $rateFormula = $undertimeFormula['rate_formula'];
            $amountFormula = $undertimeFormula['amount_formula'];
            
            // Use the unrounded rate per minute for calculation
            $unrounded_rate_per_minute = $computation['rate_per_hour'] / 60;
            $computation['late_undertime_rate'] = round($unrounded_rate_per_minute, 2);
            $computation['late_undertime_amount'] = round($computation['late_undertime_minutes'] * $unrounded_rate_per_minute, 2);
            error_log("Applied Undertime formula, amount: " . $computation['late_undertime_amount'] . " (minutes: " . $computation['late_undertime_minutes'] . ")");
        } else {
            error_log("No Undertime/Late formula found - amount stays 0");
        }
        
        // ONLY calculate absences if formula exists - NO DEFAULT CALCULATION
        if (isset($formulas['Absences'])) {
            $rateFormula = $formulas['Absences']['rate_formula'];
            $amountFormula = $formulas['Absences']['amount_formula'];
            
            $computation['absences_rate'] = applyRateFormula($rateFormula, $baseRates);
            // FIXED: Pass days directly to the amount formula
            $computation['absences_amount'] = applyAmountFormula(
                $amountFormula, 
                $computation['absences_rate'], 
                $computation['absences_days'] // Use days directly
            );
            error_log("Applied Absences formula, amount: " . $computation['absences_amount'] . " (days: " . $computation['absences_days'] . ")");
        } else {
            error_log("No Absences formula found - amount stays 0");
        }
        
        // Calculate totals
        $computation['total_holiday_pay'] = $computation['regular_holiday_amount'] + 
                                          $computation['regular_holiday_ot_amount'] + 
                                          $computation['regular_holiday_nd_amount'] + 
                                          $computation['regular_holiday_rot_ot_amount'] + 
                                          $computation['special_holiday_amount'] + 
                                          $computation['special_holiday_ot_amount'] + 
                                          $computation['special_holiday_nd_amount'] + 
                                          $computation['special_holiday_rot_ot_amount'] + 
                                          $computation['special_holiday_rot_nd_amount'];
        
        $computation['total_overtime_pay'] = $computation['regular_ot_amount'] + 
                                           $computation['regular_ot_nd_amount'];
        
        // Calculate gross pay
        $computation['gross_pay'] = $computation['basic_pay_semi_monthly'] + 
                                  $computation['total_allowances'] + 
                                  $computation['total_overtime_pay'] + 
                                  $computation['total_holiday_pay'] +
                                  $computation['travel_time_amount'];
        
        // Calculate total deductions - late_undertime_amount and absences_amount are 0 unless formulas exist
        $computation['total_deductions'] = $computation['late_undertime_amount'] + 
                                         $computation['absences_amount'] + 
                                         $computation['sss_contribution'] + 
                                         $computation['phic_contribution'] + 
                                         $computation['hdmf_contribution'] + 
                                         $computation['tax_amount'] + 
                                         $computation['sss_loan'] + 
                                         $computation['hdmf_loan'] + 
                                         $computation['teed'] + 
                                         $computation['staff_house'] + 
                                         $computation['cash_advance'];
        
        // Calculate net pay
        $computation['net_pay'] = max(0, $computation['gross_pay'] - $computation['total_deductions']);
        
        return $computation;
        
    } catch (Exception $e) {
        error_log("Error in computeEmployeePayroll: " . $e->getMessage());
        throw $e;
    }
}



// NEW: Safe formula evaluation function
function evaluateFormula($formula) {
    if (empty($formula)) {
        return 0;
    }
    
    // Clean and normalize the formula
    $formula = trim($formula);
    
    // Replace common problematic patterns
    $formula = preg_replace('/\s*\/\s*/', '/', $formula); // Remove spaces around division
    $formula = preg_replace('/\s*\*\s*/', '*', $formula); // Remove spaces around multiplication  
    $formula = preg_replace('/\s*\+\s*/', '+', $formula); // Remove spaces around addition
    $formula = preg_replace('/\s*\-\s*/', '-', $formula); // Remove spaces around subtraction
    
    // Handle double operators like **
    $formula = preg_replace('/\*\*+/', '*', $formula); // Replace ** with *
    $formula = preg_replace('/\/\/+/', '/', $formula); // Replace // with /
    
    // Remove any non-mathematical characters for security (keep parentheses, decimal points)
    $formula = preg_replace('/[^0-9+\-*\/\.\(\)\s]/', '', $formula);
    
    // Validate the formula structure
    if (preg_match('/[+\-*\/]{2,}/', $formula)) {
        error_log("Invalid formula structure: $formula");
        return 0;
    }
    
    // Use eval() safely (only for mathematical expressions)
    try {
        // Add extra safety check
        if (strpos($formula, '..') !== false || strpos($formula, '__') !== false) {
            return 0;
        }
        
        $result = eval("return $formula;");
        return is_numeric($result) ? round(floatval($result), 2) : 0;
    } catch (ParseError $e) {
        error_log("Formula parse error: $formula - " . $e->getMessage());
        return 0;
    } catch (Exception $e) {
        error_log("Formula evaluation error: $formula - " . $e->getMessage());
        return 0;
    }
}

function getPayComponentFormulas() {
    global $conn;
    
    $sql = "SELECT rate_type, formula, rate_formula, amount_formula FROM pay_components WHERE status = 'Active'";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $formulas = [];
    while ($row = $result->fetch_assoc()) {
        $formulas[$row['rate_type']] = [
            'legacy_formula' => $row['formula'],
            'rate_formula' => $row['rate_formula'],
            'amount_formula' => $row['amount_formula']
        ];
    }
    
    return $formulas;
}

function applyRateFormula($formula, $baseRates) {
    if (empty($formula)) {
        return 0;
    }
    
    // Expand base rates to include computed rates from previous calculations
    $expandedBaseRates = array_merge($baseRates, [
        'Regular OT Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.25 : 0,
        'Regular OT + ND Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.35 : 0,
        'Rest Day OT Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.5 : 0,
        'Rest Day OT + OT Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.75 : 0,
        'Rest Day ND Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.6 : 0,
        'Regular Holiday Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 2.0 : 0,
        'Regular Holiday OT Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 2.5 : 0,
        'Regular Holiday + Night Diff Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 2.1 : 0,
        'Regular Holiday + ROT + OT Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 2.75 : 0,
        'Special Holiday Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.3 : 0,
        'Special Holiday OT Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.625 : 0,
        'Special Holiday + Night Diff Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.4 : 0,
        'Special Holiday + ROT Rate' => isset($baseRates['Special Holiday + ROT']) ? $baseRates['Special Holiday + ROT'] : 0, // ADD THIS LINE
        'Special Holiday + ROT + OT Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.95 : 0,
        'Special Holiday + ROT + ND Rate' => isset($baseRates['Rate Per Hour']) ? $baseRates['Rate Per Hour'] * 1.69 : 0
    ]);
    
    // Replace base rate column names with their actual values
    $processedFormula = $formula;
    
    // Sort by length (longest first) to avoid partial replacements
    uksort($expandedBaseRates, function($a, $b) {
        return strlen($b) - strlen($a);
    });
    
    foreach ($expandedBaseRates as $columnName => $value) {
        $processedFormula = str_replace($columnName, strval($value), $processedFormula);
    }
    
    error_log("Original formula: $formula");
    error_log("Processed formula: $processedFormula");
    
    return evaluateFormula($processedFormula);
}

function applyAmountFormula($formula, $rate, $timeValue) {
    if (empty($formula) || $timeValue == 0) {
        return 0;
    }
    
    // Replace RATE, HOURS, MINUTES, and DAYS placeholders with actual values
    $processedFormula = str_replace(['RATE', 'HOURS', 'MINUTES', 'DAYS'], [$rate, $timeValue, $timeValue, $timeValue], $formula);
    
    error_log("Amount formula calculation: $formula -> $processedFormula (rate: $rate, timeValue: $timeValue)");
    
    return evaluateFormula($processedFormula);
}


function savePayrollComputation($recordId, $empId, $payrollPeriodId, $computation) {
    global $conn;
    
    $sql = "INSERT INTO payroll_computations (
                payroll_record_id, emp_id, payroll_period_id,
                basic_pay_monthly, basic_pay_semi_monthly, rate_per_day, rate_per_hour, rate_per_minute,
                site_allowance, transportation_allowance, total_allowances,
                travel_time_hours, travel_time_amount,
                regular_hours, regular_hours_amount,
                regular_ot_hours, regular_ot_amount,
                regular_holiday_hours, regular_holiday_amount,
                regular_holiday_ot_hours, regular_holiday_ot_amount,
                special_holiday_hours, special_holiday_amount,
                special_holiday_ot_hours, special_holiday_ot_amount,
                total_holiday_pay, total_overtime_pay, gross_pay,
                late_undertime_minutes, late_undertime_amount,
                absences_days, absences_amount,
                sss_contribution, phic_contribution, hdmf_contribution, tax_amount,
                sss_loan, hdmf_loan, teed, staff_house, cash_advance,
                total_deductions, net_pay,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?,
                ?, ?,
                ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                basic_pay_monthly = VALUES(basic_pay_monthly),
                basic_pay_semi_monthly = VALUES(basic_pay_semi_monthly),
                rate_per_day = VALUES(rate_per_day),
                rate_per_hour = VALUES(rate_per_hour),
                rate_per_minute = VALUES(rate_per_minute),
                site_allowance = VALUES(site_allowance),
                transportation_allowance = VALUES(transportation_allowance),
                total_allowances = VALUES(total_allowances),
                travel_time_hours = VALUES(travel_time_hours),
                travel_time_amount = VALUES(travel_time_amount),
                regular_hours = VALUES(regular_hours),
                regular_hours_amount = VALUES(regular_hours_amount),
                regular_ot_hours = VALUES(regular_ot_hours),
                regular_ot_amount = VALUES(regular_ot_amount),
                regular_holiday_hours = VALUES(regular_holiday_hours),
                regular_holiday_amount = VALUES(regular_holiday_amount),
                regular_holiday_ot_hours = VALUES(regular_holiday_ot_hours),
                regular_holiday_ot_amount = VALUES(regular_holiday_ot_amount),
                special_holiday_hours = VALUES(special_holiday_hours),
                special_holiday_amount = VALUES(special_holiday_amount),
                special_holiday_ot_hours = VALUES(special_holiday_ot_hours),
                special_holiday_ot_amount = VALUES(special_holiday_ot_amount),
                total_holiday_pay = VALUES(total_holiday_pay),
                total_overtime_pay = VALUES(total_overtime_pay),
                gross_pay = VALUES(gross_pay),
                late_undertime_minutes = VALUES(late_undertime_minutes),
                late_undertime_amount = VALUES(late_undertime_amount),
                absences_days = VALUES(absences_days),
                absences_amount = VALUES(absences_amount),
                sss_contribution = VALUES(sss_contribution),
                phic_contribution = VALUES(phic_contribution),
                hdmf_contribution = VALUES(hdmf_contribution),
                tax_amount = VALUES(tax_amount),
                sss_loan = VALUES(sss_loan),
                hdmf_loan = VALUES(hdmf_loan),
                teed = VALUES(teed),
                staff_house = VALUES(staff_house),
                cash_advance = VALUES(cash_advance),
                total_deductions = VALUES(total_deductions),
                net_pay = VALUES(net_pay),
                updated_at = NOW()";
    
    $stmt = $conn->prepare($sql);
    
    // Extract all values to variables for bind_param (43 parameters total - was 41, now +2)
    $payrollRecordId = intval($recordId);
    $employeeId = intval($empId);
    $payrollPeriodIdParam = intval($payrollPeriodId);
    
    $basicPayMonthly = floatval($computation['basic_pay_monthly'] ?? 0);
    $basicPaySemiMonthly = floatval($computation['basic_pay_semi_monthly'] ?? 0);
    $ratePerDay = floatval($computation['rate_per_day'] ?? 0);
    $ratePerHour = floatval($computation['rate_per_hour'] ?? 0);
    $ratePerMinute = floatval($computation['rate_per_minute'] ?? 0);
    
    $siteAllowance = floatval($computation['site_allowance'] ?? 0);
    $transportationAllowance = floatval($computation['transportation_allowance'] ?? 0);
    $totalAllowances = floatval($computation['total_allowances'] ?? 0);
    
    $travelTimeHours = intval($computation['travel_time_hours'] ?? 0);
    $travelTimeAmount = floatval($computation['travel_time_amount'] ?? 0);
    
    $regularHours = floatval($computation['regular_hours'] ?? 0);
    $regularHoursAmount = floatval($computation['regular_hours_amount'] ?? 0);
    
    $regularOtHours = floatval($computation['regular_ot_hours'] ?? 0);
    $regularOtAmount = floatval($computation['regular_ot_amount'] ?? 0);
    
    $regularHolidayHours = floatval($computation['regular_holiday_hours'] ?? 0);
    $regularHolidayAmount = floatval($computation['regular_holiday_amount'] ?? 0);
    $regularHolidayOtHours = floatval($computation['regular_holiday_ot_hours'] ?? 0);
    $regularHolidayOtAmount = floatval($computation['regular_holiday_ot_amount'] ?? 0);
    
    $specialHolidayHours = floatval($computation['special_holiday_hours'] ?? 0);
    $specialHolidayAmount = floatval($computation['special_holiday_amount'] ?? 0);
    $specialHolidayOtHours = floatval($computation['special_holiday_ot_hours'] ?? 0);
    $specialHolidayOtAmount = floatval($computation['special_holiday_ot_amount'] ?? 0);
    
    $totalHolidayPay = floatval($computation['total_holiday_pay'] ?? 0);
    $totalOvertimePay = floatval($computation['total_overtime_pay'] ?? 0);
    $grossPay = floatval($computation['gross_pay'] ?? 0);
    
    $lateUndertimeMinutes = floatval($computation['late_undertime_minutes'] ?? 0);
    $lateUndertimeAmount = floatval($computation['late_undertime_amount'] ?? 0);
    $absencesDays = floatval($computation['absences_days'] ?? 0);
    $absencesAmount = floatval($computation['absences_amount'] ?? 0);
    
    $sssContribution = floatval($computation['sss_contribution'] ?? 0);
    $phicContribution = floatval($computation['phic_contribution'] ?? 0);
    $hdmfContribution = floatval($computation['hdmf_contribution'] ?? 0);
    $taxAmount = floatval($computation['tax_amount'] ?? 0);
    
    $sssLoan = floatval($computation['sss_loan'] ?? 0);
    $hdmfLoan = floatval($computation['hdmf_loan'] ?? 0);
    $teed = floatval($computation['teed'] ?? 0);
    $staffHouse = floatval($computation['staff_house'] ?? 0);
    $cashAdvance = floatval($computation['cash_advance'] ?? 0);
    
    $totalDeductions = floatval($computation['total_deductions'] ?? 0);
    $netPay = floatval($computation['net_pay'] ?? 0);
    
    // Bind all parameters (3 integers + 40 doubles = 43 parameters)
    $stmt->bind_param("iiiidddddddiidddddddddddddddddddddddddddddd",
        $payrollRecordId,
        $employeeId,
        $payrollPeriodIdParam,
        $basicPayMonthly,
        $basicPaySemiMonthly,
        $ratePerDay,
        $ratePerHour,
        $ratePerMinute,
        $siteAllowance,
        $transportationAllowance,
        $totalAllowances,
        $travelTimeHours,
        $travelTimeAmount,
        $regularHours,
        $regularHoursAmount,
        $regularOtHours,
        $regularOtAmount,
        $regularHolidayHours,
        $regularHolidayAmount,
        $regularHolidayOtHours,
        $regularHolidayOtAmount,
        $specialHolidayHours,
        $specialHolidayAmount,
        $specialHolidayOtHours,
        $specialHolidayOtAmount,
        $totalHolidayPay,
        $totalOvertimePay,
        $grossPay,
        $lateUndertimeMinutes,
        $lateUndertimeAmount,
        $absencesDays,
        $absencesAmount,
        $sssContribution,
        $phicContribution,
        $hdmfContribution,
        $taxAmount,
        $sssLoan,
        $hdmfLoan,
        $teed,
        $staffHouse,
        $cashAdvance,
        $totalDeductions,
        $netPay
    );
    
    $stmt->execute();
}

function getGenerationHistoryId($payrollPeriodId) {
    global $conn;
    
    $sql = "SELECT id FROM payroll_generation_history WHERE payroll_period_id = ? ORDER BY created_at DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $payrollPeriodId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row ? $row['id'] : null;
}

function getPayrollRecordId($payrollPeriodId, $empId) {
    global $conn;
    
    $sql = "SELECT id FROM payroll_records WHERE payroll_period_id = ? AND emp_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $payrollPeriodId, $empId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    return $row ? $row['id'] : null;
}

function prepareExportData($selectedEmployees, $currentPeriod) {
    global $conn;
    
    $exportData = [];
    
    foreach ($selectedEmployees as $empId) {
        // Get employee info
        $empSql = "SELECT 
                       CONCAT('DIF', LPAD(emp_id, 3, '0')) as employee_id,
                       firstName, 
                       lastName,
                       position
                   FROM employeelist 
                   WHERE emp_id = ?";
        $empStmt = $conn->prepare($empSql);
        $empStmt->bind_param("i", $empId);
        $empStmt->execute();
        $empResult = $empStmt->get_result();
        $employee = $empResult->fetch_assoc();
        
        if (!$employee) continue;
        
        // Get computation
        $computation = computeEmployeePayroll($empId, $currentPeriod['id']);
        
        $exportData[] = [
            'employee_id' => $employee['employee_id'],
            'employee_name' => $employee['firstName'] . ' ' . $employee['lastName'],
            'position' => $employee['position'],
            'computation' => $computation
        ];
    }
    
    return [
        'employees' => $exportData,
        'period' => $currentPeriod['display_period'],
        'generated_date' => date('Y-m-d')
    ];
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>