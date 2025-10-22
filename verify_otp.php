<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create log function
function logDebug($message) {
    file_put_contents('verify_otp_debug.log', date('Y-m-d H:i:s') . ": $message\n", FILE_APPEND);
}

logDebug("=== Verify OTP Script Started ===");

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

// Include database connection
require_once 'db_connection.php';

// PHPMailer for resending OTP
require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Function to generate random OTP
function generateOTP($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

// Enhanced debug function
function debugOTPComparison($user_id, $entered_otp, $conn) {
    $sql = "SELECT id, firstName, email, otp_code, otp_expiry, 
                   NOW() as current_time,
                   CASE WHEN otp_expiry > NOW() THEN 'Valid' ELSE 'Expired' END as expiry_status
            FROM useraccounts 
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user) {
        $stored_otp = $user['otp_code'];
        logDebug("=== OTP COMPARISON DEBUG ===");
        logDebug("User ID: " . $user_id);
        logDebug("Entered OTP: '" . $entered_otp . "' (type: " . gettype($entered_otp) . ", length: " . strlen($entered_otp) . ")");
        logDebug("Stored OTP: '" . $stored_otp . "' (type: " . gettype($stored_otp) . ", length: " . strlen($stored_otp) . ")");
        logDebug("Current Time: " . $user['current_time']);
        logDebug("OTP Expiry: " . $user['otp_expiry']);
        logDebug("Expiry Status: " . $user['expiry_status']);
        logDebug("Strict Match (===): " . ($stored_otp === $entered_otp ? 'TRUE' : 'FALSE'));
        logDebug("Loose Match (==): " . ($stored_otp == $entered_otp ? 'TRUE' : 'FALSE'));
        logDebug("Trimmed Match: " . (trim($stored_otp) === trim($entered_otp) ? 'TRUE' : 'FALSE'));
        logDebug("ASCII Values - Entered: " . implode(',', array_map('ord', str_split($entered_otp))));
        logDebug("ASCII Values - Stored: " . implode(',', array_map('ord', str_split($stored_otp))));
        logDebug("=== END OTP COMPARISON DEBUG ===");
    }
    
    return $user;
}

// Function to send OTP via email
function sendOTP($email, $otp, $firstName) {
    logDebug("Attempting to send OTP to: $email");
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings - Use consistent configuration
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'icongko09@gmail.com';
        $mail->Password   = 'swim vubm bckx dktr';
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
        $mail->Subject = 'DIFSYS Account Verification - New OTP Code';
        $mail->Body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f8f9fa; }
                    .otp-code { font-size: 24px; font-weight: bold; color: #007bff; text-align: center; padding: 10px; background-color: white; border: 2px solid #007bff; margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>DIFSYS Account Verification</h2>
                    </div>
                    <div class='content'>
                        <p>Hello {$firstName},</p>
                        <p>Your new OTP code for account verification is:</p>
                        <div class='otp-code'>{$otp}</div>
                        <p><strong>This code will expire in 10 minutes.</strong></p>
                        <p>If you didn't request this verification, please ignore this email.</p>
                        <br>
                        <p>Best regards,<br>DIFSYS Team</p>
                    </div>
                </div>
            </body>
            </html>
        ";

        $mail->send();
        logDebug("OTP email sent successfully to: $email");
        return true;
    } catch (Exception $e) {
        logDebug("OTP email failed: " . $mail->ErrorInfo);
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
    if ($action === 'verify') {
        logDebug("Processing verify action");
        
        // Verify OTP
        if (!isset($data['user_id']) || !isset($data['otp'])) {
            logDebug("Missing required fields for verify action");
            echo json_encode(["success" => false, "message" => "User ID and OTP are required."]);
            exit;
        }
    
        $user_id = (int)$data['user_id'];
        $otp = trim($data['otp']);
        
        logDebug("Verifying OTP for user_id: $user_id, OTP: '$otp'");
    
        try {
            // Get user data - SIMPLE APPROACH
            $sql = "SELECT * FROM useraccounts WHERE id = ?";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                logDebug("Database prepare error: " . $conn->error);
                echo json_encode(["success" => false, "message" => "Database error."]);
                exit;
            }
            
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                logDebug("User found: " . $user['email']);
                
                $stored_otp = trim($user['otp_code']);
                $expiry_time = $user['otp_expiry'];
                $current_time = date('Y-m-d H:i:s');
                
                logDebug("Stored OTP: '$stored_otp'");
                logDebug("Entered OTP: '$otp'");
                logDebug("Expiry time: $expiry_time");
                logDebug("Current time: $current_time");
                
                // Check if OTP matches
                $otp_matches = ($stored_otp === $otp);
                logDebug("OTP matches: " . ($otp_matches ? 'YES' : 'NO'));
                
                // Check if not expired
                $is_expired = (strtotime($expiry_time) <= strtotime($current_time));
                logDebug("Is expired: " . ($is_expired ? 'YES' : 'NO'));
                
                if ($otp_matches && !$is_expired) {
                    logDebug("OTP verified successfully for user: " . $user['email']);
                    
                    // Update user's auth_status to 'Verified'
                    $update_sql = "UPDATE useraccounts SET auth_status = 'Verified', otp_code = NULL, otp_expiry = NULL, last_login = NOW() WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    
                    if ($update_stmt) {
                        $update_stmt->bind_param("i", $user_id);
                        if ($update_stmt->execute()) {
                            logDebug("User auth_status updated to Verified");
                            echo json_encode([
                                "success" => true,
                                "message" => "Account verified successfully",
                                "user" => [
                                    "id" => $user['id'],
                                    "email" => $user['email'],
                                    "firstName" => $user['firstName'],
                                    "lastName" => $user['lastName'],
                                    "role" => $user['role'],
                                    "auth_status" => "Verified"
                                ]
                            ]);
                        } else {
                            logDebug("Failed to update auth_status: " . $update_stmt->error);
                            echo json_encode(["success" => false, "message" => "Failed to update verification status."]);
                        }
                        $update_stmt->close();
                    } else {
                        logDebug("Failed to prepare update statement: " . $conn->error);
                        echo json_encode(["success" => false, "message" => "Database error."]);
                    }
                } else {
                    if (!$otp_matches) {
                        logDebug("OTP verification failed: OTP does not match");
                        echo json_encode(["success" => false, "message" => "Invalid OTP code."]);
                    } else {
                        logDebug("OTP verification failed: OTP has expired");
                        echo json_encode(["success" => false, "message" => "OTP has expired. Please request a new one."]);
                    }
                }
            } else {
                logDebug("No user found with user_id: $user_id");
                echo json_encode(["success" => false, "message" => "User not found."]);
            }
            
            $stmt->close();
            
        } catch (Exception $e) {
            logDebug("Exception in verification: " . $e->getMessage());
            echo json_encode(["success" => false, "message" => "Verification error: " . $e->getMessage()]);
        }
    
        
    } elseif ($action === 'resend') {
        // ... (resend logic remains the same)
        logDebug("Processing resend action");
        
        if (!isset($data['user_id'])) {
            logDebug("Missing user_id for resend action");
            echo json_encode(["success" => false, "message" => "User ID is required."]);
            exit;
        }

        $user_id = $data['user_id'];
        logDebug("Resending OTP for user_id: $user_id");

        // Get user data
        $sql = "SELECT * FROM useraccounts WHERE id = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            logDebug("Database prepare error: " . $conn->error);
            echo json_encode(["success" => false, "message" => "Database error."]);
            exit;
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            logDebug("User found for resend: " . $user['email']);
            
            // Generate new OTP
            $otp = generateOTP(6);
            $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            logDebug("Generated new OTP: $otp, expires: $otp_expiry");
            
            // Update OTP in database
            $update_sql = "UPDATE useraccounts SET otp_code = ?, otp_expiry = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            
            if ($update_stmt) {
                $update_stmt->bind_param("ssi", $otp, $otp_expiry, $user_id);
                if ($update_stmt->execute()) {
                    logDebug("OTP updated in database");
                    // Send new OTP via email
                    if (sendOTP($user['email'], $otp, $user['firstName'])) {
                        echo json_encode([
                            "success" => true,
                            "message" => "New OTP sent to your email"
                        ]);
                    } else {
                        echo json_encode(["success" => false, "message" => "Failed to send OTP. Please check your email configuration."]);
                    }
                } else {
                    logDebug("Failed to update OTP: " . $update_stmt->error);
                    echo json_encode(["success" => false, "message" => "Failed to generate new OTP."]);
                }
                $update_stmt->close();
            } else {
                logDebug("Failed to prepare OTP update statement: " . $conn->error);
                echo json_encode(["success" => false, "message" => "Database error."]);
            }
        } else {
            logDebug("User not found for user_id: $user_id");
            echo json_encode(["success" => false, "message" => "User not found."]);
        }
        
        $stmt->close();
        
    } else {
        logDebug("Invalid action received: $action");
        echo json_encode(["success" => false, "message" => "Invalid action."]);
    }
    
} catch (Exception $e) {
    logDebug("Exception occurred: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

logDebug("=== Verify OTP Script Completed ===");
?>