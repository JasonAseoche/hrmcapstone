<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Create log function
function logDebug($message) {
    file_put_contents('login_debug.log', date('Y-m-d H:i:s') . ": $message\n", FILE_APPEND);
}

logDebug("=== Login Script Started ===");

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

// PHPMailer for OTP sending
require_once 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Function to generate random OTP
function generateOTP($length = 6) {
    return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

// Function to send OTP via email
function sendOTP($email, $otp, $firstName) {
    logDebug("Attempting to send OTP to: $email");
    
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'icongko09@gmail.com';
        $mail->Password   = 'swim vubm bksx dtzt';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('icongko09@gmail.com', 'DIFSYS System');
        $mail->addAddress($email, $firstName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'DIFSYS Account Verification - OTP Code';
        $mail->Body = "
            <html>
            <body>
                <h2>Account Verification</h2>
                <p>Hello {$firstName},</p>
                <p>Your OTP code for account verification is: <strong>{$otp}</strong></p>
                <p>This code will expire in 10 minutes.</p>
                <p>If you didn't request this, please ignore this email.</p>
                <br>
                <p>Best regards,<br>DIFSYS Team</p>
            </body>
            </html>
        ";

        $mail->send();
        logDebug("OTP email sent successfully to: $email");
        return true;
    } catch (Exception $e) {
        logDebug("OTP email failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Get POST data
$json_data = file_get_contents('php://input');
logDebug("Raw input data: " . $json_data);

$data = json_decode($json_data, true);
logDebug("Decoded data: " . print_r($data, true));

if (!$data || !isset($data['email']) || !isset($data['password'])) {
    logDebug("Missing email or password");
    echo json_encode(["success" => false, "message" => "Email and password are required."]);
    exit;
}

$email = $data['email'];
$password = $data['password'];

logDebug("Login attempt for email: $email");

try {
    // Prepare SQL statement
    $sql = "SELECT * FROM useraccounts WHERE email=?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        logDebug("Prepare statement failed: " . $conn->error);
        echo json_encode(["success" => false, "message" => "Database error. Please try again later."]);
        exit;
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    logDebug("Query executed. Found rows: " . $result->num_rows);
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Verify password (supports both hashed and plain text for backward compatibility)
        $password_valid = false;
        if (password_verify($password, $user['password'])) {
            // Password is hashed and matches
            logDebug("Password verified (hashed)");
            $password_valid = true;
        } elseif ($password === $user['password']) {
            // Password is plain text and matches (backward compatibility)
            logDebug("Password verified (plain text)");
            $password_valid = true;
        }
        
        if (!$password_valid) {
            logDebug("Invalid password for user: " . $email);
            echo json_encode(["success" => false, "message" => "Invalid email or password."]);
            exit;
        }
        
        logDebug("Login successful for user: " . $email . " with ID: " . $user['id']);
        logDebug("User role: " . $user['role']);
        logDebug("User auth_status: " . ($user['auth_status'] ?? 'NULL'));
        
        // Get the actual user ID based on role
        $actualUserId = $user['id'];
        
        // If user is a supervisor, get their sup_id from supervisorlist
        if ($user['role'] === 'supervisor') {
            logDebug("User is supervisor, fetching sup_id from supervisorlist");
            
            $supSql = "SELECT sup_id FROM supervisorlist WHERE email = ? LIMIT 1";
            $supStmt = $conn->prepare($supSql);
            
            if ($supStmt) {
                $supStmt->bind_param("s", $email);
                $supStmt->execute();
                $supResult = $supStmt->get_result();
                
                if ($supResult->num_rows > 0) {
                    $supData = $supResult->fetch_assoc();
                    $actualUserId = $supData['sup_id'];
                    logDebug("Found supervisor with sup_id: " . $actualUserId);
                } else {
                    logDebug("WARNING: Supervisor email not found in supervisorlist table");
                    echo json_encode([
                        "success" => false, 
                        "message" => "Supervisor record not found. Please contact administrator."
                    ]);
                    $supStmt->close();
                    $stmt->close();
                    exit;
                }
                $supStmt->close();
            } else {
                logDebug("Failed to prepare supervisor query: " . $conn->error);
            }
        }
        
        logDebug("Final user ID to be returned: " . $actualUserId);
        
        // Check if user needs email verification
        if (isset($user['auth_status']) && $user['auth_status'] === 'New Account') {
            logDebug("User requires email verification");
            
            // Generate OTP
            $otp = generateOTP(6);
            $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            logDebug("Generated OTP: $otp, expires: $otp_expiry");
            
            // Store OTP in database
            $otp_sql = "UPDATE useraccounts SET otp_code = ?, otp_expiry = ? WHERE id = ?";
            $otp_stmt = $conn->prepare($otp_sql);
            
            if ($otp_stmt) {
                $otp_stmt->bind_param("ssi", $otp, $otp_expiry, $user['id']);
                if ($otp_stmt->execute()) {
                    logDebug("OTP stored in database successfully");
                    // Send OTP via email
                    if (sendOTP($email, $otp, $user['firstName'])) {
                        logDebug("OTP sent successfully, responding with verification required");
                        echo json_encode([
                            "success" => true,
                            "message" => "OTP sent to your email",
                            "requires_verification" => true,
                            "user" => [
                                "id" => $actualUserId,
                                "email" => $user['email'],
                                "firstName" => $user['firstName'],
                                "lastName" => $user['lastName'],
                                "role" => $user['role'],
                                "auth_status" => $user['auth_status'],
                                "change_pass_status" => $user['change_pass_status'] ?? "Changed"

                            ]
                        ]);
                    } else {
                        logDebug("Failed to send OTP email");
                        echo json_encode(["success" => false, "message" => "Failed to send OTP. Please try again."]);
                    }
                } else {
                    logDebug("Failed to store OTP: " . $otp_stmt->error);
                    echo json_encode(["success" => false, "message" => "Failed to generate OTP. Please try again."]);
                }
                $otp_stmt->close();
            } else {
                logDebug("Failed to prepare OTP statement: " . $conn->error);
                echo json_encode(["success" => false, "message" => "Database error. Please try again later."]);
            }
        } else {
            logDebug("User is already verified or auth_status not set, proceeding with normal login");
            
            // User is already verified, proceed with normal login
            // Update last login time
            $currentDate = date('Y-m-d H:i:s');
            $updateSql = "UPDATE useraccounts SET last_login = ? WHERE id = ?";
            $updateStmt = $conn->prepare($updateSql);
            if ($updateStmt) {
                $updateStmt->bind_param("si", $currentDate, $user['id']);
                $updateStmt->execute();
                $updateStmt->close();
            }
            
            echo json_encode([
                "success" => true,
                "message" => "Login successful",
                "requires_verification" => false,
                "user" => [
                    "id" => $actualUserId,
                    "email" => $user['email'],
                    "firstName" => $user['firstName'],
                    "lastName" => $user['lastName'],
                    "role" => $user['role'],
                    "auth_status" => $user['auth_status'] ?? "Verified",
                    "change_pass_status" => $user['change_pass_status'] ?? "Changed"
                ]
            ]);
        }
    } else {
        logDebug("Login failed for user: " . $email);
        echo json_encode(["success" => false, "message" => "Invalid email or password."]);
    }
    
    $stmt->close();
} catch (Exception $e) {
    logDebug("Exception occurred: " . $e->getMessage());
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

logDebug("=== Login Script Completed ===");
?>