<?php
require_once 'db_connection.php';

// Include PHPMailer autoloader
require_once 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

class ApplicantTracker {
    private $conn;
    
    // Email configuration
    private $emailConfig = [
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_username' => 'icongko09@gmail.com',
        'smtp_password' => 'swim vubm bksx dtzt',
        'from_email' => 'icongko09@gmail.com',
        'from_name' => 'HR Department'
    ];
    
    // Base URL for profile images
    private $baseImageUrl = 'https://www.difsysinc.com/difsysapi/uploads/';
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_GET['action'] ?? '';
        
        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet($action);
                    break;
                case 'POST':
                    $this->handlePost($action);
                    break;
                case 'PUT':
                    $this->handlePut($action);
                    break;
                default:
                    $this->sendError('Method not allowed', 405);
            }
        } catch (Exception $e) {
            $this->sendError($e->getMessage(), 500);
        }
    }
    
    private function handleGet($action) {
        switch ($action) {
            case 'applicants':
                $this->getApplicants();
                break;
            case 'applicant_details':
                $this->getApplicantDetails();
                break;
            case 'applicant_files':
                $this->getApplicantFiles();
                break;
            case 'download_file':
                $this->downloadFile();
                break;
            case 'stats':
                $this->getStats();
                break;
            case 'test_email':
                $this->testEmail();
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }
    
    private function handlePost($action) {
        switch ($action) {
            case 'schedule_interview':
                $this->scheduleInterview();
                break;
            case 'update_status':
                $this->updateStatus();
                break;
            case 'approve_with_requirements':  // ADD THIS LINE
                $this->approveWithRequirements();  // ADD THIS LINE
                break;  // ADD THIS LINE
            case 'test_email':
                $this->testEmail();
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }
    
    private function handlePut($action) {
        switch ($action) {
            case 'update_applicant':
                $this->updateApplicant();
                break;
            default:
                $this->sendError('Invalid action', 400);
        }
    }
    
    // Test email method
    public function testEmail() {
        try {
            // Check if PHPMailer class exists
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                throw new Exception('PHPMailer is not installed or not loaded properly');
            }
            
            $mail = new PHPMailer(true);
            
            // Enable verbose debug output
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->Debugoutput = function($str, $level) {
                error_log("SMTP Debug: $str");
            };
            
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->emailConfig['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->emailConfig['smtp_username'];
            $mail->Password   = $this->emailConfig['smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->emailConfig['smtp_port'];
            
            // Recipients
            $mail->setFrom($this->emailConfig['from_email'], $this->emailConfig['from_name']);
            $mail->addAddress($this->emailConfig['smtp_username'], 'Test User');
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Test Email from Applicant Tracking System';
            $mail->Body    = '<h1>Test Email</h1><p>If you receive this, PHPMailer is working correctly!</p><p>Timestamp: ' . date('Y-m-d H:i:s') . '</p>';
            
            $result = $mail->send();
            
            $this->sendSuccess([
                'message' => 'Test email sent successfully!',
                'email_sent' => true,
                'timestamp' => date('Y-m-d H:i:s'),
                'to' => $this->emailConfig['smtp_username']
            ]);
            
        } catch (Exception $e) {
            error_log("Test Email Error: " . $e->getMessage());
            $this->sendError('Test email failed: ' . $e->getMessage(), 500);
        }
    }
    
    public function getApplicants() {
        $search = $_GET['search'] ?? '';
        $status = $_GET['status'] ?? 'all';
        
        $sql = "SELECT 
                    a.id,
                    a.app_id,
                    a.firstName,
                    a.lastName,
                    a.email,
                    a.position,
                    a.contact_number as phone,
                    a.address as location,
                    a.status,
                    a.created_at as appliedDate,
                    u.profile_image,
                    a.date_of_birth,
                    a.civil_status,
                    a.gender,
                    a.citizenship,
                    a.objective,
                    a.middle_name,
                    a.height,
                    a.weight
                FROM applicantlist a
                LEFT JOIN useraccounts u ON a.app_id = u.id
                WHERE u.role = 'Applicant'";
        
        $conditions = [];
        $params = [];
        $types = '';
        
        if (!empty($search)) {
            $conditions[] = "(a.firstName LIKE ? OR a.lastName LIKE ? OR a.email LIKE ? OR a.position LIKE ?)";
            $searchParam = "%$search%";
            $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
            $types .= 'ssss';
        }
        
        if ($status !== 'all' && $status !== '') {
            $conditions[] = "a.status = ?";
            $params[] = $status;
            $types .= 's';
        }
        
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        $sql .= " ORDER BY a.created_at DESC";
        
        $stmt = $this->conn->prepare($sql);
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        $applicants = [];
        while ($row = $result->fetch_assoc()) {
            $applicants[] = [
                'id' => $row['id'],
                'app_id' => $row['app_id'],
                'name' => trim($row['firstName'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['lastName']),
                'firstName' => $row['firstName'],
                'lastName' => $row['lastName'],
                'middleName' => $row['middle_name'],
                'position' => $row['position'] ?? 'Business Analyst',
                'email' => $row['email'],
                'phone' => $row['phone'] ?? 'N/A',
                'location' => $row['location'] ?? 'N/A',
                'status' => $this->mapStatus($row['status']),
                'appliedDate' => $row['appliedDate'],
                'avatar' => $this->getProfileImageUrl($row['profile_image']),
                'dateOfBirth' => $row['date_of_birth'],
                'civilStatus' => $row['civil_status'],
                'gender' => $row['gender'],
                'citizenship' => $row['citizenship'],
                'height' => $row['height'],
                'weight' => $row['weight'],
                'objective' => $row['objective']
            ];
        }
        
        $this->sendSuccess($applicants);
    }
    
    public function getApplicantDetails() {
        $app_id = $_GET['app_id'] ?? null;
        
        if (!$app_id) {
            $this->sendError('App ID is required', 400);
            return;
        }
        
        $sql = "SELECT 
                    a.*,
                    u.profile_image
                FROM applicantlist a
                LEFT JOIN useraccounts u ON a.app_id = u.id
                WHERE a.app_id = ? AND u.role = 'Applicant'";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $app_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $applicant = $result->fetch_assoc();
        
        if (!$applicant) {
            $this->sendError('Applicant not found', 404);
            return;
        }
        
        // Get files
        $filesSql = "SELECT id, file_type, file_name, file_size, mime_type, uploaded_at 
                     FROM applicant_files 
                     WHERE app_id = ?";
        $filesStmt = $this->conn->prepare($filesSql);
        $filesStmt->bind_param('i', $app_id);
        $filesStmt->execute();
        $filesResult = $filesStmt->get_result();
        
        $files = [];
        while ($file = $filesResult->fetch_assoc()) {
            $files[] = $file;
        }
        
        $formattedApplicant = [
            'id' => $applicant['id'],
            'app_id' => $applicant['app_id'],
            'firstName' => $applicant['firstName'],
            'lastName' => $applicant['lastName'],
            'middleName' => $applicant['middle_name'],
            'name' => trim($applicant['firstName'] . ' ' . ($applicant['middle_name'] ?? '') . ' ' . $applicant['lastName']),
            'email' => $applicant['email'],
            'position' => $applicant['position'] ?? 'Business Analyst',
            'phone' => $applicant['contact_number'],
            'location' => $applicant['address'],
            'status' => $this->mapStatus($applicant['status']),
            'appliedDate' => $applicant['created_at'],
            'avatar' => $this->getProfileImageUrl($applicant['profile_image']),
            'dateOfBirth' => $applicant['date_of_birth'],
            'civilStatus' => $applicant['civil_status'],
            'gender' => $applicant['gender'],
            'citizenship' => $applicant['citizenship'],
            'height' => $applicant['height'],
            'weight' => $applicant['weight'],
            'objective' => $applicant['objective'],
            'education' => !empty($applicant['education']) ? json_decode($applicant['education'], true) : null,
            'workExperience' => !empty($applicant['work_experience']) ? json_decode($applicant['work_experience'], true) : null,
            'skills' => !empty($applicant['skills']) ? json_decode($applicant['skills'], true) : null,
            'traits' => !empty($applicant['traits']) ? json_decode($applicant['traits'], true) : null,
            'files' => $files
        ];
        
        $this->sendSuccess($formattedApplicant);
    }
    
    public function getApplicantFiles() {
        $app_id = $_GET['app_id'] ?? null;
        
        if (!$app_id) {
            $this->sendError('App ID is required', 400);
            return;
        }
        
        $sql = "SELECT id, file_type, file_name, file_size, mime_type, uploaded_at 
                FROM applicant_files 
                WHERE app_id = ?
                ORDER BY uploaded_at DESC";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $app_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $files = [];
        while ($file = $result->fetch_assoc()) {
            $files[] = $file;
        }
        
        $this->sendSuccess($files);
    }
    
    public function downloadFile() {
        $file_id = $_GET['file_id'] ?? null;
        
        if (!$file_id) {
            $this->sendError('File ID is required', 400);
            return;
        }
        
        $sql = "SELECT file_content, file_name, mime_type 
                FROM applicant_files 
                WHERE id = ?";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $file_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $file = $result->fetch_assoc();
        
        if (!$file) {
            $this->sendError('File not found', 404);
            return;
        }
        
        // Set headers for file download
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: inline; filename="' . $file['file_name'] . '"');
        header('Content-Length: ' . strlen($file['file_content']));
        
        echo $file['file_content'];
        exit;
    }
    
    public function scheduleInterview() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $app_id = $input['app_id'] ?? null;
        $date = $input['date'] ?? null;
        $time = $input['time'] ?? null;
        
        if (!$app_id || !$date || !$time) {
            $this->sendError('App ID, date, and time are required', 400);
            return;
        }
        
        try {
            $this->conn->begin_transaction();
            
            // Update applicant status to scheduled
            $updateSql = "UPDATE applicantlist SET 
             status = 'scheduled',
             interview_date = ?,
             interview_time = ?,
             updated_at = CURRENT_TIMESTAMP
             WHERE app_id = ?";

            $stmt = $this->conn->prepare($updateSql);
            $stmt->bind_param('ssi', $date, $time, $app_id);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                throw new Exception('Applicant not found');
            }
            
            // Get applicant details for email
            $applicantSql = "SELECT firstName, lastName, email, position FROM applicantlist WHERE app_id = ?";
            $applicantStmt = $this->conn->prepare($applicantSql);
            $applicantStmt->bind_param('i', $app_id);
            $applicantStmt->execute();
            $applicantResult = $applicantStmt->get_result();
            $applicant = $applicantResult->fetch_assoc();
            
            // Send email notification
            $emailSent = $this->sendInterviewEmail($applicant, $date, $time);
            
            $this->conn->commit();
            
            $this->sendSuccess([
                'message' => 'Interview scheduled successfully' . ($emailSent ? ' and email sent' : ' but email failed to send'),
                'applicant' => $applicant,
                'scheduled_date' => $date,
                'scheduled_time' => $time,
                'email_sent' => $emailSent
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollback();
            $this->sendError('Failed to schedule interview: ' . $e->getMessage(), 500);
        }
    }
    
    public function approveWithRequirements() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $app_id = $input['app_id'] ?? null;
        $requirements = $input['requirements'] ?? [];
        $comments = $input['comments'] ?? '';
        
        if (!$app_id || empty($requirements)) {
            $this->sendError('App ID and requirements are required', 400);
            return;
        }
        
        try {
            $this->conn->begin_transaction();
            
            // Get applicant details before update
            $applicantSql = "SELECT firstName, lastName, email, position, status as current_status FROM applicantlist WHERE app_id = ?";
            $applicantStmt = $this->conn->prepare($applicantSql);
            $applicantStmt->bind_param('i', $app_id);
            $applicantStmt->execute();
            $applicantResult = $applicantStmt->get_result();
            $applicant = $applicantResult->fetch_assoc();
            
            if (!$applicant) {
                throw new Exception('Applicant not found');
            }
            
            // Update applicant status to approved
            $updateSql = "UPDATE applicantlist SET 
                         status = 'approved',
                         updated_at = CURRENT_TIMESTAMP
                         WHERE app_id = ?";
            
            $stmt = $this->conn->prepare($updateSql);
            $stmt->bind_param('i', $app_id);
            $stmt->execute();
            
            // Store requirements for this applicant
            $requirementsJson = json_encode($requirements);
            
            // Check if requirements already exist for this applicant
            $checkSql = "SELECT id FROM applicant_requirements WHERE app_id = ?";
            $checkStmt = $this->conn->prepare($checkSql);
            $checkStmt->bind_param('i', $app_id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                // Update existing requirements
                $updateReqSql = "UPDATE applicant_requirements SET 
                                requirements = ?,
                                updated_at = CURRENT_TIMESTAMP
                                WHERE app_id = ?";
                $updateReqStmt = $this->conn->prepare($updateReqSql);
                $updateReqStmt->bind_param('si', $requirementsJson, $app_id);
                $updateReqStmt->execute();
            } else {
                // Insert new requirements
                $insertReqSql = "INSERT INTO applicant_requirements (app_id, requirements, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)";
                $insertReqStmt = $this->conn->prepare($insertReqSql);
                $insertReqStmt->bind_param('is', $app_id, $requirementsJson);
                $insertReqStmt->execute();
            }
            
            // Send approval email with requirements
            $emailSent = $this->sendApprovalWithRequirementsEmail($applicant, $requirements, $comments);
            
            $this->conn->commit();
            
            $this->sendSuccess([
                'message' => 'Application approved successfully' . ($emailSent ? ' and email sent' : ''),
                'applicant' => $applicant,
                'requirements' => $requirements,
                'email_sent' => $emailSent
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollback();
            $this->sendError('Failed to approve application: ' . $e->getMessage(), 500);
        }
    }

    private function sendApprovalWithRequirementsEmail($applicant, $requirements, $comments = '') {
        return $this->sendApprovalWithRequirementsEmailPHPMailer($applicant, $requirements, $comments);
    }
    
    private function sendApprovalWithRequirementsEmailPHPMailer($applicant, $requirements, $comments = '') {
        $mail = new PHPMailer(true);
    
        try {
            // Server settings (using your existing config)
            $mail->isSMTP();
            $mail->Host       = $this->emailConfig['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->emailConfig['smtp_username'];
            $mail->Password   = $this->emailConfig['smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->emailConfig['smtp_port'];
    
            // Recipients
            $mail->setFrom($this->emailConfig['from_email'], $this->emailConfig['from_name']);
            $mail->addAddress($applicant['email'], $applicant['firstName'] . ' ' . $applicant['lastName']);
    
            // Content
            $mail->isHTML(true);
            $mail->Subject = "Application Approved - Next Steps Required";
            $mail->Body = $this->getApprovalWithRequirementsEmailTemplate($applicant, $requirements, $comments);
    
            $mail->send();
            error_log("Approval with requirements email sent successfully to: " . $applicant['email']);
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
    
    private function getApprovalWithRequirementsEmailTemplate($applicant, $requirements, $comments = '') {
        $requirementsList = '';
        foreach ($requirements as $index => $requirement) {
            $requirementsList .= "<li style='margin-bottom: 8px; color: #374151;'>" . htmlspecialchars($requirement) . "</li>";
        }
        
        $message = "
        <html>
        <head>
            <title>Application Approved - Next Steps</title>
        </head>
        <body>
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #059669; border-bottom: 2px solid #059669; padding-bottom: 10px;'>
                    🎉 Application Approved!
                </h2>
                
                <p>Congratulations {$applicant['firstName']} {$applicant['lastName']}!</p>
                
                <p>We are pleased to inform you that your application for the position of <strong>{$applicant['position']}</strong> has been <strong style='color: #059669;'>APPROVED</strong>!</p>
                
                <div style='background-color: #d1fae5; border-left: 4px solid #059669; padding: 15px; margin: 20px 0;'>
                    <h3 style='margin: 0 0 15px 0; color: #065f46;'>Next Steps - Required Documents:</h3>
                    <p style='margin: 0 0 10px 0; color: #374151;'>To complete your application process, please upload the following documents through your applicant portal:</p>
                    <ol style='margin: 10px 0 0 20px; padding: 0;'>
                        {$requirementsList}
                    </ol>
                </div>
                
                <div style='background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0;'>
                    <h4 style='margin: 0 0 10px 0; color: #92400e;'>Important Instructions:</h4>
                    <ul style='margin: 0; padding-left: 20px; color: #374151;'>
                        <li>Please ensure all documents are clear and legible</li>
                        <li>Upload documents in PDF format only</li>
                        <li>Maximum file size: 10MB per document</li>
                        <li>Complete this process within 7 days</li>
                    </ul>
                </div>";
        
        if (!empty($comments)) {
            $message .= "
                <div style='background-color: #f8fafc; border-left: 4px solid #3b82f6; padding: 15px; margin: 20px 0;'>
                    <h4 style='margin: 0 0 10px 0; color: #1e40af;'>Additional Notes:</h4>
                    <p style='margin: 0; color: #374151;'>{$comments}</p>
                </div>";
        }
        
        $message .= "
                <p><strong>How to Upload:</strong></p>
                <ol style='color: #374151;'>
                    <li>Log in to your applicant portal</li>
                    <li>Navigate to the 'Upload Requirements' section</li>
                    <li>Select the document type and upload your file</li>
                    <li>Repeat for all required documents</li>
                </ol>
                
                <p>Once you have uploaded all required documents, our HR team will review them and contact you with further instructions.</p>
                
                <p>Welcome to our team!</p>
                
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0;'>
                    <p style='margin: 0;'>Best regards,</p>
                    <p style='margin: 5px 0 0 0;'><strong>Human Resources Department</strong></p>
                    <p style='margin: 0; color: #64748b; font-size: 14px;'>{$this->emailConfig['from_email']}</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $message;
    }

    public function updateStatus() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $app_id = $input['app_id'] ?? null;
        $status = $input['status'] ?? null;
        $comments = $input['comments'] ?? '';
        
        if (!$app_id || !$status) {
            $this->sendError('App ID and status are required', 400);
            return;
        }
        
        $validStatuses = ['pending', 'scheduled', 'approved', 'declined'];
        if (!in_array(strtolower($status), $validStatuses)) {
            $this->sendError('Invalid status', 400);
            return;
        }
        
        try {
            $this->conn->begin_transaction();
            
            // Get applicant details before update
            $applicantSql = "SELECT firstName, lastName, email, position, status as current_status FROM applicantlist WHERE app_id = ?";
            $applicantStmt = $this->conn->prepare($applicantSql);
            $applicantStmt->bind_param('i', $app_id);
            $applicantStmt->execute();
            $applicantResult = $applicantStmt->get_result();
            $applicant = $applicantResult->fetch_assoc();
            
            if (!$applicant) {
                throw new Exception('Applicant not found');
            }
            
            // Update applicant status
            $updateSql = "UPDATE applicantlist SET 
             status = ?,
             updated_at = CURRENT_TIMESTAMP
             WHERE app_id = ?";

            $stmt = $this->conn->prepare($updateSql);
            $statusLower = strtolower($status);
            $stmt->bind_param('si', $statusLower, $app_id);
            $stmt->execute();
            
            // Send status update email
            $emailSent = false;
            if ($applicant['current_status'] !== strtolower($status)) {
                $emailSent = $this->sendStatusUpdateEmail($applicant, $status, $comments);
            }
            
            $this->conn->commit();
            
            $this->sendSuccess([
                'message' => 'Status updated successfully' . ($emailSent ? ' and email sent' : ''),
                'applicant' => $applicant,
                'new_status' => $status,
                'email_sent' => $emailSent
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollback();
            $this->sendError('Failed to update status: ' . $e->getMessage(), 500);
        }
    }
    
    

    public function updateApplicant() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $app_id = $input['app_id'] ?? null;
        
        if (!$app_id) {
            $this->sendError('App ID is required', 400);
            return;
        }
        
        try {
            $this->conn->begin_transaction();
            
            // Build update query dynamically based on provided fields
            $updateFields = [];
            $params = [];
            $types = '';
            
            $allowedFields = [
                'firstName', 'lastName', 'middle_name', 'email', 'position', 
                'contact_number', 'address', 'date_of_birth', 'civil_status', 
                'gender', 'citizenship', 'height', 'weight', 'objective',
                'education', 'work_experience', 'skills', 'traits'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updateFields[] = "$field = ?";
                    
                    // Handle JSON fields
                    if (in_array($field, ['education', 'work_experience', 'skills', 'traits'])) {
                        $params[] = json_encode($input[$field]);
                    } else {
                        $params[] = $input[$field];
                    }
                    $types .= 's';
                }
            }
            
            if (empty($updateFields)) {
                $this->sendError('No valid fields to update', 400);
                return;
            }
            
            $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
            $params[] = $app_id;
            $types .= 'i';
            
            $sql = "UPDATE applicantlist SET " . implode(', ', $updateFields) . " WHERE app_id = ?";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                throw new Exception('Applicant not found or no changes made');
            }
            
            $this->conn->commit();
            
            $this->sendSuccess([
                'message' => 'Applicant updated successfully',
                'app_id' => $app_id
            ]);
            
        } catch (Exception $e) {
            $this->conn->rollback();
            $this->sendError('Failed to update applicant: ' . $e->getMessage(), 500);
        }
    }
    
    public function getStats() {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN a.status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN a.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                    SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN a.status = 'declined' THEN 1 ELSE 0 END) as declined
                FROM applicantlist a
                LEFT JOIN useraccounts u ON a.app_id = u.id
                WHERE u.role = 'Applicant'";
        
        $result = $this->conn->query($sql);
        $stats = $result->fetch_assoc();
        
        $this->sendSuccess($stats);
    }
    
    private function getProfileImageUrl($profileImage) {
        if (empty($profileImage)) {
            return 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=150&h=150&fit=crop&crop=face';
        }
        
        if (filter_var($profileImage, FILTER_VALIDATE_URL)) {
            return $profileImage;
        }
        
        $profileImage = ltrim($profileImage, '/');
        return $profileImage;
    }
    
    private function mapStatus($dbStatus) {
        $statusMap = [
            'pending' => 'New Applicant',
            'scheduled' => 'Scheduled',
            'approved' => 'Approved',
            'declined' => 'Declined'
        ];
        
        return $statusMap[strtolower($dbStatus)] ?? 'New Applicant';
    }
    
    private function sendInterviewEmail($applicant, $date, $time) {
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendInterviewEmailPHPMailer($applicant, $date, $time);
        } else {
            return $this->sendInterviewEmailBasic($applicant, $date, $time);
        }
    }
    
    private function sendInterviewEmailPHPMailer($applicant, $date, $time) {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->emailConfig['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->emailConfig['smtp_username'];
            $mail->Password   = $this->emailConfig['smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->emailConfig['smtp_port'];

            // Recipients
            $mail->setFrom($this->emailConfig['from_email'], $this->emailConfig['from_name']);
            $mail->addAddress($applicant['email'], $applicant['firstName'] . ' ' . $applicant['lastName']);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "Interview Scheduled - " . ($applicant['position'] ?? 'Position');
            
            $formattedDate = date('F j, Y', strtotime($date));
            $formattedTime = date('g:i A', strtotime($time));
            
            $mail->Body = $this->getInterviewEmailTemplate($applicant, $formattedDate, $formattedTime);

            $mail->send();
            error_log("Interview email sent successfully to: " . $applicant['email']);
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
    
    private function sendInterviewEmailBasic($applicant, $date, $time) {
        $to = $applicant['email'];
        $subject = "Interview Scheduled - " . ($applicant['position'] ?? 'Position');
        
        $formattedDate = date('F j, Y', strtotime($date));
        $formattedTime = date('g:i A', strtotime($time));
        
        $message = $this->getInterviewEmailTemplate($applicant, $formattedDate, $formattedTime);
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . $this->emailConfig['from_name'] . " <" . $this->emailConfig['from_email'] . ">" . "\r\n";
        
        $result = mail($to, $subject, $message, $headers);
        
        if ($result) {
            error_log("Interview email sent successfully to: " . $to);
        } else {
            error_log("Failed to send interview email to: " . $to);
        }
        
        return $result;
    }
    
    private function sendStatusUpdateEmail($applicant, $status, $comments = '') {
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendStatusUpdateEmailPHPMailer($applicant, $status, $comments);
        } else {
            return $this->sendStatusUpdateEmailBasic($applicant, $status, $comments);
        }
    }
    
    private function sendStatusUpdateEmailPHPMailer($applicant, $status, $comments = '') {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->emailConfig['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->emailConfig['smtp_username'];
            $mail->Password   = $this->emailConfig['smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->emailConfig['smtp_port'];

            // Recipients
            $mail->setFrom($this->emailConfig['from_email'], $this->emailConfig['from_name']);
            $mail->addAddress($applicant['email'], $applicant['firstName'] . ' ' . $applicant['lastName']);

            // Content
            $mail->isHTML(true);
            $statusText = ucfirst($status);
            $mail->Subject = "Application Status Update - " . $statusText;
            $mail->Body = $this->getStatusUpdateEmailTemplate($applicant, $status, $comments);

            $mail->send();
            error_log("Status update email sent successfully to: " . $applicant['email']);
            return true;
        } catch (Exception $e) {
            error_log("PHPMailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
    
    private function sendStatusUpdateEmailBasic($applicant, $status, $comments = '') {
        $to = $applicant['email'];
        $statusText = ucfirst($status);
        $subject = "Application Status Update - " . $statusText;
        
        $message = $this->getStatusUpdateEmailTemplate($applicant, $status, $comments);
        
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . $this->emailConfig['from_name'] . " <" . $this->emailConfig['from_email'] . ">" . "\r\n";
        
        $result = mail($to, $subject, $message, $headers);
        
        if ($result) {
            error_log("Status update email sent successfully to: " . $to);
        } else {
            error_log("Failed to send status update email to: " . $to);
        }
        
        return $result;
    }
    
    private function getInterviewEmailTemplate($applicant, $formattedDate, $formattedTime) {
        return "
        <html>
        <head>
            <title>Interview Scheduled</title>
        </head>
        <body>
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2563eb; border-bottom: 2px solid #2563eb; padding-bottom: 10px;'>
                    Interview Scheduled
                </h2>
                
                <p>Good Day {$applicant['lastName']},</p>
                
                <p>We are pleased to inform you that your application for the position of <strong>{$applicant['position']}</strong> has been reviewed, and we would like to schedule an interview with you.</p>
                
                <div style='background-color: #f8fafc; border-left: 4px solid #2563eb; padding: 15px; margin: 20px 0;'>
                    <h3 style='margin: 0 0 10px 0; color: #1e293b;'>Interview Details:</h3>
                    <p style='margin: 5px 0;'><strong>Position:</strong> {$applicant['position']}</p>
                </div>
                
                <p><strong>What to Expect:</strong></p>
                <ul>
                    <li>The interview will last approximately 45-60 minutes</li>
                    <li>Please bring a copy of your resume and any relevant documents</li>
                    <li>Be prepared to discuss your experience and qualifications</li>
                    <li>You will have the opportunity to ask questions about the role and company</li>
                </ul>
                
                <p><strong>What to Bring:</strong></p>
                <ul>
                    <li>Valid ID</li>
                    <li>Updated resume</li>
                    <li>Portfolio (if applicable)</li>
                    <li>List of references</li>
                </ul>
                
                <p>Please confirm your attendance by replying to this email or contacting our HR department.</p>
                
                <p>If you need to reschedule or have any questions, please don't hesitate to reach out to us.</p>
                
                <p>We look forward to meeting you!</p>
                
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0;'>
                    <p style='margin: 0;'>Best regards,</p>
                    <p style='margin: 5px 0 0 0;'><strong>Human Resources Department</strong></p>
                    <p style='margin: 0; color: #64748b; font-size: 14px;'>{$this->emailConfig['from_email']}</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    private function getStatusUpdateEmailTemplate($applicant, $status, $comments = '') {
        $statusText = ucfirst($status);
        
        $message = "
        <html>
        <head>
            <title>Application Status Update</title>
        </head>
        <body>
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2563eb; border-bottom: 2px solid #2563eb; padding-bottom: 10px;'>
                    Application Status Update
                </h2>
                
                <p>Dear {$applicant['firstName']} {$applicant['lastName']},</p>
                
                <p>We wanted to update you on the status of your application for the position of <strong>{$applicant['position']}</strong>.</p>
                
                <div style='background-color: #f8fafc; border-left: 4px solid #2563eb; padding: 15px; margin: 20px 0;'>
                    <h3 style='margin: 0 0 10px 0; color: #1e293b;'>Status Update:</h3>
                    <p style='margin: 5px 0; font-size: 18px;'><strong>Your application has been: {$statusText}</strong></p>
                </div>";
        
        if ($status === 'approved') {
            $message .= "
                <p style='color: #16a34a;'><strong>Congratulations!</strong> We are excited to move forward with your application. Our HR team will be in touch with you soon regarding the next steps.</p>";
        } elseif ($status === 'declined') {
            $message .= "
                <p>While we were impressed with your qualifications, we have decided to proceed with other candidates whose experience more closely matches our current needs.</p>
                <p>We encourage you to apply for future positions that match your skills and experience.</p>";
        }
        
        if (!empty($comments)) {
            $message .= "
                <div style='background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0;'>
                    <h4 style='margin: 0 0 10px 0; color: #92400e;'>Additional Comments:</h4>
                    <p style='margin: 0;'>{$comments}</p>
                </div>";
        }
        
        $message .= "
                <p>Thank you for your interest in our company.</p>
                
                <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #e2e8f0;'>
                    <p style='margin: 0;'>Best regards,</p>
                    <p style='margin: 5px 0 0 0;'><strong>Human Resources Department</strong></p>
                    <p style='margin: 0; color: #64748b; font-size: 14px;'>{$this->emailConfig['from_email']}</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $message;
    }
    
    private function sendSuccess($data) {
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
    }
    
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
    }
}

// Initialize and handle request
try {
    $tracker = new ApplicantTracker($conn);
    $tracker->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}
?> 