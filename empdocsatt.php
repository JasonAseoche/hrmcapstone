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

require_once 'db_connection.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    // Check if this is an attendance-related request
    $action = $_GET['action'] ?? null;
    
    if ($action === 'attendance') {
        handleAttendanceRequests();
        return;
    }
    
    // Existing document handling code
    switch ($method) {
        case 'GET':
            handleGetDocuments();
            break;
        case 'POST':
            handleUploadDocument();
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            handleUpdateDocument($input);
            break;
        case 'DELETE':
            $input = json_decode(file_get_contents('php://input'), true);
            handleDeleteDocument($input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
}

// NEW FUNCTION: Handle attendance-related requests
function handleAttendanceRequests() {
    global $conn;
    
    $emp_id = $_GET['emp_id'] ?? null;
    $type = $_GET['type'] ?? 'history'; // 'history' or 'stats'
    $limit = $_GET['limit'] ?? 30; // Default to last 30 records
    $month = $_GET['month'] ?? null; // Optional month filter (YYYY-MM format)
    
    if (!$emp_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Employee ID is required']);
        return;
    }
    
    try {
        if ($type === 'stats') {
            handleGetAttendanceStats($emp_id, $month);
        } else {
            handleGetAttendanceHistory($emp_id, $limit, $month);
        }
    } catch (Exception $e) {
        error_log("Attendance API Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

// NEW FUNCTION: Get attendance statistics
function handleGetAttendanceStats($emp_id, $month = null) {
    global $conn;
    
    // Build WHERE clause based on filters
    $whereClause = "WHERE emp_id = ?";
    $params = [$emp_id];
    $types = "i";
    
    if ($month) {
        $whereClause .= " AND DATE_FORMAT(date, '%Y-%m') = ?";
        $params[] = $month;
        $types .= "s";
    } else {
        // Default to current month if no month specified
        $whereClause .= " AND DATE_FORMAT(date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    }
    
    $stmt = $conn->prepare("
        SELECT 
            ROUND(AVG(CASE WHEN total_workhours > 0 THEN total_workhours/60 ELSE 0 END), 1) as avg_work_hours,
            COUNT(CASE WHEN present = 1 THEN 1 END) as present_days,
            COUNT(CASE WHEN late_minutes > 0 THEN 1 END) as late_days,
            COUNT(CASE WHEN absent = 1 THEN 1 END) as absent_days,
            COUNT(CASE WHEN on_leave = 1 THEN 1 END) as leave_days,
            SUM(CASE WHEN overtime > 0 THEN overtime ELSE 0 END) as total_overtime_minutes,
            SUM(CASE WHEN late_minutes > 0 THEN late_minutes ELSE 0 END) as total_late_minutes,
            SUM(CASE WHEN undertime_minutes > 0 THEN undertime_minutes ELSE 0 END) as total_undertime_minutes
        FROM attendancelist 
        $whereClause
    ");
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    $stmt->close();
    
    // Format the response
    $response = [
        'avgWorkHours' => $stats['avg_work_hours'] ?: '0.0',
        'presentDays' => (int)$stats['present_days'],
        'lateDays' => (int)$stats['late_days'],
        'absentDays' => (int)$stats['absent_days'],
        'leaveDays' => (int)$stats['leave_days'],
        'totalOvertimeHours' => round(($stats['total_overtime_minutes'] ?: 0) / 60, 1),
        'totalLateMinutes' => (int)($stats['total_late_minutes'] ?: 0),
        'totalUndertimeMinutes' => (int)($stats['total_undertime_minutes'] ?: 0)
    ];
    
    echo json_encode(['stats' => $response]);
}

// NEW FUNCTION: Get attendance history
function handleGetAttendanceHistory($emp_id, $limit, $month = null) {
    global $conn;
    
    // Build WHERE clause based on filters
    $whereClause = "WHERE emp_id = ?";
    $params = [$emp_id];
    $types = "i";
    
    if ($month) {
        $whereClause .= " AND DATE_FORMAT(date, '%Y-%m') = ?";
        $params[] = $month;
        $types .= "s";
    }
    
    $stmt = $conn->prepare("
        SELECT 
            id,
            date,
            time_in,
            time_out,
            total_workhours,
            overtime,
            late_minutes,
            undertime_minutes,
            late_undertime,
            present,
            absent,
            on_leave,
            status,
            is_holiday,
            holiday_type,
            break_duration
        FROM attendancelist 
        $whereClause
        ORDER BY date DESC 
        LIMIT ?
    ");
    
    // Add limit parameter
    $params[] = (int)$limit;
    $types .= "i";
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $attendance = [];
    while ($row = $result->fetch_assoc()) {
        $attendance[] = formatAttendanceRecord($row);
    }
    $stmt->close();
    
    echo json_encode(['attendance' => $attendance]);
}

// NEW FUNCTION: Format attendance record for frontend
function formatAttendanceRecord($record) {
    // Format date
    $date = date('d M, Y', strtotime($record['date']));
    
    // Format times
    $checkIn = $record['time_in'] ? date('g:i A', strtotime($record['time_in'])) : '--';
    $checkOut = $record['time_out'] ? date('g:i A', strtotime($record['time_out'])) : '--';
    
    // Calculate total hours
    $totalHours = '0h 0m';
    if ($record['total_workhours'] > 0) {
        $hours = floor($record['total_workhours'] / 60);
        $minutes = $record['total_workhours'] % 60;
        $totalHours = $hours . 'h ' . $minutes . 'm';
    }
    
    // Format overtime
    $overtime = '0h 0m';
    if ($record['overtime'] > 0) {
        $hours = floor($record['overtime'] / 60);
        $minutes = $record['overtime'] % 60;
        $overtime = $hours . 'h ' . $minutes . 'm';
    }
    
    // Format late/undertime
    $lateUndertime = '--';
    if ($record['late_undertime'] > 0) {
        $lateUndertime = $record['late_undertime'] . 'm';
    } elseif ($record['late_minutes'] > 0) {
        $lateUndertime = $record['late_minutes'] . 'm (Late)';
    } elseif ($record['undertime_minutes'] > 0) {
        $lateUndertime = $record['undertime_minutes'] . 'm (Undertime)';
    }
    
    // Determine status
    $status = 'Present';
    $isWeekend = false;
    
    if ($record['absent']) {
        $status = 'Absent';
    } elseif ($record['on_leave']) {
        $status = 'On Leave';
    } elseif ($record['is_holiday']) {
        $status = $record['holiday_type'] . ' Holiday';
    } elseif ($record['status']) {
        $status = $record['status'];
    } elseif ($record['late_minutes'] > 0) {
        $status = 'Late';
    }
    
    // Check if weekend (you might want to add a weekend detection logic based on your business rules)
    $dayOfWeek = date('N', strtotime($record['date'])); // 1 = Monday, 7 = Sunday
    if ($dayOfWeek == 6 || $dayOfWeek == 7) { // Saturday or Sunday
        $isWeekend = true;
        if (!$record['present'] && !$record['absent'] && !$record['on_leave']) {
            $status = 'Weekend';
        }
    }
    
    return [
        'id' => $record['id'],
        'date' => $date,
        'checkIn' => $checkIn,
        'checkOut' => $checkOut,
        'totalHours' => $totalHours,
        'overtime' => $overtime,
        'lateUndertime' => $lateUndertime,
        'status' => $status,
        'isWeekend' => $isWeekend,
        'rawData' => [
            'total_workhours' => $record['total_workhours'],
            'overtime' => $record['overtime'],
            'late_minutes' => $record['late_minutes'],
            'undertime_minutes' => $record['undertime_minutes'],
            'present' => $record['present'],
            'absent' => $record['absent'],
            'on_leave' => $record['on_leave'],
            'is_holiday' => $record['is_holiday'],
            'holiday_type' => $record['holiday_type']
        ]
    ];
}

// EXISTING FUNCTIONS BELOW (unchanged)

function handleGetDocuments() {
    global $conn;
    
    $emp_id = $_GET['emp_id'] ?? null;
    $document_id = $_GET['document_id'] ?? null;
    
    if (!$emp_id && !$document_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Employee ID or Document ID is required']);
        return;
    }
    
    try {
        if ($document_id) {
            // Get specific document for preview/download
            $stmt = $conn->prepare("
                SELECT id, emp_id, file_type, file_content, file_name, file_size, 
                       mime_type, uploaded_at, updated_at 
                FROM employee_files 
                WHERE id = ?
            ");
            $stmt->bind_param("i", $document_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $document = $result->fetch_assoc();
            $stmt->close();
            
            if (!$document) {
                http_response_code(404);
                echo json_encode(['error' => 'Document not found']);
                return;
            }
            
            // Check if preview is requested
            $preview = $_GET['preview'] ?? false;
            
            if ($preview) {
                // Return base64 encoded content for preview
                $base64_content = base64_encode($document['file_content']);
                echo json_encode([
                    'id' => $document['id'],
                    'file_name' => $document['file_name'],
                    'mime_type' => $document['mime_type'],
                    'file_size' => $document['file_size'],
                    'content' => $base64_content
                ]);
            } else {
                // Return file for download
                header('Content-Type: ' . $document['mime_type']);
                header('Content-Disposition: attachment; filename="' . $document['file_name'] . '"');
                header('Content-Length: ' . $document['file_size']);
                echo $document['file_content'];
            }
        } else {
            // Get all documents for employee
            $stmt = $conn->prepare("
                SELECT id, emp_id, file_type, file_name, file_size, mime_type, 
                       uploaded_at, updated_at, migrated_from_applicant
                FROM employee_files 
                WHERE emp_id = ? 
                ORDER BY uploaded_at DESC
            ");
            $stmt->bind_param("i", $emp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $documents = [];
            while ($row = $result->fetch_assoc()) {
                $documents[] = $row;
            }
            $stmt->close();
            
            // Transform data to match frontend expectations
            $transformedDocs = array_map(function($doc) {
                $fileType = getFileTypeFromMime($doc['mime_type']);
                return [
                    'id' => $doc['id'],
                    'name' => pathinfo($doc['file_name'], PATHINFO_FILENAME),
                    'type' => getFileTypeLabel($fileType),
                    'uploadDate' => date('Y-m-d', strtotime($doc['uploaded_at'])),
                    'fileType' => $fileType,
                    'isUploaded' => true,
                    'file_size' => $doc['file_size'],
                    'mime_type' => $doc['mime_type'],
                    'migrated_from_applicant' => $doc['migrated_from_applicant'] ?? 0
                ];
            }, $documents);
            
            echo json_encode(['documents' => $transformedDocs]);
        }
    } catch (Exception $e) {
        error_log("Get Documents Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleUploadDocument() {
    global $conn;
    
    try {
        $emp_id = $_POST['emp_id'] ?? null;
        $file_type = $_POST['file_type'] ?? 'Other Document';
        
        if (!$emp_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Employee ID is required']);
            return;
        }
        
        if (!isset($_FILES['file'])) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded']);
            return;
        }
        
        $file = $_FILES['file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'File upload error: ' . $file['error']]);
            return;
        }
        
        // Validate file size (10MB max)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            http_response_code(400);
            echo json_encode(['error' => 'File size exceeds 10MB limit']);
            return;
        }
        
        // Validate file type
        $allowedTypes = [
            'application/pdf', 
            'image/jpeg', 
            'image/jpg',
            'image/png', 
            'image/gif', 
            'application/vnd.ms-excel', 
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        
        if (!in_array($file['type'], $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['error' => 'File type not allowed: ' . $file['type']]);
            return;
        }
        
        // Read file content
        $file_content = file_get_contents($file['tmp_name']);
        if ($file_content === false) {
            http_response_code(400);
            echo json_encode(['error' => 'Failed to read file content']);
            return;
        }
        
        // Always insert new document (removed duplicate check)
        $stmt = $conn->prepare("
            INSERT INTO employee_files (emp_id, file_type, file_content, file_name, file_size, mime_type, uploaded_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("isssss",
            $emp_id,
            $file_type,
            $file_content,
            $file['name'],
            $file['size'],
            $file['type']
        );
        
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to save file: ' . $stmt->error);
        }
        
        $document_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'document_id' => $document_id
        ]);
        
    } catch (Exception $e) {
        error_log("Upload Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Upload failed: ' . $e->getMessage()]);
    }
}

function handleUpdateDocument($input) {
    global $conn;
    
    $document_id = $input['document_id'] ?? null;
    $file_type = $input['file_type'] ?? null;
    
    if (!$document_id || !$file_type) {
        http_response_code(400);
        echo json_encode(['error' => 'Document ID and file type are required']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("
            UPDATE employee_files 
            SET file_type = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->bind_param("si", $file_type, $document_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Document updated successfully']);
        } else {
            $stmt->close();
            http_response_code(404);
            echo json_encode(['error' => 'Document not found']);
        }
        
    } catch (Exception $e) {
        error_log("Update Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleDeleteDocument($input) {
    global $conn;
    
    $document_ids = $input['document_ids'] ?? null;
    
    if (!$document_ids || !is_array($document_ids)) {
        http_response_code(400);
        echo json_encode(['error' => 'Document IDs array is required']);
        return;
    }
    
    try {
        $placeholders = str_repeat('?,', count($document_ids) - 1) . '?';
        $stmt = $conn->prepare("DELETE FROM employee_files WHERE id IN ($placeholders)");
        
        // Bind parameters dynamically
        $types = str_repeat('i', count($document_ids));
        $stmt->bind_param($types, ...$document_ids);
        
        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            echo json_encode([
                'success' => true,
                'message' => 'Documents deleted successfully',
                'deleted_count' => $affected_rows
            ]);
        } else {
            $stmt->close();
            throw new Exception('Failed to delete documents: ' . $stmt->error);
        }
        
    } catch (Exception $e) {
        error_log("Delete Error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function getFileTypeFromMime($mimeType) {
    if (strpos($mimeType, 'pdf') !== false) return 'pdf';
    if (strpos($mimeType, 'image') !== false) return 'image';
    if (strpos($mimeType, 'spreadsheet') !== false || strpos($mimeType, 'excel') !== false) return 'excel';
    return 'other';
}

function getFileTypeLabel($fileType) {
    switch ($fileType) {
        case 'pdf': return 'PDF Document';
        case 'image': return 'Image File';
        case 'excel': return 'Excel File';
        default: return 'Document';
    }
}

// Close connection
if (isset($conn) && $conn) {
    $conn->close();
}
?>