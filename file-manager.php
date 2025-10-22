<?php
// difsysapi/file-manager.php

// Prevent any output before headers
ob_start();

// Set error handling to prevent HTML output
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Function to send JSON response and exit
function sendJsonResponse($data, $status_code = 200) {
    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

// Function to send error response
function sendErrorResponse($message, $status_code = 400) {
    sendJsonResponse([
        'success' => false,
        'message' => $message
    ], $status_code);
}

// Include database connection with error handling
try {
    require_once 'db_connection.php';
    if (!isset($conn) || !$conn) {
        throw new Exception('Database connection failed');
    }
} catch (Exception $e) {
    sendErrorResponse('Database connection error: ' . $e->getMessage(), 500);
}

// Helper function to get authenticated user
function getAuthenticatedUser($conn) {
    $app_id = null;
    
    if (isset($_POST['app_id'])) {
        $app_id = intval($_POST['app_id']);
    } else if (isset($_GET['app_id'])) {
        $app_id = intval($_GET['app_id']);
    }
    
    if (!$app_id) {
        throw new Exception('Missing user ID');
    }

    $stmt = $conn->prepare("SELECT id, firstName, lastName, email, role FROM useraccounts WHERE id = ?");
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $app_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('User not found');
    }
    
    return $result->fetch_assoc();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';

    // Debug information
    if ($action === 'debug') {
        sendJsonResponse([
            'success' => true,
            'message' => 'API is working',
            'method' => $method,
            'action' => $action,
            'get' => $_GET,
            'post' => $_POST,
            'files' => $_FILES,
            'db_connected' => isset($conn) && $conn ? true : false
        ]);
    }

    switch ($method . ':' . $action) {
        case 'POST:upload':
            handleFileUpload($conn);
            break;
        case 'GET:files':
            getUploadedFiles($conn);
            break;
        case 'GET:file':
            getFileContent($conn);
            break;
        case 'DELETE:delete':
            deleteFile($conn);
            break;
        default:
            throw new Exception('Invalid action: ' . $method . ':' . $action);
    }

} catch (Exception $e) {
    sendErrorResponse($e->getMessage());
}

function handleFileUpload($conn) {
    try {
        // Validate input
        if (!isset($_POST['app_id']) || !isset($_POST['type_file']) || !isset($_FILES['file'])) {
            throw new Exception('Missing required fields');
        }

        $app_id = intval($_POST['app_id']);
        $type_file = trim($_POST['type_file']);
        $file = $_FILES['file'];

        // Authenticate user
        $user = getAuthenticatedUser($conn);

        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit',
                UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit',
                UPLOAD_ERR_PARTIAL => 'File upload was interrupted',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
            ];
            $error_message = isset($error_messages[$file['error']]) ? $error_messages[$file['error']] : 'Unknown upload error';
            throw new Exception($error_message);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Invalid file upload');
        }

        // Check file type
        $file_info = finfo_open(FILEINFO_MIME_TYPE);
        if (!$file_info) {
            throw new Exception('Unable to detect file type');
        }
        
        $file_type = finfo_file($file_info, $file['tmp_name']);
        finfo_close($file_info);
        
        if ($file_type !== 'application/pdf') {
            throw new Exception('Only PDF files are allowed. Detected type: ' . $file_type);
        }

        // Check file size (10MB max)
        $max_size = 10 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            throw new Exception('File size exceeds 10MB limit');
        }

        // Read file content
        $file_content = file_get_contents($file['tmp_name']);
        if ($file_content === false) {
            throw new Exception('Failed to read file');
        }

        // Start transaction
        $conn->autocommit(false);

        try {
            // Ensure the user has an applicant record (but don't duplicate it)
            $stmt = $conn->prepare("SELECT id FROM applicantlist WHERE app_id = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Database prepare error: ' . $conn->error);
            }
            
            $stmt->bind_param("i", $app_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                // Create base applicant record (without file data)
                $stmt = $conn->prepare("INSERT INTO applicantlist (app_id, firstName, lastName, email, role, status, created_at) 
                                       SELECT id, firstName, lastName, email, role, 'pending', NOW() 
                                       FROM useraccounts WHERE id = ?");
                if (!$stmt) {
                    throw new Exception('Database prepare error: ' . $conn->error);
                }
                
                $stmt->bind_param("i", $app_id);
                if (!$stmt->execute()) {
                    throw new Exception('Failed to create applicant record: ' . $conn->error);
                }
            }

            // Check if this file type already exists in applicant_files table
            $stmt = $conn->prepare("SELECT id FROM applicant_files WHERE app_id = ? AND file_type = ?");
            if (!$stmt) {
                throw new Exception('Database prepare error: ' . $conn->error);
            }
            
            $stmt->bind_param("is", $app_id, $type_file);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // Update existing file
                $file_id = $result->fetch_assoc()['id'];
                $stmt = $conn->prepare("UPDATE applicant_files 
                                       SET file_content = ?, file_name = ?, file_size = ?, mime_type = ?, updated_at = NOW() 
                                       WHERE id = ?");
                if (!$stmt) {
                    throw new Exception('Database prepare error: ' . $conn->error);
                }
                
                $stmt->bind_param("ssisi", $file_content, $file['name'], $file['size'], $file_type, $file_id);
                
                if (!$stmt->execute()) {
                    throw new Exception('Failed to update file: ' . $conn->error);
                }
            } else {
                // Insert new file record
                $stmt = $conn->prepare("INSERT INTO applicant_files 
                                       (app_id, file_type, file_content, file_name, file_size, mime_type, uploaded_at) 
                                       VALUES (?, ?, ?, ?, ?, ?, NOW())");
                if (!$stmt) {
                    throw new Exception('Database prepare error: ' . $conn->error);
                }
                
                $stmt->bind_param("isssss", $app_id, $type_file, $file_content, $file['name'], $file['size'], $file_type);
                
                if (!$stmt->execute()) {
                    throw new Exception('Failed to insert file record: ' . $conn->error);
                }
                $file_id = $conn->insert_id;
            }

            // Commit transaction
            $conn->commit();
            $conn->autocommit(true);

            sendJsonResponse([
                'success' => true,
                'message' => 'File uploaded successfully',
                'id' => $file_id,
                'type_file' => $type_file,
                'filename' => $file['name'],
                'size' => $file['size']
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            $conn->autocommit(true);
            throw $e;
        }

    } catch (Exception $e) {
        sendErrorResponse($e->getMessage());
    }
}

function getUploadedFiles($conn) {
    try {
        $app_id = isset($_GET['app_id']) ? intval($_GET['app_id']) : null;
        
        if (!$app_id) {
            throw new Exception('Missing app_id parameter');
        }

        // Verify user exists
        $stmt = $conn->prepare("SELECT id FROM useraccounts WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $app_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('User not found');
        }

        // Get uploaded files from applicant_files table
        $stmt = $conn->prepare("SELECT id, file_type, file_name, file_size, mime_type, uploaded_at, updated_at 
                               FROM applicant_files 
                               WHERE app_id = ? 
                               ORDER BY updated_at DESC");
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $app_id);
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

        sendJsonResponse([
            'success' => true,
            'files' => $files,
            'count' => count($files)
        ]);

    } catch (Exception $e) {
        sendErrorResponse($e->getMessage());
    }
}

function getFileContent($conn) {
    try {
        $file_id = isset($_GET['id']) ? intval($_GET['id']) : null;
        $app_id = isset($_GET['app_id']) ? intval($_GET['app_id']) : null;
        $preview = isset($_GET['preview']) ? $_GET['preview'] : false;
        
        if (!$file_id || !$app_id) {
            throw new Exception('Missing file ID or app ID');
        }

        // Get file content from applicant_files table
        $stmt = $conn->prepare("SELECT file_content, file_name, file_type, mime_type 
                               FROM applicant_files 
                               WHERE id = ? AND app_id = ?");
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("ii", $file_id, $app_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            throw new Exception('File not found');
        }

        $row = $result->fetch_assoc();
        
        if (!$row['file_content']) {
            throw new Exception('File content not found');
        }

        // If preview is requested, return JSON with base64 content
        if ($preview) {
            // Clear any previous output
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Return JSON response for preview
            sendJsonResponse([
                'id' => $file_id,
                'file_name' => $row['file_name'],
                'file_type' => $row['file_type'],
                'mime_type' => $row['mime_type'],
                'content' => base64_encode($row['file_content'])
            ]);
        } else {
            // Clear any previous output and set headers for file display/download
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            header('Content-Type: ' . $row['mime_type']);
            header('Content-Disposition: inline; filename="' . $row['file_name'] . '"');
            header('Content-Length: ' . strlen($row['file_content']));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            
            echo $row['file_content'];
            exit;
        }

    } catch (Exception $e) {
        sendErrorResponse($e->getMessage());
    }
}

function deleteFile($conn) {
    try {
        // Get request body for DELETE method
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['id']) || !isset($input['app_id'])) {
            throw new Exception('Missing required fields');
        }

        $file_id = intval($input['id']);
        $app_id = intval($input['app_id']);

        // Verify user exists
        $stmt = $conn->prepare("SELECT id FROM useraccounts WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $app_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('User not found');
        }

        // Delete the file from applicant_files table
        $stmt = $conn->prepare("DELETE FROM applicant_files WHERE id = ? AND app_id = ?");
        if (!$stmt) {
            throw new Exception('Database prepare error: ' . $conn->error);
        }
        
        $stmt->bind_param("ii", $file_id, $app_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to delete file: ' . $conn->error);
        }

        if ($stmt->affected_rows === 0) {
            throw new Exception('File not found or already deleted');
        }

        sendJsonResponse([
            'success' => true,
            'message' => 'File deleted successfully'
        ]);

    } catch (Exception $e) {
        sendErrorResponse($e->getMessage());
    }
}

// Close database connection
if (isset($conn)) {
    $conn->close();
}
?>