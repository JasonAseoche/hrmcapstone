<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($action);
            break;
        case 'POST':
            handlePost($action);
            break;
        case 'PUT':
            handlePut($action);
            break;
        case 'DELETE':
            handleDelete($action);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGet($action) {
    switch ($action) {
        case 'departments':
            getDepartments();
            break;
        case 'supervisors':
            getSupervisors();
            break;
        case 'employees':
            getEmployees();
            break;
        case 'available_employees':
            getAvailableEmployees();
            break;
        case 'department_details':
            getDepartmentDetails($_GET['id']);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
}

function handlePost($action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'create_department':
            createDepartment($input);
            break;
        case 'assign_employees':
            assignEmployeesToDepartment($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
}

function handlePut($action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'update_department':
            updateDepartment($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
}

function handleDelete($action) {
    switch ($action) {
        case 'delete_department':
            deleteDepartment($_GET['id']);
            break;
        case 'remove_employees':
            $input = json_decode(file_get_contents('php://input'), true);
            removeEmployeesFromDepartment($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
}

function getDepartments() {
    global $conn;
    
    try {
        $search = $_GET['search'] ?? '';
        
        $sql = "SELECT 
                    d.id,
                    d.name,
                    d.positions,
                    d.status,
                    d.created_at,
                    d.updated_at,
                    CONCAT(s.firstName, ' ', s.lastName) as supervisor_name,
                    s.sup_id as supervisor_id,
                    COUNT(e.emp_id) as employee_count
                FROM department_list d
                LEFT JOIN supervisorlist s ON d.supervisor_id = s.sup_id
                LEFT JOIN employeelist e ON d.id = e.department_id AND e.status = 'active'
                WHERE d.status = 'active'";
        
        if (!empty($search)) {
            $sql .= " AND d.name LIKE ?";
        }
        
        $sql .= " GROUP BY d.id, d.name, d.positions, d.status, d.created_at, d.updated_at, s.firstName, s.lastName, s.sup_id
                  ORDER BY d.name";
        
        $stmt = $conn->prepare($sql);
        if (!empty($search)) {
            $searchParam = "%$search%";
            $stmt->bind_param("s", $searchParam);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $departments = [];
        while ($row = $result->fetch_assoc()) {
            $departments[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $departments
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error fetching departments: ' . $e->getMessage());
    }
}

function getSupervisors() {
    global $conn;
    
    try {
        $search = $_GET['search'] ?? '';
        
        $sql = "SELECT 
                    sup_id as id,
                    CONCAT(firstName, ' ', lastName) as name,
                    position,
                    email,
                    department
                FROM supervisorlist 
                WHERE status = 'active'";
        
        if (!empty($search)) {
            $sql .= " AND (firstName LIKE ? OR lastName LIKE ? OR position LIKE ?)";
        }
        
        $sql .= " ORDER BY firstName, lastName";
        
        $stmt = $conn->prepare($sql);
        if (!empty($search)) {
            $searchParam = "%$search%";
            $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $supervisors = [];
        while ($row = $result->fetch_assoc()) {
            $supervisors[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $supervisors
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error fetching supervisors: ' . $e->getMessage());
    }
}

function getEmployees() {
    global $conn;
    
    try {
        $department_id = $_GET['department_id'] ?? null;
        
        $sql = "SELECT 
                    emp_id,
                    CONCAT(firstName, ' ', lastName) as name,
                    position,
                    work_days,
                    rest_day,
                    email,
                    department_id
                FROM employeelist 
                WHERE status = 'active'";
        
        if ($department_id) {
            $sql .= " AND department_id = ?";
        }
        
        $sql .= " ORDER BY firstName, lastName";
        
        $stmt = $conn->prepare($sql);
        if ($department_id) {
            $stmt->bind_param("i", $department_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $employees
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error fetching employees: ' . $e->getMessage());
    }
}

function getAvailableEmployees() {
    global $conn;
    
    try {
        $search = $_GET['search'] ?? '';
        
        $sql = "SELECT 
                    emp_id,
                    CONCAT(firstName, ' ', lastName) as name,
                    position,
                    work_days,
                    rest_day,
                    email
                FROM employeelist 
                WHERE status = 'active' AND (department_id IS NULL OR department_id = 0)";
        
        if (!empty($search)) {
            $sql .= " AND (firstName LIKE ? OR lastName LIKE ? OR position LIKE ?)";
        }
        
        $sql .= " ORDER BY firstName, lastName";
        
        $stmt = $conn->prepare($sql);
        if (!empty($search)) {
            $searchParam = "%$search%";
            $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        
        echo json_encode([
            'success' => true,
            'data' => $employees
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error fetching available employees: ' . $e->getMessage());
    }
}

function getDepartmentDetails($id) {
    global $conn;
    
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Department ID is required']);
            return;
        }
        
        // Get department info
        $sql = "SELECT 
                    d.id,
                    d.name,
                    d.positions,
                    d.status,
                    d.supervisor_id,
                    CONCAT(s.firstName, ' ', s.lastName) as supervisor_name,
                    s.position as supervisor_position
                FROM department_list d
                LEFT JOIN supervisorlist s ON d.supervisor_id = s.sup_id
                WHERE d.id = ? AND d.status = 'active'";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $department = $result->fetch_assoc();
        
        if (!$department) {
            http_response_code(404);
            echo json_encode(['error' => 'Department not found']);
            return;
        }
        
        // Get department employees
        $sql = "SELECT 
                    emp_id,
                    CONCAT(firstName, ' ', lastName) as name,
                    position,
                    work_days,
                    rest_day,
                    email
                FROM employeelist 
                WHERE department_id = ? AND status = 'active'
                ORDER BY firstName, lastName";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        
        $department['employees'] = $employees;
        $department['employee_count'] = count($employees);
        
        echo json_encode([
            'success' => true,
            'data' => $department
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error fetching department details: ' . $e->getMessage());
    }
}

function createDepartment($data) {
    global $conn;
    
    try {
        if (empty($data['name']) || empty($data['positions'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Department name and positions are required']);
            return;
        }
        
        // Check if department name already exists
        $stmt = $conn->prepare("SELECT id FROM department_list WHERE name = ? AND status = 'active'");
        $stmt->bind_param("s", $data['name']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->fetch_assoc()) {
            http_response_code(400);
            echo json_encode(['error' => 'Department name already exists']);
            return;
        }
        
        $conn->begin_transaction();
        
        // Insert department
       $sql = "INSERT INTO department_list (name, supervisor_id, positions) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $supervisor_id = !empty($data['supervisor_id']) ? $data['supervisor_id'] : null;
        $positions_string = is_array($data['positions']) ? implode(',', $data['positions']) : $data['positions'];
        $stmt->bind_param("sis", $data['name'], $supervisor_id, $positions_string);
        $stmt->execute();
                
        $department_id = $conn->insert_id;
        
        // Assign employees if provided
        if (!empty($data['employee_ids']) && is_array($data['employee_ids'])) {
            $sql = "UPDATE employeelist SET department_id = ? WHERE emp_id = ?";
            $stmt = $conn->prepare($sql);
            
            foreach ($data['employee_ids'] as $emp_id) {
                $stmt->bind_param("ii", $department_id, $emp_id);
                $stmt->execute();
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Department created successfully',
            'department_id' => $department_id
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Error creating department: ' . $e->getMessage());
    }
}

function updateDepartment($data) {
    global $conn;
    
    try {
        if (empty($data['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Department ID is required']);
            return;
        }
        
        // Check if department exists
        $stmt = $conn->prepare("SELECT id FROM department_list WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result->fetch_assoc()) {
            http_response_code(404);
            echo json_encode(['error' => 'Department not found']);
            return;
        }
        
        // Check if new name conflicts with existing departments
        if (!empty($data['name'])) {
            $stmt = $conn->prepare("SELECT id FROM department_list WHERE name = ? AND id != ? AND status = 'active'");
            $stmt->bind_param("si", $data['name'], $data['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->fetch_assoc()) {
                http_response_code(400);
                echo json_encode(['error' => 'Department name already exists']);
                return;
            }
        }
        
        $updateFields = [];
        $params = [];
        $types = "";
        
        if (!empty($data['name'])) {
            $updateFields[] = 'name = ?';
            $params[] = $data['name'];
            $types .= "s";
        }
        
        if (!empty($data['positions'])) {
            $updateFields[] = 'positions = ?';
            $positions_string = is_array($data['positions']) ? implode(',', $data['positions']) : $data['positions'];
            $params[] = $positions_string;
            $types .= "s";
        }
        
        if (isset($data['supervisor_id'])) {
            $updateFields[] = 'supervisor_id = ?';
            $params[] = $data['supervisor_id'];
            $types .= "i";
        }
        
        if (empty($updateFields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }
        
        $params[] = $data['id'];
        $types .= "i";
        
        $sql = "UPDATE department_list SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Department updated successfully'
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error updating department: ' . $e->getMessage());
    }
}

function assignEmployeesToDepartment($data) {
    global $conn;
    
    try {
        if (empty($data['department_id']) || empty($data['employee_ids']) || !is_array($data['employee_ids'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Department ID and employee IDs are required']);
            return;
        }
        
        $conn->begin_transaction();
        
        $sql = "UPDATE employeelist SET department_id = ? WHERE emp_id = ? AND status = 'active'";
        $stmt = $conn->prepare($sql);
        
        $assigned = 0;
        foreach ($data['employee_ids'] as $emp_id) {
            $stmt->bind_param("ii", $data['department_id'], $emp_id);
            $result = $stmt->execute();
            if ($result && $stmt->affected_rows > 0) {
                $assigned++;
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "$assigned employees assigned to department successfully"
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Error assigning employees: ' . $e->getMessage());
    }
}

function removeEmployeesFromDepartment($data) {
    global $conn;
    
    try {
        if (empty($data['employee_ids']) || !is_array($data['employee_ids'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Employee IDs are required']);
            return;
        }
        
        $placeholders = str_repeat('?,', count($data['employee_ids']) - 1) . '?';
        $sql = "UPDATE employeelist SET department_id = NULL WHERE emp_id IN ($placeholders)";
        
        $stmt = $conn->prepare($sql);
        $types = str_repeat('i', count($data['employee_ids']));
        $stmt->bind_param($types, ...$data['employee_ids']);
        $stmt->execute();
        
        echo json_encode([
            'success' => true,
            'message' => 'Employees removed from department successfully'
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error removing employees: ' . $e->getMessage());
    }
}

function deleteDepartment($id) {
    global $conn;
    
    try {
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Department ID is required']);
            return;
        }
        
        // Check if department exists
        $stmt = $conn->prepare("SELECT id FROM department_list WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if (!$result->fetch_assoc()) {
            http_response_code(404);
            echo json_encode(['error' => 'Department not found']);
            return;
        }
        
        $conn->begin_transaction();
        
        // Remove all employees from department (set department_id to NULL)
        $stmt = $conn->prepare("UPDATE employeelist SET department_id = NULL WHERE department_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Hard delete department from table
        $stmt = $conn->prepare("DELETE FROM department_list WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('Department could not be deleted');
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Department deleted successfully'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Error deleting department: ' . $e->getMessage());
    }
}
?>