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
        case 'create_request':
            createOvertimeRequest($connection, $connection_type, $input);
            break;
        case 'get_requests':
            getOvertimeRequests($connection, $connection_type);
            break;
        case 'get_request_details':
            getRequestDetails($connection, $connection_type);
            break;
        case 'update_status':
            updateRequestStatus($connection, $connection_type, $input);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function createOvertimeRequest($conn, $type, $data) {
    try {
        if ($type === 'mysqli') {
            $query = "
                INSERT INTO overtime_requests (
                    emp_id, date_filed, employee_number, employee_name,
                    project_number, project_name, project_phase, hours_requested, minutes_requested, end_time,
                    task_description, not_earlier_reason, not_later_reason, is_urgent, urgent_explanation,
                    status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
                return;
            }
            
            $urgent_explanation = isset($data['urgentExplanation']) ? $data['urgentExplanation'] : null;
            
            // 14 parameters total:
            // 1. emp_id (i) - integer
            // 2. date_filed (s) - string
            // 3. employee_number (s) - string
            // 4. employee_name (s) - string
            // 5. project_number (s) - string
            // 6. project_name (s) - string
            // 7. project_phase (s) - string
            // 8. hours_requested (i) - integer
            // 9. end_time (s) - string
            // 10. task_description (s) - string
            // 11. not_earlier_reason (s) - string
            // 12. not_later_reason (s) - string
            // 13. is_urgent (s) - string
            // 14. urgent_explanation (s) - string
            
            $stmt->bind_param('isssssssissssss',
                $data['emp_id'],                  // 1: i
                $data['dateFiled'],               // 2: s
                $data['employeeNumber'],          // 3: s
                $data['employeeName'],            // 4: s
                $data['projectNumber'],           // 5: s
                $data['projectName'],             // 6: s
                $data['projectPhase'],            // 7: s
                $data['hoursRequested'],          // 8: i
                $data['minutesRequested'],        // 9: i (NEW)
                $data['endTime'],                 // 10: s
                $data['taskDescription'],         // 11: s
                $data['notEarlierReason'],        // 12: s
                $data['notLaterReason'],          // 13: s
                $data['isUrgent'],                // 14: s
                $urgent_explanation               // 15: s
            );
            
            $result = $stmt->execute();
            if (!$result) {
                echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
                return;
            }
            
            $insert_id = $conn->insert_id;
            $stmt->close();
            
            echo json_encode(['success' => true, 'message' => 'Overtime request created successfully', 'otr_id' => $insert_id]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO overtime_requests (
                    emp_id, date_filed, employee_number, employee_name,
                    project_number, project_name, project_phase, hours_requested, minutes_requested, end_time,
                    task_description, not_earlier_reason, not_later_reason, is_urgent, urgent_explanation,
                    status, created_at
                ) VALUES (
                    :emp_id, :date_filed, :employee_number, :employee_name,
                    :project_number, :project_name, :project_phase, :hours_requested, :minutes_requested, :end_time,
                    :task_description, :not_earlier_reason, :not_later_reason, :is_urgent, :urgent_explanation,
                    'Pending', NOW()
                )
            ");

            $stmt->execute([
                ':emp_id' => $data['emp_id'],
                ':date_filed' => $data['dateFiled'],
                ':employee_number' => $data['employeeNumber'],
                ':employee_name' => $data['employeeName'],
                ':project_number' => $data['projectNumber'],
                ':project_name' => $data['projectName'],
                ':project_phase' => $data['projectPhase'],
                ':hours_requested' => $data['hoursRequested'],
                ':minutes_requested' => $data['minutesRequested'],
                ':end_time' => $data['endTime'],
                ':task_description' => $data['taskDescription'],
                ':not_earlier_reason' => $data['notEarlierReason'],
                ':not_later_reason' => $data['notLaterReason'],
                ':is_urgent' => $data['isUrgent'],
                ':urgent_explanation' => isset($data['urgentExplanation']) ? $data['urgentExplanation'] : null
            ]);
                        
            echo json_encode(['success' => true, 'message' => 'Overtime request created successfully', 'otr_id' => $conn->lastInsertId()]);
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error creating request: ' . $e->getMessage()]);
    }
}

function getOvertimeRequests($conn, $type) {
    try {
        $emp_id = isset($_GET['emp_id']) ? $_GET['emp_id'] : null;
        
        if (!$emp_id) {
            echo json_encode(['success' => false, 'message' => 'Employee ID is required']);
            return;
        }
        
        $query = "
            SELECT otr_id, emp_id, date_filed, employee_number, employee_name,
                project_number, project_name, project_phase, hours_requested, minutes_requested, end_time,
                task_description, not_earlier_reason, not_later_reason, is_urgent, urgent_explanation,
                status, approved_by, approved_at, remarks, created_at, updated_at
            FROM overtime_requests
            WHERE emp_id = ?
            ORDER BY created_at DESC
        ";
        
        if ($type === 'mysqli') {
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $emp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $requests = [];
            while ($row = $result->fetch_assoc()) {
                $requests[] = $row;
            }
            $stmt->close();
            
            echo json_encode(['success' => true, 'requests' => $requests]);
        } else {
            $stmt = $conn->prepare("
                SELECT otr_id, emp_id, date_filed, employee_number, employee_name,
                    project_number, project_name, project_phase, hours_requested, minutes_requested, end_time,
                    task_description, not_earlier_reason, not_later_reason, is_urgent, urgent_explanation,
                    status, approved_by, approved_at, remarks, created_at, updated_at
                FROM overtime_requests
                WHERE emp_id = :emp_id
                ORDER BY created_at DESC
            ");
            
            $stmt->execute([':emp_id' => $emp_id]);
            $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'requests' => $requests]);
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching requests: ' . $e->getMessage()]);
    }
}

function getRequestDetails($conn, $type) {
    try {
        $otr_id = isset($_GET['otr_id']) ? $_GET['otr_id'] : null;
        
        if (!$otr_id) {
            echo json_encode(['success' => false, 'message' => 'OTR ID is required']);
            return;
        }
        
        // Just get from overtime_requests table (no join needed)
        $query = "SELECT * FROM overtime_requests WHERE otr_id = ?";
        
        if ($type === 'mysqli') {
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $otr_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $request = $result->fetch_assoc();
            $stmt->close();
            
            if ($request) {
                echo json_encode(['success' => true, 'request' => $request]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found']);
            }
        } else {
            $stmt = $conn->prepare("SELECT * FROM overtime_requests WHERE otr_id = :otr_id");
            $stmt->execute([':otr_id' => $otr_id]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($request) {
                echo json_encode(['success' => true, 'request' => $request]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Request not found']);
            }
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching request details: ' . $e->getMessage()]);
    }
}

function updateRequestStatus($conn, $type, $data) {
    try {
        $query = "
            UPDATE overtime_requests
            SET status = ?, approved_by = ?, approved_at = NOW(),
                remarks = ?, updated_at = NOW()
            WHERE otr_id = ?
        ";
        
        if ($type === 'mysqli') {
            $stmt = $conn->prepare($query);
            $stmt->bind_param('sisi',
                $data['status'],
                $data['approved_by'],
                isset($data['remarks']) ? $data['remarks'] : null,
                $data['otr_id']
            );
            
            $result = $stmt->execute();
            $stmt->close();
            
            echo json_encode(['success' => true, 'message' => 'Request status updated successfully']);
        } else {
            $stmt = $conn->prepare("
                UPDATE overtime_requests
                SET status = :status, approved_by = :approved_by, approved_at = NOW(),
                    remarks = :remarks, updated_at = NOW()
                WHERE otr_id = :otr_id
            ");
            
            $stmt->execute([
                ':status' => $data['status'],
                ':approved_by' => $data['approved_by'],
                ':remarks' => isset($data['remarks']) ? $data['remarks'] : null,
                ':otr_id' => $data['otr_id']
            ]);
            
            echo json_encode(['success' => true, 'message' => 'Request status updated successfully']);
        }
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error updating status: ' . $e->getMessage()]);
    }
}
?>