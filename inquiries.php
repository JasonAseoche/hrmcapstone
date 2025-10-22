<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once 'db_connection.php';

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = '';

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_POST['action'] ?? '';
}

try {
    switch ($action) {
        case 'getTickets':
            getTickets();
            break;
            
        case 'getTicketDetails':
            getTicketDetails();
            break;
            
        case 'createInquiry':
            createInquiry();
            break;
            
        case 'sendReply':
            sendReply();
            break;
            
        case 'markAsRead':
            markAsRead();
            break;
            
        case 'createFollowUp':
            createFollowUp();
            break;
            
        case 'getUserTickets':
            getUserTickets();
            break;
            
        case 'updateTicket':
            updateTicket();
            break;
            
        case 'cancelTicket':
            cancelTicket();
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// Helper function to get base URL for direct file access
function getBaseURL() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $basePath = dirname($scriptName);
    return $protocol . '://' . $host . $basePath . '/';
}

// Simple attachment formatter for direct file access
function formatAttachment($attachmentPath) {
    if (empty($attachmentPath)) {
        return null;
    }
    
    $baseURL = getBaseURL();
    $filename = basename($attachmentPath);
    // Direct access to uploaded files
    $fullURL = $baseURL . $attachmentPath;
    
    return [
        'filename' => $filename,
        'url' => $fullURL,
        'path' => $attachmentPath
    ];
}

// Get all tickets for HR dashboard
function getTickets() {
    global $conn;
    
    try {
        $sql = "SELECT 
                    t.id,
                    t.employee_id,
                    t.subject,
                    t.description,
                    t.priority,
                    t.status,
                    t.date_submitted,
                    t.unread_hr,
                    t.original_ticket_id,
                    u.firstName,
                    u.lastName,
                    CONCAT(u.firstName, ' ', u.lastName) as employee_name
                FROM inquiries t 
                LEFT JOIN useraccounts u ON t.employee_id = u.id 
                ORDER BY t.date_submitted DESC";
        
        $result = $conn->query($sql);
        $tickets = [];
        
        while ($row = $result->fetch_assoc()) {
            $tickets[] = [
                'id' => $row['id'],
                'employee_id' => $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'subject' => $row['subject'],
                'description' => $row['description'],
                'priority' => $row['priority'],
                'status' => $row['status'],
                'date_submitted' => $row['date_submitted'],
                'unread' => (bool)$row['unread_hr'],
                'original_ticket_id' => $row['original_ticket_id']
            ];
        }
        
        echo json_encode(['success' => true, 'tickets' => $tickets]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to fetch tickets: ' . $e->getMessage());
    }
}

// Add this function near the top of inquiries.php after the existing helper functions
function getUserProfileForMessage($conn, $userId, $userType) {
    try {
        if ($userType === 'hr') {
            // For HR users, get from useraccounts table
            $sql = "SELECT 
                        u.id,
                        u.firstName, 
                        u.lastName, 
                        u.profile_image,
                        CONCAT(u.firstName, ' ', u.lastName) as full_name
                    FROM useraccounts u 
                    WHERE u.id = ? AND u.role IN ('hr', 'admin')";
        } else {
            // For employees, get from useraccounts table
            $sql = "SELECT 
                        u.id,
                        u.firstName, 
                        u.lastName, 
                        u.profile_image,
                        CONCAT(u.firstName, ' ', u.lastName) as full_name
                    FROM useraccounts u 
                    WHERE u.id = ? AND u.role = 'employee'";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $userData = $result->fetch_assoc();
            
            // Format profile image URL if exists
            if ($userData['profile_image']) {
                $userData['profile_image_url'] = 'https://difsysinc.com/difsysapi/' . $userData['profile_image'];
            } else {
                $userData['profile_image_url'] = null;
            }
            
            return $userData;
        }
        
        return null;
    } catch (Exception $e) {
        error_log('Error fetching user profile for message: ' . $e->getMessage());
        return null;
    }
}

// Get ticket details with messages
function getTicketDetails() {
    global $conn;
    
    $ticket_id = $_GET['ticket_id'] ?? '';
    
    if (empty($ticket_id)) {
        throw new Exception('Ticket ID is required');
    }
    
    try {
        // Get ticket details
        $sql = "SELECT 
                    t.id,
                    t.employee_id,
                    t.subject,
                    t.description,
                    t.priority,
                    t.status,
                    t.date_submitted,
                    t.unread_hr,
                    t.original_ticket_id,
                    u.firstName,
                    u.lastName,
                    CONCAT(u.firstName, ' ', u.lastName) as employee_name
                FROM inquiries t 
                LEFT JOIN useraccounts u ON t.employee_id = u.id 
                WHERE t.id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $ticket_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Ticket not found');
        }
        
        $ticket = $result->fetch_assoc();
        
        // Get messages for this ticket
        $sql_messages = "SELECT 
                            m.id,
                            m.sender_id,
                            m.sender_type,
                            m.message,
                            m.timestamp,
                            m.attachment_path
                        FROM inquiry_messages m
                        WHERE m.ticket_id = ?
                        ORDER BY m.timestamp ASC";
        
        $stmt_messages = $conn->prepare($sql_messages);
        $stmt_messages->bind_param('s', $ticket_id);
        $stmt_messages->execute();
        $messages_result = $stmt_messages->get_result();
        
        $messages = [];
        while ($msg = $messages_result->fetch_assoc()) {
            // Get user profile information for this message
            $userProfile = getUserProfileForMessage($conn, $msg['sender_id'], $msg['sender_type']);
            
            $attachments = [];
            if (!empty($msg['attachment_path'])) {
                $attachment = formatAttachment($msg['attachment_path']);
                if ($attachment) {
                    $attachments = [$attachment];
                }
            }
            
            $messages[] = [
                'id' => $msg['id'],
                'sender' => $userProfile ? $userProfile['full_name'] : ($msg['sender_type'] === 'hr' ? 'HR Team' : 'Employee'),
                'sender_type' => $msg['sender_type'],
                'sender_id' => $msg['sender_id'],
                'sender_profile' => $userProfile, // Add complete profile data
                'message' => $msg['message'],
                'timestamp' => $msg['timestamp'],
                'attachments' => $attachments
            ];
        }
        
        // Check if this is a follow-up ticket
        $follow_up_from = null;
        if (!empty($ticket['original_ticket_id'])) {
            $follow_up_from = $ticket['original_ticket_id'];
        }
        
        $ticket_data = [
            'id' => $ticket['id'],
            'employee_id' => $ticket['employee_id'],
            'employee_name' => $ticket['employee_name'],
            'subject' => $ticket['subject'],
            'description' => $ticket['description'],
            'priority' => $ticket['priority'],
            'status' => $ticket['status'],
            'date_submitted' => $ticket['date_submitted'],
            'unread' => (bool)$ticket['unread_hr'],
            'follow_up_from' => $follow_up_from,
            'messages' => $messages
        ];
        
        echo json_encode(['success' => true, 'ticket' => $ticket_data]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to fetch ticket details: ' . $e->getMessage());
    }
}

// Create new inquiry (Employee side)
function createInquiry() {
    global $conn;
    
    $employee_id = $_POST['employee_id'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $description = $_POST['description'] ?? '';
    $priority = $_POST['priority'] ?? 'Medium';
    $follow_up_from = $_POST['follow_up_from'] ?? null;
    
    if (empty($employee_id) || empty($subject) || empty($description)) {
        throw new Exception('Employee ID, subject, and description are required');
    }
    
    try {
        $conn->begin_transaction();
        
        // Generate ticket ID
        $ticket_id = 'TKT' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        
        // Insert inquiry
        $sql = "INSERT INTO inquiries (id, employee_id, subject, description, priority, status, date_submitted, unread_hr, unread_employee, original_ticket_id) 
                VALUES (?, ?, ?, ?, ?, 'Open', CURDATE(), 1, 0, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sissss', $ticket_id, $employee_id, $subject, $description, $priority, $follow_up_from);
        $stmt->execute();
        
        // Insert initial message
        $sql_message = "INSERT INTO inquiry_messages (ticket_id, sender_id, sender_type, message, timestamp) 
                        VALUES (?, ?, 'employee', ?, NOW())";
        
        $stmt_message = $conn->prepare($sql_message);
        $stmt_message->bind_param('sis', $ticket_id, $employee_id, $description);
        $stmt_message->execute();
        
        $message_id = $conn->insert_id;
        
        // Handle file upload if present
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/inquiries/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $filename = time() . '_' . basename($_FILES['attachment']['name']);
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                // Update message with attachment
                $sql_attachment = "UPDATE inquiry_messages SET attachment_path = ? WHERE id = ?";
                $stmt_attachment = $conn->prepare($sql_attachment);
                $stmt_attachment->bind_param('si', $upload_path, $message_id);
                $stmt_attachment->execute();
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'ticket_id' => $ticket_id]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Failed to create inquiry: ' . $e->getMessage());
    }
}

// Send reply (HR or Employee)
function sendReply() {
    global $conn;
    
    $ticket_id = $_POST['ticket_id'] ?? '';
    $message = $_POST['message'] ?? '';
    $close_ticket = $_POST['close_ticket'] ?? 'false';
    $sender_type = $_POST['sender_type'] ?? 'hr';
    $sender_id = $_POST['sender_id'] ?? null;
    $update_status = $_POST['update_status'] ?? null;

    if ($sender_type === 'hr' && !$sender_id) {

        $sender_id = $_SESSION['hr_user_id'] ?? 1; 
    }
    
    if (empty($ticket_id) || empty($message)) {
        throw new Exception('Ticket ID and message are required');
    }
    
    $close_ticket = filter_var($close_ticket, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $conn->begin_transaction();
        
        // Insert message
        $sql = "INSERT INTO inquiry_messages (ticket_id, sender_id, sender_type, message, timestamp) 
                VALUES (?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('siss', $ticket_id, $sender_id, $sender_type, $message);
        $stmt->execute();
        
        $message_id = $conn->insert_id;
        
        // Handle file upload if present
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/inquiries/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $filename = time() . '_' . basename($_FILES['attachment']['name']);
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                $sql_attachment = "UPDATE inquiry_messages SET attachment_path = ? WHERE id = ?";
                $stmt_attachment = $conn->prepare($sql_attachment);
                $stmt_attachment->bind_param('si', $upload_path, $message_id);
                $stmt_attachment->execute();
            }
        }
        
        // Update ticket status and unread flags
        if ($close_ticket && $sender_type === 'hr') {
            $sql_update = "UPDATE inquiries SET status = 'Closed', unread_hr = 0, unread_employee = 1 WHERE id = ?";
        } else {
            if ($sender_type === 'hr' && $update_status === 'In Progress') {
                $sql_update = "UPDATE inquiries SET status = 'In Progress', unread_employee = 1, unread_hr = 0 WHERE id = ?";
            } else if ($sender_type === 'hr') {
                $sql_update = "UPDATE inquiries SET unread_employee = 1, unread_hr = 0 WHERE id = ?";
            } else {
                $sql_update = "UPDATE inquiries SET unread_hr = 1, unread_employee = 0 WHERE id = ?";
            }
        }
        
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->bind_param('s', $ticket_id);
        $stmt_update->execute();
        
        $conn->commit();
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Failed to send reply: ' . $e->getMessage());
    }
}

// Mark ticket as read
function markAsRead() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $ticket_id = $input['ticket_id'] ?? '';
    $reader_type = $input['reader_type'] ?? 'hr';
    
    if (empty($ticket_id)) {
        throw new Exception('Ticket ID is required');
    }
    
    try {
        if ($reader_type === 'hr') {
            $sql = "UPDATE inquiries SET unread_hr = 0 WHERE id = ?";
        } else {
            $sql = "UPDATE inquiries SET unread_employee = 0 WHERE id = ?";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $ticket_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to mark as read: ' . $e->getMessage());
    }
}

// Create follow-up inquiry
function createFollowUp() {
    global $conn;
    
    $original_ticket_id = $_POST['original_ticket_id'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $description = $_POST['description'] ?? '';
    $priority = $_POST['priority'] ?? 'Medium';
    
    if (empty($original_ticket_id) || empty($subject) || empty($description)) {
        throw new Exception('Original ticket ID, subject, and description are required');
    }
    
    try {
        // Get employee ID from original ticket
        $sql_original = "SELECT employee_id FROM inquiries WHERE id = ?";
        $stmt_original = $conn->prepare($sql_original);
        $stmt_original->bind_param('s', $original_ticket_id);
        $stmt_original->execute();
        $result = $stmt_original->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Original ticket not found');
        }
        
        $original_ticket = $result->fetch_assoc();
        $employee_id = $original_ticket['employee_id'];
        
        $conn->begin_transaction();
        
        // Generate new ticket ID
        $ticket_id = 'TKT' . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $follow_up_subject = 'Follow-up: ' . $subject;
        
        // Insert follow-up inquiry
        $sql = "INSERT INTO inquiries (id, employee_id, subject, description, priority, status, date_submitted, unread_hr, unread_employee, original_ticket_id) 
                VALUES (?, ?, ?, ?, ?, 'Open', CURDATE(), 1, 0, ?)";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sissss', $ticket_id, $employee_id, $follow_up_subject, $description, $priority, $original_ticket_id);
        $stmt->execute();
        
        // Insert initial message
        $sql_message = "INSERT INTO inquiry_messages (ticket_id, sender_id, sender_type, message, timestamp) 
                        VALUES (?, ?, 'employee', ?, NOW())";
        
        $stmt_message = $conn->prepare($sql_message);
        $stmt_message->bind_param('sis', $ticket_id, $employee_id, $description);
        $stmt_message->execute();
        
        $message_id = $conn->insert_id;
        
        // Handle file upload if present
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/inquiries/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $filename = time() . '_' . basename($_FILES['attachment']['name']);
            $upload_path = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                $sql_attachment = "UPDATE inquiry_messages SET attachment_path = ? WHERE id = ?";
                $stmt_attachment = $conn->prepare($sql_attachment);
                $stmt_attachment->bind_param('si', $upload_path, $message_id);
                $stmt_attachment->execute();
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'ticket_id' => $ticket_id]);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw new Exception('Failed to create follow-up: ' . $e->getMessage());
    }
}

// Get tickets for specific user (Employee side)
function getUserTickets() {
    global $conn;
    
    $employee_id = $_GET['employee_id'] ?? '';
    
    if (empty($employee_id)) {
        throw new Exception('Employee ID is required');
    }
    
    try {
        $sql = "SELECT 
                    id,
                    subject,
                    description,
                    priority,
                    status,
                    date_submitted,
                    unread_employee,
                    original_ticket_id
                FROM inquiries 
                WHERE employee_id = ? 
                ORDER BY date_submitted DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $tickets[] = [
                'id' => $row['id'],
                'subject' => $row['subject'],
                'description' => $row['description'],
                'priority' => $row['priority'],
                'status' => $row['status'],
                'date_submitted' => $row['date_submitted'],
                'unread' => (bool)$row['unread_employee'],
                'follow_up_from' => $row['original_ticket_id']
            ];
        }
        
        echo json_encode(['success' => true, 'tickets' => $tickets]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to fetch user tickets: ' . $e->getMessage());
    }
}

// Update ticket (Employee side)
function updateTicket() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $ticket_id = $input['ticket_id'] ?? '';
    $subject = $input['subject'] ?? '';
    $description = $input['description'] ?? '';
    $priority = $input['priority'] ?? '';
    
    if (empty($ticket_id)) {
        throw new Exception('Ticket ID is required');
    }
    
    try {
        $sql = "UPDATE inquiries SET subject = ?, description = ?, priority = ?, unread_hr = 1 WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssss', $subject, $description, $priority, $ticket_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to update ticket: ' . $e->getMessage());
    }
}

// Cancel ticket (Employee side)
function cancelTicket() {
    global $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    $ticket_id = $input['ticket_id'] ?? '';
    
    if (empty($ticket_id)) {
        throw new Exception('Ticket ID is required');
    }
    
    try {
        $sql = "UPDATE inquiries SET status = 'Cancelled', unread_hr = 1 WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $ticket_id);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to cancel ticket: ' . $e->getMessage());
    }
}

$conn->close();
?>