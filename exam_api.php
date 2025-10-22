<?php
// exam_api.php - Updated with better progress handling
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connection.php';

$method = $_SERVER['REQUEST_METHOD'];

// Handle different content types for progress saving
$input = null;
if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        // Handle sendBeacon data (usually sent as text)
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        
        // If that fails, try to parse as form data
        if ($input === null) {
            parse_str($rawInput, $input);
        }
    }
} else {
    $input = json_decode(file_get_contents('php://input'), true);
}

// Get endpoint from query parameter
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

// Debug logging
error_log("Method: " . $method);
error_log("Endpoint: " . $endpoint);
error_log("Input: " . json_encode($input));
error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));

try {
    // Validate endpoint
    if (empty($endpoint)) {
        throw new Exception('No endpoint specified', 400);
    }
    
    switch ($method) {
        case 'GET':
            handleGet($endpoint, $_GET);
            break;
        case 'POST':
            handlePost($endpoint, $input);
            break;
        case 'PUT':
            handlePut($endpoint, $input);
            break;
        case 'DELETE':
            handleDelete($endpoint, $_GET);
            break;
        default:
            throw new Exception('Method not allowed', 405);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'error' => $e->getMessage(),
        'endpoint' => $endpoint,
        'method' => $method
    ]);
}

function handleGet($endpoint, $params) {
    global $conn;
    
    switch ($endpoint) {
        case 'exams':
            // Get all exams for HR
            $stmt = $conn->prepare("SELECT * FROM examinations ORDER BY created_at DESC");
            $stmt->execute();
            $result = $stmt->get_result();
            $exams = [];
            while ($row = $result->fetch_assoc()) {
                $row['questions'] = json_decode($row['questions'], true);
                $exams[] = $row;
            }
            echo json_encode($exams);
            break;
            
        case 'applicants':
            // Get all applicants for assignment, excluding those already assigned to a specific exam
            if (isset($params['exclude_exam_id'])) {
                // Get applicants NOT assigned to this specific exam
                $exam_id = intval($params['exclude_exam_id']);
                $stmt = $conn->prepare("
                    SELECT app_id, firstName, lastName, email, position 
                    FROM applicantlist 
                    WHERE (status = 'pending' OR status = 'approved') 
                    AND app_id NOT IN (
                        SELECT app_id FROM exam_assignments WHERE exam_id = ?
                    )
                ");
                $stmt->bind_param("i", $exam_id);
            } else {
                // Get all available applicants
                $stmt = $conn->prepare("SELECT app_id, firstName, lastName, email, position FROM applicantlist WHERE status = 'pending' OR status = 'approved'");
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $applicants = [];
            while ($row = $result->fetch_assoc()) {
                $applicants[] = [
                    'id' => $row['app_id'],
                    'name' => $row['firstName'] . ' ' . $row['lastName'],
                    'email' => $row['email'],
                    'position' => $row['position']
                ];
            }
            echo json_encode($applicants);
            break;
            
        case 'applicant-info':
            // Get applicant information
            if (!isset($params['app_id'])) {
                throw new Exception('app_id required', 400);
            }
            
            $stmt = $conn->prepare("SELECT firstName, lastName, email, position FROM applicantlist WHERE app_id = ?");
            $stmt->bind_param("i", $params['app_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            $applicant = $result->fetch_assoc();
            
            echo json_encode($applicant);
            break;
            
        case 'assignments':
            // Get exam assignments
            if (isset($params['app_id'])) {
                // Get assignments for specific applicant
                $stmt = $conn->prepare("
                    SELECT ea.*, e.title, e.duration, e.questions, 
                           al.firstName, al.lastName, al.email,
                           hr.firstName as hr_fname, hr.lastName as hr_lname
                    FROM exam_assignments ea
                    JOIN examinations e ON ea.exam_id = e.id
                    JOIN applicantlist al ON ea.app_id = al.app_id
                    LEFT JOIN applicantlist hr ON ea.assigned_by = hr.app_id
                    WHERE ea.app_id = ? AND ea.status != 'Unassigned'
                    ORDER BY ea.assigned_at DESC
                ");
                $stmt->bind_param("i", $params['app_id']);
            } else if (isset($params['exam_id'])) {
                // Get assignments for specific exam (for ungive functionality)
                $stmt = $conn->prepare("
                    SELECT ea.*, al.firstName, al.lastName, al.email, al.position,
                           al.app_id
                    FROM exam_assignments ea
                    JOIN applicantlist al ON ea.app_id = al.app_id
                    WHERE ea.exam_id = ?
                    ORDER BY ea.assigned_at DESC
                ");
                $stmt->bind_param("i", $params['exam_id']);
            } else {
                // Get all assignments for HR
                $stmt = $conn->prepare("
                    SELECT ea.*, e.title, e.duration,
                           al.firstName, al.lastName, al.email
                    FROM exam_assignments ea
                    JOIN examinations e ON ea.exam_id = e.id
                    JOIN applicantlist al ON ea.app_id = al.app_id
                    ORDER BY ea.assigned_at DESC
                ");
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $assignments = [];
            while ($row = $result->fetch_assoc()) {
                if (isset($row['questions'])) {
                    $row['questions'] = json_decode($row['questions'], true);
                }
                $assignments[] = $row;
            }
            echo json_encode($assignments);
            break;
            
        case 'attempts':
            // Get exam attempts/results
            if (isset($params['app_id'])) {
                // Get attempts for specific applicant with all necessary fields
                $stmt = $conn->prepare("
                    SELECT ea.*, e.title as exam_title, e.questions, e.id as exam_id,
                           al.firstName, al.lastName
                    FROM exam_attempts ea
                    JOIN examinations e ON ea.exam_id = e.id
                    JOIN applicantlist al ON ea.app_id = al.app_id
                    WHERE ea.app_id = ?
                    ORDER BY ea.started_at DESC
                ");
                $stmt->bind_param("i", $params['app_id']);
            } else {
                // Get all attempts for HR with all necessary fields
                $stmt = $conn->prepare("
                    SELECT ea.*, e.title as exam_title, e.id as exam_id,
                           al.firstName, al.lastName
                    FROM exam_attempts ea
                    JOIN examinations e ON ea.exam_id = e.id
                    JOIN applicantlist al ON ea.app_id = al.app_id
                    ORDER BY ea.started_at DESC
                ");
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $attempts = [];
            while ($row = $result->fetch_assoc()) {
                if ($row['answers']) {
                    $row['answers'] = json_decode($row['answers'], true);
                }
                // Ensure exam_id is properly set for the frontend
                if (!isset($row['exam_id']) && isset($row['id'])) {
                    $row['exam_id'] = $row['id'];
                }
                $attempts[] = $row;
            }
            echo json_encode($attempts);
            break;
            
        case 'exam-progress':
            // Get exam progress for resuming
            if (!isset($params['assignment_id'])) {
                throw new Exception('assignment_id required', 400);
            }
            
            $assignment_id = intval($params['assignment_id']);
            
            // First check if assignment is still active
            $check_stmt = $conn->prepare("SELECT status FROM exam_assignments WHERE id = ?");
            $check_stmt->bind_param("i", $assignment_id);
            $check_stmt->execute();
            $assignment = $check_stmt->get_result()->fetch_assoc();
            
            if (!$assignment || $assignment['status'] === 'Unassigned') {
                echo json_encode(['error' => 'Assignment no longer available']);
                return;
            }
            
            // Check if exam is already completed - if so, don't return progress data
            if ($assignment['status'] === 'Completed') {
                echo json_encode(null);
                return;
            }
            
            $stmt = $conn->prepare("
                SELECT current_question_index, answers, time_elapsed, status
                FROM exam_attempts 
                WHERE assignment_id = ? AND status = 'In Progress'
                ORDER BY started_at DESC
                LIMIT 1
            ");
            $stmt->bind_param("i", $assignment_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $progress = $result->fetch_assoc();
            
            if ($progress && $progress['answers']) {
                $progress['answers'] = json_decode($progress['answers'], true);
            }
            
            echo json_encode($progress ?: null);
            break;
            
        default:
            throw new Exception("Endpoint '$endpoint' not found", 404);
    }
}

function handlePost($endpoint, $data) {
    global $conn;
    
    // Add error logging for debugging
    error_log("POST endpoint: " . $endpoint);
    error_log("POST data: " . json_encode($data));
    
    switch ($endpoint) {
        case 'exams':
            // Create new exam
            if (!isset($data['title']) || !isset($data['duration']) || !isset($data['questions'])) {
                throw new Exception('Missing required fields for exam creation', 400);
            }
            
            $stmt = $conn->prepare("INSERT INTO examinations (title, duration, questions, status) VALUES (?, ?, ?, ?)");
            $questions_json = json_encode($data['questions']);
            $status = isset($data['status']) ? $data['status'] : 'Draft';
            $stmt->bind_param("siss", $data['title'], $data['duration'], $questions_json, $status);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create exam: ' . $stmt->error, 500);
            }
            
            echo json_encode(['id' => $conn->insert_id, 'message' => 'Exam created successfully']);
            break;
            
        case 'assign-exam':
            // Assign exam to applicants
            if (!isset($data['exam_id']) || !isset($data['app_ids']) || !isset($data['assigned_by'])) {
                throw new Exception('Missing required fields for exam assignment', 400);
            }
            
            $exam_id = intval($data['exam_id']);
            $app_ids = $data['app_ids'];
            $assigned_by = intval($data['assigned_by']);
            $due_date = isset($data['due_date']) ? $data['due_date'] : null;
            
            $stmt = $conn->prepare("INSERT INTO exam_assignments (exam_id, app_id, assigned_by, due_date, status) VALUES (?, ?, ?, ?, 'Assigned')");
            
            $assigned_count = 0;
            foreach ($app_ids as $app_id) {
                $app_id = intval($app_id);
                // Check if assignment already exists and is not unassigned
                $check_stmt = $conn->prepare("SELECT id, status FROM exam_assignments WHERE exam_id = ? AND app_id = ?");
                $check_stmt->bind_param("ii", $exam_id, $app_id);
                $check_stmt->execute();
                $existing = $check_stmt->get_result()->fetch_assoc();
                
                if ($existing) {
                    if ($existing['status'] === 'Unassigned') {
                        // Reactivate the assignment
                        $update_stmt = $conn->prepare("UPDATE exam_assignments SET status = 'Assigned', assigned_at = NOW(), due_date = ? WHERE id = ?");
                        $update_stmt->bind_param("si", $due_date, $existing['id']);
                        if ($update_stmt->execute()) {
                            $assigned_count++;
                        }
                    }
                    // If already assigned and not unassigned, skip
                } else {
                    // Create new assignment
                    $stmt->bind_param("iiis", $exam_id, $app_id, $assigned_by, $due_date);
                    if ($stmt->execute()) {
                        $assigned_count++;
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => "Exam assigned to {$assigned_count} applicants"]);
            break;
            
        case 'unassign-exam':
            // Unassign exam from applicants (DELETE the assignment records)
            if (!isset($data['exam_id']) || !isset($data['app_ids'])) {
                throw new Exception('Missing required fields for exam unassignment', 400);
            }
            
            $exam_id = intval($data['exam_id']);
            $app_ids = $data['app_ids'];
            
            // Use DELETE to completely remove the assignment
            $stmt = $conn->prepare("DELETE FROM exam_assignments WHERE exam_id = ? AND app_id = ?");
            
            $unassigned_count = 0;
            foreach ($app_ids as $app_id) {
                $app_id = intval($app_id);
                $stmt->bind_param("ii", $exam_id, $app_id);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $unassigned_count++;
                    
                    // Also delete any exam attempts for this assignment
                    $delete_attempts = $conn->prepare("DELETE FROM exam_attempts WHERE exam_id = ? AND app_id = ?");
                    $delete_attempts->bind_param("ii", $exam_id, $app_id);
                    $delete_attempts->execute();
                }
            }
            
            echo json_encode(['success' => true, 'message' => "Exam unassigned from {$unassigned_count} applicants"]);
            break;
            
        case 'start-exam':
            // Start exam attempt
            if (!isset($data['assignment_id']) || !isset($data['app_id']) || !isset($data['exam_id'])) {
                $missing = [];
                if (!isset($data['assignment_id'])) $missing[] = 'assignment_id';
                if (!isset($data['app_id'])) $missing[] = 'app_id';
                if (!isset($data['exam_id'])) $missing[] = 'exam_id';
                throw new Exception('Missing required fields: ' . implode(', ', $missing), 400);
            }
            
            $assignment_id = intval($data['assignment_id']);
            $app_id = intval($data['app_id']);
            $exam_id = intval($data['exam_id']);
            
            // Check if assignment is still active
            $check_stmt = $conn->prepare("SELECT status FROM exam_assignments WHERE id = ?");
            $check_stmt->bind_param("i", $assignment_id);
            $check_stmt->execute();
            $assignment = $check_stmt->get_result()->fetch_assoc();
            
            if (!$assignment || $assignment['status'] === 'Unassigned') {
                throw new Exception('This exam assignment is no longer available', 403);
            }
            
            // Check if attempt already exists
            $stmt = $conn->prepare("SELECT id FROM exam_attempts WHERE assignment_id = ?");
            $stmt->bind_param("i", $assignment_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing attempt
                $stmt = $conn->prepare("UPDATE exam_attempts SET status = 'In Progress', started_at = NOW() WHERE assignment_id = ?");
                $stmt->bind_param("i", $assignment_id);
            } else {
                // Create new attempt
                $stmt = $conn->prepare("INSERT INTO exam_attempts (assignment_id, app_id, exam_id, status, started_at, current_question_index, answers, time_elapsed) VALUES (?, ?, ?, 'In Progress', NOW(), 0, '{}', 0)");
                $stmt->bind_param("iii", $assignment_id, $app_id, $exam_id);
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to start exam: ' . $stmt->error, 500);
            }
            
            echo json_encode(['success' => true, 'message' => 'Exam started successfully']);
            break;
            
        case 'save-progress':
            // Save exam progress for resuming later
            if (!isset($data['assignment_id'])) {
                throw new Exception('Assignment ID is required', 400);
            }
            
            $assignment_id = intval($data['assignment_id']);
            $app_id = intval($data['app_id'] ?? 0);
            $current_question_index = isset($data['current_question_index']) ? intval($data['current_question_index']) : 0;
            $answers = isset($data['answers']) ? json_encode($data['answers']) : '{}';
            $time_elapsed = isset($data['time_elapsed']) ? intval($data['time_elapsed']) : 0;
            
            // Check if assignment is still active and not completed
            $check_stmt = $conn->prepare("SELECT status, exam_id FROM exam_assignments WHERE id = ?");
            $check_stmt->bind_param("i", $assignment_id);
            $check_stmt->execute();
            $assignment = $check_stmt->get_result()->fetch_assoc();
            
            if (!$assignment || $assignment['status'] === 'Unassigned') {
                // Silent fail for unassigned exams to prevent errors during auto-save
                echo json_encode(['success' => false, 'message' => 'Assignment no longer available']);
                return;
            }
            
            // Don't save progress if exam is already completed
            if ($assignment['status'] === 'Completed') {
                echo json_encode(['success' => false, 'message' => 'Exam already completed']);
                return;
            }
            
            // Check if attempt exists
            $stmt = $conn->prepare("SELECT id FROM exam_attempts WHERE assignment_id = ?");
            $stmt->bind_param("i", $assignment_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing attempt only if it's not completed
                $stmt = $conn->prepare("
                    UPDATE exam_attempts 
                    SET current_question_index = ?, answers = ?, time_elapsed = ?, status = 'In Progress'
                    WHERE assignment_id = ? AND status != 'Completed'
                ");
                $stmt->bind_param("isii", $current_question_index, $answers, $time_elapsed, $assignment_id);
            } else {
                // Create new attempt if needed
                $exam_id = $assignment['exam_id'];
                $stmt = $conn->prepare("
                    INSERT INTO exam_attempts (assignment_id, app_id, exam_id, current_question_index, answers, time_elapsed, status, started_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'In Progress', NOW())
                ");
                $stmt->bind_param("iiiisi", $assignment_id, $app_id, $exam_id, $current_question_index, $answers, $time_elapsed);
            }
            
            if (!$stmt->execute()) {
                error_log('Failed to save progress: ' . $stmt->error);
                echo json_encode(['success' => false, 'message' => 'Failed to save progress']);
                return;
            }
            
            echo json_encode(['success' => true, 'message' => 'Progress saved successfully']);
            break;
            
        case 'submit-exam':
            // Submit exam answers
            if (!isset($data['assignment_id'])) {
                throw new Exception('Assignment ID is required', 400);
            }
            
            $assignment_id = $data['assignment_id'];
            $answers = isset($data['answers']) ? json_encode($data['answers']) : '[]';
            $total_score = isset($data['total_score']) ? floatval($data['total_score']) : 0;
            $max_score = isset($data['max_score']) ? floatval($data['max_score']) : 100;
            $time_taken = isset($data['time_taken']) ? intval($data['time_taken']) : 0;
            
            // Check if assignment is still active
            $check_stmt = $conn->prepare("SELECT status FROM exam_assignments WHERE id = ?");
            $check_stmt->bind_param("i", $assignment_id);
            $check_stmt->execute();
            $assignment = $check_stmt->get_result()->fetch_assoc();
            
            if (!$assignment || $assignment['status'] === 'Unassigned') {
                throw new Exception('This exam assignment is no longer available', 403);
            }
            
            $stmt = $conn->prepare("
                UPDATE exam_attempts 
                SET answers = ?, total_score = ?, max_score = ?, time_taken = ?, 
                    completed_at = NOW(), status = 'Completed' 
                WHERE assignment_id = ?
            ");
            $stmt->bind_param("sddii", $answers, $total_score, $max_score, $time_taken, $assignment_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to submit exam: ' . $stmt->error, 500);
            }
            
            // Update assignment status
            $stmt = $conn->prepare("UPDATE exam_assignments SET status = 'Completed' WHERE id = ?");
            $stmt->bind_param("i", $assignment_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to update assignment status: ' . $stmt->error, 500);
            }
            
            echo json_encode(['success' => true, 'message' => 'Exam submitted successfully']);
            break;
            
        default:
            throw new Exception('Endpoint not found', 404);
    }
}

function handlePut($endpoint, $data) {
    global $conn;
    
    switch ($endpoint) {
        case 'exams':
            // Update exam
            $stmt = $conn->prepare("UPDATE examinations SET title = ?, duration = ?, questions = ?, status = ? WHERE id = ?");
            $questions_json = json_encode($data['questions']);
            $stmt->bind_param("sissi", $data['title'], $data['duration'], $questions_json, $data['status'], $data['id']);
            $stmt->execute();
            
            echo json_encode(['message' => 'Exam updated successfully']);
            break;
            
        case 'score':
            // Update exam score
            $attempt_id = $data['attempt_id'];
            $total_score = $data['total_score'];
            $answers = isset($data['answers']) ? json_encode($data['answers']) : null;
            
            if ($answers) {
                $stmt = $conn->prepare("UPDATE exam_attempts SET total_score = ?, answers = ? WHERE id = ?");
                $stmt->bind_param("dsi", $total_score, $answers, $attempt_id);
            } else {
                $stmt = $conn->prepare("UPDATE exam_attempts SET total_score = ? WHERE id = ?");
                $stmt->bind_param("di", $total_score, $attempt_id);
            }
            
            $stmt->execute();
            echo json_encode(['message' => 'Score updated successfully']);
            break;
            
        default:
            throw new Exception('Endpoint not found', 404);
    }
}

function handleDelete($endpoint, $params) {
    global $conn;
    
    switch ($endpoint) {
        case 'exams':
            // Delete exam
            if (!isset($params['id'])) {
                throw new Exception('Exam ID required', 400);
            }
            
            $exam_id = intval($params['id']);
            
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Delete related exam attempts first
                $stmt = $conn->prepare("DELETE FROM exam_attempts WHERE exam_id = ?");
                $stmt->bind_param("i", $exam_id);
                $stmt->execute();
                
                // Delete exam assignments
                $stmt = $conn->prepare("DELETE FROM exam_assignments WHERE exam_id = ?");
                $stmt->bind_param("i", $exam_id);
                $stmt->execute();
                
                // Delete the exam itself
                $stmt = $conn->prepare("DELETE FROM examinations WHERE id = ?");
                $stmt->bind_param("i", $exam_id);
                $stmt->execute();
                
                $conn->commit();
                echo json_encode(['message' => 'Exam deleted successfully']);
            } catch (Exception $e) {
                $conn->rollback();
                throw new Exception('Failed to delete exam: ' . $e->getMessage(), 500);
            }
            break;
            
        default:
            throw new Exception('Endpoint not found', 404);
    }
}

$conn->close();
?>