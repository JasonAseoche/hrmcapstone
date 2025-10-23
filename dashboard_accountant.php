<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Set the correct timezone
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
        case 'get_tasks':
            getTasks($pdo);
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
            'tasks' => getTasksData($pdo)
        ];

        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
    } catch(Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Error fetching dashboard data: ' . $e->getMessage()]);
    }
}

// Get dashboard statistics
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
    $empSql = "SELECT COUNT(*) as total_employees FROM employeelist WHERE status = 'active'";
    $empStmt = $pdo->prepare($empSql);
    $empStmt->execute();
    $totalEmployees = $empStmt->fetch(PDO::FETCH_ASSOC)['total_employees'];

    // Get completed tasks count (tasks that have been marked as completed)
    $completedSql = "SELECT COUNT(*) as completed_tasks FROM accountant_tasks WHERE status = 'Completed'";
    $completedStmt = $pdo->prepare($completedSql);
    $completedStmt->execute();
    $completedTasks = $completedStmt->fetch(PDO::FETCH_ASSOC)['completed_tasks'];

    // Get new tasks count (pending tasks)
    $newTasksSql = "SELECT COUNT(*) as new_tasks FROM accountant_tasks WHERE status = 'Pending'";
    $newTasksStmt = $pdo->prepare($newTasksSql);
    $newTasksStmt->execute();
    $newTasks = $newTasksStmt->fetch(PDO::FETCH_ASSOC)['new_tasks'];

    return [
        'total_employees' => (int)$totalEmployees,
        'completed_tasks' => (int)$completedTasks,
        'new_tasks' => (int)$newTasks
    ];
}

// Get calendar events with payroll periods
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
    $currentMonth = date('Y-m');
    
    // Get current month and year for display
    $currentMonthName = date('F Y');
    
    // Get all events for the current month
    $eventsSql = "SELECT title, event_date, start_time 
                  FROM events 
                  WHERE DATE_FORMAT(event_date, '%Y-%m') = :current_month 
                  ORDER BY event_date ASC, start_time ASC";
    $eventsStmt = $pdo->prepare($eventsSql);
    $eventsStmt->bindParam(':current_month', $currentMonth);
    $eventsStmt->execute();
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get payroll periods for the current month and upcoming
    $payrollSql = "SELECT * FROM payroll_periods 
                   WHERE (date_from >= :first_day_of_month OR date_to >= :first_day_of_month)
                   ORDER BY date_from ASC";
    $firstDayOfMonth = date('Y-m-01');
    $payrollStmt = $pdo->prepare($payrollSql);
    $payrollStmt->bindParam(':first_day_of_month', $firstDayOfMonth);
    $payrollStmt->execute();
    $payrollPeriods = $payrollStmt->fetchAll(PDO::FETCH_ASSOC);

    $calendarEvents = [];
    
    // Add regular events
    foreach ($events as $event) {
        $eventDate = $event['event_date'];
        $eventDay = (int)date('d', strtotime($eventDate));
        
        if (!isset($calendarEvents[$eventDay])) {
            $calendarEvents[$eventDay] = [];
        }
        
        $calendarEvents[$eventDay][] = [
            'title' => $event['title'],
            'time' => date('g:i A', strtotime($event['start_time'])),
            'type' => 'event',
            'color' => 'development'
        ];
    }
    
    // Add payroll period start dates
    foreach ($payrollPeriods as $period) {
        $dateFrom = $period['date_from'];
        $dateTo = $period['date_to'];
        
        // Check if start date is in current month
        if (date('Y-m', strtotime($dateFrom)) === $currentMonth) {
            $startDay = (int)date('d', strtotime($dateFrom));
            
            if (!isset($calendarEvents[$startDay])) {
                $calendarEvents[$startDay] = [];
            }
            
            $calendarEvents[$startDay][] = [
                'title' => 'Payroll Period Starts',
                'time' => 'All Day',
                'type' => 'payroll_start',
                'color' => 'development',
                'period' => date('M d', strtotime($dateFrom)) . ' - ' . date('M d', strtotime($dateTo))
            ];
        }
        
        // Add "Upload Benefits" for 5 days before end date
        $endDateTime = strtotime($dateTo);
        for ($i = 1; $i <= 5; $i++) {
            $benefitDate = date('Y-m-d', strtotime("-{$i} days", $endDateTime));
            
            // Check if benefit date is in current month
            if (date('Y-m', strtotime($benefitDate)) === $currentMonth) {
                $benefitDay = (int)date('d', strtotime($benefitDate));
                
                if (!isset($calendarEvents[$benefitDay])) {
                    $calendarEvents[$benefitDay] = [];
                }
                
                // Check if already added
                $alreadyAdded = false;
                foreach ($calendarEvents[$benefitDay] as $existingEvent) {
                    if ($existingEvent['title'] === 'Upload Benefits' && $existingEvent['type'] === 'upload_benefits') {
                        $alreadyAdded = true;
                        break;
                    }
                }
                
                if (!$alreadyAdded) {
                    $calendarEvents[$benefitDay][] = [
                        'title' => 'Upload Benefits',
                        'time' => date('g:i A', strtotime('09:00:00')),
                        'type' => 'upload_benefits',
                        'color' => 'ux',
                        'due_date' => $dateTo
                    ];
                }
            }
        }
    }
    
    // Get days with events
    $daysWithEvents = array_keys($calendarEvents);
    
    return [
        'current_date' => $today,
        'current_month_year' => $currentMonthName,
        'calendar_events' => $calendarEvents,
        'days_with_events' => $daysWithEvents
    ];
}

// Get tasks for accountant
function getTasks($pdo) {
    try {
        $data = getTasksData($pdo);
        echo json_encode(['success' => true, 'data' => $data]);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getTasksData($pdo) {
    $today = date('Y-m-d');
    
    // Auto-generate tasks based on payroll periods
    generatePayrollTasks($pdo, $today);
    
    // Check for new employee accounts and generate tasks
    generateNewAccountTasks($pdo, $today);
    
    // Get all tasks ordered by status and due date
    $tasksSql = "SELECT * FROM accountant_tasks 
                 ORDER BY 
                     CASE status 
                         WHEN 'Pending' THEN 1 
                         WHEN 'In Progress' THEN 2 
                         WHEN 'Completed' THEN 3 
                     END,
                     due_date ASC
                 LIMIT 20";
    $tasksStmt = $pdo->prepare($tasksSql);
    $tasksStmt->execute();
    $tasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formattedTasks = [];
    foreach ($tasks as $task) {
        $formattedTasks[] = [
            'id' => $task['id'],
            'name' => $task['task_name'],
            'status' => $task['status'],
            'dueBy' => $task['due_date'],
            'link' => $task['link'],
            'task_type' => $task['task_type']
        ];
    }
    
    return $formattedTasks;
}

// Function to auto-generate payroll tasks
function generatePayrollTasks($pdo, $today) {
    try {
        // Get payroll periods where today is within 7 days of the end date OR currently active
        $sql = "SELECT * FROM payroll_periods 
                WHERE (date_to >= :today AND date_to <= DATE_ADD(:today, INTERVAL 7 DAY))
                   OR (date_from <= :today AND date_to >= :today)
                ORDER BY date_to ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':today', $today);
        $stmt->execute();
        $periods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($periods as $period) {
            $dateTo = $period['date_to'];
            $dueDate = date('Y-m-d', strtotime($dateTo . ' +5 days'));
            $periodId = $period['prp_id']; // Changed from 'id' to 'prp_id'
            
            // Check if "Generate Payroll" task should be marked as completed
            $payrollCompleted = false;
            $checkPayrollSql = "SELECT COUNT(*) as total, 
                                      SUM(CASE WHEN status = 'Generated' THEN 1 ELSE 0 END) as generated 
                               FROM payroll_records 
                               WHERE payroll_period_id = :period_id";
            $checkPayrollStmt = $pdo->prepare($checkPayrollSql);
            $checkPayrollStmt->bindParam(':period_id', $periodId);
            $checkPayrollStmt->execute();
            $payrollResult = $checkPayrollStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payrollResult['total'] > 0 && $payrollResult['total'] == $payrollResult['generated']) {
                $payrollCompleted = true;
            }
            
            // Check if "Generate Payslip" task should be marked as completed
            $payslipCompleted = false;
            $checkPayslipSql = "SELECT COUNT(*) as total, 
                                      SUM(CASE WHEN status = 'Generated' THEN 1 ELSE 0 END) as generated 
                               FROM payroll_releases 
                               WHERE payroll_period_id = :period_id";
            $checkPayslipStmt = $pdo->prepare($checkPayslipSql);
            $checkPayslipStmt->bindParam(':period_id', $periodId);
            $checkPayslipStmt->execute();
            $payslipResult = $checkPayslipStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payslipResult['total'] > 0 && $payslipResult['total'] == $payslipResult['generated']) {
                $payslipCompleted = true;
            }
            
            // Tasks to generate
            $tasksToGenerate = [
                [
                    'name' => 'Generate Payroll',
                    'type' => 'generate_payroll',
                    'link' => '/generate-payroll',
                    'status' => $payrollCompleted ? 'Completed' : 'Pending'
                ],
                [
                    'name' => 'Generate Payslip',
                    'type' => 'generate_payslip',
                    'link' => '/generate-payroll',
                    'status' => $payslipCompleted ? 'Completed' : 'Pending'
                ],
                [
                    'name' => 'Upload Benefits',
                    'type' => 'upload_benefits',
                    'link' => '/benefits',
                    'status' => 'Pending'
                ]
            ];
            
            foreach ($tasksToGenerate as $taskInfo) {
                // Check if task already exists for this period
                $checkSql = "SELECT id, status FROM accountant_tasks 
                            WHERE task_type = :task_type 
                            AND payroll_period_id = :period_id";
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->bindParam(':task_type', $taskInfo['type']);
                $checkStmt->bindParam(':period_id', $periodId);
                $checkStmt->execute();
                $existingTask = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existingTask) {
                    // Insert new task
                    $insertSql = "INSERT INTO accountant_tasks 
                                 (task_name, status, due_date, link, task_type, payroll_period_id, created_at) 
                                 VALUES (:task_name, :status, :due_date, :link, :task_type, :period_id, NOW())";
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->bindParam(':task_name', $taskInfo['name']);
                    $insertStmt->bindParam(':status', $taskInfo['status']);
                    $insertStmt->bindParam(':due_date', $dueDate);
                    $insertStmt->bindParam(':link', $taskInfo['link']);
                    $insertStmt->bindParam(':task_type', $taskInfo['type']);
                    $insertStmt->bindParam(':period_id', $periodId);
                    $insertStmt->execute();
                } else {
                    // Update existing task status if needed
                    if ($existingTask['status'] !== $taskInfo['status']) {
                        $updateSql = "UPDATE accountant_tasks 
                                     SET status = :status, updated_at = NOW() 
                                     WHERE id = :task_id";
                        $updateStmt = $pdo->prepare($updateSql);
                        $updateStmt->bindParam(':status', $taskInfo['status']);
                        $updateStmt->bindParam(':task_id', $existingTask['id']);
                        $updateStmt->execute();
                    }
                }
            }
        }
    } catch(Exception $e) {
        error_log("Error generating payroll tasks: " . $e->getMessage());
    }
}

// Function to generate tasks for new employee accounts
function generateNewAccountTasks($pdo, $today) {
    try {
        // Find employees created in the last 30 days
        $sql = "SELECT e.id, e.emp_id, e.firstName, e.lastName 
                FROM employeelist e
                WHERE e.created_at >= DATE_SUB(:today, INTERVAL 30 DAY)
                ORDER BY e.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':today', $today);
        $stmt->execute();
        $newEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($newEmployees as $employee) {
            $empId = $employee['emp_id'];
            
            // Check if employee has complete payroll information
            $checkPayrollSql = "SELECT 
                                    sss_account, 
                                    phic_account, 
                                    hdmf_account, 
                                    tax_account, 
                                    basic_pay_monthly, 
                                    basic_pay_semi_monthly
                                FROM employee_payroll 
                                WHERE emp_id = :emp_id";
            $checkPayrollStmt = $pdo->prepare($checkPayrollSql);
            $checkPayrollStmt->bindParam(':emp_id', $empId);
            $checkPayrollStmt->execute();
            $payrollData = $checkPayrollStmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if all required fields have data
            $isComplete = false;
            if ($payrollData) {
                $isComplete = !empty($payrollData['sss_account']) &&
                             !empty($payrollData['phic_account']) &&
                             !empty($payrollData['hdmf_account']) &&
                             !empty($payrollData['tax_account']) &&
                             !empty($payrollData['basic_pay_monthly']) &&
                             !empty($payrollData['basic_pay_semi_monthly']);
            }
            
            $taskStatus = $isComplete ? 'Completed' : 'Pending';
            $taskName = "New Account: Update Payroll - " . $employee['firstName'] . " " . $employee['lastName'];
            $dueDate = date('Y-m-d', strtotime($employee['created_at'] ?? $today . ' +5 days'));
            
            // Check if task already exists
            $checkTaskSql = "SELECT id, status FROM accountant_tasks 
                            WHERE employee_id = :emp_id 
                            AND task_type = 'new_account'";
            $checkTaskStmt = $pdo->prepare($checkTaskSql);
            $checkTaskStmt->bindParam(':emp_id', $empId);
            $checkTaskStmt->execute();
            $existingTask = $checkTaskStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingTask) {
                // Insert new account task
                $insertSql = "INSERT INTO accountant_tasks 
                             (task_name, status, due_date, link, task_type, employee_id, created_at) 
                             VALUES (:task_name, :status, :due_date, '/payroll-account', 'new_account', :emp_id, NOW())";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->bindParam(':task_name', $taskName);
                $insertStmt->bindParam(':status', $taskStatus);
                $insertStmt->bindParam(':due_date', $dueDate);
                $insertStmt->bindParam(':emp_id', $empId);
                $insertStmt->execute();
            } else {
                // Update existing task status if it changed to completed
                if ($existingTask['status'] !== 'Completed' && $taskStatus === 'Completed') {
                    $updateSql = "UPDATE accountant_tasks 
                                 SET status = :status, updated_at = NOW() 
                                 WHERE id = :task_id";
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->bindParam(':status', $taskStatus);
                    $updateStmt->bindParam(':task_id', $existingTask['id']);
                    $updateStmt->execute();
                }
            }
        }
    } catch(Exception $e) {
        error_log("Error generating new account tasks: " . $e->getMessage());
    }
}

?>