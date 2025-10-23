<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create log function
function logDebug($message) {
    file_put_contents('change_password_debug.log', date('Y-m-d H:i:s') . ": $message\n", FILE_APPEND);
}

logDebug("=== Change Password Script Started ===");

// Set headers to allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    logDebug("OPTIONS request received");
    exit(0);
}

// Include database connection and convert to PDO
require_once 'db_connection.php';

// PHPMailer for sending OTP
require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Convert mysqli connection to PDO
try {
    // Get database credentials from your db_connection.php variables
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}

// Function to generate random OTP
function generateOTP($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

// Function to send OTP via email
function sendPasswordChangeOTP($email, $otp, $firstName) {
    logDebug("Attempting to send password change OTP to: $email");
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'icongko09@gmail.com';
        $mail->Password   = 'flic qamh mhsx wtri';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Additional SMTP options for better Gmail compatibility
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        $mail->Timeout = 60;

        // Recipients
        $mail->setFrom('icongko09@gmail.com', 'DIFSYS System');
        $mail->addAddress($email, $firstName);
        $mail->addReplyTo('icongko09@gmail.com', 'DIFSYS System');

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'DIFSYS Password Change Verification - OTP Code';
        $mail->Body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f8f9fa; }
                    .otp-code { font-size: 24px; font-weight: bold; color: #dc3545; text-align: center; padding: 10px; background-color: white; border: 2px solid #dc3545; margin: 20px 0; }
                    .warning { background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>🔐 DIFSYS Password Change Request</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$firstName},</p>
                        <p>You have requested to change your password. Your verification code is:</p>
                        <div class='otp-code'>{$otp}</div>
                        <div class='warning'>
                            <strong>⚠️ Security Notice:</strong> If you didn't request this password change, please ignore this email and contact support immediately.
                        </div>
                        <p><strong>This code will expire in 10 minutes.</strong></p>
                        <p>After verification, you'll be able to set your new password.</p>
                        <br>
                        <p>Best regards,<br>DIFSYS Security Team</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        $mail->send();
        logDebug("Password change OTP email sent successfully to: $email");
        return true;
    } catch (Exception $e) {
        logDebug("Password change OTP email failed: " . $mail->ErrorInfo);
        logDebug("Exception details: " . $e->getMessage());
        return false;
    }
}

// Get the raw POST data
$json_data = file_get_contents('php://input');
logDebug("Raw input data: " . $json_data);

// Check if we have data
if (empty($json_data)) {
    logDebug("Empty request data received");
    echo json_encode(["success" => false, "message" => "No data received."]);
    exit;
}

$data = json_decode($json_data, true);
logDebug("Decoded data: " . print_r($data, true));

if ($data === null) {
    logDebug("JSON decode error: " . json_last_error_msg());
    echo json_encode(["success" => false, "message" => "Invalid JSON data: " . json_last_error_msg()]);
    exit;
}

$action = $data['action'] ?? '';
logDebug("Action requested: " . $action);

try {
    if ($action === 'request_otp') {
        logDebug("Processing request_otp action");
        
        if (!isset($data['user_id'])) {
            logDebug("Missing user_id for request_otp action");
            echo json_encode(["success" => false, "message" => "User ID is required."]);
            exit;
        }

        $user_id = (int)$data['user_id'];
        logDebug("Requesting password change OTP for user_id: $user_id");

        // Get user data using PDO
        $stmt = $pdo->prepare("SELECT id, firstName, lastName, email, auth_status FROM useraccounts WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if ($user) {
            logDebug("User found for password change request: " . $user['email']);
            
            // Check if user is verified
            if ($user['auth_status'] !== 'Verified') {
                logDebug("User is not verified: " . $user['auth_status']);
                echo json_encode(["success" => false, "message" => "Account must be verified before changing password."]);
                exit;
            }
            
            // Generate new OTP for password change
            $otp = generateOTP(6);
            $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            logDebug("Generated password change OTP: $otp, expires: $otp_expiry");
            
            // Update OTP in database using PDO
            $update_stmt = $pdo->prepare("UPDATE useraccounts SET otp_code = :otp, otp_expiry = :otp_expiry WHERE id = :user_id");
            $update_stmt->bindParam(':otp', $otp);
            $update_stmt->bindParam(':otp_expiry', $otp_expiry);
            $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);

            if ($update_stmt->execute()) {
                logDebug("Password change OTP updated in database");
                // Send OTP via email
                if (sendPasswordChangeOTP($user['email'], $otp, $user['firstName'])) {
                    echo json_encode([
                        "success" => true,
                        "message" => "Password change verification code sent to your email",
                        "user" => [
                            "id" => $user['id'],
                            "email" => $user['email'],
                            "firstName" => $user['firstName'],
                            "lastName" => $user['lastName']
                        ]
                    ]);
                } else {
                    echo json_encode(["success" => false, "message" => "Failed to send verification code. Please try again."]);
                }
            } else {
                logDebug("Failed to update password change OTP");
                echo json_encode(["success" => false, "message" => "Failed to generate verification code."]);
            }
        } else {
            logDebug("User not found for user_id: $user_id");
            echo json_encode(["success" => false, "message" => "User not found."]);
        }
        
    } elseif ($action === 'verify_otp') {
        logDebug("Processing verify_otp action for password change");
        
        if (!isset($data['user_id']) || !isset($data['otp'])) {
            logDebug("Missing required fields for verify_otp action");
            echo json_encode(["success" => false, "message" => "User ID and OTP are required."]);
            exit;
        }

        $user_id = (int)$data['user_id'];
        $otp = trim($data['otp']);
        
        logDebug("Verifying password change OTP for user_id: $user_id, OTP: '$otp'");

        // Get user data and verify OTP using PDO
        $stmt = $pdo->prepare("SELECT id, firstName, email, otp_code, otp_expiry FROM useraccounts WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if ($user) {
            logDebug("User found for OTP verification: " . $user['email']);
            
            $stored_otp = trim($user['otp_code']);
            $expiry_time = $user['otp_expiry'];
            $current_time = date('Y-m-d H:i:s');
            
            logDebug("Stored OTP: '$stored_otp'");
            logDebug("Entered OTP: '$otp'");
            logDebug("Expiry time: $expiry_time");
            logDebug("Current time: $current_time");
            
            // Check if OTP matches and is not expired
            $otp_matches = ($stored_otp === $otp);
            $is_expired = (strtotime($expiry_time) <= strtotime($current_time));
            
            logDebug("OTP matches: " . ($otp_matches ? 'YES' : 'NO'));
            logDebug("Is expired: " . ($is_expired ? 'YES' : 'NO'));
            
            if ($otp_matches && !$is_expired) {
                logDebug("Password change OTP verified successfully for user: " . $user['email']);
                echo json_encode([
                    "success" => true,
                    "message" => "OTP verified successfully. You can now change your password.",
                    "verified" => true
                ]);
            } else {
                if (!$otp_matches) {
                    logDebug("Password change OTP verification failed: OTP does not match");
                    echo json_encode(["success" => false, "message" => "Invalid OTP code."]);
                } else {
                    logDebug("Password change OTP verification failed: OTP has expired");
                    echo json_encode(["success" => false, "message" => "OTP has expired. Please request a new one."]);
                }
            }
        } else {
            logDebug("User not found for user_id: $user_id");
            echo json_encode(["success" => false, "message" => "User not found."]);
        }
        
    } elseif ($action === 'change_password') {
        logDebug("Processing change_password action");
        
        if (!isset($data['user_id']) || !isset($data['new_password'])) {
            logDebug("Missing required fields for change_password action");
            echo json_encode(["success" => false, "message" => "User ID and new password are required."]);
            exit;
        }
    
        $user_id = (int)$data['user_id'];
        $current_password = $data['current_password'] ?? '';
        $new_password = $data['new_password'];
        $is_first_login = $data['is_first_login'] ?? false;
        $bypass_current_check = $data['bypass_current_check'] ?? false;
        
        logDebug("Changing password for user_id: $user_id");
        logDebug("Is first login: " . ($is_first_login ? 'YES' : 'NO'));
        logDebug("Bypass current check: " . ($bypass_current_check ? 'YES' : 'NO'));
    
        // Get user's current password and change_pass_status using PDO
        $stmt = $pdo->prepare("SELECT id, firstName, email, password, change_pass_status FROM useraccounts WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        if ($user) {
            logDebug("User found for password change: " . $user['email']);
            logDebug("User change_pass_status: " . ($user['change_pass_status'] ?? 'NULL'));
            
            $stored_password = $user['password'];
            $password_verified = false;
            
            // If NOT bypassing (normal password change), verify current password
            if (!$bypass_current_check && !$is_first_login) {
                logDebug("Verifying current password (normal password change)");
                
                if (empty($current_password)) {
                    logDebug("Current password is required but not provided");
                    echo json_encode(["success" => false, "message" => "Current password is required."]);
                    exit;
                }
                
                // Check both hashed and plain text password for compatibility
                if (password_verify($current_password, $stored_password)) {
                    logDebug("Current password verified (hashed)");
                    $password_verified = true;
                } elseif ($current_password === $stored_password) {
                    logDebug("Current password verified (plain text)");
                    $password_verified = true;
                } else {
                    logDebug("Current password verification failed");
                    echo json_encode(["success" => false, "message" => "Current password is incorrect."]);
                    exit;
                }
            } else {
                logDebug("Bypassing current password check (first login or bypass flag set)");
                $password_verified = true;
            }
            
            if ($password_verified) {
                logDebug("Proceeding with password update");
                
                // Validate new password strength
                if (strlen($new_password) < 6) {
                    logDebug("New password too short");
                    echo json_encode(["success" => false, "message" => "New password must be at least 6 characters long."]);
                    exit;
                }
                
                // Hash the new password
                $hashed_new_password = password_hash($new_password, PASSWORD_BCRYPT);
                logDebug("New password hashed successfully");
                
                // Update password, clear OTP, and update change_pass_status if first login
                if ($is_first_login || $user['change_pass_status'] === 'Not Yet') {
                    logDebug("Updating password and setting change_pass_status to 'Changed'");
                    $update_stmt = $pdo->prepare("UPDATE useraccounts SET password = :new_password, change_pass_status = 'Changed', otp_code = NULL, otp_expiry = NULL WHERE id = :user_id");
                } else {
                    logDebug("Updating password only (regular password change)");
                    $update_stmt = $pdo->prepare("UPDATE useraccounts SET password = :new_password, otp_code = NULL, otp_expiry = NULL WHERE id = :user_id");
                }
                
                $update_stmt->bindParam(':new_password', $hashed_new_password);
                $update_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                
                if ($update_stmt->execute()) {
                    logDebug("Password updated successfully for user: " . $user['email']);
                    
                    // Return appropriate message based on context
                    if ($is_first_login) {
                        echo json_encode([
                            "success" => true,
                            "message" => "Password set successfully! You can now log in with your new password.",
                            "is_first_login" => true
                        ]);
                    } else {
                        echo json_encode([
                            "success" => true,
                            "message" => "Password changed successfully!"
                        ]);
                    }
                } else {
                    logDebug("Failed to update password");
                    echo json_encode(["success" => false, "message" => "Failed to update password. Please try again."]);
                }
            }
        } else {
            logDebug("User not found for user_id: $user_id");
            echo json_encode(["success" => false, "message" => "User not found."]);
        }
        
    }else {
        logDebug("Invalid action received: $action");
        echo json_encode(["success" => false, "message" => "Invalid action."]);
    }
    
} catch (PDOException $e) {
    logDebug("PDO Exception occurred: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
} catch (Exception $e) {
    logDebug("Exception occurred: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}

logDebug("=== Change Password Script Completed ===");
?>