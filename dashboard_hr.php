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
    $conn->close();
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

    switch ($action) {
        case 'get_dashboard_stats':
            getDashboardStats($pdo);
            break;
        case 'get_calendar_events':
            getCalendarEvents($pdo);
            break;
        case 'get_attendance_activities':
            getAttendanceActivities($pdo);
            break;
        case 'get_pending_applicants_overview':
            getPendingApplicantsOverview($pdo);
            break;
        case 'get_attendance_overview':
            getAttendanceOverview($pdo);
            break;
        case 'get_all_dashboard_data':
            getAllDashboardData($pdo);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}

// Function to get all dashboard data in one request
function getAllDashboardData($pdo) {
    try {
        $data = [
            'stats' => getDashboardStatsData($pdo),
            'calendar_events' => getCalendarEventsData($pdo),
            'attendance_activities' => getAttendanceActivitiesData($pdo),
            'pending_applicants_chart' => getPendingApplicantsOverviewData($pdo),
            'attendance_chart' => getAttendanceOverviewData($pdo)
        ];

        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error fetching dashboard data: ' . $e->getMessage()]);
    }
}

// Get dashboard statistics (total employees, applicants, pending applicants)
function getDashboardStats($pdo) {
    try {
        $data = getDashboardStatsData($pdo);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getDashboardStatsData($pdo) {
    // Get total employees
    $empSql = "SELECT COUNT(*) as total_employees FROM employeelist";
    $empStmt = $pdo->prepare($empSql);
    $empStmt->execute();
    $totalEmployees = $empStmt->fetch(PDO::FETCH_ASSOC)['total_employees'];

    // Get total applicants
    $appSql = "SELECT COUNT(*) as total_applicants FROM applicantlist";
    $appStmt = $pdo->prepare($appSql);
    $appStmt->execute();
    $totalApplicants = $appStmt->fetch(PDO::FETCH_ASSOC)['total_applicants'];

    // Get pending applicants
    $pendingSql = "SELECT COUNT(*) as pending_applicants FROM applicantlist WHERE status = 'pending'";
    $pendingStmt = $pdo->prepare($pendingSql);
    $pendingStmt->execute();
    $pendingApplicants = $pendingStmt->fetch(PDO::FETCH_ASSOC)['pending_applicants'];

    return [
        'total_employees' => (int)$totalEmployees,
        'total_applicants' => (int)$totalApplicants,
        'pending_applicants' => (int)$pendingApplicants
    ];
}

// Get calendar events (today's events and upcoming events)
function getCalendarEvents($pdo) {
    try {
        $data = getCalendarEventsData($pdo);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getCalendarEventsData($pdo) {
    $today = date('Y-m-d');
    
    // Get today's events
    $todayEventsSql = "SELECT title, event_date, start_time 
                       FROM events 
                       WHERE DATE(event_date) = :today 
                       ORDER BY start_time ASC";
    $todayStmt = $pdo->prepare($todayEventsSql);
    $todayStmt->bindParam(':today', $today);
    $todayStmt->execute();
    $todayEvents = $todayStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get upcoming events (next 7 days, excluding today)
    $upcomingEventsSql = "SELECT title, event_date, start_time 
                          FROM events 
                          WHERE DATE(event_date) > :today 
                          AND DATE(event_date) <= DATE_ADD(:today, INTERVAL 7 DAY)
                          ORDER BY event_date ASC, start_time ASC 
                          LIMIT 10";
    $upcomingStmt = $pdo->prepare($upcomingEventsSql);
    $upcomingStmt->bindParam(':today', $today);
    $upcomingStmt->execute();
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
}

// Get attendance activities (real-time attendance activities)
function getAttendanceActivities($pdo) {
    try {
        $data = getAttendanceActivitiesData($pdo);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getAttendanceActivitiesData($pdo) {
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    // Get recent attendance activities (time in/out for today and yesterday)
    $activitiesSql = "SELECT a.*, u.profile_image 
                      FROM attendancelist a 
                      LEFT JOIN useraccounts u ON a.emp_id = u.id
                      WHERE a.date >= :yesterday 
                      AND (a.time_in IS NOT NULL OR a.time_out IS NOT NULL)
                      ORDER BY 
                          CASE 
                              WHEN a.time_out IS NOT NULL THEN CONCAT(a.date, ' ', a.time_out)
                              ELSE CONCAT(a.date, ' ', a.time_in)
                          END DESC
                      LIMIT 20";
    
    $stmt = $pdo->prepare($activitiesSql);
    $stmt->bindParam(':yesterday', $yesterday);
    $stmt->execute();
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedActivities = [];
    
    foreach ($activities as $activity) {
        $employee_name = $activity['firstName'] . ' ' . $activity['lastName'];
        $profile_image = $activity['profile_image'];
        $date = $activity['date'];
        
        // Add time in activity
        if ($activity['time_in']) {
            $time_in = date('g:i A', strtotime($activity['time_in']));
            $formattedActivities[] = [
                'id' => 'in_' . $activity['id'],
                'employee' => $employee_name,
                'action' => 'Checked in',
                'time' => $time_in,
                'date' => date('M j, Y', strtotime($date)),
                'avatar' => $profile_image ? (strpos($profile_image, 'http') === 0 ? $profile_image : "https://difsysinc.com/difsysapi/" . $profile_image) : null,
                'timestamp' => strtotime($date . ' ' . $activity['time_in'])
            ];
        }
        
        // Add time out activity
        if ($activity['time_out']) {
            $time_out = date('g:i A', strtotime($activity['time_out']));
            $formattedActivities[] = [
                'id' => 'out_' . $activity['id'],
                'employee' => $employee_name,
                'action' => 'Checked out',
                'time' => $time_out,
                'date' => date('M j, Y', strtotime($date)),
                'avatar' => $profile_image ? (strpos($profile_image, 'http') === 0 ? $profile_image : "https://difsysinc.com/difsysapi/" . $profile_image) : null,
                'timestamp' => strtotime($date . ' ' . $activity['time_out'])
            ];
        }
    }
    
    // Sort by timestamp (most recent first) and limit to 6 activities
    usort($formattedActivities, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return array_slice($formattedActivities, 0, 6);
}

// Get pending applicants overview for bar chart
function getPendingApplicantsOverview($pdo) {
    try {
        $data = getPendingApplicantsOverviewData($pdo);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getPendingApplicantsOverviewData($pdo) {
    $currentYear = date('Y');
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $monthlyData = [];
    
    for ($month = 1; $month <= 12; $month++) {
        $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);
        
        // Get pending applicants for this month
        $sql = "SELECT COUNT(*) as count 
                FROM applicantlist 
                WHERE status = 'pending' 
                AND YEAR(created_at) = :year 
                AND MONTH(created_at) = :month";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':year', $currentYear, PDO::PARAM_INT);
        $stmt->bindParam(':month', $month, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $monthlyData[] = (int)$result['count'];
    }
    
    return [
        'labels' => $months, // Return ALL 12 months  
        'data' => $monthlyData // Return ALL 12 months of data
    ];
}

// Get attendance overview for dual bar chart (Present vs Absent)
function getAttendanceOverview($pdo) {
    try {
        $data = getAttendanceOverviewData($pdo);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getAttendanceOverviewData($pdo) {
    $currentYear = date('Y');
    $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $presentData = [];
    $absentData = [];
    
    for ($month = 1; $month <= 12; $month++) {
        $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);
        
        // Get present count for this month - fixed query to use proper status checking
        $presentSql = "SELECT COUNT(*) as count 
                       FROM attendancelist 
                       WHERE (present = 1 OR status = 'Present' OR time_in IS NOT NULL)
                       AND YEAR(date) = :year 
                       AND MONTH(date) = :month";
        
        $presentStmt = $pdo->prepare($presentSql);
        $presentStmt->bindParam(':year', $currentYear, PDO::PARAM_INT);
        $presentStmt->bindParam(':month', $month, PDO::PARAM_INT);
        $presentStmt->execute();
        $presentResult = $presentStmt->fetch(PDO::FETCH_ASSOC);
        
        // Get absent count for this month - fixed query
        $absentSql = "SELECT COUNT(*) as count 
                         FROM attendancelist 
                         WHERE (absent = 1 OR status = 'Absent')
                        AND YEAR(date) = :year 
                        AND MONTH(date) = :month";
        
        $absentStmt = $pdo->prepare($absentSql);
        $absentStmt->bindParam(':year', $currentYear, PDO::PARAM_INT);
        $absentStmt->bindParam(':month', $month, PDO::PARAM_INT);
        $absentStmt->execute();
        $absentResult = $absentStmt->fetch(PDO::FETCH_ASSOC);
        
        $presentData[] = (int)$presentResult['count'];
        $absentData[] = (int)$absentResult['count'];
    }
    
    // Debug: Log September data specifically
    error_log("September (Month 9) - Present: " . $presentData[8] . ", Absent: " . $absentData[8]);
    
    return [
        'labels' => $months,
        'present_data' => $presentData,
        'absent_data' => $absentData,
        'debug' => [
            'current_year' => $currentYear,
            'september_present' => $presentData[8],
            'september_absent' => $absentData[8],
            'total_months_checked' => count($presentData)
        ]
    ];
}

?>