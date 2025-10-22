<?php
require_once 'db_connection.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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
    global $conn;
    
    switch ($action) {
        case 'payroll_periods':
            getPayrollPeriods();
            break;
        case 'holidays':
            getHolidays();
            break;
        case 'pay_components':
            getPayComponents();
            break;
        case 'payroll_period_details':
            getPayrollPeriodDetails($_GET['id']);
            break;
        case 'available_holidays':
            getAvailableHolidays();
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePost($action, $input) {
    global $conn;
    
    switch ($action) {
        case 'payroll_period':
            createPayrollPeriod($input);
            break;
        case 'holiday':
            createHoliday($input);
            break;
        case 'pay_component':
            createPayComponent($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handlePut($action, $input) {
    global $conn;
    
    switch ($action) {
        case 'payroll_period':
            updatePayrollPeriod($input);
            break;
        case 'holiday':
            updateHoliday($input);
            break;
        case 'pay_component':
            updatePayComponent($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

function handleDelete($action, $input) {
    global $conn;
    
    switch ($action) {
        case 'payroll_periods':
            deletePayrollPeriods($input['ids']);
            break;
        case 'holidays':
            deleteHolidays($input['ids']);
            break;
        case 'pay_components':
            deletePayComponents($input['ids']);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
}

// Payroll Period Functions
function getPayrollPeriods() {
    global $conn;
    
    $sql = "SELECT pp.*, 
                   COUNT(CASE WHEN h.holiday_type = 'Regular' THEN 1 END) as regular_holidays,
                   COUNT(CASE WHEN h.holiday_type = 'Special' THEN 1 END) as special_holidays
            FROM payroll_periods pp
            LEFT JOIN payroll_period_holidays pph ON pp.id = pph.payroll_period_id
            LEFT JOIN holidays h ON pph.holiday_id = h.id
            GROUP BY pp.id
            ORDER BY pp.date_from DESC";
    
    $result = $conn->query($sql);
    $payroll_periods = [];
    
    while ($row = $result->fetch_assoc()) {
        $payroll_periods[] = [
            'id' => (int)$row['id'],
            'prpId' => $row['prp_id'],
            'dateFrom' => $row['date_from'],
            'dateTo' => $row['date_to'],
            'regularHolidays' => (int)$row['regular_holidays'],
            'specialHolidays' => (int)$row['special_holidays'],
            'createdAt' => $row['created_at']
        ];
    }
    
    echo json_encode(['data' => $payroll_periods]);
}

function getPayrollPeriodDetails($id) {
    global $conn;
    
    // Get payroll period details
    $sql = "SELECT * FROM payroll_periods WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $period = $stmt->get_result()->fetch_assoc();
    
    if (!$period) {
        http_response_code(404);
        echo json_encode(['error' => 'Payroll period not found']);
        return;
    }
    
    // Get associated holidays
    $sql = "SELECT h.* FROM holidays h
            INNER JOIN payroll_period_holidays pph ON h.id = pph.holiday_id
            WHERE pph.payroll_period_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $holidays = [];
    while ($row = $result->fetch_assoc()) {
        $holidays[] = [
            'id' => (int)$row['id'],
            'holidayId' => $row['holiday_id'],
            'name' => $row['name'],
            'type' => $row['holiday_type'],
            'dateFrom' => $row['date_from'],
            'dateTo' => $row['date_to']
        ];
    }
    
    $period_details = [
        'id' => (int)$period['id'],
        'prpId' => $period['prp_id'],
        'dateFrom' => $period['date_from'],
        'dateTo' => $period['date_to'],
        'holidays' => $holidays,
        'createdAt' => $period['created_at']
    ];
    
    echo json_encode(['data' => $period_details]);
}

function createPayrollPeriod($input) {
    global $conn;
    
    $dateFrom = $input['dateFrom'];
    $dateTo = $input['dateTo'];
    $selectedHolidays = $input['selectedHolidays'] ?? [];
    
    // Generate PRP ID
    $prpId = generatePRPId();
    
    $conn->begin_transaction();
    
    try {
        // Insert payroll period
        $sql = "INSERT INTO payroll_periods (prp_id, date_from, date_to) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $prpId, $dateFrom, $dateTo);
        $stmt->execute();
        
        $payroll_period_id = $conn->insert_id;
        
        // Insert holiday associations
        if (!empty($selectedHolidays)) {
            $sql = "INSERT INTO payroll_period_holidays (payroll_period_id, holiday_id) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            
            foreach ($selectedHolidays as $holidayId) {
                $stmt->bind_param("ii", $payroll_period_id, $holidayId);
                $stmt->execute();
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'id' => $payroll_period_id, 'prpId' => $prpId]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function updatePayrollPeriod($input) {
    global $conn;
    
    $id = $input['id'];
    $dateFrom = $input['dateFrom'];
    $dateTo = $input['dateTo'];
    $selectedHolidays = $input['selectedHolidays'] ?? [];
    
    $conn->begin_transaction();
    
    try {
        // Update payroll period
        $sql = "UPDATE payroll_periods SET date_from = ?, date_to = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $dateFrom, $dateTo, $id);
        $stmt->execute();
        
        // Delete existing holiday associations
        $sql = "DELETE FROM payroll_period_holidays WHERE payroll_period_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Insert new holiday associations
        if (!empty($selectedHolidays)) {
            $sql = "INSERT INTO payroll_period_holidays (payroll_period_id, holiday_id) VALUES (?, ?)";
            $stmt = $conn->prepare($sql);
            
            foreach ($selectedHolidays as $holidayId) {
                $stmt->bind_param("ii", $id, $holidayId);
                $stmt->execute();
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function deletePayrollPeriods($ids) {
    global $conn;
    
    $conn->begin_transaction();
    
    try {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        // Delete holiday associations first
        $sql = "DELETE FROM payroll_period_holidays WHERE payroll_period_id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        
        // Delete payroll periods
        $sql = "DELETE FROM payroll_periods WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        
        $conn->commit();
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

// Holiday Functions
function getHolidays() {
    global $conn;
    
    $sql = "SELECT * FROM holidays ORDER BY date_from DESC";
    $result = $conn->query($sql);
    $holidays = [];
    
    while ($row = $result->fetch_assoc()) {
        $holidays[] = [
            'id' => (int)$row['id'],
            'holidayId' => $row['holiday_id'],
            'name' => $row['name'],
            'type' => $row['holiday_type'],
            'dateFrom' => $row['date_from'],
            'dateTo' => $row['date_to'],
            'createdAt' => $row['created_at']
        ];
    }
    
    echo json_encode(['data' => $holidays]);
}

function getAvailableHolidays() {
    global $conn;
    
    $sql = "SELECT id, name, holiday_type as type, date_from as date FROM holidays ORDER BY date_from";
    $result = $conn->query($sql);
    $holidays = [];
    
    while ($row = $result->fetch_assoc()) {
        $holidays[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'type' => $row['type'],
            'date' => $row['date']
        ];
    }
    
    echo json_encode(['data' => $holidays]);
}

function createHoliday($input) {
    global $conn;
    
    $name = $input['holidayName'];
    $type = $input['holidayType'];
    $dateFrom = $input['dateFrom'];
    
    // Generate Holiday ID
    $holidayId = generateHolidayId();
    
    $sql = "INSERT INTO holidays (holiday_id, name, holiday_type, date_from, date_to) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssss", $holidayId, $name, $type, $dateFrom, $dateFrom);
    $stmt->execute();
    
    echo json_encode(['success' => true, 'id' => $conn->insert_id, 'holidayId' => $holidayId]);
}

function updateHoliday($input) {
    global $conn;
    
    $id = $input['id'];
    $name = $input['holidayName'];
    $type = $input['holidayType'];
    $dateFrom = $input['dateFrom'];
    
    $sql = "UPDATE holidays SET name = ?, holiday_type = ?, date_from = ?, date_to = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssi", $name, $type, $dateFrom, $dateFrom, $id);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
}

function deleteHolidays($ids) {
    global $conn;
    
    $conn->begin_transaction();
    
    try {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        // Delete holiday associations first
        $sql = "DELETE FROM payroll_period_holidays WHERE holiday_id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        
        // Delete holidays
        $sql = "DELETE FROM holidays WHERE id IN ($placeholders)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $stmt->execute();
        
        $conn->commit();
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

// Pay Component Functions
function getPayComponents() {
    global $conn;
    
    $sql = "SELECT * FROM pay_components ORDER BY created_at DESC";
    $result = $conn->query($sql);
    $components = [];
    
    while ($row = $result->fetch_assoc()) {
        $components[] = [
            'id' => (int)$row['id'],
            'ppcId' => $row['ppc_id'],
            'component' => $row['component_name'],
            'baseRateType' => $row['base_rate_type'],
            'rateType' => $row['rate_type'],
            'formula' => $row['formula'],
            'rateFormula' => $row['rate_formula'], // ADD THIS LINE
            'amountFormula' => $row['amount_formula'], // ADD THIS LINE
            'dateAdded' => $row['created_at'],
            'status' => $row['status']
        ];
    }
    
    echo json_encode(['data' => $components]);
}

function createPayComponent($input) {
    global $conn;
    
    $component = $input['component'] ?? '';
    $baseRateType = $input['baseRateType'] ?? '';
    $rateType = $input['rateType'] ?? '';
    $rateMultiplier = $input['rateMultiplier'] ?? '';
    $amountCalculationType = $input['amountCalculationType'] ?? 'rate_times_hours';
    $formula = $input['formula'] ?? '';
    $status = $input['status'] ?? 'Active';

    // Build rate formula based on rate type
    $rateFormula = '';
    $amountFormula = '';
    
    // FIXED: Handle deduction types (no multiplier needed)
    if (in_array($rateType, ['Undertime/Late', 'Late/Undertime', 'Absences'])) {
        // For deductions, use the base rate directly (no multiplier)
        $rateFormula = $baseRateType;
        
        // Set default amount formulas for deductions
        if ($rateType === 'Absences') {
            $amountFormula = $amountCalculationType === 'custom' ? ($input['amountFormula'] ?? 'RATE * DAYS') : 'RATE * DAYS';
        } else { // Undertime/Late
            $amountFormula = $amountCalculationType === 'custom' ? ($input['amountFormula'] ?? 'RATE * MINUTES') : 'RATE * MINUTES';
        }
    } else {
        // Normal rate calculation for other types
        if ($baseRateType) {
            if ($rateMultiplier && $rateMultiplier !== 'custom' && $rateMultiplier !== '') {
                // Standard multiplier
                $rateFormula = $baseRateType . ' * ' . $rateMultiplier;
            } else if ($rateMultiplier === 'custom' && !empty($input['rateFormula'])) {
                // Custom formula
                $rateFormula = $input['rateFormula'];
            } else {
                // No multiplier, just use base rate
                $rateFormula = $baseRateType;
            }
        }
        
        // Set amount formula for normal types
        $rateTypesWithoutAmount = [
            'Rate Per Day',
            'Rate Per Hour', 
            'Rate Per Min',
            'Basic pay-Monthly',
            'Basic Pay-Semi-Monthly'
        ];
        
        if (!in_array($rateType, $rateTypesWithoutAmount)) {
            switch ($amountCalculationType) {
                case 'rate_times_days':
                    $amountFormula = 'RATE * DAYS';
                    break;
                case 'rate_times_minutes':
                    $amountFormula = 'RATE * MINUTES';
                    break;
                case 'custom':
                    $amountFormula = $input['amountFormula'] ?? 'RATE * HOURS';
                    break;
                default:
                    $amountFormula = 'RATE * HOURS';
            }
        }
    }
    
    // Legacy formula for backward compatibility
    if (empty($formula)) {
        if ($rateFormula) {
            $formula = $rateFormula;
        }
    }
    
    $ppcId = generatePPCId();
    
    // Debug logging
    error_log("Creating pay component - Rate Type: $rateType, Base Rate: $baseRateType, Rate Formula: $rateFormula, Amount Formula: $amountFormula");
    
    $sql = "INSERT INTO pay_components (ppc_id, component_name, base_rate_type, rate_type, formula, rate_formula, amount_formula, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssss", $ppcId, $component, $baseRateType, $rateType, $formula, $rateFormula, $amountFormula, $status);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create pay component: " . $stmt->error);
    }
    
    echo json_encode(['success' => true, 'id' => $conn->insert_id, 'ppcId' => $ppcId]);
}

function deletePayComponents($ids) {
    global $conn;
    
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $sql = "DELETE FROM pay_components WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
}


function updatePayComponent($input) {
    global $conn;
    
    $id = $input['id'];
    $component = $input['component'] ?? '';
    $baseRateType = $input['baseRateType'] ?? '';
    $rateType = $input['rateType'] ?? '';
    $rateMultiplier = $input['rateMultiplier'] ?? '';
    $amountCalculationType = $input['amountCalculationType'] ?? 'rate_times_hours';
    $formula = $input['formula'] ?? '';
    $status = $input['status'] ?? 'Active';
    
    // Build rate formula based on rate type (same logic as create)
    $rateFormula = '';
    $amountFormula = '';
    
    // FIXED: Handle deduction types (no multiplier needed)
    if (in_array($rateType, ['Undertime/Late', 'Late/Undertime', 'Absences'])) {
        // For deductions, use the base rate directly
        $rateFormula = $baseRateType;
        
        // Set amount formulas for deductions
        if ($rateType === 'Absences') {
            $amountFormula = $amountCalculationType === 'custom' ? ($input['amountFormula'] ?? 'RATE * DAYS') : 'RATE * DAYS';
        } else { // Undertime/Late
            $amountFormula = $amountCalculationType === 'custom' ? ($input['amountFormula'] ?? 'RATE * MINUTES') : 'RATE * MINUTES';
        }
    } else {
        // Normal rate calculation for other types
        if ($baseRateType) {
            if ($rateMultiplier && $rateMultiplier !== 'custom' && $rateMultiplier !== '') {
                // Standard multiplier
                $rateFormula = $baseRateType . ' * ' . $rateMultiplier;
            } else if ($rateMultiplier === 'custom' && !empty($input['rateFormula'])) {
                // Custom formula
                $rateFormula = $input['rateFormula'];
            } else {
                // No multiplier, just use base rate
                $rateFormula = $baseRateType;
            }
        }
        
        // Set amount formula for normal types
        $rateTypesWithoutAmount = [
            'Rate Per Day',
            'Rate Per Hour', 
            'Rate Per Min',
            'Basic pay-Monthly',
            'Basic Pay-Semi-Monthly'
        ];
        
        if (!in_array($rateType, $rateTypesWithoutAmount)) {
            switch ($amountCalculationType) {
                case 'rate_times_days':
                    $amountFormula = 'RATE * DAYS';
                    break;
                case 'rate_times_minutes':
                    $amountFormula = 'RATE * MINUTES';
                    break;
                case 'custom':
                    $amountFormula = $input['amountFormula'] ?? 'RATE * HOURS';
                    break;
                default:
                    $amountFormula = 'RATE * HOURS';
            }
        }
    }
    
    // Legacy formula for backward compatibility
    if (empty($formula)) {
        if ($rateFormula) {
            $formula = $rateFormula;
        }
    }
    
    error_log("Updating pay component - Rate Type: $rateType, Base Rate: $baseRateType, Rate Formula: $rateFormula, Amount Formula: $amountFormula");
    
    $sql = "UPDATE pay_components SET component_name = ?, base_rate_type = ?, rate_type = ?, formula = ?, rate_formula = ?, amount_formula = ?, status = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssi", $component, $baseRateType, $rateType, $formula, $rateFormula, $amountFormula, $status, $id);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to update pay component: " . $stmt->error);
    }
    
    echo json_encode(['success' => true]);
}

// Helper Functions
function generatePRPId() {
    global $conn;
    
    $sql = "SELECT COUNT(*) as count FROM payroll_periods";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $count = $row['count'] + 1;
    
    return 'PRP' . str_pad($count, 3, '0', STR_PAD_LEFT);
}

function generateHolidayId() {
    global $conn;
    
    $sql = "SELECT COUNT(*) as count FROM holidays";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $count = $row['count'] + 1;
    
    return 'HOL' . str_pad($count, 3, '0', STR_PAD_LEFT);
}

function generatePPCId() {
    global $conn;
    
    $sql = "SELECT COUNT(*) as count FROM pay_components";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $count = $row['count'] + 1;
    
    return 'PPC' . str_pad($count, 3, '0', STR_PAD_LEFT);
}
?>