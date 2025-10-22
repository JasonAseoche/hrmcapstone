<?php
session_start();

// Set CORS headers first - make them match your login.php
header("Access-Control-Allow-Origin: *"); // Allow all origins like your login.php
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS"); // Allow all methods
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With"); // Allow headers
header('Content-Type: application/json; charset=utf-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db_connection.php';

$method = $_SERVER['REQUEST_METHOD'];
$request = isset($_GET['action']) ? $_GET['action'] : '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($request);
            break;
        case 'POST':
            handlePostRequest($request);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGetRequest($action) {
    switch ($action) {
        case 'get_session':
            if (isset($_SESSION['user_id'])) {
                echo json_encode([
                    'success' => true,
                    'user' => [
                        'id' => $_SESSION['user_id'],
                        'firstName' => $_SESSION['firstName'],
                        'lastName' => $_SESSION['lastName'],
                        'email' => $_SESSION['email'],
                        'role' => $_SESSION['role']
                    ]
                ]);
            } else {
                http_response_code(401);
                echo json_encode(['error' => 'Not authenticated']);
            }
            break;
            
        case 'logout':
            session_destroy();
            echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
}

function handlePostRequest($action) {
    global $conn;
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'login':
            $email = $input['email'] ?? null;
            $password = $input['password'] ?? null;
            
            if (!$email || !$password) {
                throw new Exception('Email and password are required');
            }
            
            $stmt = $conn->prepare("SELECT * FROM useraccounts WHERE email = ? AND password = ?");
            $stmt->bind_param("ss", $email, $password);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($user = $result->fetch_assoc()) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['firstName'] = $user['firstName'];
                $_SESSION['lastName'] = $user['lastName'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Login successful',
                    'user' => [
                        'id' => $user['id'],
                        'firstName' => $user['firstName'],
                        'lastName' => $user['lastName'],
                        'email' => $user['email'],
                        'role' => $user['role']
                    ]
                ]);
            } else {
                http_response_code(401);
                throw new Exception('Invalid email or password');
            }
            break;
            
        default:
            throw new Exception('Invalid action');
    }
}
?>