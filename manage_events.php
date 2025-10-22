<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'db_connection.php';

// Use the existing MySQLi connection
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not available']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

switch($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            // Get single event
            $id = (int)$_GET['id'];
            $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $event = $result->fetch_assoc();
            
            if ($event) {
                echo json_encode($event);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Event not found']);
            }
            $stmt->close();
        } else {
            // Get all events with pagination
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            // Get total count
            $countResult = $conn->query("SELECT COUNT(*) as total FROM events");
            $total = $countResult->fetch_assoc()['total'];
            
            // Get events with pagination
            $stmt = $conn->prepare("
                SELECT * FROM events 
                ORDER BY event_date DESC, start_time DESC 
                LIMIT ? OFFSET ?
            ");
            $stmt->bind_param("ii", $limit, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            $events = [];
            while ($row = $result->fetch_assoc()) {
                $events[] = $row;
            }
            $stmt->close();
            
            echo json_encode([
                'events' => $events,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($total / $limit),
                    'total_records' => (int)$total,
                    'per_page' => $limit
                ]
            ]);
        }
        break;
        
    case 'POST':
        // Create new event
        if (!isset($input['title']) || !isset($input['event_date']) || !isset($input['start_time']) || 
            !isset($input['end_time']) || !isset($input['location']) || !isset($input['created_by'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Required fields: title, event_date, start_time, end_time, location, created_by']);
            break;
        }
        
        try {
            $stmt = $conn->prepare("
                INSERT INTO events (title, description, event_date, start_time, end_time, location, 
                                  status, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $description = $input['description'] ?? null;
            $status = $input['status'] ?? 'active';
            
            $stmt->bind_param("ssssssss", 
                $input['title'],
                $description,
                $input['event_date'],
                $input['start_time'],
                $input['end_time'],
                $input['location'],
                $status,
                $input['created_by']
            );
            
            if ($stmt->execute()) {
                $eventId = $conn->insert_id;
                $stmt->close();
                
                // Return the created event
                $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
                $stmt->bind_param("i", $eventId);
                $stmt->execute();
                $result = $stmt->get_result();
                $event = $result->fetch_assoc();
                $stmt->close();
                
                http_response_code(201);
                echo json_encode($event);
            } else {
                throw new Exception("Failed to insert event");
            }
            
        } catch(Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create event: ' . $e->getMessage()]);
        }
        break;
        
    case 'PUT':
        // Update event
        if (!isset($_GET['id']) || !isset($input['title'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Event ID and title are required']);
            break;
        }
        
        try {
            $id = (int)$_GET['id'];
            $stmt = $conn->prepare("
                UPDATE events SET 
                    title = ?, 
                    description = ?, 
                    event_date = ?, 
                    start_time = ?, 
                    end_time = ?, 
                    location = ?, 
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $description = $input['description'] ?? null;
            $status = $input['status'] ?? 'active';
            
            $stmt->bind_param("sssssssi", 
                $input['title'],
                $description,
                $input['event_date'],
                $input['start_time'],
                $input['end_time'],
                $input['location'],
                $status,
                $id
            );
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $stmt->close();
                
                // Return the updated event
                $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $result = $stmt->get_result();
                $event = $result->fetch_assoc();
                $stmt->close();
                
                echo json_encode($event);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Event not found']);
            }
            
        } catch(Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to update event: ' . $e->getMessage()]);
        }
        break;
        
    case 'DELETE':
        // Delete event
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Event ID is required']);
            break;
        }
        
        try {
            $id = (int)$_GET['id'];
            $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                echo json_encode(['message' => 'Event deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Event not found']);
            }
            $stmt->close();
            
        } catch(Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to delete event: ' . $e->getMessage()]);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}
?>