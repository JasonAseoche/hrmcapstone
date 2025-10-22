<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
require_once 'db_connection.php';

// Convert mysqli connection to PDO for compatibility with existing code
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Close the mysqli connection since we're using PDO
    $conn->close();
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

class PerformanceAPI {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        
        switch($action) {
            case 'get_performance_data':
                $this->getPerformanceData();
                break;
            case 'generate_pdf_summary':
                $this->generatePDFSummary();
                break;
            default:
                $this->sendResponse(['success' => false, 'message' => 'Invalid action']);
        }
    }
    
    private function getPerformanceData() {
        try {
            $fromDate = $_GET['from_date'] ?? date('Y-m-01');
            $toDate = $_GET['to_date'] ?? date('Y-m-d');
            
            // Validate dates
            if (!$this->isValidDate($fromDate) || !$this->isValidDate($toDate)) {
                throw new Exception('Invalid date format');
            }
            
            // First, get the count of actual days that have attendance data
            $actualDataDaysQuery = "
                SELECT COUNT(DISTINCT date) as actual_data_days
                FROM attendancelist 
                WHERE date BETWEEN :from_date AND :to_date
            ";
            
            $stmt = $this->pdo->prepare($actualDataDaysQuery);
            $stmt->bindParam(':from_date', $fromDate);
            $stmt->bindParam(':to_date', $toDate);
            $stmt->execute();
            $actualDataDays = $stmt->fetch(PDO::FETCH_ASSOC)['actual_data_days'] ?? 0;
            
            // If no data days found, use 1 to avoid division by zero
            $divisor = max($actualDataDays, 1);
            
            // Calculate total days in the date range (inclusive) for reference
            $fromTimestamp = strtotime($fromDate);
            $toTimestamp = strtotime($toDate);
            $totalDays = floor(($toTimestamp - $fromTimestamp) / (60 * 60 * 24)) + 1;
            
            $sql = "
                SELECT 
                    e.emp_id,
                    CONCAT(e.firstName, ' ', e.lastName) as employee_name,
                    e.position,
                    e.email,
                    e.workarrangement,
                    -- All existing fields (keep these)
                    COUNT(CASE WHEN a.present = 1 THEN 1 END) as total_present_days,
                    COUNT(CASE WHEN a.late = 1 THEN 1 END) as total_late_days,
                    COUNT(CASE WHEN a.absent = 1 THEN 1 END) as total_absent_days,
                    COUNT(CASE WHEN a.on_leave = 1 THEN 1 END) as total_leave_days,
                    ROUND((COUNT(CASE WHEN a.present = 1 THEN 1 END) / {$divisor}) * 100, 2) as avg_present_percentage,
                    ROUND((COUNT(CASE WHEN a.late = 1 THEN 1 END) / {$divisor}) * 100, 2) as avg_late_percentage,
                    ROUND((COUNT(CASE WHEN a.absent = 1 THEN 1 END) / {$divisor}) * 100, 2) as avg_absent_percentage,
                    ROUND((COUNT(CASE WHEN a.on_leave = 1 THEN 1 END) / {$divisor}) * 100, 2) as avg_leave_percentage,
                    COUNT(a.id) as total_records,
                    ROUND(AVG(a.total_workhours), 2) as avg_work_hours_per_day,
                    SUM(a.overtime) as total_overtime_minutes,
                    SUM(a.late_undertime) as total_late_minutes,
                    -- ADD THIS NEW LINE:
                    SUM(COALESCE(a.total_workhours, 0)) as total_work_hours_minutes,
                    -- Existing fields (keep these)
                    {$totalDays} as date_range_days,
                    {$actualDataDays} as actual_data_days
                FROM employeelist e
                LEFT JOIN attendancelist a ON e.emp_id = a.emp_id 
                    AND a.date BETWEEN :from_date AND :to_date
                WHERE e.status = 'active'
                GROUP BY e.emp_id, e.firstName, e.lastName, e.position, e.email, e.workarrangement
                ORDER BY employee_name ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':from_date', $fromDate);
            $stmt->bindParam(':to_date', $toDate);
            
            $stmt->execute();
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate overall statistics based on actual data days
            $totalEmployees = count($records);
            $totalPresentInstances = array_sum(array_column($records, 'total_present_days'));
            $totalLateInstances = array_sum(array_column($records, 'total_late_days'));
            $totalAbsentInstances = array_sum(array_column($records, 'total_absent_days'));
            $totalLeaveInstances = array_sum(array_column($records, 'total_leave_days'));
            
            // Calculate average per actual data day (not total date range)
            $avgPresentDays = $actualDataDays > 0 ? round($totalPresentInstances / $actualDataDays) : 0;
            $avgLateDays = $actualDataDays > 0 ? round($totalLateInstances / $actualDataDays) : 0;
            $avgAbsentDays = $actualDataDays > 0 ? round($totalAbsentInstances / $actualDataDays) : 0;
            $avgLeaveDays = $actualDataDays > 0 ? round($totalLeaveInstances / $actualDataDays) : 0;
            
            $this->sendResponse([
                'success' => true,
                'records' => $records,
                'summary_stats' => [
                    'total_employees' => $totalEmployees,
                    'avg_present_days' => $avgPresentDays,
                    'avg_late_days' => $avgLateDays,
                    'avg_absent_days' => $avgAbsentDays,
                    'avg_leave_days' => $avgLeaveDays,
                    'actual_data_days' => $actualDataDays,
                    'total_days_in_range' => $totalDays
                ],
                'date_range' => [
                    'from' => $fromDate,
                    'to' => $toDate,
                    'total_days' => $totalDays,
                    'actual_data_days' => $actualDataDays
                ]
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    private function generatePDFSummary() {
        try {
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            $fromDate = $input['from_date'] ?? date('Y-m-01');
            $toDate = $input['to_date'] ?? date('Y-m-d');
            
            // Validate dates
            if (!$this->isValidDate($fromDate) || !$this->isValidDate($toDate)) {
                throw new Exception('Invalid date format');
            }
            
            // Get performance data
            $performanceData = $this->getPerformanceDataForPDF($fromDate, $toDate);
            $summaryStats = $this->getSummaryStatistics($fromDate, $toDate);
            
            // Generate HTML content for PDF
            $html = $this->generatePDFHTML($performanceData, $summaryStats, $fromDate, $toDate);
            
            // Set PDF headers
            header('Content-Type: text/html');
            header('Content-Disposition: attachment; filename="Performance_Summary_' . $fromDate . '_to_' . $toDate . '.html"');
            
            // Output HTML that can be saved as PDF by browser
            echo $html;
            exit;
            
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }
    
    private function getPerformanceDataForPDF($fromDate, $toDate) {
        // Get actual data days first
        $actualDataDaysQuery = "
            SELECT COUNT(DISTINCT date) as actual_data_days
            FROM attendancelist 
            WHERE date BETWEEN ? AND ?
        ";
        
        $stmt = $this->pdo->prepare($actualDataDaysQuery);
        $stmt->execute([$fromDate, $toDate]);
        $actualDataDays = $stmt->fetch(PDO::FETCH_ASSOC)['actual_data_days'] ?? 1;
        
        // Calculate total days in the date range (inclusive)
        $fromTimestamp = strtotime($fromDate);
        $toTimestamp = strtotime($toDate);
        $totalDays = floor(($toTimestamp - $fromTimestamp) / (60 * 60 * 24)) + 1;
        
        $sql = "
            SELECT 
                e.emp_id,
                CONCAT(e.firstName, ' ', e.lastName) as employee_name,
                e.position,
                e.email,
                e.workarrangement,
                -- Count actual days for each status
                COUNT(CASE WHEN a.present = 1 THEN 1 END) as present_days,
                COUNT(CASE WHEN a.late = 1 THEN 1 END) as late_days,
                COUNT(CASE WHEN a.absent = 1 THEN 1 END) as absent_days,
                COUNT(CASE WHEN a.on_leave = 1 THEN 1 END) as leave_days,
                -- Calculate percentages based on actual data days
                ROUND((COUNT(CASE WHEN a.present = 1 THEN 1 END) / {$actualDataDays}) * 100, 1) as present_percentage,
                ROUND((COUNT(CASE WHEN a.late = 1 THEN 1 END) / {$actualDataDays}) * 100, 1) as late_percentage,
                ROUND((COUNT(CASE WHEN a.absent = 1 THEN 1 END) / {$actualDataDays}) * 100, 1) as absent_percentage,
                ROUND((COUNT(CASE WHEN a.on_leave = 1 THEN 1 END) / {$actualDataDays}) * 100, 1) as leave_percentage,
                -- Additional metrics
                ROUND(SUM(a.total_workhours) / 60, 2) as total_work_hours,
                ROUND(SUM(a.overtime) / 60, 2) as total_overtime_hours,
                SUM(a.late_undertime) as total_late_minutes,
                {$totalDays} as total_days_in_range,
                {$actualDataDays} as actual_data_days
            FROM employeelist e
            LEFT JOIN attendancelist a ON e.emp_id = a.emp_id 
                AND a.date BETWEEN ? AND ?
            WHERE e.status = 'active'
            GROUP BY e.emp_id, e.firstName, e.lastName, e.position, e.email, e.workarrangement
            ORDER BY employee_name ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$fromDate, $toDate]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getSummaryStatistics($fromDate, $toDate) {
        // Get actual data days first
        $actualDataDaysQuery = "
            SELECT COUNT(DISTINCT date) as actual_data_days
            FROM attendancelist 
            WHERE date BETWEEN ? AND ?
        ";
        
        $stmt = $this->pdo->prepare($actualDataDaysQuery);
        $stmt->execute([$fromDate, $toDate]);
        $actualDataDays = $stmt->fetch(PDO::FETCH_ASSOC)['actual_data_days'] ?? 1;
        
        $fromTimestamp = strtotime($fromDate);
        $toTimestamp = strtotime($toDate);
        $totalDays = floor(($toTimestamp - $fromTimestamp) / (60 * 60 * 24)) + 1;
        
        $sql = "
            SELECT 
                COUNT(DISTINCT e.emp_id) as total_employees,
                COUNT(CASE WHEN a.present = 1 THEN 1 END) as total_present_records,
                COUNT(CASE WHEN a.late = 1 THEN 1 END) as total_late_records,
                COUNT(CASE WHEN a.absent = 1 THEN 1 END) as total_absent_records,
                COUNT(CASE WHEN a.on_leave = 1 THEN 1 END) as total_leave_records,
                ROUND(AVG(a.total_workhours) / 60, 2) as avg_daily_hours,
                ROUND(SUM(a.overtime) / 60, 2) as total_overtime_hours,
                ROUND(AVG(a.late_undertime), 2) as avg_late_minutes,
                {$totalDays} as total_days_in_range,
                {$actualDataDays} as actual_data_days
            FROM employeelist e
            LEFT JOIN attendancelist a ON e.emp_id = a.emp_id 
                AND a.date BETWEEN ? AND ?
            WHERE e.status = 'active'
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$fromDate, $toDate]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function generatePDFHTML($performanceData, $summaryStats, $fromDate, $toDate) {
        $totalDays = (strtotime($toDate) - strtotime($fromDate)) / (60 * 60 * 24) + 1;
        $actualDataDays = $summaryStats['actual_data_days'] ?? 1;
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Performance Summary Report</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            color: #333; 
            line-height: 1.4;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #003D7C; 
            padding-bottom: 20px; 
        }
        .header h1 { 
            color: #003D7C; 
            margin: 0; 
            font-size: 24px; 
        }
        .header p { 
            margin: 5px 0; 
            color: #666; 
        }
        .summary-stats { 
            display: flex; 
            justify-content: space-around; 
            margin: 20px 0; 
            flex-wrap: wrap;
        }
        .stat-box { 
            text-align: center; 
            padding: 15px; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            min-width: 120px; 
            margin: 5px;
        }
        .stat-number { 
            font-size: 18px; 
            font-weight: bold; 
            color: #003D7C; 
        }
        .stat-label { 
            font-size: 12px; 
            color: #666; 
            margin-top: 5px; 
        }
        .table-container { 
            margin: 20px 0; 
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px; 
        }
        th, td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
            font-size: 11px; 
        }
        th { 
            background-color: #003D7C; 
            color: white; 
            font-weight: bold; 
        }
        tr:nth-child(even) { 
            background-color: #f9f9f9; 
        }
        .performance-badge { 
            padding: 3px 8px; 
            border-radius: 12px; 
            font-size: 10px; 
            font-weight: bold; 
        }
        .excellent { background-color: #d4edda; color: #155724; }
        .good { background-color: #d1ecf1; color: #0c5460; }
        .average { background-color: #fff3cd; color: #856404; }
        .poor { background-color: #f8d7da; color: #721c24; }
        .footer { 
            margin-top: 30px; 
            text-align: center; 
            font-size: 10px; 
            color: #666; 
        }
        .data-info {
            background-color: #e3f2fd;
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            font-size: 12px;
            color: #1565c0;
        }
        @media print {
            body { margin: 0; }
            .header { page-break-inside: avoid; }
            table { page-break-inside: avoid; }
        }
    </style>
    <script>
        window.onload = function() {
            // Auto-trigger print dialog for PDF conversion
            setTimeout(function() {
                window.print();
            }, 1000);
        }
    </script>
</head>
<body>
    <div class="header">
        <h1>Employee Performance Summary Report</h1>
        <p>Period: ' . date('F j, Y', strtotime($fromDate)) . ' - ' . date('F j, Y', strtotime($toDate)) . '</p>
        <p>Total Days in Range: ' . $totalDays . ' | Days with Data: ' . $actualDataDays . ' | Generated on: ' . date('F j, Y g:i A') . '</p>
    </div>
    
    <div class="data-info">
        <strong>Note:</strong> Averages are calculated based on ' . $actualDataDays . ' day(s) with actual attendance data, not the full ' . $totalDays . ' day range.
    </div>
    
    <div class="summary-stats">
        <div class="stat-box">
            <div class="stat-number">' . $summaryStats['total_employees'] . '</div>
            <div class="stat-label">Total Employees</div>
        </div>
        <div class="stat-box">
            <div class="stat-number">' . $summaryStats['total_present_records'] . '</div>
            <div class="stat-label">Total Present Records</div>
        </div>
        <div class="stat-box">
            <div class="stat-number">' . $summaryStats['total_late_records'] . '</div>
            <div class="stat-label">Total Late Records</div>
        </div>
        <div class="stat-box">
            <div class="stat-number">' . $summaryStats['total_absent_records'] . '</div>
            <div class="stat-label">Total Absent Records</div>
        </div>
        <div class="stat-box">
            <div class="stat-number">' . number_format($summaryStats['avg_daily_hours'], 1) . 'h</div>
            <div class="stat-label">Avg Daily Hours</div>
        </div>
        <div class="stat-box">
            <div class="stat-number">' . $actualDataDays . '</div>
            <div class="stat-label">Days with Data</div>
        </div>
    </div>
    
    <div class="table-container">
        <h2>Individual Employee Performance</h2>
        <table>
            <thead>
                <tr>
                    <th>Employee Name</th>
                    <th>Position</th>
                    <th>Work Arrangement</th>
                    <th>Present Days</th>
                    <th>Present %</th>
                    <th>Late Days</th>
                    <th>Late %</th>
                    <th>Absent Days</th>
                    <th>Absent %</th>
                    <th>Leave Days</th>
                    <th>Leave %</th>
                    <th>Total Hours</th>
                    <th>Performance</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($performanceData as $employee) {
            $presentPercentage = $employee['present_percentage'] ?? 0;
            $performanceClass = $this->getPerformanceClass($presentPercentage);
            $performanceLabel = $this->getPerformanceLabel($presentPercentage);
            
            $html .= '<tr>
                <td>' . htmlspecialchars($employee['employee_name']) . '</td>
                <td>' . htmlspecialchars($employee['position']) . '</td>
                <td>' . htmlspecialchars($employee['workarrangement'] ?? 'N/A') . '</td>
                <td>' . $employee['present_days'] . '</td>
                <td>' . number_format($presentPercentage, 1) . '%</td>
                <td>' . $employee['late_days'] . '</td>
                <td>' . number_format($employee['late_percentage'], 1) . '%</td>
                <td>' . $employee['absent_days'] . '</td>
                <td>' . number_format($employee['absent_percentage'], 1) . '%</td>
                <td>' . $employee['leave_days'] . '</td>
                <td>' . number_format($employee['leave_percentage'], 1) . '%</td>
                <td>' . number_format($employee['total_work_hours'], 1) . '</td>
                <td><span class="performance-badge ' . $performanceClass . '">' . $performanceLabel . '</span></td>
            </tr>';
        }
        
        $html .= '</tbody>
        </table>
    </div>
    
    <div class="footer">
        <p>This report was automatically generated by the HR Management System</p>
        <p>Averages calculated based on actual attendance data days only</p>
        <p>For questions or concerns, please contact the HR department</p>
    </div>
</body>
</html>';
        
        return $html;
    }
    
    private function getPerformanceClass($percentage) {
        if ($percentage >= 95) return 'excellent';
        if ($percentage >= 85) return 'good';
        if ($percentage >= 75) return 'average';
        return 'poor';
    }
    
    private function getPerformanceLabel($percentage) {
        if ($percentage >= 95) return 'Excellent';
        if ($percentage >= 85) return 'Good';
        if ($percentage >= 75) return 'Average';
        return 'Needs Improvement';
    }
    
    private function isValidDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    private function sendResponse($data) {
        echo json_encode($data);
        exit;
    }
}

// Initialize database connection and handle request
try {
    if (!isset($pdo)) {
        throw new Exception('Database connection not available');
    }
    
    $api = new PerformanceAPI($pdo);
    $api->handleRequest();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>