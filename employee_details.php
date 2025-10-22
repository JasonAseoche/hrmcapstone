<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Include database connection
try {
    require_once 'db_connection.php';
    
    // Check if $conn is available and connected
    if (!isset($conn) || $conn === null) {
        throw new Exception('Database connection failed');
    }
    
    if ($conn->connect_error) {
        throw new Exception('Database connection error: ' . $conn->connect_error);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection error: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Check if this is an EmpDetails request
$isEmpDetailsRequest = isset($_GET['type']) && $_GET['type'] === 'empdetails';

try {
    switch ($method) {
        case 'GET':
            if ($isEmpDetailsRequest) {
                handleEmpDetailsGet($conn);
            } else {
                handleGet($conn);
            }
            break;
        case 'POST':
            if ($isEmpDetailsRequest) {
                handleEmpDetailsPost($conn, $input);
            } else {
                handlePost($conn, $input);
            }
            break;
        case 'PUT':
            if ($isEmpDetailsRequest) {
                handleEmpDetailsPut($conn, $input);
            } else {
                handlePut($conn, $input);
            }
            break;
        case 'DELETE':
            handleDelete($conn, $input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// NEW FUNCTIONS FOR EMPDETAILS
function handleEmpDetailsGet($conn) {
    $user_id = $_GET['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }
    
    try {
        // Get employee data from employeelist and useraccounts
        $sql = "SELECT 
                    el.*,
                    ua.firstName,
                    ua.lastName,
                    ua.email,
                    ua.profile_image
                FROM employeelist el 
                LEFT JOIN useraccounts ua ON el.emp_id = ua.id 
                WHERE el.emp_id = ?";
                
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('SQL prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee = $result->fetch_assoc();
        
        if (!$employee) {
            http_response_code(404);
            echo json_encode(['error' => 'Employee not found']);
            return;
        }
        
        // Get user_profiles data for background, skills, and gender
        $userProfileData = [];
        try {
            $profileSql = "SELECT background_position, background_company, background_year, 
                    background_description, skills_traits, gender, contact_number,
                    education_level, school_name, field_study, start_year, end_year
            FROM user_profiles WHERE user_id = ?";
            $profileStmt = $conn->prepare($profileSql);
            if ($profileStmt) {
                $profileStmt->bind_param("i", $user_id);
                $profileStmt->execute();
                $profileResult = $profileStmt->get_result();
                $userProfileData = $profileResult->fetch_assoc() ?: [];
            }
        } catch (Exception $e) {
            error_log("Error fetching user_profiles: " . $e->getMessage());
        }
        
        // Parse background experience data
        $backgroundExperience = [];
        if (!empty($userProfileData['background_position'])) {
            $positions = explode('|', $userProfileData['background_position'] ?? '');
            $companies = explode('|', $userProfileData['background_company'] ?? '');
            $years = explode('|', $userProfileData['background_year'] ?? '');
            $descriptions = explode('|', $userProfileData['background_description'] ?? '');
            
            for ($i = 0; $i < count($positions); $i++) {
                if (!empty($positions[$i])) {
                    $backgroundExperience[] = [
                        'company' => $companies[$i] ?? '',
                        'position' => $positions[$i] ?? '',
                        'duration' => $years[$i] ?? '',
                        'description' => $descriptions[$i] ?? ''
                    ];
                }
            }
        }
        
        // Parse skills data
        $skills = [];
        if (!empty($userProfileData['skills_traits'])) {
            $skills = explode(',', $userProfileData['skills_traits']);
            $skills = array_map('trim', $skills);
        }
        
        // Format profile image URL
        $profileImage = '';
        if (!empty($employee['profile_image'])) {
            if (strpos($employee['profile_image'], 'http') === 0) {
                $profileImage = $employee['profile_image'];
            } else {
                $profileImage = 'https://www.difsysinc.com/difsysapi/' . $employee['profile_image'];
            }
        }

        $education = [];
            if (!empty($userProfileData['education_level'])) {
                $education[] = [
                    'id' => 1,
                    'level' => $userProfileData['education_level'] ?? '',
                    'school' => $userProfileData['school_name'] ?? '',
                    'address' => $userProfileData['school_address'] ?? '', // FIXED: Use correct field
                    'field' => $userProfileData['field_study'] ?? '',
                    'startYear' => $userProfileData['start_year'] ?? '',
                    'endYear' => $userProfileData['end_year'] ?? ''
                ];
            }
        
        // Format response for EmpDetails
        $response = [
            'basicInfo' => [
                'name' => trim(($employee['firstName'] ?? '') . ' ' . ($employee['lastName'] ?? '')),
                'id' => $employee['emp_id'] ?? '',
                'gender' => $userProfileData['gender'] ?? '', // Get gender from user_profiles
                'email' => $employee['email'] ?? '',
                'contact' => $userProfileData['contact_number'] ?? $employee['number'] ?? '',
                'position' => $employee['position'] ?? '',
                'dateHired' => $employee['date_hired'] ?? '', // From employeelist
                'workingDays' => $employee['workDays'] ?? 'Monday-Friday',
                'restDay' => $employee['rest_day'] ?? 'Saturday-Sunday', // From employeelist
                'workArrangement' => $employee['workarrangement'] ?? 'On-Site',
                'profileImage' => $profileImage
            ],
            'backgroundExperience' => $backgroundExperience,
            'skills' => $skills,
            'education' => $education  // Add this line
        ];
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        error_log("EmpDetails Get error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleEmpDetailsPost($conn, $input) {
    if (!$input || !isset($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input data']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        $user_id = (int) $input['user_id'];
        
        // Handle background experience data
        $backgroundPositions = [];
        $backgroundCompanies = [];
        $backgroundYears = [];
        $backgroundDescriptions = [];
        
        if (isset($input['backgroundExperience']) && is_array($input['backgroundExperience'])) {
            foreach ($input['backgroundExperience'] as $exp) {
                $backgroundPositions[] = $exp['position'] ?? '';
                $backgroundCompanies[] = $exp['company'] ?? '';
                $backgroundYears[] = $exp['duration'] ?? '';
                $backgroundDescriptions[] = $exp['description'] ?? '';
            }
        }
        
        $backgroundPositionStr = implode('|', $backgroundPositions);
        $backgroundCompanyStr = implode('|', $backgroundCompanies);
        $backgroundYearStr = implode('|', $backgroundYears);
        $backgroundDescriptionStr = implode('|', $backgroundDescriptions);
        
        // Handle skills data
        $skillsStr = '';
        if (isset($input['skills']) && is_array($input['skills'])) {
            $skillsStr = implode(', ', $input['skills']);
        }
        
        // Check if user_profiles record exists
        $checkSql = "SELECT id FROM user_profiles WHERE user_id = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("i", $user_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $profileExists = $checkResult->fetch_assoc();
        
        if ($profileExists) {
            // Update existing record
            $sql = "UPDATE user_profiles SET 
                        background_position = ?,
                        background_company = ?,
                        background_year = ?,
                        background_description = ?,
                        skills_traits = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE user_id = ?";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("sssssi",
                $backgroundPositionStr,
                $backgroundCompanyStr,
                $backgroundYearStr,
                $backgroundDescriptionStr,
                $skillsStr,
                $user_id
            );
        } else {
            // Insert new record
            $sql = "INSERT INTO user_profiles (
                        user_id, background_position, background_company, 
                        background_year, background_description, skills_traits
                    ) VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed: ' . $conn->error);
            }
            
            $stmt->bind_param("isssss",
                $user_id,
                $backgroundPositionStr,
                $backgroundCompanyStr,
                $backgroundYearStr,
                $backgroundDescriptionStr,
                $skillsStr
            );
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Save failed: ' . $stmt->error);
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Employee details updated successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("EmpDetails Save error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleEmpDetailsPut($conn, $input) {
    // For this implementation, PUT will work the same as POST
    handleEmpDetailsPost($conn, $input);
}

// EXISTING FUNCTIONS (unchanged)
function handleGet($conn) {
    $user_id = $_GET['user_id'] ?? null;
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }
    
    try {
        // Debug: Log the user_id being searched
        error_log("Searching for user_id: " . $user_id);
        
        // Start simple - just get data from employeelist and useraccounts
        $sql = "SELECT 
                    el.*,
                    ua.firstName as ua_first_name,
                    ua.lastName as ua_last_name,
                    ua.email as ua_email,
                    ua.profile_image
                FROM employeelist el 
                LEFT JOIN useraccounts ua ON el.emp_id = ua.id 
                WHERE el.emp_id = ?";
                
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('SQL prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $profile = $result->fetch_assoc();
        
        // Debug: Log the profile result
        error_log("Profile result for user_id $user_id: " . json_encode($profile));
        
        if (!$profile) {
            http_response_code(404);
            echo json_encode(['error' => 'Employee not found']);
            return;
        }
        
        // Now try to get user_profiles data if it exists
        $userProfileData = [];
        try {
            $profileSql = "SELECT * FROM user_profiles WHERE user_id = ?";
            $profileStmt = $conn->prepare($profileSql);
            if ($profileStmt) {
                $profileStmt->bind_param("i", $user_id);
                $profileStmt->execute();
                $profileResult = $profileStmt->get_result();
                $userProfileData = $profileResult->fetch_assoc() ?: [];
            }
        } catch (Exception $e) {
            error_log("user_profiles table might not exist: " . $e->getMessage());
        }
        
        // Use employeelist data as primary source
        $firstName = $profile['firstName'] ?? $profile['ua_first_name'] ?? '';
        $lastName = $profile['lastName'] ?? $profile['ua_last_name'] ?? '';
        $email = $profile['email'] ?? $profile['ua_email'] ?? '';
        $empId = $profile['emp_id'] ?? '';
        $contact = $userProfileData['contact_number'] ?? $profile['number'] ?? '';
        $homeAddress = $profile['address'] ?? '';
        
        // Parse education data from user_profiles if available
        $education = [];
        if (!empty($userProfileData['education_level'])) {
            $education[] = [
                'id' => 1,
                'level' => $userProfileData['education_level'] ?? '',
                'school' => $userProfileData['school_name'] ?? '',
                'address' => '',
                'field' => $userProfileData['field_study'] ?? '',
                'startYear' => $userProfileData['start_year'] ?? '',
                'endYear' => $userProfileData['end_year'] ?? ''
            ];
        }
        
        // Parse family data from user_profiles if available
        $family = [];
        if (!empty($userProfileData['family_type'])) {
            $family[] = [
                'id' => 1,
                'type' => $userProfileData['family_type'] ?? '',
                'name' => $userProfileData['family_name'] ?? ''
            ];
        }
        
        // Format response data
        $profileImage = '';
            if (!empty($profile['profile_image'])) {
                if (strpos($profile['profile_image'], 'http') === 0) {
                    $profileImage = $profile['profile_image'];
                } else {
                    $profileImage = 'https://www.difsysinc.com/difsysapi/' . $profile['profile_image'];
                }
            }

            // Format response data
            $response = [
                'name' => trim($firstName . ' ' . $lastName),
                'id' => $empId,
                'gender' => $userProfileData['gender'] ?? '',
                'email' => $email,
                'contact' => $userProfileData['contact_number'] ?? $profile['number'] ?? '',
                'placeOfBirth' => $userProfileData['place_birth'] ?? '',
                'birthDate' => $userProfileData['date_of_birth'] ?? '',
                'maritalStatus' => $userProfileData['marital_status'] ?? '',
                'religion' => $userProfileData['religion'] ?? '',
                'citizenIdAddress' => $homeAddress, // Home Address
                'residentialAddress' => $userProfileData['permanent_address'] ?? '', // Permanent Address
                'emergencyContactName' => $userProfileData['emergency_name'] ?? '',
                'emergencyContactRelationship' => $userProfileData['emergency_rel'] ?? '',
                'emergencyContactPhone' => $userProfileData['emergency_number'] ?? '',
                'education' => $education,
                'family' => $family,
                'profileImage' => $profileImage, // Add this line
                // Additional employee info
                'position' => $profile['position'] ?? '',
                'role' => $profile['role'] ?? '',
                'status' => $profile['status'] ?? ''
            ];
        
        // Debug: Log the final response
        error_log("Final response: " . json_encode($response));
        
        echo json_encode($response);
        
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePost($conn, $input) {
    if (!$input || !isset($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input data']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        // Safely extract and set default values for all variables
        $user_id = (int) $input['user_id'];
        $contact = isset($input['contact']) ? (string) $input['contact'] : '';
        $birthDate = isset($input['birthDate']) && !empty($input['birthDate']) ? (string) $input['birthDate'] : null;
        $gender = isset($input['gender']) ? (string) $input['gender'] : '';
        $placeOfBirth = isset($input['placeOfBirth']) ? (string) $input['placeOfBirth'] : '';
        $maritalStatus = isset($input['maritalStatus']) ? (string) $input['maritalStatus'] : '';
        $religion = isset($input['religion']) ? (string) $input['religion'] : '';
        $citizenIdAddress = isset($input['citizenIdAddress']) ? (string) $input['citizenIdAddress'] : '';
        $residentialAddress = isset($input['residentialAddress']) ? (string) $input['residentialAddress'] : '';
        $emergencyContactName = isset($input['emergencyContactName']) ? (string) $input['emergencyContactName'] : '';
        $emergencyContactRelationship = isset($input['emergencyContactRelationship']) ? (string) $input['emergencyContactRelationship'] : '';
        $emergencyContactPhone = isset($input['emergencyContactPhone']) ? (string) $input['emergencyContactPhone'] : '';
        $email = isset($input['email']) ? (string) $input['email'] : '';
        
        // Prepare education data (only first education entry for now)
        $education_level = '';
        $school_name = '';
        $school_address = ''; // ✅ Add this
        $field_study = '';
        $start_year = '';
        $end_year = '';
        
        if (isset($input['education']) && is_array($input['education']) && count($input['education']) > 0) {
            $firstEducation = $input['education'][0];
            $education_level = isset($firstEducation['level']) ? (string) $firstEducation['level'] : '';
            $school_name = isset($firstEducation['school']) ? (string) $firstEducation['school'] : '';
            $school_address = isset($firstEducation['address']) ? (string) $firstEducation['address'] : ''; // ✅ Add this
            $field_study = isset($firstEducation['field']) ? (string) $firstEducation['field'] : '';
            $start_year = isset($firstEducation['startYear']) ? (string) $firstEducation['startYear'] : '';
            $end_year = isset($firstEducation['endYear']) ? (string) $firstEducation['endYear'] : '';
        }
        
        // Prepare family data (only first family entry for now)
        $family_type = '';
        $family_name = '';
        
        if (isset($input['family']) && is_array($input['family']) && count($input['family']) > 0) {
            $firstFamily = $input['family'][0];
            $family_type = isset($firstFamily['type']) ? (string) $firstFamily['type'] : '';
            $family_name = isset($firstFamily['name']) ? (string) $firstFamily['name'] : '';
        }
        
        // Check if user_profiles table exists
        $userProfilesExists = false;
        try {
            $checkTableSql = "SHOW TABLES LIKE 'user_profiles'";
            $checkResult = $conn->query($checkTableSql);
            $userProfilesExists = $checkResult->num_rows > 0;
        } catch (Exception $e) {
            error_log("Error checking user_profiles table: " . $e->getMessage());
        }
        
        if ($userProfilesExists) {
            // Check if record exists
            $checkSql = "SELECT id FROM user_profiles WHERE user_id = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->bind_param("i", $user_id);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $profileExists = $checkResult->fetch_assoc();
            
            if ($profileExists) {
                // Update existing user_profiles record with extended fields
                $sql = "UPDATE user_profiles SET 
                            contact_number = ?,
                            date_of_birth = ?,
                            gender = ?,
                            place_birth = ?,
                            marital_status = ?,
                            religion = ?,
                            address = ?,
                            permanent_address = ?,
                            emergency_name = ?,
                            emergency_rel = ?,
                            emergency_number = ?,
                            education_level = ?,
                            school_name = ?,
                            school_address = ?,
                            field_study = ?,
                            start_year = ?,
                            end_year = ?,
                            family_type = ?,
                            family_name = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE user_id = ?";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $conn->error);
                }
                
                $stmt->bind_param("sssssssssssssssssssi",
                    $contact,
                    $birthDate,
                    $gender,
                    $placeOfBirth,
                    $maritalStatus,
                    $religion,
                    $citizenIdAddress,
                    $residentialAddress,
                    $emergencyContactName,
                    $emergencyContactRelationship,
                    $emergencyContactPhone,
                    $education_level,
                    $school_name,
                    $school_address,      // ✅ Now matches position 14
                    $field_study,         // ✅ Now matches position 15
                    $start_year,
                    $end_year,
                    $family_type,
                    $family_name,
                    $user_id
                );
                
                if (!$stmt->execute()) {
                    throw new Exception('Update user_profiles failed: ' . $stmt->error);
                }
            } else {
                // Insert new user_profiles record
                $sql = "INSERT INTO user_profiles (
                            user_id, contact_number, date_of_birth, gender, place_birth, 
                            marital_status, religion, address, permanent_address, 
                            emergency_name, emergency_rel, emergency_number,
                            education_level, school_name, field_study, start_year, end_year,
                            family_type, family_name
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Prepare failed: ' . $conn->error);
                }
                
                $stmt->bind_param("issssssssssssssssss",
                    $user_id,
                    $contact,
                    $birthDate,
                    $gender,
                    $placeOfBirth,
                    $maritalStatus,
                    $religion,
                    $citizenIdAddress,
                    $residentialAddress,
                    $emergencyContactName,
                    $emergencyContactRelationship,
                    $emergencyContactPhone,
                    $education_level,
                    $school_name,
                    $field_study,
                    $start_year,
                    $end_year,
                    $family_type,
                    $family_name
                );
                
                if (!$stmt->execute()) {
                    throw new Exception('Insert user_profiles failed: ' . $stmt->error);
                }
            }
        }
        
        // Update employeelist table
        $updateEmployeelistSql = "UPDATE employeelist SET 
                                    email = ?,
                                    address = ?,
                                    number = ?
                                  WHERE emp_id = ?";
        $updateEmployeelistStmt = $conn->prepare($updateEmployeelistSql);
        if (!$updateEmployeelistStmt) {
            throw new Exception('Prepare employeelist update failed: ' . $conn->error);
        }
        
        $updateEmployeelistStmt->bind_param("sssi",
            $email,
            $citizenIdAddress,
            $contact,
            $user_id
        );
        
        if (!$updateEmployeelistStmt->execute()) {
            throw new Exception('Update employeelist failed: ' . $updateEmployeelistStmt->error);
        }
        
        // Update useraccounts table if email is provided
        if (!empty($email)) {
            $updateUserAccountsSql = "UPDATE useraccounts SET email = ? WHERE id = ?";
            $updateUserAccountsStmt = $conn->prepare($updateUserAccountsSql);
            if (!$updateUserAccountsStmt) {
                throw new Exception('Prepare useraccounts update failed: ' . $conn->error);
            }
            
            $updateUserAccountsStmt->bind_param("si", $email, $user_id);
            if (!$updateUserAccountsStmt->execute()) {
                throw new Exception('Update useraccounts failed: ' . $updateUserAccountsStmt->error);
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Employee details updated successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Save error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handlePut($conn, $input) {
    // For this implementation, PUT will work the same as POST
    handlePost($conn, $input);
}

function handleDelete($conn, $input) {
    if (!$input || !isset($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'User ID is required']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        $user_id = (int) $input['user_id'];
        
        // Try to clear education and family data in user_profiles if table exists
        try {
            if (isset($input['clear_education']) && $input['clear_education']) {
                $sql = "UPDATE user_profiles SET 
                            education_level = NULL,
                            school_name = NULL,
                            school_address = NULL, 
                            field_study = NULL,
                            start_year = NULL,
                            end_year = NULL
                        WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                }
            }
            
            if (isset($input['clear_family']) && $input['clear_family']) {
                $sql = "UPDATE user_profiles SET 
                            family_type = NULL,
                            family_name = NULL
                        WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                }
            }
        } catch (Exception $e) {
            error_log("Could not clear user_profiles data: " . $e->getMessage());
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Records cleared successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>