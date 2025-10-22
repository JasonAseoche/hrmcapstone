<?php
// audit_trail.php - Uses your existing db_connection.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include your existing database connection
require_once 'db_connection.php';

// Convert mysqli connection to PDO using the same credentials from db_connection.php
try {
    // Get database credentials from your db_connection.php variables
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

class AuditTrailAPI {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->createAuditTable();
    }
    
    // Create audit_logs table if it doesn't exist
    private function createAuditTable() {
        $sql = "CREATE TABLE IF NOT EXISTS audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            component VARCHAR(100) NOT NULL,
            action VARCHAR(50) NOT NULL,
            user_id VARCHAR(100),
            user_email VARCHAR(255),
            ip_address VARCHAR(45),
            session_id VARCHAR(255),
            details TEXT,
            success BOOLEAN DEFAULT TRUE,
            user_agent TEXT,
            url VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_timestamp (timestamp),
            INDEX idx_component (component),
            INDEX idx_action (action),
            INDEX idx_user_email (user_email)
        )";
        
        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("Failed to create audit_logs table: " . $e->getMessage());
        }
    }
    
    // Log an audit entry
    public function logAudit($data) {
        $sql = "INSERT INTO audit_logs (
            component, action, user_id, user_email, ip_address, 
            session_id, details, success, user_agent, url
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                $data['component'] ?? 'Unknown',
                $data['action'] ?? 'unknown',
                $data['userId'] ?? null,
                $data['userEmail'] ?? 'anonymous',
                $this->getClientIP(),
                $data['sessionId'] ?? session_id(),
                $data['details'] ?? '',
                $data['success'] ?? true,
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $data['url'] ?? $_SERVER['REQUEST_URI'] ?? ''
            ]);
            
            return [
                'success' => true,
                'message' => 'Audit log created successfully',
                'id' => $this->pdo->lastInsertId()
            ];
        } catch (PDOException $e) {
            error_log("Audit log failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to create audit log: ' . $e->getMessage()
            ];
        }
    }
    
    // Get audit logs with filtering and pagination
    public function getAuditLogs($filters = []) {
        $where = [];
        $params = [];
        
        // Build WHERE clause based on filters
        if (!empty($filters['component'])) {
            $where[] = "component LIKE ?";
            $params[] = '%' . $filters['component'] . '%';
        }
        
        if (!empty($filters['action'])) {
            $where[] = "action LIKE ?";
            $params[] = '%' . $filters['action'] . '%';
        }
        
        if (!empty($filters['user'])) {
            $where[] = "user_email LIKE ?";
            $params[] = '%' . $filters['user'] . '%';
        }
        
        if (!empty($filters['dateFrom'])) {
            $where[] = "timestamp >= ?";
            $params[] = $filters['dateFrom'] . ' 00:00:00';
        }
        
        if (!empty($filters['dateTo'])) {
            $where[] = "timestamp <= ?";
            $params[] = $filters['dateTo'] . ' 23:59:59';
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        try {
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM audit_logs $whereClause";
            $countStmt = $this->pdo->prepare($countSql);
            $countStmt->execute($params);
            $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get paginated results
            $page = max(1, intval($filters['page'] ?? 1));
            $itemsPerPage = max(1, min(100, intval($filters['itemsPerPage'] ?? 10)));
            $offset = ($page - 1) * $itemsPerPage;
            
            $sql = "SELECT * FROM audit_logs 
                    $whereClause 
                    ORDER BY timestamp DESC 
                    LIMIT $itemsPerPage OFFSET $offset";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'data' => $logs,
                'pagination' => [
                    'page' => $page,
                    'itemsPerPage' => $itemsPerPage,
                    'totalRecords' => intval($totalRecords),
                    'totalPages' => ceil($totalRecords / $itemsPerPage)
                ]
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to get audit logs: ' . $e->getMessage()
            ];
        }
    }
    
    // Get audit statistics
    public function getStatistics() {
        try {
            // Total actions
            $totalStmt = $this->pdo->query("SELECT COUNT(*) as total FROM audit_logs");
            $totalActions = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Unique users
            $userStmt = $this->pdo->query("SELECT COUNT(DISTINCT user_email) as unique_users FROM audit_logs WHERE user_email != 'anonymous'");
            $uniqueUsers = $userStmt->fetch(PDO::FETCH_ASSOC)['unique_users'];
            
            // Most used component
            $componentStmt = $this->pdo->query("
                SELECT component, COUNT(*) as count 
                FROM audit_logs 
                GROUP BY component 
                ORDER BY count DESC 
                LIMIT 1
            ");
            $topComponent = $componentStmt->fetch(PDO::FETCH_ASSOC);
            
            // Today's actions
            $todayStmt = $this->pdo->query("
                SELECT COUNT(*) as today_count 
                FROM audit_logs 
                WHERE DATE(timestamp) = CURDATE()
            ");
            $todayActions = $todayStmt->fetch(PDO::FETCH_ASSOC)['today_count'];
            
            return [
                'success' => true,
                'stats' => [
                    'totalActions' => intval($totalActions),
                    'uniqueUsers' => intval($uniqueUsers),
                    'topComponent' => $topComponent['component'] ?? 'N/A',
                    'todayActions' => intval($todayActions)
                ]
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Failed to get statistics: ' . $e->getMessage()
            ];
        }
    }
    
    // Get client IP address
    private function getClientIP() {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

// Initialize API
try {
    $api = new AuditTrailAPI($pdo);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $endpoint = $_GET['endpoint'] ?? '';
    
    switch ($method) {
        case 'POST':
            if ($endpoint === 'log') {
                // Log new audit entry
                $input = json_decode(file_get_contents('php://input'), true);
                $result = $api->logAudit($input);
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
            }
            break;
            
        case 'GET':
            if ($endpoint === 'logs') {
                // Get audit logs with filters
                $filters = [
                    'component' => $_GET['component'] ?? '',
                    'action' => $_GET['action'] ?? '',
                    'user' => $_GET['user'] ?? '',
                    'dateFrom' => $_GET['dateFrom'] ?? '',
                    'dateTo' => $_GET['dateTo'] ?? '',
                    'page' => $_GET['page'] ?? 1,
                    'itemsPerPage' => $_GET['itemsPerPage'] ?? 10
                ];
                $result = $api->getAuditLogs($filters);
                echo json_encode($result);
            } elseif ($endpoint === 'stats') {
                // Get statistics
                $result = $api->getStatistics();
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error: ' . $e->getMessage()
    ]);
    error_log("Audit Trail API Error: " . $e->getMessage());
}
?>