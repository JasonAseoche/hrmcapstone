<?php
// employee-file-manager.php - File management system for employees

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
include 'db_connection.php';

// Get the action parameter
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'upload':
        handleFileUpload();
        break;
    case 'update_type':      // KEEP THIS
        handleFileTypeUpdate();
        break;
    case 'files':
        getEmployeeFiles();
        break;
    case 'file':
        serveFile();
        break;
    case 'delete':
        deleteFile();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function handleFileUpload() {
    global $conn;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            'success' => false,
            'message' => 'Only POST requests allowed for upload'
        ]);
        return;
    }
    
    // Validate required fields
    if (!isset($_POST['emp_id']) || !isset($_POST['type_file']) || !isset($_FILES['file'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Missing required fields: emp_id, type_file, or file'
        ]);
        return;
    }
    
    $emp_id = intval($_POST['emp_id']);
    $file_type = trim($_POST['type_file']);
    $file = $_FILES['file'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode([
            'success' => false,
            'message' => 'File upload error: ' . $file['error']
        ]);
        return;
    }
    
    // Validate file type
    if ($file['type'] !== 'application/pdf' && !str_ends_with(strtolower($file['name']), '.pdf')) {
        echo json_encode([
            'success' => false,
            'message' => 'Only PDF files are allowed'
        ]);
        return;
    }
    
    // Validate file size (10MB max)
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode([
            'success' => false,
            'message' => 'File size must be less than 10MB'
        ]);
        return;
    }
    
    try {
        // Start transaction
        $conn->autocommit(FALSE);
        
        // Check if file type already exists for this employee
        $check_sql = "SELECT id FROM employee_files WHERE emp_id = ? AND file_type = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("is", $emp_id, $file_type);
        $check_stmt->execute();
        $existing_result = $check_stmt->get_result();
        
        if ($existing_result->num_rows > 0) {
            // Update existing file
            $existing_file = $existing_result->fetch_assoc();
            $file_content = file_get_contents($file['tmp_name']);
            
            $update_sql = "UPDATE employee_files SET 
                          file_content = ?, 
                          file_name = ?, 
                          file_size = ?, 
                          mime_type = ?,
                          updated_at = CURRENT_TIMESTAMP
                          WHERE id = ?";
            
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ssisi", 
                $file_content, 
                $file['name'], 
                $file['size'], 
                $file['type'],
                $existing_file['id']
            );
            
            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update file: " . $update_stmt->error);
            }
            
            $file_id = $existing_file['id'];
            $update_stmt->close();
        } else {
            // Insert new file
            $file_content = file_get_contents($file['tmp_name']);
            
            $insert_sql = "INSERT INTO employee_files (emp_id, file_type, file_content, file_name, file_size, mime_type) 
                          VALUES (?, ?, ?, ?, ?, ?)";
            
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("isssss", 
                $emp_id, 
                $file_type, 
                $file_content, 
                $file['name'], 
                $file['size'], 
                $file['type']
            );
            
            if (!$insert_stmt->execute()) {
                throw new Exception("Failed to insert file: " . $insert_stmt->error);
            }
            
            $file_id = $conn->insert_id;
            $insert_stmt->close();
        }
        
        $check_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'id' => $file_id
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        
        echo json_encode([
            'success' => false,
            'message' => 'Error uploading file: ' . $e->getMessage()
        ]);
    } finally {
        $conn->autocommit(TRUE);
    }
}

function handleFileUpdate() {
    global $conn;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Only POST requests allowed']);
        return;
    }
    
    if (!isset($_POST['emp_id']) || !isset($_POST['type_file']) || !isset($_POST['update_id']) || !isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    $emp_id = intval($_POST['emp_id']);
    $file_type = trim($_POST['type_file']);
    $update_id = intval($_POST['update_id']);
    $file = $_FILES['file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error']);
        return;
    }
    
    if ($file['type'] !== 'application/pdf') {
        echo json_encode(['success' => false, 'message' => 'Only PDF files allowed']);
        return;
    }
    
    if ($file['size'] > 10 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File too large']);
        return;
    }
    
    try {
        $conn->autocommit(FALSE);
        
        $file_content = file_get_contents($file['tmp_name']);
        
        $sql = "UPDATE employee_files SET 
                file_type = ?, file_content = ?, file_name = ?, 
                file_size = ?, mime_type = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND emp_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssisii", $file_type, $file_content, $file['name'], 
                         $file['size'], $file['type'], $update_id, $emp_id);
        
        if ($stmt->execute()) {
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'File updated successfully']);
        } else {
            throw new Exception('Update failed');
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } finally {
        $conn->autocommit(TRUE);
    }
}

function handleFileTypeUpdate() {
    global $conn;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Only POST requests allowed']);
        return;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || !isset($input['emp_id']) || !isset($input['type_file'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        return;
    }
    
    $file_id = intval($input['id']);
    $emp_id = intval($input['emp_id']);
    $file_type = trim($input['type_file']);
    
    try {
        $sql = "UPDATE employee_files SET file_type = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ? AND emp_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $file_type, $file_id, $emp_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Type updated successfully']);
        } else {
            throw new Exception('Update failed');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getEmployeeFiles() {
    global $conn;
    
    if (!isset($_GET['emp_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Employee ID is required'
        ]);
        return;
    }
    
    $emp_id = intval($_GET['emp_id']);
    
    try {
        $sql = "SELECT id, file_type, file_name, file_size, mime_type, uploaded_at, updated_at 
                FROM employee_files 
                WHERE emp_id = ? 
                ORDER BY uploaded_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $emp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $files = [];
        while ($row = $result->fetch_assoc()) {
            $files[] = [
                'id' => $row['id'],
                'type_file' => $row['file_type'],
                'name' => $row['file_name'],
                'size' => $row['file_size'],
                'type' => $row['mime_type'],
                'uploaded_at' => $row['uploaded_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'files' => $files
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error fetching files: ' . $e->getMessage()
        ]);
    }
}

function serveFile() {
    global $conn;
    
    if (!isset($_GET['id']) || !isset($_GET['emp_id'])) {
        http_response_code(400);
        echo "File ID and Employee ID are required";
        return;
    }
    
    $file_id = intval($_GET['id']);
    $emp_id = intval($_GET['emp_id']);
    
    try {
        $sql = "SELECT file_content, file_name, mime_type 
                FROM employee_files 
                WHERE id = ? AND emp_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $file_id, $emp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            http_response_code(404);
            echo "File not found";
            return;
        }
        
        $file = $result->fetch_assoc();
        
        // Set appropriate headers
        header("Content-Type: " . $file['mime_type']);
        header("Content-Disposition: inline; filename=\"" . $file['file_name'] . "\"");
        header("Content-Length: " . strlen($file['file_content']));
        
        // Output file content
        echo $file['file_content'];
        
    } catch (Exception $e) {
        http_response_code(500);
        echo "Error serving file: " . $e->getMessage();
    }
}

function deleteFile() {
    global $conn;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
        echo json_encode([
            'success' => false,
            'message' => 'Only DELETE requests allowed'
        ]);
        return;
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id']) || !isset($input['emp_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'File ID and Employee ID are required'
        ]);
        return;
    }
    
    $file_id = intval($input['id']);
    $emp_id = intval($input['emp_id']);
    
    try {
        $sql = "DELETE FROM employee_files WHERE id = ? AND emp_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $file_id, $emp_id);
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'File deleted successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'File not found or access denied'
                ]);
            }
        } else {
            throw new Exception("Failed to delete file: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting file: ' . $e->getMessage()
        ]);
    }
}

// Close connection
if (isset($conn)) {
    $conn->close();
}
?>