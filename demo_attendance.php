<?php
// demo_attendance.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connection.php';

class AttendanceManager {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    // Fetch all attendance records
    public function fetchAttendance() {
        try {
            $query = "SELECT id, emp_id, firstName, lastName, date, time_in, time_out, 
                           CASE 
                               WHEN present = 1 THEN 'present'
                               WHEN absent = 1 THEN 'absent' 
                               WHEN on_leave = 1 THEN 'on_leave'
                               ELSE 'present'
                           END as status
                      FROM attendancelist 
                      ORDER BY date DESC, id DESC 
                      LIMIT 100";
            
            $result = mysqli_query($this->conn, $query);
            
            if (!$result) {
                throw new Exception("Database error: " . mysqli_error($this->conn));
            }
            
            $attendance = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $attendance[] = $row;
            }
            
            return [
                'success' => true,
                'data' => $attendance
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Create new attendance record
    public function createAttendance($data) {
        try {
            // Validate required fields
            if (empty($data['emp_id']) || empty($data['firstName']) || 
                empty($data['lastName']) || empty($data['date'])) {
                throw new Exception("Required fields are missing");
            }
            
            // Set status flags based on status
            $present = ($data['status'] === 'present') ? 1 : 0;
            $absent = ($data['status'] === 'absent') ? 1 : 0;
            $on_leave = ($data['status'] === 'on_leave') ? 1 : 0;
            
            $query = "INSERT INTO attendancelist 
                      (emp_id, firstName, lastName, date, time_in, time_out, 
                       present, absent, on_leave, status) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = mysqli_prepare($this->conn, $query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . mysqli_error($this->conn));
            }
            
            mysqli_stmt_bind_param($stmt, "isssssiiis",
                $data['emp_id'],
                $data['firstName'],
                $data['lastName'],
                $data['date'],
                $data['time_in'] ?: null,
                $data['time_out'] ?: null,
                $present,
                $absent,
                $on_leave,
                $data['status']
            );
            
            $result = mysqli_stmt_execute($stmt);
            
            if (!$result) {
                throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
            }
            
            mysqli_stmt_close($stmt);
            
            return [
                'success' => true,
                'message' => 'Attendance record created successfully',
                'id' => mysqli_insert_id($this->conn)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Update attendance record
    public function updateAttendance($data) {
        try {
            if (empty($data['id'])) {
                throw new Exception("ID is required for update");
            }
            
            // Set status flags based on status
            $present = ($data['status'] === 'present') ? 1 : 0;
            $absent = ($data['status'] === 'absent') ? 1 : 0;
            $on_leave = ($data['status'] === 'on_leave') ? 1 : 0;
            
            $query = "UPDATE attendancelist SET 
                      emp_id = ?, firstName = ?, lastName = ?, date = ?, 
                      time_in = ?, time_out = ?, present = ?, absent = ?, 
                      on_leave = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                      WHERE id = ?";
            
            $stmt = mysqli_prepare($this->conn, $query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . mysqli_error($this->conn));
            }
            
            mysqli_stmt_bind_param($stmt, "isssssiiisi",
                $data['emp_id'],
                $data['firstName'],
                $data['lastName'],
                $data['date'],
                $data['time_in'] ?: null,
                $data['time_out'] ?: null,
                $present,
                $absent,
                $on_leave,
                $data['status'],
                $data['id']
            );
            
            $result = mysqli_stmt_execute($stmt);
            
            if (!$result) {
                throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
            }
            
            $affected_rows = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            
            if ($affected_rows === 0) {
                throw new Exception("No record found with the given ID");
            }
            
            return [
                'success' => true,
                'message' => 'Attendance record updated successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Delete attendance record
    public function deleteAttendance($id) {
        try {
            if (empty($id)) {
                throw new Exception("ID is required for delete");
            }
            
            $query = "DELETE FROM attendancelist WHERE id = ?";
            $stmt = mysqli_prepare($this->conn, $query);
            
            if (!$stmt) {
                throw new Exception("Prepare failed: " . mysqli_error($this->conn));
            }
            
            mysqli_stmt_bind_param($stmt, "i", $id);
            $result = mysqli_stmt_execute($stmt);
            
            if (!$result) {
                throw new Exception("Execute failed: " . mysqli_stmt_error($stmt));
            }
            
            $affected_rows = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            
            if ($affected_rows === 0) {
                throw new Exception("No record found with the given ID");
            }
            
            return [
                'success' => true,
                'message' => 'Attendance record deleted successfully'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

// Main execution
try {
    $attendanceManager = new AttendanceManager($conn);
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'fetch':
            $response = $attendanceManager->fetchAttendance();
            break;
            
        case 'create':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid JSON input");
            }
            $response = $attendanceManager->createAttendance($input);
            break;
            
        case 'update':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception("Invalid JSON input");
            }
            $response = $attendanceManager->updateAttendance($input);
            break;
            
        case 'delete':
            $id = $_GET['id'] ?? '';
            if (empty($id)) {
                throw new Exception("ID parameter is required for delete");
            }
            $response = $attendanceManager->deleteAttendance($id);
            break;
            
        default:
            $response = [
                'success' => false,
                'message' => 'Invalid action. Available actions: fetch, create, update, delete'
            ];
            break;
    }
    
} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

// Output JSON response
echo json_encode($response);

// Close database connection
if (isset($conn)) {
    mysqli_close($conn);
}
?>