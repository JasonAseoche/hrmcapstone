<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendWelcomeEmail($email, $firstName, $password) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'icongko09@gmail.com';
        $mail->Password   = 'icys siar pbab bput';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        $mail->setFrom('icongko09@gmail.com', 'DIFSYS System');
        $mail->addAddress($email, $firstName);

        $mail->isHTML(true);
        $mail->Subject = 'Welcome to DIFSYS - Your Account Has Been Created';
        $mail->Body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #007bff; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                    .content { padding: 30px; background-color: #f8f9fa; border-radius: 0 0 8px 8px; }
                    .password-box { 
                        font-size: 20px; 
                        font-weight: bold; 
                        color: #007bff; 
                        text-align: center; 
                        padding: 20px; 
                        background-color: white; 
                        border: 2px solid #007bff; 
                        margin: 20px 0; 
                        border-radius: 8px;
                        letter-spacing: 2px;
                    }
                    .info-box {
                        background: white;
                        padding: 15px;
                        border-radius: 8px;
                        margin: 15px 0;
                    }
                    .warning {
                        color: #dc3545;
                        font-weight: bold;
                        margin-top: 20px;
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>Welcome to DIFSYS!</h2>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>{$firstName}</strong>,</p>
                        <p>Your account has been successfully created. Below are your login credentials:</p>
                        
                        <div class='info-box'>
                            <p><strong>Email:</strong> {$email}</p>
                        </div>
                        
                        <p><strong>Your temporary password is:</strong></p>
                        <div class='password-box'>{$password}</div>
                        
                        <div class='warning'>
                            <p>⚠️ IMPORTANT SECURITY NOTICE:</p>
                            <ul>
                                <li>You will be required to change this password upon your first login</li>
                                <li>Do not share this password with anyone</li>
                                <li>Keep this email secure or delete it after changing your password</li>
                            </ul>
                        </div>
                        
                        <p style='margin-top: 30px;'>If you did not request this account or have any questions, please contact your administrator immediately.</p>
                        
                        <br>
                        <p>Best regards,<br><strong>DIFSYS Team</strong></p>
                    </div>
                </div>
            </body>
            </html>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Include database connection
include 'db_connection.php';

try {
    // Check if request method is POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. POST required.');
    }

    // Get form data
    $firstName = isset($_POST['firstName']) ? trim($_POST['firstName']) : '';
    $lastName = isset($_POST['lastName']) ? trim($_POST['lastName']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $role = isset($_POST['role']) ? strtolower(trim($_POST['role'])) : 'user';

    // Validate required fields
    if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
        throw new Exception('All fields are required.');
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format.');
    }

    // Validate password length
    if (strlen($password) < 6) {
        throw new Exception('Password must be at least 6 characters long.');
    }

    // Check if email already exists
    $checkEmailSql = "SELECT id FROM useraccounts WHERE email = ?";
    $checkStmt = $conn->prepare($checkEmailSql);
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows > 0) {
        throw new Exception('Email address already exists.');
    }
    $checkStmt->close();

    // Start transaction
    $conn->autocommit(FALSE);

    // Get the next unique ID for useraccounts table
    $getMaxIdSql = "SELECT COALESCE(MAX(id), 100) + 1 as next_id FROM useraccounts";
    $maxIdResult = $conn->query($getMaxIdSql);
    $maxIdRow = $maxIdResult->fetch_assoc();
    $nextUserId = $maxIdRow['next_id'];

    // Insert into useraccounts table with explicit ID
    // Hash the password before storing
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

$insertUserSql = "INSERT INTO useraccounts (id, firstName, lastName, email, password, role, auth_status, change_pass_status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'New Account', 'Not Yet', NOW())";
    $userStmt = $conn->prepare($insertUserSql);
    $userStmt->bind_param("isssss", $nextUserId, $firstName, $lastName, $email, $hashedPassword, $role);


    
    if (!$userStmt->execute()) {
        throw new Exception('Failed to create user account: ' . $userStmt->error);
    }

    // Use the explicitly set user ID
    $userId = $nextUserId;
    $userStmt->close();

    // Insert into appropriate table based on role
    if ($role === 'employee') {
        // Get the next unique ID for employeelist table
        $getMaxEmpIdSql = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM employeelist";
        $maxEmpIdResult = $conn->query($getMaxEmpIdSql);
        $maxEmpIdRow = $maxEmpIdResult->fetch_assoc();
        $nextEmpId = $maxEmpIdRow['next_id'];

        // Insert into employeelist table with explicit ID
        $insertEmployeeSql = "INSERT INTO employeelist (id, emp_id, firstName, lastName, email, role, position, work_days, status, address, number, workarrangement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $employeeStmt = $conn->prepare($insertEmployeeSql);
        
        // Set default values for employee
        $defaultPosition = 'Not Assigned';
        $defaultWorkDays = 'Monday-Friday';
        $defaultStatus = 'active';
        $defaultAddress = '';
        $defaultNumber = null;
        $defaultWorkArrangement = 'office';
        
        $employeeStmt->bind_param("iissssssssss", 
            $nextEmpId,
            $userId, 
            $firstName, 
            $lastName, 
            $email, 
            $role,
            $defaultPosition,
            $defaultWorkDays,
            $defaultStatus,
            $defaultAddress,
            $defaultNumber,
            $defaultWorkArrangement
        );
        
        if (!$employeeStmt->execute()) {
            throw new Exception('Failed to create employee record: ' . $employeeStmt->error);
        }
        $employeeStmt->close();
        
    } elseif ($role === 'applicant') {
        // First, check if there's already an applicant record for this user
        $checkApplicantSql = "SELECT id FROM applicantlist WHERE app_id = ?";
        $checkApplicantStmt = $conn->prepare($checkApplicantSql);
        $checkApplicantStmt->bind_param("i", $userId);
        $checkApplicantStmt->execute();
        $applicantResult = $checkApplicantStmt->get_result();
        
        if ($applicantResult->num_rows === 0) {
            // Get the next unique ID for applicantlist table
            $getMaxAppIdSql = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM applicantlist";
            $maxAppIdResult = $conn->query($getMaxAppIdSql);
            $maxAppIdRow = $maxAppIdResult->fetch_assoc();
            $nextAppId = $maxAppIdRow['next_id'];

            // Insert into applicantlist table with explicit ID
            $insertApplicantSql = "INSERT INTO applicantlist (id, app_id, firstName, lastName, email, role, address, position, number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $applicantStmt = $conn->prepare($insertApplicantSql);
            
            // Set default values for applicant
            $defaultAddress = '';
            $defaultPosition = 'Applied Position';
            $defaultNumber = '';
            $defaultStatus = 'pending';
            
            $applicantStmt->bind_param("iissssssss", 
                $nextAppId,
                $userId, 
                $firstName, 
                $lastName, 
                $email, 
                $role,
                $defaultAddress,
                $defaultPosition,
                $defaultNumber,
                $defaultStatus
            );
            
            if (!$applicantStmt->execute()) {
                throw new Exception('Failed to create applicant record: ' . $applicantStmt->error);
            }
            $applicantStmt->close();
        }
        $checkApplicantStmt->close();
        
    } elseif ($role === 'supervisor') {
        // Get the next unique ID for supervisorlist table
        $getMaxSupIdSql = "SELECT COALESCE(MAX(id), 0) + 1 as next_id FROM supervisorlist";
        $maxSupIdResult = $conn->query($getMaxSupIdSql);
        $maxSupIdRow = $maxSupIdResult->fetch_assoc();
        $nextSupId = $maxSupIdRow['next_id'];
    
        // Insert into supervisorlist table with explicit ID
        $insertSupervisorSql = "INSERT INTO supervisorlist (id, sup_id, firstName, lastName, email, role, position, work_days, status, address, number, workarrangement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $supervisorStmt = $conn->prepare($insertSupervisorSql);
        
        // Set default values for supervisor
        $defaultPosition = 'Department Supervisor';
        $defaultWorkDays = 'Monday-Friday';
        $defaultStatus = 'active';
        $defaultAddress = '';
        $defaultNumber = null;
        $defaultWorkArrangement = 'office';
        
        $supervisorStmt->bind_param("iissssssssss", 
            $nextSupId,
            $userId, 
            $firstName, 
            $lastName, 
            $email, 
            $role,
            $defaultPosition,
            $defaultWorkDays,
            $defaultStatus,
            $defaultAddress,
            $defaultNumber,
            $defaultWorkArrangement
        );
        
        if (!$supervisorStmt->execute()) {
            throw new Exception('Failed to create supervisor record: ' . $supervisorStmt->error);
        }
        $supervisorStmt->close();
    }

    

    // Commit transaction
    $conn->commit();
    $conn->autocommit(TRUE);

    // Send welcome email with the PLAIN PASSWORD (before it was hashed)
    $emailSent = sendWelcomeEmail($email, $firstName, $password);

    // Success response
    if ($emailSent) {
        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully and credentials sent to email!',
            'user_id' => $userId,
            'role' => $role
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Account created successfully but failed to send email. Please provide credentials manually.',
            'user_id' => $userId,
            'role' => $role
        ]);
    }



} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($conn)) {
        $conn->rollback();
        $conn->autocommit(TRUE);
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} finally {
    // Close the connection
    if (isset($conn)) {
        $conn->close();
    }
}
?>