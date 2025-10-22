<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

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
 * Get user profile data including profile image
 */
function getUserProfile($conn, $id) {
    try {
        $stmt = $conn->prepare("SELECT id, firstName, lastName, email, role, profile_image FROM useraccounts WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            return [
                'success' => true,
                'data' => $data
            ];
        } else {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error fetching user profile: ' . $e->getMessage()
        ];
    }
}

/**
 * Get applicant profile data (for applicants only)
 */
function getApplicantProfile($conn, $id) {
    try {
        // First get user data
        $userProfile = getUserProfile($conn, $id);
        if (!$userProfile['success']) {
            return $userProfile;
        }
        
        // Then get applicant-specific data
        $stmt = $conn->prepare("SELECT * FROM applicantlist WHERE app_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $applicantData = $result->fetch_assoc();
            
            // Decode JSON fields
            $applicantData['education'] = $applicantData['education'] ? json_decode($applicantData['education'], true) : [];
            $applicantData['work_experience'] = $applicantData['work_experience'] ? json_decode($applicantData['work_experience'], true) : [];
            $applicantData['skills'] = $applicantData['skills'] ? json_decode($applicantData['skills'], true) : [];
            $applicantData['traits'] = $applicantData['traits'] ? json_decode($applicantData['traits'], true) : [];
            
            // Merge user data with applicant data
            $combinedData = array_merge($userProfile['data'], $applicantData);
            
            return [
                'success' => true,
                'data' => $combinedData
            ];
        } else {
            // Return just user data if no applicant profile exists yet
            return $userProfile;
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error fetching applicant profile: ' . $e->getMessage()
        ];
    }
}

/**
 * Update applicant profile data
 */
function updateApplicantProfile($conn, $id, $profileData) {
    try {
        // Encode JSON fields
        $education = json_encode($profileData['education']);
        $work_experience = json_encode($profileData['workExperience']);
        $skills = json_encode($profileData['skills']);
        $traits = json_encode($profileData['traits']);
        
        // Check if applicant record exists
        $checkStmt = $conn->prepare("SELECT id FROM applicantlist WHERE app_id = ?");
        $checkStmt->bind_param("i", $id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        if ($checkResult->num_rows > 0) {
            // Update existing record
            $stmt = $conn->prepare("
                UPDATE applicantlist SET 
                    firstName = ?,
                    lastName = ?,
                    middle_name = ?,
                    email = ?,
                    contact_number = ?,
                    address = ?,
                    position = ?,
                    date_of_birth = ?,
                    civil_status = ?,
                    gender = ?,
                    citizenship = ?,
                    height = ?,
                    weight = ?,
                    objective = ?,
                    education = ?,
                    work_experience = ?,
                    skills = ?,
                    traits = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE app_id = ?
            ");
            
            $stmt->bind_param(
                "ssssssssssssssssssi",
                $profileData['firstName'],
                $profileData['lastName'],
                $profileData['middleName'],
                $profileData['email'],
                $profileData['contactNumber'],
                $profileData['address'],
                $profileData['position'],
                $profileData['dateOfBirth'],
                $profileData['civilStatus'],
                $profileData['gender'],
                $profileData['citizenship'],
                $profileData['height'],
                $profileData['weight'],
                $profileData['objective'],
                $education,
                $work_experience,
                $skills,
                $traits,
                $id
            );
        } else {
            // Insert new record
            $stmt = $conn->prepare("
                INSERT INTO applicantlist 
                (app_id, firstName, lastName, middle_name, email, contact_number, address, position, 
                 date_of_birth, civil_status, gender, citizenship, height, weight, objective, 
                 education, work_experience, skills, traits, role, status, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Applicant', 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            
            $stmt->bind_param(
                "isssssssssssssssss",
                $id,
                $profileData['firstName'],
                $profileData['lastName'],
                $profileData['middleName'],
                $profileData['email'],
                $profileData['contactNumber'],
                $profileData['address'],
                $profileData['position'],
                $profileData['dateOfBirth'],
                $profileData['civilStatus'],
                $profileData['gender'],
                $profileData['citizenship'],
                $profileData['height'],
                $profileData['weight'],
                $profileData['objective'],
                $education,
                $work_experience,
                $skills,
                $traits
            );
        }
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'message' => 'Profile updated successfully'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Failed to update profile'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Error updating profile: ' . $e->getMessage()
        ];
    }
}

/**
 * Handle file upload
 */
function handleFileUpload($file, $id) {
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
        $fileName = 'profile_' . $id . '_' . time() . '.' . $fileExtension;
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
function updateProfileImage($conn, $id, $imagePath) {
    try {
        $stmt = $conn->prepare("UPDATE useraccounts SET profile_image = ? WHERE id = ?");
        $stmt->bind_param("si", $imagePath, $id);
        
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
function deleteOldProfileImage($conn, $id) {
    try {
        $stmt = $conn->prepare("SELECT profile_image FROM useraccounts WHERE id = ?");
        $stmt->bind_param("i", $id);
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
        if (isset($_GET['id'])) {
            $id = intval($_GET['id']);
            $type = $_GET['type'] ?? 'user'; // 'user' or 'applicant'
            
            if ($type === 'applicant') {
                $response = getApplicantProfile($conn, $id);
            } else {
                $response = getUserProfile($conn, $id);
            }
        } else {
            $response = ['success' => false, 'message' => 'id parameter is required'];
        }
        break;
        
    case 'POST':
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                if (isset($_POST['id']) && isset($_POST['profileData'])) {
                    $id = intval($_POST['id']);
                    $profileData = json_decode($_POST['profileData'], true);
                    
                    if ($profileData) {
                        $response = updateApplicantProfile($conn, $id, $profileData);
                    } else {
                        $response = ['success' => false, 'message' => 'Invalid profile data'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'id and profileData are required'];
                }
                break;
                
            case 'upload_image':
                if (isset($_POST['id']) && isset($_FILES['profile_image'])) {
                    $id = intval($_POST['id']);
                    
                    // Delete old image first
                    deleteOldProfileImage($conn, $id);
                    
                    // Upload new image
                    $uploadResult = handleFileUpload($_FILES['profile_image'], $id);
                    
                    if ($uploadResult['success']) {
                        // Update database with new image path
                        $imageUpdateResult = updateProfileImage($conn, $id, $uploadResult['filePath']);
                        
                        if ($imageUpdateResult['success']) {
                            $response = [
                                'success' => true,
                                'message' => 'Profile image uploaded successfully',
                                'imagePath' => $uploadResult['filePath'],
                                'imageUrl' => 'https://www.difsysinc.com/difsysapi/' . $uploadResult['filePath']
                            ];
                        } else {
                            // Delete uploaded file if database update failed
                            unlink($uploadResult['filePath']);
                            $response = $imageUpdateResult;
                        }
                    } else {
                        $response = $uploadResult;
                    }
                } else {
                    $response = ['success' => false, 'message' => 'id and profile_image file are required'];
                }
                break;
                
            case 'update_profile_with_image':
                if (isset($_POST['id']) && isset($_POST['profileData'])) {
                    $id = intval($_POST['id']);
                    $profileData = json_decode($_POST['profileData'], true);
                    
                    if ($profileData) {
                        // Update profile data first
                        $profileUpdateResult = updateApplicantProfile($conn, $id, $profileData);
                        
                        if ($profileUpdateResult['success'] && isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                            // Delete old image
                            deleteOldProfileImage($conn, $id);
                            
                            // Upload new image
                            $uploadResult = handleFileUpload($_FILES['profile_image'], $id);
                            
                            if ($uploadResult['success']) {
                                $imageUpdateResult = updateProfileImage($conn, $id, $uploadResult['filePath']);
                                
                                if ($imageUpdateResult['success']) {
                                    $response = [
                                        'success' => true,
                                        'message' => 'Profile and image updated successfully',
                                        'imagePath' => $uploadResult['filePath'],
                                        'imageUrl' => 'https://www.difsysinc.com/difsysapi/' . $uploadResult['filePath']
                                    ];
                                } else {
                                    unlink($uploadResult['filePath']);
                                    $response = [
                                        'success' => true,
                                        'message' => 'Profile updated successfully, but image upload failed',
                                        'imageError' => $imageUpdateResult['message']
                                    ];
                                }
                            } else {
                                $response = [
                                    'success' => true,
                                    'message' => 'Profile updated successfully, but image upload failed',
                                    'imageError' => $uploadResult['message']
                                ];
                            }
                        } else {
                            $response = $profileUpdateResult;
                        }
                    } else {
                        $response = ['success' => false, 'message' => 'Invalid profile data'];
                    }
                } else {
                    $response = ['success' => false, 'message' => 'id and profileData are required'];
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