<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Database connection
include_once 'db_connection.php';

class NotificationAPI {
    private $conn;
    
    public function __construct($db_connection) {
        $this->conn = $db_connection;
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        switch($method) {
            case 'GET':
                $this->getNotifications();
                break;
            case 'POST':
                $this->createNotification();
                break;
            case 'PUT':
                $this->markAsRead();
                break;
            case 'DELETE':
                $this->deleteNotification();
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
        }
    }
    
    private function getNotifications() {
        try {
            $user_id = $_GET['user_id'] ?? null;
            $user_role = $_GET['user_role'] ?? null;
            $unread_only = $_GET['unread_only'] ?? false;
            $limit = $_GET['limit'] ?? 50;
            
            if (!$user_id || !$user_role) {
                throw new Exception('User ID and role are required');
            }
            
            $query = "SELECT * FROM notifications WHERE user_id = ? AND user_role = ?";
            
            if ($unread_only === 'true') {
                $query .= " AND is_read = FALSE";
            }
            
            $query .= " ORDER BY created_at DESC LIMIT ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("ssi", $user_id, $user_role, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            $notifications = $result->fetch_all(MYSQLI_ASSOC);
            
            // Get unread count
            $unreadQuery = "SELECT COUNT(*) as unread_count FROM notifications WHERE user_id = ? AND user_role = ? AND is_read = FALSE";
            $unreadStmt = $this->conn->prepare($unreadQuery);
            $unreadStmt->bind_param("ss", $user_id, $user_role);
            $unreadStmt->execute();
            $unreadResult = $unreadStmt->get_result();
            $unreadCount = $unreadResult->fetch_assoc()['unread_count'];
            
            echo json_encode([
                'success' => true,
                'notifications' => $notifications,
                'unread_count' => (int)$unreadCount
            ]);
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function createNotification() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                throw new Exception("Invalid JSON input");
            }
            
            $required_fields = ['user_role', 'type', 'title', 'message'];
            foreach ($required_fields as $field) {
                if (!isset($input[$field])) {
                    throw new Exception("Missing required field: $field");
                }
            }
            
            // If user_id is 0 and role is hr, send to all HR users
            if ((!isset($input['user_id']) || $input['user_id'] == 0) && $input['user_role'] == 'HR') {
                // Check if users table exists and has HR users
                $hrQuery = "SELECT id FROM useraccounts WHERE role = 'HR' LIMIT 10";
                $hrResult = $this->conn->query($hrQuery);
                
                if (!$hrResult) {
                    // Try alternative role names if 'hr' doesn't work
                    $hrQuery = "SELECT id FROM useraccounts WHERE role = 'HR' OR role = 'human_resources' LIMIT 10";
                    $hrResult = $this->conn->query($hrQuery);
                }
                
                if (!$hrResult) {
                    // If still no result, create a single notification for user_id 1 (assuming admin)
                    $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                              VALUES (1, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $this->conn->prepare($query);
                    if ($stmt) {
                        $stmt->bind_param("ssssss", 
                            $input['user_role'], 
                            $input['type'], 
                            $input['title'], 
                            $input['message'],
                            $input['related_id'],
                            $input['related_type']
                        );
                        $stmt->execute();
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Notification sent to admin (no HR users found)',
                        'count' => 1
                    ]);
                    return;
                }
                
                $hrUsers = $hrResult->fetch_all(MYSQLI_ASSOC);
                
                if (empty($hrUsers)) {
                    // No HR users found, send to user_id 1 instead
                    $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                              VALUES (1, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt = $this->conn->prepare($query);
                    if ($stmt) {
                        $stmt->bind_param("ssssss", 
                            $input['user_role'], 
                            $input['type'], 
                            $input['title'], 
                            $input['message'],
                            $input['related_id'],
                            $input['related_type']
                        );
                        $stmt->execute();
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Notification sent to admin (no HR users found)',
                        'count' => 1
                    ]);
                    return;
                }
                
                $insertQuery = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                               VALUES (?, ?, ?, ?, ?, ?, ?)";
                $insertStmt = $this->conn->prepare($insertQuery);
                
                if (!$insertStmt) {
                    throw new Exception("Failed to prepare insert statement: " . $this->conn->error);
                }
                
                foreach ($hrUsers as $hr) {
                    $insertStmt->bind_param("issssis", 
                        $hr['id'], 
                        $input['user_role'], 
                        $input['type'], 
                        $input['title'], 
                        $input['message'],
                        $input['related_id'],
                        $input['related_type']
                    );
                    $insertStmt->execute();
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Notifications sent to all HR users',
                    'count' => count($hrUsers)
                ]);
            } else {
                // Send to specific user
                $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $this->conn->prepare($query);
                if (!$stmt) {
                    throw new Exception("Failed to prepare statement: " . $this->conn->error);
                }
                
                $stmt->bind_param("issssis", 
                    $input['user_id'], 
                    $input['user_role'], 
                    $input['type'], 
                    $input['title'], 
                    $input['message'],
                    $input['related_id'],
                    $input['related_type']
                );
                $result = $stmt->execute();
                
                if ($result) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Notification created successfully',
                        'notification_id' => $this->conn->insert_id
                    ]);
                } else {
                    throw new Exception('Failed to create notification: ' . $stmt->error);
                }
            }
            
        } catch (Exception $e) {
            error_log("Notification API Error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function markAsRead() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (isset($input['notification_id'])) {
                // Mark single notification as read
                $query = "UPDATE notifications SET is_read = TRUE WHERE id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("i", $input['notification_id']);
                $result = $stmt->execute();
            } elseif (isset($input['user_id']) && isset($input['user_role'])) {
                // Mark all notifications as read for user
                $query = "UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND user_role = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param("is", $input['user_id'], $input['user_role']);
                $result = $stmt->execute();
            } else {
                throw new Exception('Either notification_id or user_id with user_role required');
            }
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Notification(s) marked as read']);
            } else {
                throw new Exception('Failed to mark notification(s) as read');
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function deleteNotification() {
        try {
            $notification_id = $_GET['id'] ?? null;
            
            if (!$notification_id) {
                throw new Exception('Notification ID is required');
            }
            
            $query = "DELETE FROM notifications WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $notification_id);
            $result = $stmt->execute();
            
            if ($result) {
                echo json_encode(['success' => true, 'message' => 'Notification deleted successfully']);
            } else {
                throw new Exception('Failed to delete notification');
            }
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    // Helper method to create notifications for specific events
    public static function createNotificationForEvent($conn, $event_type, $data) {
        try {
            switch($event_type) {
                case 'resume_uploaded':
                    self::notifyHRResumeUploaded($conn, $data);
                    break;
                case 'exam_submitted':
                    self::notifyHRExamSubmitted($conn, $data);
                    break;
                case 'requirements_uploaded':
                    self::notifyHRRequirementsUploaded($conn, $data);
                    break;
                case 'interview_scheduled':
                    self::notifyApplicantInterviewScheduled($conn, $data);
                    break;
                case 'application_approved':
                    self::notifyApplicantApproved($conn, $data);
                    break;
                case 'application_declined':
                    self::notifyApplicantDeclined($conn, $data);
                    break;
                case 'exam_assigned':
                    self::notifyApplicantExamAssigned($conn, $data);
                    break;
                case 'exam_graded':
                    self::notifyApplicantExamGraded($conn, $data);
                    break;
            }
        } catch (Exception $e) {
            error_log("Notification creation failed: " . $e->getMessage());
        }
    }
    
    private static function notifyHRResumeUploaded($conn, $data) {
        // Get all HR users from useraccounts table (try both uppercase and lowercase)
        $hrQuery = "SELECT id FROM useraccounts WHERE role = 'HR'";
        $hrResult = $conn->query($hrQuery);
        $hrUsers = $hrResult->fetch_all(MYSQLI_ASSOC);
        
        foreach ($hrUsers as $hr) {
            $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                      VALUES (?, 'HR', 'resume_uploaded', ?, ?, ?, 'applicant')";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("issi", 
                $hr['id'], 
                'New Resume Uploaded', 
                $data['applicant_name'] . ' has uploaded their resume for the position: ' . $data['position'],
                $data['applicant_id']
            );
            $stmt->execute();
        }
    }
    
    private static function notifyHRExamSubmitted($conn, $data) {
        // Get all HR users from useraccounts table (try both uppercase and lowercase)
        $hrQuery = "SELECT id FROM useraccounts WHERE role = 'HR'";
        $hrResult = $conn->query($hrQuery);
        $hrUsers = $hrResult->fetch_all(MYSQLI_ASSOC);
        
        foreach ($hrUsers as $hr) {
            $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                      VALUES (?, 'HR', 'exam_submitted', ?, ?, ?, 'exam')";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("issi", 
                $hr['id'], 
                'Exam Submitted', 
                $data['applicant_name'] . ' has submitted the exam: ' . $data['exam_title'],
                $data['exam_id']
            );
            $stmt->execute();
        }
    }
    
    private static function notifyHRRequirementsUploaded($conn, $data) {
        // Get all HR users from useraccounts table (try both uppercase and lowercase)
        $hrQuery = "SELECT id FROM useraccounts WHERE role = 'HR'";
        $hrResult = $conn->query($hrQuery);
        $hrUsers = $hrResult->fetch_all(MYSQLI_ASSOC);
        
        foreach ($hrUsers as $hr) {
            $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                      VALUES (?, 'HR', 'requirements_uploaded', ?, ?, ?, 'applicant')";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("issi", 
                $hr['id'], 
                'Requirements Uploaded', 
                $data['applicant_name'] . ' has uploaded required documents: ' . $data['document_type'],
                $data['applicant_id']
            );
            $stmt->execute();
        }
    }
    
    private static function notifyApplicantInterviewScheduled($conn, $data) {
        $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                  VALUES (?, 'applicant', 'interview_scheduled', ?, ?, ?, 'interview')";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issi", 
            $data['applicant_id'], 
            'Interview Scheduled', 
            'Your interview has been scheduled for ' . $data['date'] . ' at ' . $data['time'] . '. Please be prepared and arrive on time.',
            $data['interview_id'] ?? null
        );
        $stmt->execute();
    }
    
    private static function notifyApplicantApproved($conn, $data) {
        $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                  VALUES (?, 'applicant', 'application_approved', ?, ?, ?, 'application')";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issi", 
            $data['applicant_id'], 
            'Application Approved', 
            'Congratulations! Your application has been approved. Please upload the required documents to proceed.',
            $data['application_id'] ?? null
        );
        $stmt->execute();
    }
    
    private static function notifyApplicantDeclined($conn, $data) {
        $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                  VALUES (?, 'applicant', 'application_declined', ?, ?, ?, 'application')";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issi", 
            $data['applicant_id'], 
            'Application Status Update', 
            'Thank you for your interest in our position. After careful consideration, we have decided to move forward with other candidates.',
            $data['application_id'] ?? null
        );
        $stmt->execute();
    }
    
    private static function notifyApplicantExamAssigned($conn, $data) {
        $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                  VALUES (?, 'applicant', 'exam_assigned', ?, ?, ?, 'exam')";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issi", 
            $data['applicant_id'], 
            'New Exam Assigned', 
            'You have been assigned a new exam: ' . $data['exam_title'] . '. Please complete it before the due date.',
            $data['exam_id']
        );
        $stmt->execute();
    }
    
    private static function notifyApplicantExamGraded($conn, $data) {
        $query = "INSERT INTO notifications (user_id, user_role, type, title, message, related_id, related_type) 
                  VALUES (?, 'applicant', 'exam_graded', ?, ?, ?, 'exam')";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issi", 
            $data['applicant_id'], 
            'Exam Results Available', 
            'Your exam "' . $data['exam_title'] . '" has been graded. Your score: ' . $data['score'] . '%',
            $data['exam_id']
        );
        $stmt->execute();
    }
}

// Handle the request
try {
    $api = new NotificationAPI($conn);
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>