<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

// Handle GET request - check if applicant has submitted application
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $userId = $_GET['user_id'] ?? '';
        
        if (empty($userId)) {
            sendResponse(false, 'User ID is required', ['hasApplied' => false]);
        }
        
        // Check if applicant exists in applicantlist table
        $stmt = $conn->prepare("SELECT id, position, status, resume_status FROM applicantlist WHERE app_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $applicant = $result->fetch_assoc();
            
            // Check if they have uploaded a resume based on resume_status column
            $hasApplied = ($applicant['resume_status'] === 'Uploaded');
            
            sendResponse(true, 'Status checked successfully', [
                'hasApplied' => $hasApplied,
                'applicationStatus' => $applicant['status'],
                'position' => $applicant['position'],
                'resumeStatus' => $applicant['resume_status']
            ]);
        } else {
            // No application found
            sendResponse(true, 'No application found', ['hasApplied' => false]);
        }
        
    } catch (Exception $e) {
        sendResponse(false, 'Error checking applicant status: ' . $e->getMessage(), ['hasApplied' => false]);
    }
}

// Close connection
$conn->close();
?>