<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db_connection.php';

// Check for connection - support both PDO and mysqli
$connection = null;
if (isset($pdo)) {
    $connection = $pdo;
    $connection_type = 'pdo';
} elseif (isset($conn)) {
    $connection = $conn;
    $connection_type = 'mysqli';
} else {
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';
$input = null;

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input && isset($input['action'])) {
        $action = $input['action'];
    }
}

try {
    switch($action) {
        case 'get_all_requests':
            $supervisor_id = isset($_GET['supervisor_id']) ? $_GET['supervisor_id'] : null;
            getAllOvertimeRequests($connection, $connection_type, $supervisor_id);
            break;
        case 'update_status':
            if ($input) {
                updateOvertimeStatus($connection, $connection_type, $input);
            } else {
                echo json_encode(['success' => false, 'message' => 'No data received']);
            }
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function getAllOvertimeRequests($conn, $type, $supervisor_id = null) {
    try {
        $query = "
            SELECT 
                otr.otr_id,
                otr.emp_id,
                otr.date_filed,
                otr.employee_number,
                otr.employee_name,
                otr.project_number,
                otr.project_name,
                otr.project_phase,
                otr.hours_requested,
                otr.minutes_requested,
                otr.end_time,
                otr.task_description,
                otr.not_earlier_reason,
                otr.not_later_reason,
                otr.is_urgent,
                otr.urgent_explanation,
                otr.status,
                otr.approved_by,
                otr.approved_at,
                otr.remarks,
                otr.created_at,
                otr.updated_at
            FROM overtime_requests otr
            INNER JOIN employeelist e ON otr.emp_id = e.emp_id
            INNER JOIN department_list d ON e.department_id = d.id
            WHERE d.supervisor_id = ? 
            AND d.status = 'active'
            AND e.status = 'active'
            ORDER BY otr.created_at DESC
        ";
        
        if ($type === 'mysqli') {
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                return;
            }
            
            $stmt->bind_param('i', $supervisor_id);
            $result = $stmt->execute();
            
            if (!$result) {
                echo json_encode(['success' => false, 'message' => 'Query failed: ' . $conn->error]);
                return;
            }
            
            $result = $stmt->get_result();
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $requests[] = $row;
            }
            $stmt->close();
            
            echo json_encode(['success' => true, 'requests' => $requests]);
        } else {
            // PDO
            $stmt = $conn->prepare($query);
            $stmt->execute([$supervisor_id]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'requests' => $requests]);
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching requests: ' . $e->getMessage()]);
    }
}

function updateOvertimeStatus($conn, $type, $data) {
    try {
        if (!isset($data['otr_id']) || !isset($data['status']) || !isset($data['supervisor_id'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields (otr_id, status, or supervisor_id)']);
            return;
        }
        
        $otr_id = $data['otr_id'];
        $status = $data['status'];
        $supervisor_id = $data['supervisor_id'];
        $remarks = isset($data['remarks']) ? $data['remarks'] : null;
        
        if ($type === 'mysqli') {
            // UPDATE THE OVERTIME REQUEST STATUS
            $query = "
                UPDATE overtime_requests
                SET 
                    status = ?,
                    approved_by = ?,
                    approved_at = NOW(),
                    remarks = ?,
                    updated_at = NOW()
                WHERE otr_id = ?
            ";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                return;
            }
            
            $stmt->bind_param('sisi', $status, $supervisor_id, $remarks, $otr_id);
            $result = $stmt->execute();
            $stmt->close();
            
            if ($result && $status === 'Approved') {
                // GET OT REQUEST DETAILS including hours requested
                $getOTSql = "SELECT emp_id, date_filed, end_time, hours_requested FROM overtime_requests WHERE otr_id = ?";
                $getOTStmt = $conn->prepare($getOTSql);
                $getOTStmt->bind_param('i', $otr_id);
                $getOTStmt->execute();
                $otResult = $getOTStmt->get_result()->fetch_assoc();
                $getOTStmt->close();
                
                if ($otResult) {
                    error_log("✅ OT APPROVED (mysqli) - Updating auto_time_out");
                    error_log("   emp_id: " . $otResult['emp_id']);
                    error_log("   date: " . $otResult['date_filed']);
                    error_log("   end_time: " . $otResult['end_time']);
                    error_log("   hours_requested: " . $otResult['hours_requested']);
                    
                    // UPDATE ATTENDANCE RECORD with approved OT hours
                    $updateAttSql = "UPDATE attendancelist 
                                    SET auto_time_out = ?, 
                                        approved_ot_minutes = ? 
                                    WHERE emp_id = ? 
                                    AND date = ? 
                                    AND time_out IS NULL";
                    $updateAttStmt = $conn->prepare($updateAttSql);
                    
                    // Convert hours to minutes (supporting decimal hours)
                    $approvedOTMinutes = (int)($otResult['hours_requested'] * 60);
                    
                    $updateAttStmt->bind_param('siis', 
                        $otResult['end_time'], 
                        $approvedOTMinutes,
                        $otResult['emp_id'], 
                        $otResult['date_filed']
                    );
                    $updateAttStmt->execute();
                    $affectedRows = $updateAttStmt->affected_rows;
                    $updateAttStmt->close();
                    
                    error_log("✅ Updated $affectedRows attendance records with auto_time_out=" . $otResult['end_time']);
                    
                    if ($affectedRows > 0) {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Overtime approved! Auto-timeout set to ' . $otResult['end_time']
                        ]);
                    } else {
                        error_log("⚠️ WARNING: No attendance records updated. Employee may not be timed in yet.");
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Overtime approved (employee not timed in yet)'
                        ]);
                    }
                } else {
                    error_log("❌ ERROR: Could not fetch OT request details for otr_id: $otr_id");
                    echo json_encode(['success' => true, 'message' => 'Overtime request status updated']);
                }
            } else {
                echo json_encode(['success' => true, 'message' => 'Overtime request status updated successfully']);
            }
            
        } else {
            // PDO VERSION
            $stmt = $conn->prepare("
                UPDATE overtime_requests
                SET 
                    status = :status,
                    approved_by = :supervisor_id,
                    approved_at = NOW(),
                    remarks = :remarks,
                    updated_at = NOW()
                WHERE otr_id = :otr_id
            ");
            
            $result = $stmt->execute([
                ':status' => $status,
                ':supervisor_id' => $supervisor_id,
                ':remarks' => $remarks,
                ':otr_id' => $otr_id
            ]);
            
            if ($result && $status === 'Approved') {
                // GET OT REQUEST DETAILS
                $getOTSql = "SELECT emp_id, date_filed, end_time FROM overtime_requests WHERE otr_id = :otr_id";
                $getOTStmt = $conn->prepare($getOTSql);
                $getOTStmt->execute([':otr_id' => $otr_id]);
                $otResult = $getOTStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($otResult) {
                    error_log("✅ OT APPROVED (PDO) - Updating auto_time_out");
                    error_log("   emp_id: " . $otResult['emp_id']);
                    error_log("   date: " . $otResult['date_filed']);
                    error_log("   end_time: " . $otResult['end_time']);
                    
                    // UPDATE ATTENDANCE RECORD
                    $updateAttSql = "UPDATE attendancelist 
                                    SET auto_time_out = :end_time 
                                    WHERE emp_id = :emp_id 
                                    AND date = :date 
                                    AND time_out IS NULL";
                    $updateAttStmt = $conn->prepare($updateAttSql);
                    $updateAttStmt->execute([
                        ':end_time' => $otResult['end_time'],
                        ':emp_id' => $otResult['emp_id'],
                        ':date' => $otResult['date_filed']
                    ]);
                    $affectedRows = $updateAttStmt->rowCount();
                    
                    error_log("✅ Updated $affectedRows attendance records with auto_time_out=" . $otResult['end_time']);
                    
                    if ($affectedRows > 0) {
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Overtime approved! Auto-timeout set to ' . $otResult['end_time']
                        ]);
                    } else {
                        error_log("⚠️ WARNING: No attendance records updated. Employee may not be timed in yet.");
                        echo json_encode([
                            'success' => true, 
                            'message' => 'Overtime approved (employee not timed in yet)'
                        ]);
                    }
                } else {
                    error_log("❌ ERROR: Could not fetch OT request details for otr_id: $otr_id");
                    echo json_encode(['success' => true, 'message' => 'Overtime request status updated']);
                }
            } else {
                echo json_encode(['success' => true, 'message' => 'Overtime request status updated successfully']);
            }
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating status: ' . $e->getMessage()]);
    }
}
?>