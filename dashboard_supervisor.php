<?php

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Set the correct timezone for the entire script
date_default_timezone_set('Asia/Manila');

require_once 'db_connection.php';

// Convert mysqli connection to PDO for compatibility
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Close the mysqli connection since we're using PDO
    if ($conn) {
        $conn->close();
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleGetRequest($pdo);
} else {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

function handleGetRequest($pdo) {
    $action = $_GET['action'] ?? '';
    $supervisor_id = $_GET['supervisor_id'] ?? '';

    if (empty($supervisor_id)) {
        echo json_encode(['success' => false, 'error' => 'Supervisor ID is required']);
        return;
    }

    switch ($action) {
        case 'get_dashboard_stats':
            getDashboardStats($pdo, $supervisor_id);
            break;
        case 'get_attendance_overview':
            getAttendanceOverview($pdo, $supervisor_id);
            break;
        case 'get_today_overview':
            getTodayOverview($pdo, $supervisor_id);
            break;
        case 'get_calendar_events':
            getCalendarEvents($pdo, $supervisor_id);
            break;
        case 'get_all_dashboard_data':
            getAllDashboardData($pdo, $supervisor_id);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}

/**
 * Get all dashboard data in one request
 */
function getAllDashboardData($pdo, $supervisor_id) {
    try {
        $data = [
            'today_stats' => getTodayStatsData($pdo, $supervisor_id),
            'attendance_overview' => getAttendanceOverviewData($pdo, $supervisor_id),
            'calendar_events' => getCalendarEventsData($pdo)
        ];

        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error fetching dashboard data: ' . $e->getMessage()]);
    }
}

/**
 * Get today's attendance overview for the supervisor's department
 */
function getTodayOverview($pdo, $supervisor_id) {
    try {
        $data = getTodayStatsData($pdo, $supervisor_id);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getTodayStatsData($pdo, $supervisor_id) {
    $today = date('Y-m-d');

    try {
        // First, verify supervisor exists and get their department
        $supervisorSql = "SELECT sup_id FROM supervisorlist WHERE sup_id = ? AND status = 'active'";
        $supervisorStmt = $pdo->prepare($supervisorSql);
        $supervisorStmt->execute([$supervisor_id]);
        $supervisor = $supervisorStmt->fetch(PDO::FETCH_ASSOC);

        if (!$supervisor) {
            return [
                'total_employees' => 0,
                'present_today' => 0,
                'absent_today' => 0,
                'on_leave_today' => 0,
                'error' => 'Supervisor not found'
            ];
        }

        // Get total employees in supervisor's active departments (including work from home)
        $totalEmpSql = "SELECT COUNT(DISTINCT e.emp_id) as count
                        FROM employeelist e
                        LEFT JOIN department_list d ON e.department_id = d.id
                        WHERE d.supervisor_id = ? AND e.status = 'active' AND d.status = 'active'";
        $totalEmpStmt = $pdo->prepare($totalEmpSql);
        $totalEmpStmt->execute([$supervisor_id]);
        $totalEmployees = (int)$totalEmpStmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Get present count - employees with time_in today (including work from home)
        $presentSql = "SELECT COUNT(DISTINCT e.emp_id) as count
                       FROM employeelist e
                       LEFT JOIN department_list d ON e.department_id = d.id
                       LEFT JOIN attendancelist a ON e.emp_id = a.emp_id
                       WHERE d.supervisor_id = ? 
                       AND e.status = 'active' 
                       AND d.status = 'active'
                       AND a.date = ?
                       AND a.time_in IS NOT NULL";
        $presentStmt = $pdo->prepare($presentSql);
        $presentStmt->execute([$supervisor_id, $today]);
        $presentCount = (int)$presentStmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Get absent count - employees with absent status today (and no time_in) (including work from home)
        $absentSql = "SELECT COUNT(DISTINCT e.emp_id) as count
                      FROM employeelist e
                      LEFT JOIN department_list d ON e.department_id = d.id
                      LEFT JOIN attendancelist a ON e.emp_id = a.emp_id
                      WHERE d.supervisor_id = ? 
                      AND e.status = 'active' 
                      AND d.status = 'active'
                      AND a.date = ?
                      AND (a.status = 'Absent' OR (a.absent = 1 AND a.time_in IS NULL))";
        $absentStmt = $pdo->prepare($absentSql);
        $absentStmt->execute([$supervisor_id, $today]);
        $absentCount = (int)$absentStmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Get on leave count - employees with leave status today (including work from home)
        $leaveSql = "SELECT COUNT(DISTINCT e.emp_id) as count
                     FROM employeelist e
                     LEFT JOIN department_list d ON e.department_id = d.id
                     LEFT JOIN attendancelist a ON e.emp_id = a.emp_id AND a.date = ?
                     WHERE d.supervisor_id = ? 
                     AND e.status = 'active' 
                     AND d.status = 'active'
                     AND (a.status = 'On Leave' OR a.status = 'Leave' OR a.on_leave = 1)";
        $leaveStmt = $pdo->prepare($leaveSql);
        $leaveStmt->execute([$today, $supervisor_id]);
        $leaveCount = (int)$leaveStmt->fetch(PDO::FETCH_ASSOC)['count'];

        return [
            'total_employees' => $totalEmployees,
            'present_today' => $presentCount,
            'absent_today' => $absentCount,
            'on_leave_today' => $leaveCount
        ];

    } catch(Exception $e) {
        error_log('Error in getTodayStatsData: ' . $e->getMessage());
        return [
            'total_employees' => 0,
            'present_today' => 0,
            'absent_today' => 0,
            'on_leave_today' => 0,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get monthly attendance overview for supervisor's department employees
 */
function getAttendanceOverview($pdo, $supervisor_id) {
    try {
        $data = getAttendanceOverviewData($pdo, $supervisor_id);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getAttendanceOverviewData($pdo, $supervisor_id) {
    $currentYear = date('Y');
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    
    $presentData = [];
    $absentData = [];
    $leaveData = [];

    try {
        // Verify supervisor exists
        $supervisorSql = "SELECT sup_id FROM supervisorlist WHERE sup_id = ? AND status = 'active'";
        $supervisorStmt = $pdo->prepare($supervisorSql);
        $supervisorStmt->execute([$supervisor_id]);
        $supervisor = $supervisorStmt->fetch(PDO::FETCH_ASSOC);

        if (!$supervisor) {
            return [
                'labels' => $months,
                'present_data' => array_fill(0, 12, 0),
                'absent_data' => array_fill(0, 12, 0),
                'leave_data' => array_fill(0, 12, 0),
                'error' => 'Supervisor not found'
            ];
        }

        for ($month = 1; $month <= 12; $month++) {
            $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);

            // Get present count for this month - employees with time_in (including work from home)
            $presentSql = "SELECT COUNT(DISTINCT a.emp_id) as count
                           FROM attendancelist a
                           LEFT JOIN employeelist e ON a.emp_id = e.emp_id
                           LEFT JOIN department_list d ON e.department_id = d.id
                           WHERE d.supervisor_id = ?
                           AND e.status = 'active'
                           AND d.status = 'active'
                           AND YEAR(a.date) = ?
                           AND MONTH(a.date) = ?
                           AND a.time_in IS NOT NULL";

            $presentStmt = $pdo->prepare($presentSql);
            $presentStmt->execute([$supervisor_id, $currentYear, $month]);
            $presentResult = $presentStmt->fetch(PDO::FETCH_ASSOC);
            $presentData[] = (int)$presentResult['count'];

            // Get absent count for this month (including work from home)
            $absentSql = "SELECT COUNT(DISTINCT a.emp_id) as count
                          FROM attendancelist a
                          LEFT JOIN employeelist e ON a.emp_id = e.emp_id
                          LEFT JOIN department_list d ON e.department_id = d.id
                          WHERE d.supervisor_id = ?
                          AND e.status = 'active'
                          AND d.status = 'active'
                          AND YEAR(a.date) = ?
                          AND MONTH(a.date) = ?
                          AND (a.status = 'Absent' OR (a.absent = 1 AND a.time_in IS NULL))";

            $absentStmt = $pdo->prepare($absentSql);
            $absentStmt->execute([$supervisor_id, $currentYear, $month]);
            $absentResult = $absentStmt->fetch(PDO::FETCH_ASSOC);
            $absentData[] = (int)$absentResult['count'];

            // Get leave count for this month (including work from home)
            $leaveSql = "SELECT COUNT(DISTINCT a.emp_id) as count
                         FROM attendancelist a
                         LEFT JOIN employeelist e ON a.emp_id = e.emp_id
                         LEFT JOIN department_list d ON e.department_id = d.id
                         WHERE d.supervisor_id = ?
                         AND e.status = 'active'
                         AND d.status = 'active'
                         AND YEAR(a.date) = ?
                         AND MONTH(a.date) = ?
                         AND (a.status = 'On Leave' OR a.status = 'Leave' OR a.on_leave = 1)";

            $leaveStmt = $pdo->prepare($leaveSql);
            $leaveStmt->execute([$supervisor_id, $currentYear, $month]);
            $leaveResult = $leaveStmt->fetch(PDO::FETCH_ASSOC);
            $leaveData[] = (int)$leaveResult['count'];
        }

        return [
            'labels' => $months,
            'present_data' => $presentData,
            'absent_data' => $absentData,
            'leave_data' => $leaveData,
            'current_year' => $currentYear
        ];

    } catch(Exception $e) {
        error_log('Error in getAttendanceOverviewData: ' . $e->getMessage());
        return [
            'labels' => $months,
            'present_data' => array_fill(0, 12, 0),
            'absent_data' => array_fill(0, 12, 0),
            'leave_data' => array_fill(0, 12, 0),
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get dashboard statistics
 */
function getDashboardStats($pdo, $supervisor_id) {
    try {
        $data = getDashboardStatsData($pdo, $supervisor_id);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getDashboardStatsData($pdo, $supervisor_id) {
    try {
        // Get total employees in supervisor's department (including work from home)
        $totalEmpSql = "SELECT COUNT(DISTINCT e.emp_id) as total_employees
                        FROM employeelist e
                        LEFT JOIN department_list d ON e.department_id = d.id
                        WHERE d.supervisor_id = ? AND e.status = 'active' AND d.status = 'active'";
        $totalEmpStmt = $pdo->prepare($totalEmpSql);
        $totalEmpStmt->execute([$supervisor_id]);
        $totalEmployees = (int)$totalEmpStmt->fetch(PDO::FETCH_ASSOC)['total_employees'];

        // Get department name
        $deptSql = "SELECT name FROM department_list WHERE supervisor_id = ? AND status = 'active' LIMIT 1";
        $deptStmt = $pdo->prepare($deptSql);
        $deptStmt->execute([$supervisor_id]);
        $deptResult = $deptStmt->fetch(PDO::FETCH_ASSOC);
        $departmentName = $deptResult ? $deptResult['name'] : 'Department';

        return [
            'total_employees' => $totalEmployees,
            'department_name' => $departmentName
        ];

    } catch(Exception $e) {
        error_log('Error in getDashboardStatsData: ' . $e->getMessage());
        return [
            'total_employees' => 0,
            'department_name' => 'Department',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get calendar events for today and upcoming
 */
function getCalendarEvents($pdo, $supervisor_id) {
    try {
        $data = getCalendarEventsData($pdo);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getCalendarEventsData($pdo) {
    $today = date('Y-m-d');
    
    try {
        // Get today's events
        $todayEventsSql = "SELECT title, event_date, start_time 
                           FROM events 
                           WHERE DATE(event_date) = ? 
                           ORDER BY start_time ASC";
        $todayStmt = $pdo->prepare($todayEventsSql);
        $todayStmt->execute([$today]);
        $todayEvents = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

        // Get upcoming events (next 7 days, excluding today)
        $upcomingEventsSql = "SELECT title, event_date, start_time 
                              FROM events 
                              WHERE DATE(event_date) > ? 
                              AND DATE(event_date) <= DATE_ADD(?, INTERVAL 7 DAY)
                              ORDER BY event_date ASC, start_time ASC 
                              LIMIT 10";
        $upcomingStmt = $pdo->prepare($upcomingEventsSql);
        $upcomingStmt->execute([$today, $today]);
        $upcomingEvents = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

        // Format the events
        $todayFormatted = array_map(function($event) {
            return [
                'title' => $event['title'],
                'date' => $event['event_date'],
                'time' => date('g:i A', strtotime($event['start_time'])),
                'type' => 'today'
            ];
        }, $todayEvents);

        $upcomingFormatted = array_map(function($event) {
            return [
                'title' => $event['title'],
                'date' => $event['event_date'],
                'time' => date('g:i A', strtotime($event['start_time'])),
                'type' => 'upcoming',
                'formatted_date' => date('M j', strtotime($event['event_date']))
            ];
        }, $upcomingEvents);

        return [
            'current_date' => $today,
            'current_month_year' => date('F Y'),
            'today_events' => $todayFormatted,
            'upcoming_events' => $upcomingFormatted
        ];

    } catch(Exception $e) {
        error_log('Error in getCalendarEventsData: ' . $e->getMessage());
        return [
            'current_date' => $today,
            'current_month_year' => date('F Y'),
            'today_events' => [],
            'upcoming_events' => [],
            'error' => $e->getMessage()
        ];
    }
}

?>