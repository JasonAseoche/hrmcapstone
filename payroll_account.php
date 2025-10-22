<?php
// payroll_account.php - Complete Payroll Management API
// Note: Database tables must be created using payroll_schema.sql

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

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
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
}

function handleGetRequest($action) {
    switch ($action) {
        case 'fetch_complete_employee_details':
            fetchCompleteEmployeeDetails();
            break;
        case 'fetch_employees':
            fetchEmployeesForPayroll();
            break;
        case 'fetch_employee_details':
            fetchEmployeeDetails();
            break;
        case 'fetch_payroll_data':
            fetchPayrollData();
            break;
        default:
            fetchEmployeesForPayroll();
            break;
    }
}

function handlePostRequest($action) {
    switch ($action) {
        case 'save_payroll_data':
            savePayrollData();
            break;
        case 'update_benefits':
            updateEmployeeBenefits();
            break;
        case 'update_employee_benefits_accounts':
            updateEmployeeBenefitsAccounts();
            break;
        default:
            throw new Exception("Invalid POST action", 400);
    }
}

function handlePutRequest($action) {
    switch ($action) {
        case 'update_payroll_data':
            savePayrollData();
            break;
        case 'update_employee_benefits':
            updateEmployeeBenefits();
            break;
        case 'update_employee_benefits_accounts':  // ADD THIS LINE
            updateEmployeeBenefitsAccounts();      // ADD THIS LINE
            break;                                 // ADD THIS LINE
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


function updateEmployeeBenefitsAccounts() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['emp_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid input data - missing emp_id'
        ]);
        return;
    }
    
    $emp_id = intval($input['emp_id']);
    
    if ($emp_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee ID: ' . $emp_id
        ]);
        return;
    }
    
    if (!isset($input['benefits'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing benefits data in request'
        ]);
        return;
    }
    
    try {
        // Extract benefits data to variables (required for bind_param)
        $sss_account = $input['benefits']['sss_account'] ?? '';
        $phic_account = $input['benefits']['phic_account'] ?? '';
        $hdmf_account = $input['benefits']['hdmf_account'] ?? '';
        $tax_account = $input['benefits']['tax_account'] ?? '';
        
        // First check if employee payroll record exists
        $check_sql = "SELECT id FROM employee_payroll WHERE emp_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        
        if (!$check_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $check_stmt->bind_param("i", $emp_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing record
            $sql = "UPDATE employee_payroll SET 
                        sss_account = ?, 
                        phic_account = ?, 
                        hdmf_account = ?, 
                        tax_account = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE emp_id = ?";
            
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Update prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param("ssssi",
                $sss_account,
                $phic_account,
                $hdmf_account,
                $tax_account,
                $emp_id
            );
        } else {
            // Create new record
            $sql = "INSERT INTO employee_payroll (emp_id, sss_account, phic_account, hdmf_account, tax_account) 
                    VALUES (?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Insert prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param("issss",
                $emp_id,
                $sss_account,
                $phic_account,
                $hdmf_account,
                $tax_account
            );
        }
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Employee benefits accounts updated successfully',
                'emp_id' => $emp_id,
                'benefits' => [
                    'sss_account' => $sss_account,
                    'phic_account' => $phic_account,
                    'hdmf_account' => $hdmf_account,
                    'tax_account' => $tax_account
                ]
            ]);
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error updating employee benefits accounts: ' . $e->getMessage(),
            'emp_id' => $emp_id ?? 'unknown'
        ]);
    }
}

function fetchEmployeesForPayroll() {
    global $conn;
    
    try {
        $sql = "SELECT 
                    e.id,
                    e.emp_id,
                    e.firstName, 
                    e.lastName, 
                    e.email, 
                    e.role,
                    e.position,
                    e.work_days,
                    e.status,
                    e.workarrangement,
                    u.profile_image,
                    e.address,
                    e.number
                FROM employeelist e
                LEFT JOIN useraccounts u ON e.emp_id = u.id
                WHERE e.role = 'employee' AND e.status = 'active'
                ORDER BY e.firstName, e.lastName";
        
        $result = $conn->query($sql);
        
        if (!$result) {
            throw new Exception("Query failed: " . $conn->error);
        }
        
        $employees = [];
        
        while ($row = $result->fetch_assoc()) {
            $employees[] = [
                'id' => $row['emp_id'],
                'employee_list_id' => $row['id'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'email' => $row['email'],
                'role' => $row['role'],
                'position' => $row['position'],
                'workDays' => $row['work_days'],
                'status' => $row['status'],
                'workarrangement' => $row['workarrangement'],
                'profileImage' => $row['profile_image'],
                'address' => $row['address'],
                'number' => $row['number']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'employees' => $employees
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching employees: ' . $e->getMessage()
        ]);
    }
}

function fetchCompleteEmployeeDetails() {
    global $conn;
    
    $emp_id = isset($_GET['emp_id']) ? intval($_GET['emp_id']) : 0;
    
    if ($emp_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee ID'
        ]);
        return;
    }
    
    try {
        // Get employee basic info with user_profiles data
        $sql = "SELECT 
                    e.emp_id,
                    e.firstName, 
                    e.lastName, 
                    e.email, 
                    e.position,
                    e.work_days,
                    e.status,
                    e.workarrangement,
                    e.address as emp_address,
                    e.number as emp_number,
                    u.profile_image,
                    up.middle_name,
                    up.address,
                    up.contact_number,
                    up.date_of_birth,
                    up.civil_status,
                    up.gender,
                    up.citizenship,
                    up.height,
                    up.weight
                FROM employeelist e
                LEFT JOIN useraccounts u ON e.emp_id = u.id
                LEFT JOIN user_profiles up ON e.emp_id = up.user_id
                WHERE e.emp_id = ? AND e.role = 'employee'";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $emp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            // Get complete payroll data
            $payroll_sql = "SELECT * FROM employee_payroll WHERE emp_id = ?";
            $payroll_stmt = $conn->prepare($payroll_sql);
            $payroll_stmt->bind_param("i", $emp_id);
            $payroll_stmt->execute();
            $payroll_result = $payroll_stmt->get_result();
            $payroll_row = $payroll_result->fetch_assoc();
            
            $employee_details = [
                'id' => $row['emp_id'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'email' => $row['email'],
                'position' => $row['position'],
                'workDays' => $row['work_days'],
                'status' => $row['status'],
                'workarrangement' => $row['workarrangement'],
                // Use user_profiles address first, fallback to employeelist address
                'address' => $row['address'] ?: $row['emp_address'],
                // Use user_profiles contact_number first, fallback to employeelist number
                'number' => $row['contact_number'] ?: $row['emp_number'],
                'profileImage' => $row['profile_image'],
                'personal_info' => [
                    'middle_name' => $row['middle_name'] ?: '',
                    'date_of_birth' => $row['date_of_birth'] ?: '',
                    'civil_status' => $row['civil_status'] ?: '',
                    'gender' => $row['gender'] ?: '',
                    'citizenship' => $row['citizenship'] ?: '',
                    'height' => $row['height'] ?: '',
                    'weight' => $row['weight'] ?: ''
                ],
                'benefits' => [
                    'sss_account' => $payroll_row ? ($payroll_row['sss_account'] ?? '') : '',
                    'phic_account' => $payroll_row ? ($payroll_row['phic_account'] ?? '') : '',
                    'hdmf_account' => $payroll_row ? ($payroll_row['hdmf_account'] ?? '') : '',
                    'tax_account' => $payroll_row ? ($payroll_row['tax_account'] ?? '') : ''
                ],
                'payroll_data' => $payroll_row ? [
                    'basic_pay' => [
                        'monthly' => number_format($payroll_row['basic_pay_monthly'] ?? 0, 2, '.', ''),
                        'semi_monthly' => number_format($payroll_row['basic_pay_semi_monthly'] ?? 0, 2, '.', ''),
                        'grosspay' => number_format($payroll_row['grosspay'] ?? 0, 2, '.', '')
                    ],
                    'employee_rate' => [
                        'rate_per_day' => number_format($payroll_row['rate_per_day'] ?? 0, 2, '.', ''),
                        'rate_per_hour' => number_format($payroll_row['rate_per_hour'] ?? 0, 2, '.', ''),
                        'rate_per_minute' => number_format($payroll_row['rate_per_minute'] ?? 0, 2, '.', '')
                    ],
                    'non_taxable_allowances' => [
                        'enabled' => (bool)($payroll_row['non_taxable_allowances_enabled'] ?? false),
                        'site_allowance' => number_format($payroll_row['site_allowance'] ?? 0, 2, '.', ''),
                        'transportation_allowance' => number_format($payroll_row['transportation_allowance'] ?? 0, 2, '.', '')
                    ],
                    'training_days' => [
                        'number_of_training_days' => strval($payroll_row['number_of_training_days'] ?? 0)
                    ],
                    'regular_overtime' => [
                        'regular_ot_rate' => number_format($payroll_row['regular_ot_rate'] ?? 0, 2, '.', ''),
                        'regular_ot_nd' => number_format($payroll_row['regular_ot_nd'] ?? 0, 2, '.', ''),
                        'rest_day_ot' => number_format($payroll_row['rest_day_ot'] ?? 0, 2, '.', ''),
                        'rest_day_ot_plus_ot' => number_format($payroll_row['rest_day_ot_plus_ot'] ?? 0, 2, '.', ''),
                        'rest_day_nd' => number_format($payroll_row['rest_day_nd'] ?? 0, 2, '.', '')
                    ],
                    'holiday_rates' => [
                        'regular_holiday' => [
                            'rh_rate' => number_format($payroll_row['rh_rate'] ?? 0, 2, '.', ''),
                            'rh_ot_rate' => number_format($payroll_row['rh_ot_rate'] ?? 0, 2, '.', ''),
                            'rh_nd_rate' => number_format($payroll_row['rh_nd_rate'] ?? 0, 2, '.', ''),
                            'rh_rot_ot_rate' => number_format($payroll_row['rh_rot_ot_rate'] ?? 0, 2, '.', '')
                        ],
                        'special_holiday' => [
                            'sh_rate' => number_format($payroll_row['sh_rate'] ?? 0, 2, '.', ''),
                            'sh_ot_rate' => number_format($payroll_row['sh_ot_rate'] ?? 0, 2, '.', ''),
                            'sh_nd_rate' => number_format($payroll_row['sh_nd_rate'] ?? 0, 2, '.', ''),
                            'sh_rot_ot_rate' => number_format($payroll_row['sh_rot_ot_rate'] ?? 0, 2, '.', ''),
                            'sh_rot_ot_plus_ot_rate' => number_format($payroll_row['sh_rot_ot_plus_ot_rate'] ?? 0, 2, '.', ''),
                            'sh_rot_nd' => number_format($payroll_row['sh_rot_nd'] ?? 0, 2, '.', '')
                        ]
                    ],
                    'government_contributions' => [
                        'sss' => number_format($payroll_row['sss'] ?? 0, 2, '.', ''),
                        'phic' => number_format($payroll_row['phic'] ?? 0, 2, '.', ''),
                        'hdmf' => number_format($payroll_row['hdmf'] ?? 0, 2, '.', ''),
                        'tax' => number_format($payroll_row['tax'] ?? 0, 2, '.', ''),
                        'sss_loan' => number_format($payroll_row['sss_loan'] ?? 0, 2, '.', ''),
                        'hdmf_loan' => number_format($payroll_row['hdmf_loan'] ?? 0, 2, '.', ''),
                        'teed' => number_format($payroll_row['teed'] ?? 0, 2, '.', ''),
                        'staff_house' => number_format($payroll_row['staff_house'] ?? 0, 2, '.', ''),
                        'cash_advance' => number_format($payroll_row['cash_advance'] ?? 0, 2, '.', '')
                    ]
                ] : [
                    // Default values when no payroll data exists
                    'basic_pay' => [
                        'monthly' => '0.00',
                        'semi_monthly' => '0.00',
                        'grosspay' => '0.00'
                    ],
                    'employee_rate' => [
                        'rate_per_day' => '0.00',
                        'rate_per_hour' => '0.00',
                        'rate_per_minute' => '0.00'
                    ],
                    'government_contributions' => [
                        'sss' => '0.00',
                        'phic' => '0.00',
                        'hdmf' => '0.00',
                        'tax' => '0.00',
                        'sss_loan' => '0.00',
                        'hdmf_loan' => '0.00',
                        'teed' => '0.00',
                        'staff_house' => '0.00',
                        'cash_advance' => '0.00'
                    ]
                ]
            ];
            
            echo json_encode([
                'success' => true,
                'employee' => $employee_details
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Employee not found'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching employee details: ' . $e->getMessage()
        ]);
    }
}

function fetchEmployeeDetails() {
    global $conn;
    
    $emp_id = isset($_GET['emp_id']) ? intval($_GET['emp_id']) : 0;
    
    if ($emp_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee ID'
        ]);
        return;
    }
    
    try {
        $sql = "SELECT 
                    e.emp_id,
                    e.firstName, 
                    e.lastName, 
                    e.email, 
                    e.position,
                    e.work_days,
                    e.status,
                    e.workarrangement,
                    e.address as emp_address,
                    e.number as emp_number,
                    u.profile_image,
                    up.middle_name,
                    up.address,
                    up.contact_number,
                    up.date_of_birth,
                    up.civil_status,
                    up.gender,
                    up.citizenship,
                    up.height,
                    up.weight,
                    COALESCE(ep.sss_account, '') as sss_account,
                    COALESCE(ep.phic_account, '') as phic_account,
                    COALESCE(ep.hdmf_account, '') as hdmf_account,
                    COALESCE(ep.tax_account, '') as tax_account
                FROM employeelist e
                LEFT JOIN useraccounts u ON e.emp_id = u.id
                LEFT JOIN user_profiles up ON e.emp_id = up.user_id
                LEFT JOIN employee_payroll ep ON e.emp_id = ep.emp_id
                WHERE e.emp_id = ? AND e.role = 'employee'";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $emp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $employee_details = [
                'id' => $row['emp_id'],
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'email' => $row['email'],
                'position' => $row['position'],
                'workDays' => $row['work_days'],
                'status' => $row['status'],
                'workarrangement' => $row['workarrangement'],
                'address' => $row['address'] ?: $row['emp_address'],
                'number' => $row['contact_number'] ?: $row['emp_number'],
                'profileImage' => $row['profile_image'],
                'personal_info' => [
                    'middle_name' => $row['middle_name'] ?: '',
                    'date_of_birth' => $row['date_of_birth'] ?: '',
                    'civil_status' => $row['civil_status'] ?: '',
                    'gender' => $row['gender'] ?: '',
                    'citizenship' => $row['citizenship'] ?: '',
                    'height' => $row['height'] ?: '',
                    'weight' => $row['weight'] ?: ''
                ],
                'benefits' => [
                    'sss_account' => $row['sss_account'],
                    'phic_account' => $row['phic_account'],
                    'hdmf_account' => $row['hdmf_account'],
                    'tax_account' => $row['tax_account']
                ]
            ];
            
            echo json_encode([
                'success' => true,
                'employee' => $employee_details
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Employee not found'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching employee details: ' . $e->getMessage()
        ]);
    }
}

function fetchPayrollData() {
    global $conn;
    
    $emp_id = isset($_GET['emp_id']) ? intval($_GET['emp_id']) : 0;
    
    if ($emp_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee ID'
        ]);
        return;
    }
    
    try {
        $sql = "SELECT * FROM employee_payroll WHERE emp_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $emp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $payroll_data = [
                'id' => $row['id'],
                'emp_id' => $row['emp_id'],
                'basic_pay' => [
                    'monthly' => number_format($row['basic_pay_monthly'] ?? 0, 2, '.', ''),
                    'semi_monthly' => number_format($row['basic_pay_semi_monthly'] ?? 0, 2, '.', ''),
                    'grosspay' => number_format($row['grosspay'] ?? 0, 2, '.', '')  // Add this line
                ],
                'employee_rate' => [
                    'rate_per_day' => number_format($row['rate_per_day'] ?? 0, 2, '.', ''),
                    'rate_per_hour' => number_format($row['rate_per_hour'] ?? 0, 2, '.', ''),
                    'rate_per_minute' => number_format($row['rate_per_minute'] ?? 0, 2, '.', '')
                ],
                'non_taxable_allowances' => [
                    'enabled' => (bool)($row['non_taxable_allowances_enabled'] ?? false),
                    'site_allowance' => number_format($row['site_allowance'] ?? 0, 2, '.', ''),
                    'transportation_allowance' => number_format($row['transportation_allowance'] ?? 0, 2, '.', '')
                ],
                'training_days' => [
                    'number_of_training_days' => strval($row['number_of_training_days'] ?? 0)
                ],
                'regular_overtime' => [
                    'regular_ot_rate' => number_format($row['regular_ot_rate'] ?? 0, 2, '.', ''),
                    'regular_ot_nd' => number_format($row['regular_ot_nd'] ?? 0, 2, '.', ''),
                    'rest_day_ot' => number_format($row['rest_day_ot'] ?? 0, 2, '.', ''),
                    'rest_day_ot_plus_ot' => number_format($row['rest_day_ot_plus_ot'] ?? 0, 2, '.', ''),
                    'rest_day_nd' => number_format($row['rest_day_nd'] ?? 0, 2, '.', '')
                ],
                'holiday_rates' => [
                    'regular_holiday' => [
                        'rh_rate' => number_format($row['rh_rate'] ?? 0, 2, '.', ''),
                        'rh_ot_rate' => number_format($row['rh_ot_rate'] ?? 0, 2, '.', ''),
                        'rh_nd_rate' => number_format($row['rh_nd_rate'] ?? 0, 2, '.', ''),
                        'rh_rot_ot_rate' => number_format($row['rh_rot_ot_rate'] ?? 0, 2, '.', '')
                    ],
                    'special_holiday' => [
                        'sh_rate' => number_format($row['sh_rate'] ?? 0, 2, '.', ''),
                        'sh_ot_rate' => number_format($row['sh_ot_rate'] ?? 0, 2, '.', ''),
                        'sh_nd_rate' => number_format($row['sh_nd_rate'] ?? 0, 2, '.', ''),
                        'sh_rot_ot_rate' => number_format($row['sh_rot_ot_rate'] ?? 0, 2, '.', ''),
                        'sh_rot_ot_plus_ot_rate' => number_format($row['sh_rot_ot_plus_ot_rate'] ?? 0, 2, '.', ''),
                        'sh_rot_nd' => number_format($row['sh_rot_nd'] ?? 0, 2, '.', '')
                    ]
                ],
                'government_contributions' => [
                    'sss' => number_format($row['sss'] ?? 0, 2, '.', ''),
                    'phic' => number_format($row['phic'] ?? 0, 2, '.', ''),
                    'hdmf' => number_format($row['hdmf'] ?? 0, 2, '.', ''),
                    'tax' => number_format($row['tax'] ?? 0, 2, '.', ''),
                    'sss_loan' => number_format($row['sss_loan'] ?? 0, 2, '.', ''),
                    'hdmf_loan' => number_format($row['hdmf_loan'] ?? 0, 2, '.', ''),
                    'teed' => number_format($row['teed'] ?? 0, 2, '.', ''),
                    'staff_house' => number_format($row['staff_house'] ?? 0, 2, '.', ''),
                    'cash_advance' => number_format($row['cash_advance'] ?? 0, 2, '.', '')
                ],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
            
            echo json_encode([
                'success' => true,
                'payroll_data' => $payroll_data
            ]);
        } else {
            $default_payroll = getDefaultPayrollData($emp_id);
            echo json_encode([
                'success' => true,
                'payroll_data' => $default_payroll,
                'is_new' => true
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching payroll data: ' . $e->getMessage()
        ]);
    }
}

function savePayrollData() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['emp_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid input data - missing emp_id'
        ]);
        return;
    }
    
    $emp_id = intval($input['emp_id']);
    
    if ($emp_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee ID'
        ]);
        return;
    }
    
    try {
        // SQL with 35 columns and 35 placeholders
        $sql = "INSERT INTO employee_payroll (
                    emp_id, basic_pay_monthly, basic_pay_semi_monthly, grosspay,
                    rate_per_day, rate_per_hour, rate_per_minute,
                    non_taxable_allowances_enabled, site_allowance, transportation_allowance,
                    number_of_training_days,
                    regular_ot_rate, regular_ot_nd, rest_day_ot, rest_day_ot_plus_ot, rest_day_nd,
                    rh_rate, rh_ot_rate, rh_nd_rate, rh_rot_ot_rate,
                    sh_rate, sh_ot_rate, sh_nd_rate, sh_rot_ot_rate, sh_rot_ot_plus_ot_rate, sh_rot_nd,
                    sss, phic, hdmf, tax, sss_loan, hdmf_loan, teed, staff_house, cash_advance
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    basic_pay_monthly = VALUES(basic_pay_monthly),
                    basic_pay_semi_monthly = VALUES(basic_pay_semi_monthly), 
                    grosspay = VALUES(grosspay),
                    rate_per_day = VALUES(rate_per_day),
                    rate_per_hour = VALUES(rate_per_hour),
                    rate_per_minute = VALUES(rate_per_minute),
                    non_taxable_allowances_enabled = VALUES(non_taxable_allowances_enabled),
                    site_allowance = VALUES(site_allowance),
                    transportation_allowance = VALUES(transportation_allowance),
                    number_of_training_days = VALUES(number_of_training_days),
                    regular_ot_rate = VALUES(regular_ot_rate),
                    regular_ot_nd = VALUES(regular_ot_nd),
                    rest_day_ot = VALUES(rest_day_ot),
                    rest_day_ot_plus_ot = VALUES(rest_day_ot_plus_ot),
                    rest_day_nd = VALUES(rest_day_nd),
                    rh_rate = VALUES(rh_rate),
                    rh_ot_rate = VALUES(rh_ot_rate),
                    rh_nd_rate = VALUES(rh_nd_rate),
                    rh_rot_ot_rate = VALUES(rh_rot_ot_rate),
                    sh_rate = VALUES(sh_rate),
                    sh_ot_rate = VALUES(sh_ot_rate),
                    sh_nd_rate = VALUES(sh_nd_rate),
                    sh_rot_ot_rate = VALUES(sh_rot_ot_rate),
                    sh_rot_ot_plus_ot_rate = VALUES(sh_rot_ot_plus_ot_rate),
                    sh_rot_nd = VALUES(sh_rot_nd),
                    sss = VALUES(sss),
                    phic = VALUES(phic),
                    hdmf = VALUES(hdmf),
                    tax = VALUES(tax),
                    sss_loan = VALUES(sss_loan),
                    hdmf_loan = VALUES(hdmf_loan),
                    teed = VALUES(teed),
                    staff_house = VALUES(staff_house),
                    cash_advance = VALUES(cash_advance),
                    updated_at = CURRENT_TIMESTAMP";
        
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        // Extract and convert values
        $basic_pay_monthly = floatval($input['basic_pay']['monthly'] ?? 0);
        $basic_pay_semi_monthly = floatval($input['basic_pay']['semi_monthly'] ?? 0);
        $grosspay = floatval($input['basic_pay']['grosspay'] ?? 0);
        $rate_per_day = floatval($input['employee_rate']['rate_per_day'] ?? 0);
        $rate_per_hour = floatval($input['employee_rate']['rate_per_hour'] ?? 0);
        $rate_per_minute = floatval($input['employee_rate']['rate_per_minute'] ?? 0);
        $non_taxable_enabled = intval($input['non_taxable_allowances']['enabled'] ?? false);
        $site_allowance = floatval($input['non_taxable_allowances']['site_allowance'] ?? 0);
        $transportation_allowance = floatval($input['non_taxable_allowances']['transportation_allowance'] ?? 0);
        $number_of_training_days = intval($input['training_days']['number_of_training_days'] ?? 0);
        
        $regular_ot_rate = floatval($input['regular_overtime']['regular_ot_rate'] ?? 0);
        $regular_ot_nd = floatval($input['regular_overtime']['regular_ot_nd'] ?? 0);
        $rest_day_ot = floatval($input['regular_overtime']['rest_day_ot'] ?? 0);
        $rest_day_ot_plus_ot = floatval($input['regular_overtime']['rest_day_ot_plus_ot'] ?? 0);
        $rest_day_nd = floatval($input['regular_overtime']['rest_day_nd'] ?? 0);
        
        $rh_rate = floatval($input['holiday_rates']['regular_holiday']['rh_rate'] ?? 0);
        $rh_ot_rate = floatval($input['holiday_rates']['regular_holiday']['rh_ot_rate'] ?? 0);
        $rh_nd_rate = floatval($input['holiday_rates']['regular_holiday']['rh_nd_rate'] ?? 0);
        $rh_rot_ot_rate = floatval($input['holiday_rates']['regular_holiday']['rh_rot_ot_rate'] ?? 0);
        
        $sh_rate = floatval($input['holiday_rates']['special_holiday']['sh_rate'] ?? 0);
        $sh_ot_rate = floatval($input['holiday_rates']['special_holiday']['sh_ot_rate'] ?? 0);
        $sh_nd_rate = floatval($input['holiday_rates']['special_holiday']['sh_nd_rate'] ?? 0);
        $sh_rot_ot_rate = floatval($input['holiday_rates']['special_holiday']['sh_rot_ot_rate'] ?? 0);
        $sh_rot_ot_plus_ot_rate = floatval($input['holiday_rates']['special_holiday']['sh_rot_ot_plus_ot_rate'] ?? 0);
        $sh_rot_nd = floatval($input['holiday_rates']['special_holiday']['sh_rot_nd'] ?? 0);
        
        $sss = floatval($input['government_contributions']['sss'] ?? 0);
        $phic = floatval($input['government_contributions']['phic'] ?? 0);
        $hdmf = floatval($input['government_contributions']['hdmf'] ?? 0);
        $tax = floatval($input['government_contributions']['tax'] ?? 0);
        $sss_loan = floatval($input['government_contributions']['sss_loan'] ?? 0);
        $hdmf_loan = floatval($input['government_contributions']['hdmf_loan'] ?? 0);
        $teed = floatval($input['government_contributions']['teed'] ?? 0);
        $staff_house = floatval($input['government_contributions']['staff_house'] ?? 0);
        $cash_advance = floatval($input['government_contributions']['cash_advance'] ?? 0);
        
        // CORRECTED: Type string with exactly 35 characters for 35 parameters
        // i=integer, d=decimal/double
        $stmt->bind_param("iddddddiidddddddddddddddddddddddddd",
            $emp_id,                    // 1. i - integer
            $basic_pay_monthly,         // 2. d - decimal
            $basic_pay_semi_monthly,    // 3. d - decimal
            $grosspay,                  // 4. d - decimal
            $rate_per_day,              // 5. d - decimal
            $rate_per_hour,             // 6. d - decimal
            $rate_per_minute,           // 7. d - decimal
            $non_taxable_enabled,       // 8. i - integer
            $site_allowance,            // 9. d - decimal
            $transportation_allowance,  // 10. d - decimal
            $number_of_training_days,   // 11. i - integer
            $regular_ot_rate,           // 12. d - decimal
            $regular_ot_nd,             // 13. d - decimal
            $rest_day_ot,               // 14. d - decimal
            $rest_day_ot_plus_ot,       // 15. d - decimal
            $rest_day_nd,               // 16. d - decimal
            $rh_rate,                   // 17. d - decimal
            $rh_ot_rate,                // 18. d - decimal
            $rh_nd_rate,                // 19. d - decimal
            $rh_rot_ot_rate,            // 20. d - decimal
            $sh_rate,                   // 21. d - decimal
            $sh_ot_rate,                // 22. d - decimal
            $sh_nd_rate,                // 23. d - decimal
            $sh_rot_ot_rate,            // 24. d - decimal
            $sh_rot_ot_plus_ot_rate,    // 25. d - decimal
            $sh_rot_nd,                 // 26. d - decimal
            $sss,                       // 27. d - decimal
            $phic,                      // 28. d - decimal
            $hdmf,                      // 29. d - decimal
            $tax,                       // 30. d - decimal
            $sss_loan,                  // 31. d - decimal
            $hdmf_loan,                 // 32. d - decimal
            $teed,                      // 33. d - decimal
            $staff_house,               // 34. d - decimal
            $cash_advance               // 35. d - decimal
        );
                
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Payroll data saved successfully'
            ]);
        } else {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error saving payroll data: ' . $e->getMessage()
        ]);
    }
}

function updateEmployeeBenefits() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['emp_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid input data'
        ]);
        return;
    }
    
    $emp_id = intval($input['emp_id']);
    
    try {
        $sql = "UPDATE employee_payroll SET 
                    sss = ?, 
                    phic = ?, 
                    hdmf = ?, 
                    tax = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE emp_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ddddi",
            floatval($input['benefits']['sss'] ?? 0),
            floatval($input['benefits']['phic'] ?? 0),
            floatval($input['benefits']['hdmf'] ?? 0),
            floatval($input['benefits']['tax'] ?? 0),
            $emp_id
        );
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Employee benefits updated successfully'
                ]);
            } else {
                $create_sql = "INSERT INTO employee_payroll (emp_id, sss, phic, hdmf, tax) VALUES (?, ?, ?, ?, ?)";
                $create_stmt = $conn->prepare($create_sql);
                $create_stmt->bind_param("idddd",
                    $emp_id,
                    floatval($input['benefits']['sss'] ?? 0),
                    floatval($input['benefits']['phic'] ?? 0),
                    floatval($input['benefits']['hdmf'] ?? 0),
                    floatval($input['benefits']['tax'] ?? 0)
                );
                
                if ($create_stmt->execute()) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Employee benefits created successfully'
                    ]);
                } else {
                    throw new Exception("Error creating benefits record: " . $create_stmt->error);
                }
            }
        } else {
            throw new Exception("Error updating benefits: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error updating employee benefits: ' . $e->getMessage()
        ]);
    }
}

function deletePayrollRecord() {
    global $conn;
    
    $emp_id = isset($_GET['emp_id']) ? intval($_GET['emp_id']) : 0;
    
    if ($emp_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid employee ID'
        ]);
        return;
    }
    
    try {
        $sql = "DELETE FROM employee_payroll WHERE emp_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $emp_id);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Payroll record deleted successfully'
            ]);
        } else {
            throw new Exception("Error deleting payroll record: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting payroll record: ' . $e->getMessage()
        ]);
    }
}

function getDefaultPayrollData($emp_id) {
    return [
        'emp_id' => $emp_id,
        'basic_pay' => [
            'monthly' => '0.00',
            'semi_monthly' => '0.00',
            'grosspay' => '0.00'
        ],
        'employee_rate' => [
            'rate_per_day' => '0.00',
            'rate_per_hour' => '0.00',
            'rate_per_minute' => '0.00'
        ],
        'non_taxable_allowances' => [
            'enabled' => false,
            'site_allowance' => '0.00',
            'transportation_allowance' => '0.00'
        ],
        'training_days' => [
            'number_of_training_days' => '0'
        ],
        'regular_overtime' => [
            'regular_ot_rate' => '0.00',
            'regular_ot_nd' => '0.00',
            'rest_day_ot' => '0.00',
            'rest_day_ot_plus_ot' => '0.00',
            'rest_day_nd' => '0.00'
        ],
        'holiday_rates' => [
            'regular_holiday' => [
                'rh_rate' => '0.00',
                'rh_ot_rate' => '0.00',
                'rh_nd_rate' => '0.00',
                'rh_rot_ot_rate' => '0.00'
            ],
            'special_holiday' => [
                'sh_rate' => '0.00',
                'sh_ot_rate' => '0.00',
                'sh_nd_rate' => '0.00',
                'sh_rot_ot_rate' => '0.00',
                'sh_rot_ot_plus_ot_rate' => '0.00',
                'sh_rot_nd' => '0.00'
            ]
        ],
        'government_contributions' => [
            'sss' => '0.00',
            'phic' => '0.00',
            'hdmf' => '0.00',
            'tax' => '0.00',
            'sss_loan' => '0.00',
            'hdmf_loan' => '0.00',
            'teed' => '0.00',
            'staff_house' => '0.00',
            'cash_advance' => '0.00'
        ]
    ];
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>