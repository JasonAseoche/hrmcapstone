<?php
// profile.php - Handle profile operations for admin, hr, accountant, and employee users

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Include database connection
require_once 'db_connection.php';

// Configuration
$uploadDir = 'uploads/profile_images/';
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxFileSize = 5 * 1024 * 1024; // 5MB

// Create upload directory if it doesn't exist
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/**
 * Get user profile data for admin, hr, accountant, employee
 */
function getUserProfile($conn, $user_id) {
    try {
        // Get user data from useraccounts table with LEFT JOIN to user_profiles
        $stmt = $conn->prepare("
            SELECT 
                u.id, 
                u.firstName, 
                u.lastName, 
                u.email, 
                u.role, 
                u.profile_image,
                u.cover_photo,
                p.middle_name,
                p.address,
                p.contact_number,
                p.date_of_birth,
                p.civil_status,
                p.gender,
                p.citizenship,
                p.height,
                p.weight
            FROM useraccounts u
            LEFT JOIN user_profiles p ON u.id = p.user_id
            WHERE u.id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }
        
        $userData = $result->fetch_assoc();
        
        // Check if user has allowed role
        // Around line 45, update the allowed roles array
        $allowedRoles = ['admin', 'hr', 'accountant', 'employee', 'supervisor', 'Applicant']; // Add 'supervisor'
        if (!in_array($userData['role'], $allowedRoles)) {
            return [
                'success' => false,
                'message' => 'Access denied. This profile is only accessible to admin, hr, accountant, employee, and supervisor roles.'
            ];
        }
        
        // Ensure all fields have default values if null
        $userData['middle_name'] = $userData['middle_name'] ?? '';
        $userData['address'] = $userData['address'] ?? '';
        $userData['contact_number'] = $userData['contact_number'] ?? '';
        $userData['date_of_birth'] = $userData['date_of_birth'] ?? '';
        $userData['civil_status'] = $userData['civil_status'] ?? '';
        $userData['gender'] = $userData['gender'] ?? '';
        $userData['citizenship'] = $userData['citizenship'] ?? '';
        $userData['height'] = $userData['height'] ?? '';
        $userData['weight'] = $userData['weight'] ?? '';
        
        return [
            'success' => true,
            'data' => $userData
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error fetching profile: ' . $e->getMessage()
        ];
    }
}

/**
 * Update user profile data
 */
function updateUserProfile($conn, $user_id, $profileData) {
    try {
        // Check if user exists and has allowed role
        $checkStmt = $conn->prepare("SELECT role FROM useraccounts WHERE id = ?");
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }
        
        $userRole = $checkResult->fetch_assoc()['role'];
        // Around line 95, update the allowed roles array
        $allowedRoles = ['admin', 'hr', 'accountant', 'employee', 'supervisor', 'Applicant']; // Add 'supervisor'

        if (!in_array($userRole, $allowedRoles)) {
            return [
                'success' => false,
                'message' => 'Access denied'
            ];
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Update useraccounts table with basic info
            $userUpdateStmt = $conn->prepare("
                UPDATE useraccounts SET 
                    firstName = ?,
                    lastName = ?,
                    email = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $userUpdateStmt->bind_param(
                "sssi",
                $profileData['firstName'],
                $profileData['lastName'],
                $profileData['email'],
                $user_id
            );
            
            if (!$userUpdateStmt->execute()) {
                throw new Exception('Failed to update user account information');
            }
            
            // Check if user_profiles record exists
            $checkProfileStmt = $conn->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
            $checkProfileStmt->bind_param("i", $user_id);
            $checkProfileStmt->execute();
            $checkProfileResult = $checkProfileStmt->get_result();
            
            if ($checkProfileResult->num_rows > 0) {
                // Update existing user_profiles record
                $profileUpdateStmt = $conn->prepare("
                    UPDATE user_profiles SET 
                        middle_name = ?,
                        address = ?,
                        contact_number = ?,
                        date_of_birth = ?,
                        civil_status = ?,
                        gender = ?,
                        citizenship = ?,
                        height = ?,
                        weight = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ?
                ");
                
                $profileUpdateStmt->bind_param(
                    "sssssssssi",
                    $profileData['middleName'],
                    $profileData['address'],
                    $profileData['contactNumber'],
                    $profileData['dateOfBirth'],
                    $profileData['civilStatus'],
                    $profileData['gender'],
                    $profileData['citizenship'],
                    $profileData['height'],
                    $profileData['weight'],
                    $user_id
                );
            } else {
                // Insert new user_profiles record
                $profileUpdateStmt = $conn->prepare("
                    INSERT INTO user_profiles 
                    (user_id, middle_name, address, contact_number, date_of_birth, 
                     civil_status, gender, citizenship, height, weight, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                
                $profileUpdateStmt->bind_param(
                    "isssssssss",
                    $user_id,
                    $profileData['middleName'],
                    $profileData['address'],
                    $profileData['contactNumber'],
                    $profileData['dateOfBirth'],
                    $profileData['civilStatus'],
                    $profileData['gender'],
                    $profileData['citizenship'],
                    $profileData['height'],
                    $profileData['weight']
                );
            }
            
            if (!$profileUpdateStmt->execute()) {
                throw new Exception('Failed to update profile information');
            }
            
            // Commit transaction
            $conn->commit();
            
            return [
                'success' => true,
                'message' => 'Profile updated successfully'
            ];
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error updating profile: ' . $e->getMessage()
        ];
    }
}

function updateCoverPhoto($conn, $user_id, $imagePath) {
    try {
        // Check if user has allowed role
        $checkStmt = $conn->prepare("SELECT role FROM useraccounts WHERE id = ?");
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }
        
        $userRole = $checkResult->fetch_assoc()['role'];
        // Around line 197, update the allowed roles array
        $allowedRoles = ['admin', 'hr', 'accountant', 'employee', 'supervisor', 'Applicant']; // Add 'supervisor'

        if (!in_array($userRole, $allowedRoles)) {
            return [
                'success' => false,
                'message' => 'Access denied'
            ];
        }
        
        // Update cover_photo in useraccounts table
        $stmt = $conn->prepare("UPDATE useraccounts SET cover_photo = ? WHERE id = ?");
        $stmt->bind_param("si", $imagePath, $user_id);
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Cover photo updated successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to update cover photo'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error updating cover photo: ' . $e->getMessage()
        ];
    }
}

function deleteOldCoverPhoto($conn, $user_id) {
    try {
        // Get old cover photo from useraccounts table
        $stmt = $conn->prepare("SELECT cover_photo FROM useraccounts WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $oldImage = $row['cover_photo'];
            
            if ($oldImage && file_exists($oldImage)) {
                unlink($oldImage);
            }
        }
    } catch (Exception $e) {
        // Log error but don't stop execution
        error_log('Error deleting old cover photo: ' . $e->getMessage());
    }
}

/**
 * Handle file upload
 */
function handleFileUpload($file, $user_id) {
    global $uploadDir, $allowedExtensions, $maxFileSize;
    
    try {
        // Check if file was uploaded
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => 'No file uploaded or upload error occurred'
            ];
        }
        
        // Check file size
        if ($file['size'] > $maxFileSize) {
            return [
                'success' => false,
                'message' => 'File size exceeds maximum limit of 5MB'
            ];
        }
        
        // Get file extension
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // Check file extension
        if (!in_array($fileExtension, $allowedExtensions)) {
            return [
                'success' => false,
                'message' => 'Invalid file type. Only ' . implode(', ', $allowedExtensions) . ' files are allowed'
            ];
        }
        
        // Verify if it's actually an image
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return [
                'success' => false,
                'message' => 'File is not a valid image'
            ];
        }
        
        // Generate unique filename
        $fileName = 'profile_' . $user_id . '_' . time() . '.' . $fileExtension;
        $filePath = $uploadDir . $fileName;
        
        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $filePath)) {
            return [
                'success' => true,
                'fileName' => $fileName,
                'filePath' => $filePath
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to move uploaded file'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error uploading file: ' . $e->getMessage()
        ];
    }
}

/**
 * Update profile image in useraccounts table
 */
function updateProfileImage($conn, $user_id, $imagePath) {
    try {
        // Check if user has allowed role
        $checkStmt = $conn->prepare("SELECT role FROM useraccounts WHERE id = ?");
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows === 0) {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }
        
        $userRole = $checkResult->fetch_assoc()['role'];
        // Around line 197, update the allowed roles array
        $allowedRoles = ['admin', 'hr', 'accountant', 'employee', 'supervisor', 'Applicant']; // Add 'supervisor'

        if (!in_array($userRole, $allowedRoles)) {
            return [
                'success' => false,
                'message' => 'Access denied'
            ];
        }
        
        // Update profile_image in useraccounts table
        $stmt = $conn->prepare("UPDATE useraccounts SET profile_image = ? WHERE id = ?");
        $stmt->bind_param("si", $imagePath, $user_id);
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Profile image updated successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to update profile image'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error updating profile image: ' . $e->getMessage()
        ];
    }
}

/**
 * Delete old profile image
 */
function deleteOldProfileImage($conn, $user_id) {
    try {
        // Get old profile image from useraccounts table
        $stmt = $conn->prepare("SELECT profile_image FROM useraccounts WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $oldImage = $row['profile_image'];
            
            if ($oldImage && file_exists($oldImage)) {
                unlink($oldImage);
            }
        }
    } catch (Exception $e) {
        // Log error but don't stop execution
        error_log('Error deleting old profile image: ' . $e->getMessage());
    }
}

// Main request handling
$method = $_SERVER['REQUEST_METHOD'];
$response = ['success' => false, 'message' => 'Invalid request'];

switch ($method) {
    case 'GET':
        if (isset($_GET['user_id'])) {
            $user_id = intval($_GET['user_id']);
            $response = getUserProfile($conn, $user_id);
        } else {
            $response = ['success' => false, 'message' => 'user_id parameter is required'];
        }
        break;
        
    case 'POST':
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                if (isset($_POST['user_id']) && isset($_POST['profileData'])) {
                    $user_id = intval($_POST['user_id']);
                    $profileData = json_decode($_POST['profileData'], true);
                    
                    if ($profileData) {
                        $response = updateUserProfile($conn, $user_id, $profileData);
                    } else {
                        $response = ['success' => false, 'message' => 'Invalid profile data'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'user_id and profileData are required'];
                }
                break;
                
                case 'upload_image':
                    // Add debugging
                    error_log("=== IMAGE UPLOAD DEBUG START ===");
                    error_log("POST data: " . print_r($_POST, true));
                    error_log("FILES data: " . print_r($_FILES, true));
                    
                    if (isset($_POST['user_id']) && isset($_FILES['profile_image'])) {
                        $user_id = intval($_POST['user_id']);
                        error_log("Processing upload for user_id: " . $user_id);
                        
                        // Check user role first
                        $checkStmt = $conn->prepare("SELECT role FROM useraccounts WHERE id = ?");
                        $checkStmt->bind_param("i", $user_id);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        
                        if ($checkResult->num_rows === 0) {
                            error_log("ERROR: User not found for ID: " . $user_id);
                            $response = ['success' => false, 'message' => 'User not found'];
                            break;
                        }
                        
                        $userRole = $checkResult->fetch_assoc()['role'];
                        error_log("User role found: " . $userRole);
                        
                        $allowedRoles = ['admin', 'hr', 'accountant', 'employee', 'supervisor', 'Applicant'];
                        error_log("Allowed roles: " . implode(', ', $allowedRoles));
                        
                        if (!in_array($userRole, $allowedRoles)) {
                            error_log("ERROR: Access denied for role: " . $userRole);
                            $response = ['success' => false, 'message' => 'Access denied for role: ' . $userRole];
                            break;
                        }
                        
                        error_log("Role check passed, proceeding with upload...");
                        
                        // Delete old image first
                        deleteOldProfileImage($conn, $user_id);
                        
                        // Upload new image
                        $uploadResult = handleFileUpload($_FILES['profile_image'], $user_id);
                        error_log("Upload result: " . print_r($uploadResult, true));
                        
                        if ($uploadResult['success']) {
                            // Update database with new image path
                            $imageUpdateResult = updateProfileImage($conn, $user_id, $uploadResult['filePath']);
                            error_log("Database update result: " . print_r($imageUpdateResult, true));
                            
                            if ($imageUpdateResult['success']) {
                                $response = [
                                    'success' => true,
                                    'message' => 'Profile image uploaded successfully',
                                    'imagePath' => $uploadResult['filePath'],
                                    'imageUrl' => 'https://difsysinc.com/difsysapi/' . $uploadResult['filePath']
                                ];
                                error_log("SUCCESS: Image uploaded and database updated");
                            } else {
                                // Delete uploaded file if database update failed
                                unlink($uploadResult['filePath']);
                                $response = $imageUpdateResult;
                                error_log("ERROR: Database update failed: " . $imageUpdateResult['message']);
                            }
                        } else {
                            $response = $uploadResult;
                            error_log("ERROR: File upload failed: " . $uploadResult['message']);
                        }
                    } else {
                        error_log("ERROR: Missing required parameters - user_id or profile_image");
                        error_log("user_id isset: " . (isset($_POST['user_id']) ? 'true' : 'false'));
                        error_log("profile_image isset: " . (isset($_FILES['profile_image']) ? 'true' : 'false'));
                        $response = ['success' => false, 'message' => 'user_id and profile_image file are required'];
                    }
                    error_log("=== IMAGE UPLOAD DEBUG END ===");
                    break;

                
                
                case 'upload_cover':  // ✅ Move this INSIDE the POST switch
                    if (isset($_POST['user_id']) && isset($_FILES['cover_photo'])) {
                        $user_id = intval($_POST['user_id']);
                        
                        // Delete old cover photo first
                        deleteOldCoverPhoto($conn, $user_id);
                        
                        // Upload new cover photo
                        $uploadResult = handleFileUpload($_FILES['cover_photo'], $user_id);
                        
                        if ($uploadResult['success']) {
                            // Rename the file to indicate it's a cover photo
                            $oldPath = $uploadResult['filePath'];
                            $newFileName = 'cover_' . $user_id . '_' . time() . '.' . pathinfo($oldPath, PATHINFO_EXTENSION);
                            $newPath = $uploadDir . $newFileName;
                            
                            if (rename($oldPath, $newPath)) {
                                // Update database with new cover photo path
                                $imageUpdateResult = updateCoverPhoto($conn, $user_id, $newPath);
                                
                                if ($imageUpdateResult['success']) {
                                    $response = [
                                        'success' => true,
                                        'message' => 'Cover photo uploaded successfully',
                                        'imagePath' => $newPath,
                                        'imageUrl' => 'https://difsysinc.com/difsysapi/' . $newPath
                                    ];
                                } else {
                                    // Delete uploaded file if database update failed
                                    unlink($newPath);
                                    $response = $imageUpdateResult;
                                }
                            } else {
                                unlink($oldPath);
                                $response = ['success' => false, 'message' => 'Failed to process cover photo'];
                            }
                        } else {
                            $response = $uploadResult;
                        }
                    } else {
                        $response = ['success' => false, 'message' => 'user_id and cover_photo file are required'];
                    }
                    break;
                    
                default:
                    $response = ['success' => false, 'message' => 'Invalid action'];
                    break;
            }
            break;

    default:
        $response = ['success' => false, 'message' => 'Method not allowed'];
        break;
}

echo json_encode($response);
?>