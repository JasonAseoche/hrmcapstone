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

try {
    switch ($method) {
        case 'GET':
            handleGet();
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGet() {
    global $conn;
    
    try {
        $supervisor_id = $_GET['supervisor_id'] ?? '';
        $date = $_GET['date'] ?? '';
        $limit = (int)($_GET['limit'] ?? 100);
        
        if (empty($supervisor_id)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Supervisor ID is required'
            ]);
            return;
        }
        
        // First, verify that this user is a supervisor
        $supervisorCheckSql = "SELECT 
                                s.sup_id,
                                s.firstName as supervisor_firstName,
                                s.lastName as supervisor_lastName,
                                s.position as supervisor_position,
                                d.id as department_id,
                                d.name as department_name
                              FROM supervisorlist s
                              LEFT JOIN department_list d ON s.sup_id = d.supervisor_id AND d.status = 'active'
                              WHERE s.status = 'active' 
                              AND (s.sup_id = ? OR s.sup_id = (SELECT id FROM useraccounts WHERE id = ? AND role = 'supervisor'))
                              LIMIT 1";
        
        $stmt = $conn->prepare($supervisorCheckSql);
        $stmt->bind_param("ii", $supervisor_id, $supervisor_id);
        $stmt->execute();
        $supervisorResult = $stmt->get_result();
        $supervisorInfo = $supervisorResult->fetch_assoc();
        
        if (!$supervisorInfo) {
            echo json_encode([
                'success' => false,
                'error' => 'User with ID ' . $supervisor_id . ' is not found as a supervisor in the system'
            ]);
            return;
        }
        
        if (!$supervisorInfo['department_id']) {
            echo json_encode([
                'success' => false,
                'error' => 'Supervisor is not assigned to any active department'
            ]);
            return;
        }
        
        $actual_supervisor_id = $supervisorInfo['sup_id'];
        $actual_department_id = $supervisorInfo['department_id'];
        
        // Build the SQL query to get attendance records for team members
        $sql = "SELECT 
                    a.id,
                    a.emp_id,
                    a.firstName,
                    a.lastName,
                    a.date,
                    a.time_in,
                    a.time_out,
                    a.total_workhours,
                    a.overtime,
                    a.regular_ot,
                    a.regular_holiday,
                    a.regular_holiday_ot,
                    a.special_holiday,
                    a.special_holiday_ot,
                    a.late_minutes,
                    a.undertime_minutes,
                    a.late_undertime,
                    a.present,
                    a.absent,
                    a.late,
                    a.status,
                    a.shift_type,
                    a.is_holiday,
                    a.holiday_type,
                    a.break_start_time,
                    a.break_end_time,
                    a.break_duration,
                    e.position,
                    e.workarrangement,
                    u.profile_image,
                    CONCAT(COALESCE(u.firstName, a.firstName), ' ', COALESCE(u.lastName, a.lastName)) as full_name
                FROM attendancelist a
                LEFT JOIN employeelist e ON a.emp_id = e.emp_id
                INNER JOIN department_list d ON e.department_id = d.id
                LEFT JOIN useraccounts u ON a.emp_id = u.id
                WHERE d.id = ?
                AND d.supervisor_id = ? 
                AND e.status = 'active' 
                AND d.status = 'active'";
        
        // Add date filter if provided
        if (!empty($date)) {
            $sql .= " AND a.date = ?";
        }
        
        $sql .= " ORDER BY a.date DESC, a.time_in DESC LIMIT ?";
        
        $stmt = $conn->prepare($sql);
        
        if (!empty($date)) {
            $stmt->bind_param("iisi", $actual_department_id, $actual_supervisor_id, $date, $limit);
        } else {
            $stmt->bind_param("iii", $actual_department_id, $actual_supervisor_id, $limit);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $attendanceRecords = [];
        while ($row = $result->fetch_assoc()) {
            // Format the profile image path if it exists
            if (!empty($row['profile_image'])) {
                // Check if it's already a full URL
                if (!str_starts_with($row['profile_image'], 'http')) {
                    // Check if path already contains uploads/ prefix
                    if (str_starts_with($row['profile_image'], 'uploads/')) {
                        // Path already has uploads/ prefix, use as-is
                        $row['profile_image'] = $row['profile_image'];
                    } else {
                        // Add uploads/profiles/ prefix
                        $row['profile_image'] = 'uploads/profiles/' . $row['profile_image'];
                    }
                }
            } else {
                // Set to null if empty to ensure consistent handling
                $row['profile_image'] = null;
            }
            
            $attendanceRecords[] = $row;
        }
        
        // Get team statistics for the supervisor
        $statsResult = getTeamAttendanceStatistics($actual_supervisor_id, $actual_department_id, $date, $conn);
        
        echo json_encode([
            'success' => true,
            'supervisor_info' => [
                'supervisor_id' => $supervisorInfo['sup_id'],
                'supervisor_name' => $supervisorInfo['supervisor_firstName'] . ' ' . $supervisorInfo['supervisor_lastName'],
                'supervisor_position' => $supervisorInfo['supervisor_position'],
                'department_id' => $supervisorInfo['department_id'],
                'department_name' => $supervisorInfo['department_name']
            ],
            'records' => $attendanceRecords,
            'statistics' => $statsResult,
            'total_records' => count($attendanceRecords),
            'message' => 'Team attendance records fetched successfully'
        ]);
        
    } catch (Exception $e) {
        error_log('Error in team_attendance.php: ' . $e->getMessage());
        throw new Exception('Error fetching team attendance records: ' . $e->getMessage());
    }
}

// Helper function to get team attendance statistics
function getTeamAttendanceStatistics($supervisor_id, $department_id, $date = null, $conn) {
    try {
        $whereClause = "WHERE d.id = ? AND d.supervisor_id = ? AND e.status = 'active' AND d.status = 'active'";
        $params = [$department_id, $supervisor_id];
        $types = "ii";
        
        if (!empty($date)) {
            $whereClause .= " AND a.date = ?";
            $params[] = $date;
            $types .= "s";
        }
        
        $statsSql = "SELECT 
                        COUNT(DISTINCT e.emp_id) as total_team_members,
                        COUNT(CASE WHEN a.present = 1 THEN 1 END) as present_count,
                        COUNT(CASE WHEN a.absent = 1 THEN 1 END) as absent_count,
                        COUNT(CASE WHEN a.late = 1 THEN 1 END) as late_count,
                        COUNT(CASE WHEN a.overtime > 0 THEN 1 END) as overtime_count,
                        COUNT(CASE WHEN a.status = 'On Leave' THEN 1 END) as on_leave_count,
                        AVG(CASE WHEN a.total_workhours > 0 THEN a.total_workhours END) as avg_work_hours,
                        SUM(CASE WHEN a.overtime > 0 THEN a.overtime END) as total_overtime_minutes
                     FROM employeelist e
                     LEFT JOIN department_list d ON e.department_id = d.id
                     LEFT JOIN attendancelist a ON e.emp_id = a.emp_id" . 
                     (!empty($date) ? " AND a.date = ?" : "") .
                     " " . $whereClause;
        
        $stmt = $conn->prepare($statsSql);
        
        if (!empty($date)) {
            // Add date parameter twice: once for the JOIN condition, once for WHERE
            $finalParams = array_merge([$date], $params);
            $finalTypes = "s" . $types;
            $stmt->bind_param($finalTypes, ...$finalParams);
        } else {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        
        // Calculate percentages and format data
        $totalMembers = (int)$stats['total_team_members'];
        $presentCount = (int)$stats['present_count'];
        $absentCount = (int)$stats['absent_count'];
        $lateCount = (int)$stats['late_count'];
        $overtimeCount = (int)$stats['overtime_count'];
        $onLeaveCount = (int)$stats['on_leave_count'];
        
        return [
            'total_team_members' => $totalMembers,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'late_count' => $lateCount,
            'overtime_count' => $overtimeCount,
            'on_leave_count' => $onLeaveCount,
            'present_percentage' => $totalMembers > 0 ? round(($presentCount / $totalMembers) * 100, 1) : 0,
            'absent_percentage' => $totalMembers > 0 ? round(($absentCount / $totalMembers) * 100, 1) : 0,
            'avg_work_hours' => $stats['avg_work_hours'] ? round($stats['avg_work_hours'] / 60, 2) : 0,
            'total_overtime_hours' => $stats['total_overtime_minutes'] ? round($stats['total_overtime_minutes'] / 60, 2) : 0
        ];
        
    } catch (Exception $e) {
        error_log('Error getting team attendance statistics: ' . $e->getMessage());
        return [
            'total_team_members' => 0,
            'present_count' => 0,
            'absent_count' => 0,
            'late_count' => 0,
            'overtime_count' => 0,
            'on_leave_count' => 0,
            'present_percentage' => 0,
            'absent_percentage' => 0,
            'avg_work_hours' => 0,
            'total_overtime_hours' => 0
        ];
    }
}

// Helper function to check if date is weekday (Monday-Friday)
function isWeekday($date) {
    $dateObj = new DateTime($date . ' 00:00:00', new DateTimeZone('Asia/Manila'));
    $dayOfWeek = (int)$dateObj->format('N'); // 1 (Monday) to 7 (Sunday)
    return $dayOfWeek >= 1 && $dayOfWeek <= 5;
}

// Helper function to get current Manila time (UTC+8)
function getCurrentTimeUTC8() {
    $manila = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $manila);
    return $now;
}

?>