<?php
// admin.php - Dashboard Statistics API

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your existing database connection
require_once 'db_connection.php';

// Convert mysqli connection to PDO
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}

class DashboardAPI {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // Get total users count
    public function getTotalUsers() {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM useraccounts");
            $result = $stmt->fetch();
            return intval($result['total']);
        } catch (PDOException $e) {
            error_log("Error getting total users: " . $e->getMessage());
            return 0;
        }
    }
    
    // Get new users (created within last 7 days)
    public function getNewUsers() {
        try {
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total 
                FROM useraccounts 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $result = $stmt->fetch();
            return intval($result['total']);
        } catch (PDOException $e) {
            error_log("Error getting new users: " . $e->getMessage());
            return 0;
        }
    }
    
    // Get verified accounts count
    public function getVerifiedAccounts() {
        try {
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total 
                FROM useraccounts 
                WHERE auth_status = 'Verified'
            ");
            $result = $stmt->fetch();
            return intval($result['total']);
        } catch (PDOException $e) {
            error_log("Error getting verified accounts: " . $e->getMessage());
            return 0;
        }
    }
    
    // Get active users (logged in within last 15 minutes)
    public function getActiveUsers() {
        try {
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total 
                FROM useraccounts 
                WHERE last_login >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                AND status = 'active'
            ");
            $result = $stmt->fetch();
            return intval($result['total']);
        } catch (PDOException $e) {
            error_log("Error getting active users: " . $e->getMessage());
            return 0;
        }
    }
    
    // Get account overview by month (last 7 months) - FIXED
    public function getAccountOverview() {
        try {
            // Generate last 7 months
            $months = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m', strtotime("-$i months"));
                $monthName = date('M', strtotime("-$i months"));
                $months[$date] = ['name' => $monthName, 'value' => 0];
            }
            
            // Get actual data using created_at
            $stmt = $this->pdo->query("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month_key,
                    DATE_FORMAT(created_at, '%b') as name,
                    COUNT(*) as value
                FROM useraccounts
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
                ORDER BY DATE_FORMAT(created_at, '%Y-%m')
            ");
            
            $results = $stmt->fetchAll();
            
            // Merge with template
            foreach ($results as $row) {
                if (isset($months[$row['month_key']])) {
                    $months[$row['month_key']]['value'] = intval($row['value']);
                }
            }
            
            return array_values($months);
        } catch (PDOException $e) {
            error_log("Error getting account overview: " . $e->getMessage());
            // Return empty data for 7 months
            $months = [];
            for ($i = 6; $i >= 0; $i--) {
                $monthName = date('M', strtotime("-$i months"));
                $months[] = ['name' => $monthName, 'value' => 0];
            }
            return $months;
        }
    }
    
    // Get user activity by day (last 7 days) - FIXED
    public function getUserActivity() {
        try {
            // Generate last 7 days
            $days = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $dayName = date('D', strtotime("-$i days"));
                $days[$date] = ['name' => $dayName, 'active' => 0];
            }
            
            // Get actual data
            $stmt = $this->pdo->query("
                SELECT 
                    DATE(last_login) as day_key,
                    DATE_FORMAT(last_login, '%a') as name,
                    COUNT(DISTINCT id) as active
                FROM useraccounts
                WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                AND last_login IS NOT NULL
                GROUP BY DATE(last_login), DATE_FORMAT(last_login, '%a')
                ORDER BY DATE(last_login)
            ");
            
            $results = $stmt->fetchAll();
            
            // Merge with template
            foreach ($results as $row) {
                if (isset($days[$row['day_key']])) {
                    $days[$row['day_key']]['active'] = intval($row['active']);
                }
            }
            
            return array_values($days);
        } catch (PDOException $e) {
            error_log("Error getting user activity: " . $e->getMessage());
            // Return empty data for 7 days
            $days = [];
            for ($i = 6; $i >= 0; $i--) {
                $dayName = date('D', strtotime("-$i days"));
                $days[] = ['name' => $dayName, 'active' => 0];
            }
            return $days;
        }
    }
    
    // Get user distribution by role (exclude Admin) - FIXED
    public function getUserDistribution() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    role as name,
                    COUNT(*) as value
                FROM useraccounts
                WHERE role != 'Admin' AND role IS NOT NULL AND role != ''
                GROUP BY role
                ORDER BY value DESC
            ");
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error getting user distribution: " . $e->getMessage());
            return [];
        }
    }
    
    // Get recent activities from audit trail - FIXED
    public function getRecentActivities($limit = 10) {
        try {
            // First check if audit_logs table exists
            $tableCheck = $this->pdo->query("SHOW TABLES LIKE 'audit_logs'");
            if ($tableCheck->rowCount() == 0) {
                error_log("audit_logs table does not exist");
                return [];
            }
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    COALESCE(user_email, 'System') as user,
                    CONCAT(component, ' - ', action) as action,
                    timestamp,
                    CASE 
                        WHEN TIMESTAMPDIFF(MINUTE, timestamp, NOW()) < 1 THEN 'Just now'
                        WHEN TIMESTAMPDIFF(MINUTE, timestamp, NOW()) < 60 THEN CONCAT(TIMESTAMPDIFF(MINUTE, timestamp, NOW()), ' mins ago')
                        WHEN TIMESTAMPDIFF(HOUR, timestamp, NOW()) < 24 THEN CONCAT(TIMESTAMPDIFF(HOUR, timestamp, NOW()), ' hours ago')
                        WHEN TIMESTAMPDIFF(DAY, timestamp, NOW()) = 1 THEN 'Yesterday'
                        ELSE CONCAT(TIMESTAMPDIFF(DAY, timestamp, NOW()), ' days ago')
                    END as time
                FROM audit_logs
                ORDER BY timestamp DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error getting recent activities: " . $e->getMessage());
            return [];
        }
    }
    
    // Get all dashboard data
    public function getDashboardData() {
        return [
            'success' => true,
            'data' => [
                'summaryCards' => [
                    'totalUsers' => $this->getTotalUsers(),
                    'newUsers' => $this->getNewUsers(),
                    'verifiedAccounts' => $this->getVerifiedAccounts(),
                    'activeUsers' => $this->getActiveUsers()
                ],
                'accountOverview' => $this->getAccountOverview(),
                'userActivity' => $this->getUserActivity(),
                'userDistribution' => $this->getUserDistribution(),
                'recentActivities' => $this->getRecentActivities(10)
            ]
        ];
    }
}

// Handle API requests
try {
    $api = new DashboardAPI($pdo);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $endpoint = $_GET['endpoint'] ?? 'dashboard';
    
    if ($method === 'GET') {
        switch ($endpoint) {
            case 'dashboard':
                $result = $api->getDashboardData();
                echo json_encode($result);
                break;
                
            case 'summary':
                $result = [
                    'success' => true,
                    'data' => [
                        'totalUsers' => $api->getTotalUsers(),
                        'newUsers' => $api->getNewUsers(),
                        'verifiedAccounts' => $api->getVerifiedAccounts(),
                        'activeUsers' => $api->getActiveUsers()
                    ]
                ];
                echo json_encode($result);
                break;
                
            case 'activities':
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
                $result = [
                    'success' => true,
                    'data' => $api->getRecentActivities($limit)
                ];
                echo json_encode($result);
                break;
                
            default:
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Endpoint not found'
                ]);
                break;
        }
    } else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
    error_log("Dashboard API Error: " . $e->getMessage());
}
?>