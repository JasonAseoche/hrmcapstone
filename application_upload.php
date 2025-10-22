<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'db_connection.php';

// Function to send JSON response
function sendResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Function to get user data by ID
function getUserData($conn, $userId) {
    try {
        $stmt = $conn->prepare("SELECT firstName, lastName, email FROM applicantlist WHERE app_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

// Handle GET request - fetch hiring positions and user data
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get hiring positions
        $positionsQuery = "SELECT id, title FROM hiring_positions WHERE status = 'active' ORDER BY title ASC";
        $positionsResult = $conn->query($positionsQuery);
        
        $positions = [];
        if ($positionsResult->num_rows > 0) {
            while ($row = $positionsResult->fetch_assoc()) {
                $positions[] = $row;
            }
        }
        
        $responseData = ['positions' => $positions];
        
        // If user ID is provided, get user data
        if (isset($_GET['user_id'])) {
            $userId = intval($_GET['user_id']);
            $userData = getUserData($conn, $userId);
            $responseData['user'] = $userData;
        }
        
        sendResponse(true, 'Data retrieved successfully', $responseData);
        
    } catch (Exception $e) {
        sendResponse(false, 'Error retrieving data: ' . $e->getMessage());
    }
}

// Handle POST request - submit application
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $position = $_POST['position'] ?? '';
        $firstName = $_POST['firstName'] ?? '';
        $lastName = $_POST['lastName'] ?? '';
        $middleName = $_POST['middleName'] ?? '';
        $email = $_POST['email'] ?? '';
        $contactNumber = $_POST['contactNumber'] ?? '';
        $age = $_POST['age'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $citizenship = $_POST['citizenship'] ?? '';
        $birthday = $_POST['birthday'] ?? '';
        $address = $_POST['address'] ?? '';
        $userId = $_POST['user_id'] ?? '';
        
        // Validate required fields
        if (empty($position) || empty($firstName) || empty($lastName) || empty($email) || empty($userId)) {
            sendResponse(false, 'Missing required fields');
        }
        
        // Validate file upload
        if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
            sendResponse(false, 'Resume file is required');
        }
        
        $file = $_FILES['resume'];
        $allowedTypes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $maxFileSize = 10 * 1024 * 1024; // 10MB
        
        // Validate file type
        if (!in_array($file['type'], $allowedTypes)) {
            sendResponse(false, 'Invalid file type. Only PDF, DOC, and DOCX files are allowed');
        }
        
        // Validate file size
        if ($file['size'] > $maxFileSize) {
            sendResponse(false, 'File size too large. Maximum size is 10MB');
        }
        
        // Read file content
        $fileContent = file_get_contents($file['tmp_name']);
        if ($fileContent === false) {
            sendResponse(false, 'Error reading uploaded file');
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Check if applicant already exists
            $checkStmt = $conn->prepare("SELECT id FROM applicantlist WHERE app_id = ?");
            $checkStmt->bind_param("i", $userId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                // Update existing applicant and set resume_status to "Uploaded"
                $updateStmt = $conn->prepare("
                    UPDATE applicantlist SET 
                    firstName = ?, lastName = ?, middle_name = ?, email = ?, 
                    contact_number = ?, date_of_birth = ?, gender = ?, 
                    citizenship = ?, address = ?, position = ?, 
                    resume_status = 'Uploaded', status = 'pending', updated_at = CURRENT_TIMESTAMP
                    WHERE app_id = ?
                ");
                $updateStmt->bind_param("ssssssssssi", 
                    $firstName, $lastName, $middleName, $email, 
                    $contactNumber, $birthday, $gender, 
                    $citizenship, $address, $position, $userId
                );
                $updateStmt->execute();
            } else {
                // Insert new applicant with resume_status set to "Uploaded"
                $insertStmt = $conn->prepare("
                    INSERT INTO applicantlist 
                    (app_id, firstName, lastName, middle_name, email, contact_number, 
                     date_of_birth, gender, citizenship, address, position, role, resume_status, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Applicant', 'Uploaded', 'pending')
                ");
                $insertStmt->bind_param("issssssssss", 
                    $userId, $firstName, $lastName, $middleName, $email, 
                    $contactNumber, $birthday, $gender, $citizenship, $address, $position
                );
                $insertStmt->execute();
            }
            
            // Handle file upload - check if file already exists
            $fileCheckStmt = $conn->prepare("SELECT id FROM applicant_files WHERE app_id = ? AND file_type = 'resume'");
            $fileCheckStmt->bind_param("i", $userId);
            $fileCheckStmt->execute();
            $fileCheckResult = $fileCheckStmt->get_result();
            
            if ($fileCheckResult->num_rows > 0) {
                // Update existing file
                $fileUpdateStmt = $conn->prepare("
                    UPDATE applicant_files SET 
                    file_content = ?, file_name = ?, file_size = ?, mime_type = ?, 
                    updated_at = CURRENT_TIMESTAMP
                    WHERE app_id = ? AND file_type = 'resume'
                ");
                $fileUpdateStmt->bind_param("bsisi", $fileContent, $file['name'], $file['size'], $file['type'], $userId);
                $fileUpdateStmt->send_long_data(0, $fileContent);
                $fileUpdateStmt->execute();
            } else {
                // Insert new file
                $fileInsertStmt = $conn->prepare("
                    INSERT INTO applicant_files 
                    (app_id, file_type, file_content, file_name, file_size, mime_type) 
                    VALUES (?, 'resume', ?, ?, ?, ?)
                ");
                $fileInsertStmt->bind_param("ibsis", $userId, $fileContent, $file['name'], $file['size'], $file['type']);
                $fileInsertStmt->send_long_data(1, $fileContent);
                $fileInsertStmt->execute();
            }
            
            // Commit transaction
            $conn->commit();
            
            sendResponse(true, 'Application submitted successfully');
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error submitting application: ' . $e->getMessage());
    }
}

// Close connection
$conn->close();
?>